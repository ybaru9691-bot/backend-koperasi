<?php

require 'vendor/autoload.php';
$app = require_once 'bootstrap/app.php';
$kernel = $app->make('Illuminate\Contracts\Console\Kernel');
$kernel->bootstrap();

$svc = app(\App\Services\BukuPutihLedgerService::class);
$pdf = $svc->exportPdf(1);

echo "PDF export successfully generated! Class: " . get_class($pdf) . "\n";
