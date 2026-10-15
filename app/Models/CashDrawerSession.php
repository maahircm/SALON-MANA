<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use LogicException;

/**
 * One cashier's shift on one drawer. Changed only through CashDrawerService; once closed it is frozen.
 */
class CashDrawerSession extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'opened_at' => 'datetime',
            'closed_at' => 'datetime',
            'opening_cash' => 'decimal:2',
            'expected_cash' => 'decimal:2',
            'counted_cash' => 'decimal:2',
            'variance' => 'decimal:2',
            'summary' => 'array',
        ];
    }

    protected static function booted(): void
    {
        static::updating(function (self $session): void {
            if ($session->getOriginal('status') === 'closed') {
                throw new LogicException('A closed cash drawer session cannot be modified.');
            }
        });
        static::deleting(fn () => throw new LogicException('Cash drawer sessions cannot be deleted.'));
    }

    public function drawer(): BelongsTo
    {
        return $this->belongsTo(CashDrawer::class, 'cash_drawer_id');
    }

    public function opener(): BelongsTo
    {
        return $this->belongsTo(User::class, 'opened_by');
    }

    public function closer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'closed_by');
    }

    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }

    public function refunds(): HasMany
    {
        return $this->hasMany(Refund::class);
    }

    public function movements(): HasMany
    {
        return $this->hasMany(CashMovement::class);
    }

    public function isOpen(): bool
    {
        return $this->status === 'open';
    }
}
