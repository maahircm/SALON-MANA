<?php

namespace App\Services;

use App\Models\Appointment;
use App\Models\Customer;
use App\Models\Invoice;
use Illuminate\Support\Collection;

/** Wording of every automatic message. Returns [subject, body]. */
class NotificationMessages
{
    public function __construct(private SettingsService $settings) {}

    /** @return array{0: string, 1: string} */
    public function bookingConfirmation(Appointment $appointment, Customer $customer): array
    {
        return ["Your appointment is booked - {$this->salon()}", "Hello {$customer->name},\nyour appointment {$appointment->appointment_number} at {$this->salon()} is booked for {$this->when($appointment)}.\n{$this->services($appointment)}{$this->contact()}"];
    }

    /** @return array{0: string, 1: string} */
    public function bookingCancellation(Appointment $appointment, Customer $customer): array
    {
        return ["Your appointment was cancelled - {$this->salon()}", "Hello {$customer->name},\nyour appointment {$appointment->appointment_number} on {$this->when($appointment)} has been cancelled.\n{$this->contact()}"];
    }

    /** @return array{0: string, 1: string} */
    public function appointmentReminder(Appointment $appointment, Customer $customer): array
    {
        return ["Reminder: your appointment on {$this->when($appointment)}", "Hello {$customer->name},\nthis is a reminder of your appointment {$appointment->appointment_number} at {$this->salon()} on {$this->when($appointment)}.\n{$this->services($appointment)}{$this->contact()}"];
    }

    /** @return array{0: string, 1: string} */
    public function paymentReminder(Invoice $invoice, Customer $customer): array
    {
        $balance = money($invoice->balanceDue());

        return ["Payment reminder for invoice {$invoice->invoice_number}", "Hello {$customer->name},\ninvoice {$invoice->invoice_number} from {$this->salon()} has an outstanding balance of {$balance}. Please settle it at your next visit or contact us.\n{$this->contact()}"];
    }

    /** @return array{0: string, 1: string} */
    public function birthday(Customer $customer): array
    {
        return ["Happy birthday from {$this->salon()}", "Happy birthday {$customer->name}! Everyone at {$this->salon()} wishes you a wonderful day.\n{$this->contact()}"];
    }

    /**
     * @param  Collection<int, object{name: string, current_stock: mixed, reorder_level: mixed}>  $products
     * @return array{0: string, 1: string}
     */
    public function lowStock(Collection $products): array
    {
        $lines = $products->take(10)->map(fn ($p) => "- {$p->name}: {$this->qty($p->current_stock)} on hand (reorder at {$this->qty($p->reorder_level)})")->implode("\n");
        $more = $products->count() > 10 ? "\n…and ".($products->count() - 10).' more.' : '';

        return ["{$products->count()} product(s) need restocking", $lines.$more];
    }

    private function salon(): string
    {
        return (string) config('salon.name');
    }

    private function when(Appointment $appointment): string
    {
        return $appointment->start_at->format(config('salon.date_format').' '.config('salon.time_format'));
    }

    private function services(Appointment $appointment): string
    {
        $names = $appointment->lines->pluck('service_name')->filter()->implode(', ');

        return $names === '' ? '' : "Services: {$names}\n";
    }

    private function contact(): string
    {
        $phone = (string) $this->settings->get('general.phone', '');

        return $phone === '' ? '' : "Questions? Call {$phone}.";
    }

    private function qty(mixed $value): string
    {
        return rtrim(rtrim(number_format((float) $value, 3, '.', ''), '0'), '.') ?: '0';
    }
}
