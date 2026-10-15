<?php

return [
    'name' => env('SALON_NAME', 'Salon Management System'),
    'currency' => env('SALON_CURRENCY', 'USD'),
    'currency_symbol' => env('SALON_CURRENCY_SYMBOL', '$'),
    'date_format' => env('SALON_DATE_FORMAT', 'Y-m-d'),
    'time_format' => env('SALON_TIME_FORMAT', 'H:i'),

    'password_min_length' => 10,
    'login_max_attempts' => 5,
    'login_decay_seconds' => 60,
];
