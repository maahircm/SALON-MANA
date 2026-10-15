<?php

namespace App\Support;

use Closure;
use Illuminate\Support\Facades\DB;

/**
 * Runs a closure in a database transaction and transparently retries it when MySQL aborts the
 * transaction as a deadlock / lock-wait victim, which is normal under concurrent sales and bookings.
 * The closure must therefore be safe to run again from the start (all of ours are: nothing commits until the end).
 */
final class Atomic
{
    public const ATTEMPTS = 5;

    /**
     * @template TReturn
     *
     * @param  Closure(): TReturn  $callback
     * @return TReturn
     */
    public static function run(Closure $callback): mixed
    {
        return DB::transaction($callback, self::ATTEMPTS);
    }
}
