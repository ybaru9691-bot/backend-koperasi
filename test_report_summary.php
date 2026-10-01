<?php
require 'vendor/autoload.php';
$app = require_once 'bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

echo "--- Testing GET /api/manager/reports/summary ---\n";
$res = app(\App\Http\Controllers\Api\KoperasiController::class)->getFinancialSummary(request());
echo json_encode($res->getData(), JSON_PRETTY_PRINT) . "\n";
