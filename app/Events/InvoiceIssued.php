<?php

namespace App\Events;

use App\Models\Invoice;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Raised after an invoice is issued and committed. Later modules (stock deduction, commissions,
 * loyalty points, notifications) subscribe to this instead of being called from the POS code.
 */
class InvoiceIssued
{
    use Dispatchable;

    public function __construct(public Invoice $invoice) {}
}
