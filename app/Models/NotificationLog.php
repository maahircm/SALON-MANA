<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class NotificationLog extends Model
{
    public const QUEUED = 'queued';

    public const SENT = 'sent';

    /** SMS accepted by the "log" driver: written to the application log, not delivered to a handset. */
    public const LOGGED = 'logged';

    public const FAILED = 'failed';

    public const SKIPPED = 'skipped';

    /** @var array<string, string> */
    public const TYPES = [
        'booking_confirmation' => 'Booking confirmation', 'booking_cancellation' => 'Booking cancellation', 'appointment_reminder' => 'Appointment reminder',
        'payment_reminder' => 'Payment reminder', 'birthday' => 'Birthday greeting', 'low_stock' => 'Low-stock alert',
    ];

    protected $guarded = [];

    protected function casts(): array
    {
        return ['sent_at' => 'datetime'];
    }

    /** Destination with the middle hidden, for screens that list many recipients. */
    public function maskedDestination(): string
    {
        $d = $this->destination;
        if (str_contains($d, '@')) {
            [$local, $domain] = explode('@', $d, 2);

            return mb_substr($local, 0, 1).str_repeat('•', max(2, mb_strlen($local) - 1)).'@'.$domain;
        }

        return mb_strlen($d) > 4 ? str_repeat('•', mb_strlen($d) - 3).mb_substr($d, -3) : $d;
    }
}
