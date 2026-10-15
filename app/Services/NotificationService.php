<?php

namespace App\Services;

use App\Jobs\SendNotificationJob;
use App\Models\Appointment;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\NotificationLog;
use App\Models\User;
use App\Models\UserNotification;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Creates in-app notifications and queues outbound email / SMS. Every automatic message carries a dedupe key, so
 * running a scheduled command twice, retrying a request or re-saving a booking can never send the same message twice.
 */
class NotificationService
{
    public function __construct(private SettingsService $settings, private NotificationMessages $messages) {}

    public function channelEnabled(string $channel): bool
    {
        return (bool) $this->settings->get("notifications.channels.{$channel}", false);
    }

    public function eventEnabled(string $type): bool
    {
        return (bool) $this->settings->get("notifications.events.{$type}", false);
    }

    /**
     * Queue a message to a customer on every enabled channel they can be reached on. Returns how many messages were queued.
     * Transactional messages (booking, reminder, payment) do not need marketing consent; $requiresConsent covers greetings and promotions.
     */
    public function toCustomer(string $type, Customer $customer, string $subject, string $body, string $key, ?Model $related = null, bool $requiresConsent = false): int
    {
        if (! $this->eventEnabled($type) || $customer->anonymized_at !== null || ($requiresConsent && ! $customer->marketing_consent) || $customer->status === 'blocked') {
            return 0;
        }
        $queued = 0;
        foreach (['email' => $customer->email, 'sms' => $customer->phone] as $channel => $destination) {
            if (! $this->channelEnabled($channel) || blank($destination)) {
                continue;
            }
            $log = $this->createLog($channel, $type, 'customer', $customer->id, (string) $destination, $channel === 'email' ? $subject : null, $body, "{$key}:{$channel}", $related);
            if ($log) {
                $this->dispatch($log);
                $queued++;
            }
        }

        return $queued;
    }

    public function bookingConfirmation(Appointment $appointment): int
    {
        $appointment->loadMissing('lines', 'customer');

        return $this->forAppointment('booking_confirmation', $appointment, "booking-confirmation:{$appointment->id}", fn ($c) => $this->messages->bookingConfirmation($appointment, $c));
    }

    public function bookingCancellation(Appointment $appointment): int
    {
        $appointment->loadMissing('lines', 'customer');

        return $this->forAppointment('booking_cancellation', $appointment, "booking-cancellation:{$appointment->id}", fn ($c) => $this->messages->bookingCancellation($appointment, $c));
    }

    /** One reminder per appointment time: moving the appointment makes it eligible for a fresh reminder. */
    public function appointmentReminder(Appointment $appointment): int
    {
        $appointment->loadMissing('lines', 'customer');

        return $this->forAppointment('appointment_reminder', $appointment, "appointment-reminder:{$appointment->id}:{$appointment->start_at->timestamp}", fn ($c) => $this->messages->appointmentReminder($appointment, $c));
    }

    /** @param int $round 0 for the first reminder, 1 for the next, … (one message per round and channel) */
    public function paymentReminder(Invoice $invoice, int $round): int
    {
        $invoice->loadMissing('customer');
        if (! $invoice->customer) {
            return 0;
        }
        [$subject, $body] = $this->messages->paymentReminder($invoice, $invoice->customer);

        return $this->toCustomer('payment_reminder', $invoice->customer, $subject, $body, "payment-reminder:{$invoice->id}:{$round}", $invoice);
    }

    public function birthday(Customer $customer, int $year): int
    {
        [$subject, $body] = $this->messages->birthday($customer);

        return $this->toCustomer('birthday', $customer, $subject, $body, "birthday:{$customer->id}:{$year}", null, true);
    }

    /** @param callable(Customer): array{0: string, 1: string} $compose */
    private function forAppointment(string $type, Appointment $appointment, string $key, callable $compose): int
    {
        $customer = $appointment->customer;
        if (! $customer) {
            return 0;
        }
        [$subject, $body] = $compose($customer);

        return $this->toCustomer($type, $customer, $subject, $body, $key, $appointment);
    }

    /** In-app notification (and an email when enabled) for every active user who holds a permission. Returns the number of users notified. */
    public function toPermissionHolders(string $permission, string $type, string $title, string $body, ?string $url, string $key): int
    {
        if (! $this->eventEnabled($type)) {
            return 0;
        }
        $count = 0;
        foreach (User::query()->where('is_active', true)->with('roles')->get() as $user) {
            if (! $user->can($permission)) {
                continue;
            }
            $notified = $this->channelEnabled('in_app') && $this->inApp($user, $type, $title, $body, $url, $key);
            if ($this->channelEnabled('email') && filled($user->email)) {
                $log = $this->createLog('email', $type, 'user', $user->id, $user->email, $title, $body, "{$key}:user{$user->id}:email");
                if ($log) {
                    $this->dispatch($log);
                    $notified = true;
                }
            }
            $count += (int) $notified;
        }

        return $count;
    }

    public function inApp(User $user, string $type, string $title, string $body, ?string $url, string $key): bool
    {
        try {
            UserNotification::create(['user_id' => $user->id, 'type' => $type, 'title' => $title, 'body' => $body, 'url' => $url, 'dedupe_key' => $key]);

            return true;
        } catch (UniqueConstraintViolationException) {
            return false;
        }
    }

    /** Put a failed (or stuck) message back in the queue. Only one caller can win the status change, so a double click sends once. */
    public function retry(NotificationLog $log): bool
    {
        $won = NotificationLog::whereKey($log->id)->whereIn('status', [NotificationLog::FAILED, 'sending'])->update(['status' => NotificationLog::QUEUED, 'last_error' => null]);
        if ($won) {
            $this->dispatch($log->refresh());
        }

        return (bool) $won;
    }

    private function createLog(string $channel, string $type, string $recipientType, ?int $recipientId, string $destination, ?string $subject, string $body, string $key, ?Model $related = null): ?NotificationLog
    {
        try {
            return NotificationLog::create([
                'channel' => $channel, 'type' => $type, 'recipient_type' => $recipientType, 'recipient_id' => $recipientId, 'destination' => $destination,
                'subject' => $subject, 'body' => $body, 'status' => NotificationLog::QUEUED, 'dedupe_key' => $key,
                'related_type' => $related?->getMorphClass(), 'related_id' => $related?->getKey(),
            ]);
        } catch (UniqueConstraintViolationException) {
            return null;
        }
    }

    /** Queue delivery. A broken queue or mail setup must never break the booking or sale that triggered the message. */
    private function dispatch(NotificationLog $log): void
    {
        try {
            SendNotificationJob::dispatch($log->id);
        } catch (Throwable $e) {
            Log::warning('Notification delivery failed to dispatch', ['log' => $log->id, 'error' => $e->getMessage()]);
        }
    }
}
