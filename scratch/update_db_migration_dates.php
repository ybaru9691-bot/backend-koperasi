<?php

require 'vendor/autoload.php';
$app = require_once 'bootstrap/app.php';
$kernel = $app->make('Illuminate\Contracts\Console\Kernel');
$kernel->bootstrap();

$count = App\Models\Transaction::where('book_type', 'BUKU_PUTIH')
    ->where(function ($q) {
        $q->where('receipt_number', 'like', 'KM-IMP%')
          ->orWhere('transaction_number', 'like', 'TRX-IMP%')
          ->orWhere('description', 'like', '%Saldo Awal%')
          ->orWhere('description', 'like', '%saldo awal%');
    })
    ->update(['transaction_date' => '2026-08-20']);

echo "Successfully updated {$count} Buku Putih migration transactions to transaction_date = 2026-08-20.\n";

$svc = app(\App\Services\BukuPutihLedgerService::class);
$result = $svc->getMemberBukuPutihLedger(1);

echo "\nVerification Member 1 (Basalina Hutauruk):\n";
echo "Opening Balance: " . number_format($result['opening_balance'], 0, ',', '.') . "\n";
echo "Total Setoran:   " . number_format($result['total_deposit'], 0, ',', '.') . "\n";
echo "Total Jasa:      " . number_format($result['total_interest'], 0, ',', '.') . "\n";
echo "Closing Balance: " . number_format($result['closing_balance'], 0, ',', '.') . "\n";
echo "Status:          " . $result['status_keaktifan'] . "\n\n";

foreach ($result['cycles'] as $c) {
    if (in_array($c['month'], [8, 9, 10])) {
        echo sprintf(
            "Cycle: %-15s | Awal: %10s | Setoran: %10s | Jasa: %10s | Akhir: %10s\n",
            $c['month_name'],
            number_format($c['opening_balance'], 0, ',', '.'),
            number_format($c['total_deposit'], 0, ',', '.'),
            number_format($c['interest'], 0, ',', '.'),
            number_format($c['closing_balance'], 0, ',', '.')
        );
        foreach ($c['rows'] as $r) {
            echo sprintf(
                "   -> %s | %s | %s | Dep: %s | Int: %s | Bal: %s\n",
                $r['date'],
                $r['evidence_no'],
                $r['type'],
                number_format($r['deposit'], 0, ',', '.'),
                number_format($r['interest'], 0, ',', '.'),
                number_format($r['balance'], 0, ',', '.')
            );
        }
    }
}
