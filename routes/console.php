<?php

use App\Services\AutomationService;
use App\Services\IntegrityChecker;
use App\Services\MembershipService;
use App\Services\NotificationService;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('salon:appointment-reminders', function (AutomationService $automation) {
    $this->info('Reminder messages queued: '.$automation->appointmentReminders());
})->purpose('Queue reminders for appointments whose reminder time has arrived');

Artisan::command('salon:low-stock-alerts', function (AutomationService $automation) {
    $this->info('Users notified: '.$automation->lowStockAlerts());
})->purpose('Tell inventory managers which products are at or below their reorder level');

Artisan::command('salon:payment-reminders', function (AutomationService $automation) {
    $this->info('Payment reminders queued: '.$automation->paymentReminders());
})->purpose('Remind customers about overdue invoice balances (needs the payment_reminder event enabled)');

Artisan::command('salon:birthday-greetings', function (AutomationService $automation) {
    $this->info('Greetings queued: '.$automation->birthdayGreetings());
})->purpose('Send birthday greetings to customers who agreed to marketing (needs the birthday event enabled)');

Artisan::command('salon:expire-memberships', function (MembershipService $memberships) {
    $this->info('Memberships expired: '.$memberships->expireOverdue());
})->purpose('Mark memberships past their end date as expired');

Artisan::command('salon:integrity-check {--json : Print the result as JSON} {--notify : Tell administrators in-app when problems are found}', function (IntegrityChecker $checker, NotificationService $notifications) {
    $findings = $checker->run();
    if ($this->option('json')) {
        $this->line(json_encode(['ok' => $findings === [], 'checks' => array_keys($checker->catalogue()), 'findings' => $findings], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    } elseif ($findings === []) {
        $this->info('Integrity check passed: '.count($checker->catalogue()).' checks, no inconsistencies found.');
    } else {
        foreach ($findings as $finding) {
            $this->error("{$finding['title']}: {$finding['count']} problem(s)");
            foreach ($finding['samples'] as $sample) {
                $this->line("  - {$sample}");
            }
        }
    }
    if ($findings !== [] && $this->option('notify')) {
        $total = array_sum(array_column($findings, 'count'));
        $notifications->toPermissionHolders('settings.manage', 'integrity', 'Data integrity check found problems', "{$total} inconsistencies in ".count($findings).' check(s). Run php artisan salon:integrity-check for details.', null, 'integrity:'.today()->toDateString());
    }

    return $findings === [] ? 0 : 1;
})->purpose('Verify stock, loyalty, invoice, payment, coupon, drawer, booking and expense records are internally consistent');

// Run "php artisan schedule:run" every minute (cron or Windows Task Scheduler). Times are salon time.
Schedule::command('salon:appointment-reminders')->everyTenMinutes()->withoutOverlapping(10);
Schedule::command('salon:low-stock-alerts')->dailyAt('08:00')->withoutOverlapping();
Schedule::command('salon:birthday-greetings')->dailyAt('08:30')->withoutOverlapping();
Schedule::command('salon:payment-reminders')->dailyAt('09:00')->withoutOverlapping();
Schedule::command('salon:expire-memberships')->dailyAt('00:15')->withoutOverlapping();
Schedule::command('salon:integrity-check --notify')->weeklyOn(1, '03:00')->withoutOverlapping();
