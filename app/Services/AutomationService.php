<?php

namespace App\Services;

use App\Models\Appointment;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Product;

/** The work behind the scheduled commands. Each method is idempotent: running it again sends nothing new. */
class AutomationService
{
    public function __construct(private NotificationService $notifications, private NotificationMessages $messages, private SettingsService $settings) {}

    /** Appointments whose reminder time has arrived and that have not started. @return int messages queued */
    public function appointmentReminders(): int
    {
        $queued = 0;
        Appointment::query()->whereIn('status', ['pending', 'confirmed'])->whereNotNull('reminder_at')->where('reminder_at', '<=', now())->where('start_at', '>', now())
            ->with('customer', 'lines')->orderBy('start_at')->limit(500)->get()
            ->each(function (Appointment $appointment) use (&$queued): void {
                $queued += $this->notifications->appointmentReminder($appointment);
            });

        return $queued;
    }

    /** One in-app (and email) summary per day to people who manage inventory. @return int users notified */
    public function lowStockAlerts(): int
    {
        $products = Product::query()->where('is_active', true)->where('reorder_level', '>', 0)->whereColumn('current_stock', '<=', 'reorder_level')->orderBy('name')->get(['id', 'name', 'current_stock', 'reorder_level']);
        if ($products->isEmpty()) {
            return 0;
        }
        [$title, $body] = $this->messages->lowStock($products);

        return $this->notifications->toPermissionHolders('inventory.manage', 'low_stock', $title, $body, route('reports.show', 'low_stock'), 'low-stock:'.today()->toDateString());
    }

    /** Overdue invoices get a reminder after N days, repeated every M days, at most K times. @return int messages queued */
    public function paymentReminders(): int
    {
        $after = max(1, (int) $this->settings->get('notifications.payment_reminder_after_days', 3));
        $repeat = max(1, (int) $this->settings->get('notifications.payment_reminder_repeat_days', 7));
        $max = max(1, (int) $this->settings->get('notifications.payment_reminder_max', 3));
        $queued = 0;

        Invoice::query()->where('status', Invoice::STATUS_ISSUED)->whereNotNull('customer_id')->whereColumn('amount_paid', '<', 'total')->where('issued_at', '<=', now()->subDays($after))
            ->with('customer')->orderBy('id')->limit(500)->get()
            ->each(function (Invoice $invoice) use ($after, $repeat, $max, &$queued): void {
                $round = intdiv((int) $invoice->issued_at->diffInDays(now()) - $after, $repeat);
                if ($round < $max) {
                    $queued += $this->notifications->paymentReminder($invoice, $round);
                }
            });

        return $queued;
    }

    /** Birthday greetings, only to customers who agreed to marketing. @return int messages queued */
    public function birthdayGreetings(): int
    {
        $queued = 0;
        Customer::query()->where('status', 'active')->where('marketing_consent', true)->whereNull('anonymized_at')
            ->whereMonth('date_of_birth', today()->month)->whereDay('date_of_birth', today()->day)->get()
            ->each(function (Customer $customer) use (&$queued): void {
                $queued += $this->notifications->birthday($customer, today()->year);
            });

        return $queued;
    }
}
