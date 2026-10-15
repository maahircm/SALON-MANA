<?php

namespace App\Services;

use App\Enums\AppointmentStatus;
use App\Models\AppointmentService;
use App\Models\Employee;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Product;
use App\Models\Service;
use App\Models\User;
use App\Support\Money;
use Illuminate\Validation\ValidationException;

/**
 * Server-side pricing of a cart. Prices, tax rates and descriptions always come from the catalogue
 * or the booked appointment line, never from the browser; only quantity and discount are user input.
 */
class InvoicePricingService
{
    public function __construct(private SettingsService $settings) {}

    /**
     * @param  array<int, array{service_id?: int|string|null, product_id?: int|string|null, appointment_service_id?: int|string|null, quantity?: int|string, employee_id?: int|string|null, discount_type?: string|null, discount_value?: string|null}>  $items
     * @return array{lines: array<int, array<string, mixed>>, subtotal: string, discount_total: string, tax_total: string, total: string, discount_override: bool}
     */
    public function price(array $items, User $actor, ?int $ignoreInvoiceId = null): array
    {
        if ($items === []) {
            throw ValidationException::withMessages(['items' => 'Add at least one item to the sale.']);
        }

        $lines = [];
        $errors = [];
        $override = false;
        $seenAppointmentLines = [];

        foreach (array_values($items) as $i => $item) {
            $line = $this->priceLine($i, $item, $actor, $errors, $override, $ignoreInvoiceId, $seenAppointmentLines);
            if ($line !== null) {
                $lines[] = $line;
            }
        }
        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        return [
            'lines' => $lines,
            'subtotal' => Money::add(...array_column($lines, 'gross')),
            'discount_total' => Money::add(...array_column($lines, 'discount_amount')),
            'tax_total' => Money::add(...array_column($lines, 'tax_amount')),
            'total' => Money::add(...array_column($lines, 'line_total')),
            'discount_override' => $override,
        ];
    }

    /**
     * @param  array<string, mixed>  $item
     * @param  array<string, string>  $errors
     * @param  array<int, bool>  $seenAppointmentLines
     * @return array<string, mixed>|null
     */
    private function priceLine(int $i, array $item, User $actor, array &$errors, bool &$override, ?int $ignoreInvoiceId, array &$seenAppointmentLines): ?array
    {
        $quantity = (int) ($item['quantity'] ?? 1);
        $employeeId = ($item['employee_id'] ?? null) ?: null;
        $serviceId = ($item['service_id'] ?? null) ?: null;
        $productId = ($item['product_id'] ?? null) ?: null;
        $itemType = 'service';
        $appointmentServiceId = ($item['appointment_service_id'] ?? null) ?: null;

        if ($appointmentServiceId !== null) {
            $booked = AppointmentService::with('appointment')->find($appointmentServiceId);
            if (! $booked) {
                $errors["items.$i.appointment_service_id"] = 'The booked service no longer exists.';

                return null;
            }
            if (in_array($booked->appointment->status, [AppointmentStatus::Cancelled, AppointmentStatus::NoShow], true)) {
                $errors["items.$i.appointment_service_id"] = "{$booked->service_name} belongs to a cancelled or no-show appointment.";

                return null;
            }
            if (isset($seenAppointmentLines[$booked->id]) || $this->alreadyInvoiced($booked->id, $ignoreInvoiceId)) {
                $errors["items.$i.appointment_service_id"] = "{$booked->service_name} has already been invoiced.";

                return null;
            }
            $seenAppointmentLines[$booked->id] = true;
            $quantity = 1;
            $productId = null;
            $serviceId = $booked->service_id;
            $employeeId ??= $booked->employee_id;
            $description = $booked->service_name;
            $unitPrice = (string) $booked->unit_price;
            $taxRate = (string) $booked->tax_rate;
        } elseif ($productId !== null) {
            $product = Product::find($productId);
            if (! $product || ! $product->is_active || ! $product->is_sellable) {
                $errors["items.$i.product_id"] = 'Choose an active product that is available for sale.';

                return null;
            }
            $itemType = 'product';
            $serviceId = null;
            $description = $product->name;
            $unitPrice = (string) $product->selling_price;
            $taxRate = (string) $product->tax_rate;
        } else {
            $service = $serviceId ? Service::find($serviceId) : null;
            if (! $service || ! $service->is_active) {
                $errors["items.$i.service_id"] = 'Choose an active service.';

                return null;
            }
            $description = $service->name;
            $unitPrice = $service->currentPrice();
            $taxRate = (string) $service->tax_rate;
        }

        if ($quantity < 1 || $quantity > 99) {
            $errors["items.$i.quantity"] = 'Quantity must be between 1 and 99.';

            return null;
        }
        if ($employeeId !== null && ! Employee::whereKey($employeeId)->where('status', '!=', 'terminated')->exists()) {
            $errors["items.$i.employee_id"] = 'The selected service provider is not available.';

            return null;
        }

        $gross = Money::times($unitPrice, $quantity);
        [$discountType, $discountValue, $discountAmount] = $this->discount($i, $item, $gross, $actor, $errors, $override);
        if (isset($errors["items.$i.discount_value"])) {
            return null;
        }
        $net = Money::sub($gross, $discountAmount);
        $tax = Money::percent($net, $taxRate);

        return [
            'item_type' => $itemType,
            'service_id' => $serviceId,
            'product_id' => $productId,
            'appointment_service_id' => $appointmentServiceId,
            'employee_id' => $employeeId,
            'description' => $description,
            'quantity' => $quantity,
            'unit_price' => $unitPrice,
            'gross' => $gross,
            'discount_type' => $discountType,
            'discount_value' => $discountValue,
            'discount_amount' => $discountAmount,
            'tax_rate' => $taxRate,
            'tax_amount' => $tax,
            'line_total' => Money::add($net, $tax),
            'sort_order' => $i,
        ];
    }

