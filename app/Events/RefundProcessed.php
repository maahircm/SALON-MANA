<?php

namespace App\Events;

use App\Models\Refund;
use Illuminate\Foundation\Events\Dispatchable;

class RefundProcessed
{
    use Dispatchable;

    public function __construct(public Refund $refund) {}
}
