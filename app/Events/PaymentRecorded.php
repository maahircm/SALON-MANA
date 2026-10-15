<?php

namespace App\Events;

use App\Models\Payment;
use Illuminate\Foundation\Events\Dispatchable;

class PaymentRecorded
{
    use Dispatchable;

    public function __construct(public Payment $payment) {}
}
