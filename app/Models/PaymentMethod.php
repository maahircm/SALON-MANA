<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['code', 'name', 'type', 'is_active', 'sort_order'])]
class PaymentMethod extends Model
{
    public const TYPES = ['cash' => 'Cash', 'card' => 'Card', 'bank' => 'Bank transfer', 'mobile' => 'Mobile money', 'loyalty' => 'Loyalty points', 'other' => 'Other'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    public function isCash(): bool
    {
        return $this->type === 'cash';
    }

    public function isLoyalty(): bool
    {
        return $this->type === 'loyalty';
    }
}