    /**
     * @param  array<string, mixed>  $item
     * @param  array<string, string>  $errors
     * @return array{0: string, 1: string, 2: string}
     */
    private function discount(int $i, array $item, string $gross, User $actor, array &$errors, bool &$override): array
    {
        $type = $item['discount_type'] ?? 'none';
        $raw = $item['discount_value'] ?? '0';
        if ($type === 'none' || $type === null || $type === '' || (float) $raw <= 0) {
            return ['none', '0.00', '0.00'];
        }
        if (! in_array($type, ['percent', 'fixed'], true) || ! preg_match('/^\d+(\.\d{1,2})?$/', (string) $raw)) {
            $errors["items.$i.discount_value"] = 'Enter a valid discount.';

            return ['none', '0.00', '0.00'];
        }
        if (! $actor->can('pos.discount')) {
            $errors["items.$i.discount_value"] = 'You are not allowed to apply discounts.';

            return ['none', '0.00', '0.00'];
        }
        if ($type === 'percent' && Money::compare($raw, '100') > 0) {
            $errors["items.$i.discount_value"] = 'A percentage discount cannot exceed 100%.';

            return ['none', '0.00', '0.00'];
        }
        $amount = $type === 'percent' ? Money::percent($gross, $raw) : Money::fromMinor(Money::toMinor($raw));
        if (Money::compare($amount, $gross) > 0) {
            $errors["items.$i.discount_value"] = 'The discount cannot exceed the line amount.';

            return ['none', '0.00', '0.00'];
        }

        // discount% > limit%  <=>  discount_minor * 10000 > limit_basis_points * gross_minor
        $limitBp = Money::toMinor($this->settings->get('pos.max_discount_percent', '10'));
        if (Money::toMinor($amount) * 10000 > $limitBp * Money::toMinor($gross)) {
            if (! $actor->can('pos.discount_unlimited')) {
                $errors["items.$i.discount_value"] = "Discounts above {$this->settings->get('pos.max_discount_percent', '10')}% need manager approval (permission pos.discount_unlimited).";

                return ['none', '0.00', '0.00'];
            }
            $override = true;
        }

        return [$type, Money::fromMinor(Money::toMinor($raw)), $amount];
    }

    private function alreadyInvoiced(int $appointmentServiceId, ?int $ignoreInvoiceId): bool
    {
        return InvoiceItem::where('appointment_service_id', $appointmentServiceId)
            ->whereHas('invoice', fn ($q) => $q->where('status', Invoice::STATUS_ISSUED)->when($ignoreInvoiceId, fn ($w) => $w->where('id', '!=', $ignoreInvoiceId)))
            ->exists();
    }
}
