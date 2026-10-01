<?php

require 'vendor/autoload.php';
$app = require_once 'bootstrap/app.php';
$kernel = $app->make('Illuminate\Contracts\Console\Kernel');
$kernel->bootstrap();

// Update member 1's KM-IMP to 2026-08-20
App\Models\Transaction::where('member_id', 1)
    ->where('book_type', 'BUKU_PUTIH')
    ->where(function ($q) {
        $q->where('receipt_number', 'like', 'KM-IMP%')
          ->orWhere('transaction_number', 'like', 'TRX-IMP%')
          ->orWhere('description', 'like', '%Saldo Awal%');
    })
    ->update(['transaction_date' => '2026-08-20']);

$svc = app(\App\Services\BukuPutihLedgerService::class);
$result = $svc->getMemberBukuPutihLedger(1);

echo "=== SUMMARY ===\n";
print_r($result['summary']);

echo "\n=== CYCLES ===\n";
foreach ($result['cycles'] as $c) {
    echo sprintf(
        "Bulan: %-15s | Awal: %10s | Setoran: %10s | Penarikan: %10s | Jasa: %10s | Akhir: %10s | Rows: %d\n",
        $c['month_name'],
        number_format($c['opening_balance'], 0, ',', '.'),
        number_format($c['total_deposit'], 0, ',', '.'),
        number_format($c['total_withdrawal'], 0, ',', '.'),
        number_format($c['interest'], 0, ',', '.'),
        number_format($c['closing_balance'], 0, ',', '.'),
        count($c['rows'])
    );
    foreach ($c['rows'] as $r) {
        echo sprintf(
            "   -> Date: %s | Voucher: %-25s | Type: %-3s | Dep: %8s | Wit: %8s | Int: %8s | Bal: %10s | Desc: %s\n",
            $r['date'],
            $r['evidence_no'],
            $r['type'],
            number_format($r['deposit'], 0, ',', '.'),
            number_format($r['withdrawal'], 0, ',', '.'),
            number_format($r['interest'], 0, ',', '.'),
            number_format($r['balance'], 0, ',', '.'),
            $r['description']
        );
    }
}
