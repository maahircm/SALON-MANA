<?php

namespace App\Models\Concerns;

/**
 * Append-only tables have no updated_at, so Eloquent does not stamp created_at. A database DEFAULT CURRENT_TIMESTAMP
 * would use the database server's clock and time zone, which can differ from the salon's. Stamp it from the application.
 */
trait StampsCreatedAt
{
    protected static function bootStampsCreatedAt(): void
    {
        static::creating(function ($model): void {
            if ($model->getAttribute('created_at') === null) {
                $model->setAttribute('created_at', now());
            }
        });
    }
}
