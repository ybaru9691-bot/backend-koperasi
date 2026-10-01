<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Scheduler Bunga Simpanan Buku Putih (0.6%) setiap tanggal 20 pukul 01:00
Schedule::command('members:calculate-interest')->monthlyOn(20, '01:00');

