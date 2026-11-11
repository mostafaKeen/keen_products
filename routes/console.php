<?php

use App\Models\Subscription;
use App\Models\SyncLog;
use App\Services\Subscriptions\SubscriptionGate;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Artisan::command('subscriptions:reconcile', function (SubscriptionGate $gate) {
    $count = 0;
    Subscription::whereIn('status', [
        Subscription::STATUS_TRIALING,
        Subscription::STATUS_ACTIVE,
        Subscription::STATUS_GRACE_PERIOD,
    ])->chunkById(100, function ($subs) use ($gate, &$count) {
        foreach ($subs as $sub) {
            $before = $sub->status;
            $after = $gate->syncExpirationState($sub)->status;
            if ($before !== $after) {
                $count++;
            }
        }
    });

    $this->info("Reconciled {$count} subscription state transition(s).");
})->purpose('Transition expired trials and billing periods into grace_period/expired');

Schedule::command('subscriptions:reconcile')->hourly();
Schedule::call(fn () => SyncLog::where('created_at', '<', now()->subDays(90))->delete())->daily();
