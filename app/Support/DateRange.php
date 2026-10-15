<?php

namespace App\Support;

use Carbon\CarbonImmutable;
use Illuminate\Http\Request;

/** An inclusive date range in salon time, built from ?from=&to= or a ?range= preset. */
final class DateRange
{
    /** @var array<string, string> */
    public const PRESETS = ['today' => 'Today', '7d' => 'Last 7 days', '30d' => 'Last 30 days', 'month' => 'This month', 'last_month' => 'Last month', 'year' => 'This year'];

    public function __construct(public readonly CarbonImmutable $from, public readonly CarbonImmutable $to) {}

    /** @return array<string, array<int, string>> */
    public static function rules(): array
    {
        return ['from' => ['nullable', 'date_format:Y-m-d'], 'to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from'], 'range' => ['nullable', 'in:'.implode(',', array_keys(self::PRESETS))]];
    }

    public static function fromRequest(Request $request, string $default = '30d'): self
    {
        $request->validate(self::rules());
        if ($request->filled('from') || $request->filled('to')) {
            $to = CarbonImmutable::parse($request->query('to', today()->toDateString()))->endOfDay();
            $from = CarbonImmutable::parse($request->query('from', $to->subDays(29)->toDateString()))->startOfDay();

            return new self($from, $to);
        }

        return self::preset((string) $request->query('range', $default));
    }

    public static function preset(string $preset): self
    {
        $today = CarbonImmutable::today();

        return match ($preset) {
            'today' => new self($today, $today->endOfDay()),
            '7d' => new self($today->subDays(6), $today->endOfDay()),
            'month' => new self($today->startOfMonth(), $today->endOfDay()),
            'last_month' => new self($today->subMonthNoOverflow()->startOfMonth(), $today->subMonthNoOverflow()->endOfMonth()),
            'year' => new self($today->startOfYear(), $today->endOfDay()),
            default => new self($today->subDays(29), $today->endOfDay()),
        };
    }

    public function days(): int
    {
        return (int) $this->from->startOfDay()->diffInDays($this->to->startOfDay()) + 1;
    }

    public function label(): string
    {
        $format = config('salon.date_format');

        return $this->from->format($format).' – '.$this->to->format($format);
    }

    /** @return array{from: string, to: string} */
    public function query(): array
    {
        return ['from' => $this->from->toDateString(), 'to' => $this->to->toDateString()];
    }
}
