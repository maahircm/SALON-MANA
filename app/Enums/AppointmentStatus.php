<?php

namespace App\Enums;

enum AppointmentStatus: string
{
    case Pending = 'pending';
    case Confirmed = 'confirmed';
    case CheckedIn = 'checked_in';
    case InService = 'in_service';
    case Completed = 'completed';
    case Cancelled = 'cancelled';
    case NoShow = 'no_show';

    public function label(): string
    {
        return match ($this) {
            self::CheckedIn => 'Checked In',
            self::InService => 'In Service',
            self::NoShow => 'No Show',
            default => ucfirst($this->value),
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Pending => '#b7791f',
            self::Confirmed => '#2b6cb0',
            self::CheckedIn => '#6b46c1',
            self::InService => '#c05621',
            self::Completed => '#2f855a',
            self::Cancelled => '#718096',
            self::NoShow => '#c53030',
        };
    }

    /** @return self[] */
    public function allowedNext(): array
    {
        return match ($this) {
            self::Pending => [self::Confirmed, self::Cancelled, self::NoShow],
            self::Confirmed => [self::CheckedIn, self::Cancelled, self::NoShow],
            self::CheckedIn => [self::InService, self::Cancelled],
            self::InService => [self::Completed],
            self::Completed, self::Cancelled, self::NoShow => [],
        };
    }

    public function canTransitionTo(self $to): bool
    {
        return in_array($to, $this->allowedNext(), true);
    }

    /** Statuses that occupy a stylist / room slot. */
    public static function blocking(): array
    {
        return [self::Pending, self::Confirmed, self::CheckedIn, self::InService];
    }

    public static function blockingValues(): array
    {
        return array_map(fn ($s) => $s->value, self::blocking());
    }

    public function isFinal(): bool
    {
        return $this->allowedNext() === [];
    }
}
