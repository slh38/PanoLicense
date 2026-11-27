<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Yalnız kısa ömürlü teknik challenge kayıtları; ticari kayıtlar silinmez.
\Illuminate\Support\Facades\Schedule::call(fn () =>
    \Illuminate\Support\Facades\DB::table('device_challenges')->where('expires_at', '<', now()->subDay())->delete()
)->daily();