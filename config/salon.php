<?php

return [
    'name' => env('SALON_NAME', 'Salon Management System'),
    'currency' => env('SALON_CURRENCY', 'USD'),
    'currency_symbol' => env('SALON_CURRENCY_SYMBOL', '$'),
    'date_format' => env('SALON_DATE_FORMAT', 'Y-m-d'),
    'time_format' => env('SALON_TIME_FORMAT', 'H:i'),

    // Defaults for settings editable under Settings > Booking & hours (stored in salon_settings).
    'booking' => [
        // weekday (0 = Sunday ... 6 = Saturday) => [open, close, closed]
        'business_hours' => [
            0 => ['open' => '09:00', 'close' => '18:00', 'closed' => false],
            1 => ['open' => '09:00', 'close' => '18:00', 'closed' => false],
            2 => ['open' => '09:00', 'close' => '18:00', 'closed' => false],
            3 => ['open' => '09:00', 'close' => '18:00', 'closed' => false],
            4 => ['open' => '09:00', 'close' => '18:00', 'closed' => false],
            5 => ['open' => '09:00', 'close' => '18:00', 'closed' => true],
            6 => ['open' => '09:00', 'close' => '18:00', 'closed' => false],
        ],
        'buffer_minutes' => 0,
        'slot_minutes' => 15,
        'max_advance_days' => 90,
        'allow_stylist_overlap' => false,
        'cancellation_notice_hours' => 24,
        'late_cancellation_action' => 'flag', // flag | block
        'reminder_hours_before' => 24,
    ],

    'password_min_length' => 10,
    'login_max_attempts' => 5,
    'login_decay_seconds' => 60,
];
