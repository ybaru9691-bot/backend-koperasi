<?php

require 'vendor/autoload.php';
$app = require_once 'bootstrap/app.php';
$kernel = $app->make('Illuminate\Contracts\Console\Kernel');
$kernel->bootstrap();

$impTrxs = App\Models\Transaction::where('receipt_number', 'like', 'KM-IMP-%')
    ->orWhere('receipt_number', 'like', 'KM-IMP-P-%')
    ->orWhere('transaction_number', 'like', 'TRX-IMP-%')
    ->get();

echo "Total Import Trxs: " . $impTrxs->count() . "\n";
foreach ($impTrxs->take(10) as $t) {
    echo sprintf(
        "ID: %d | Member: %d | Date: %s | Receipt: %-25s | Number: %-25s | Book: %-12s | Type: %-10s | Amount: %10s\n",
        $t->id,
        $t->member_id,
        $t->transaction_date,
        $t->receipt_number,
        $t->transaction_number,
        $t->book_type,
        $t->type,
        number_format($t->amount, 0, ',', '.')
    );
}

$putihImps = App\Models\Transaction::where('book_type', 'BUKU_PUTIH')
    ->where(function ($q) {
        $q->where('receipt_number', 'like', 'KM-IMP%')
          ->orWhere('transaction_number', 'like', 'TRX-IMP%')
          ->orWhere('description', 'like', '%Saldo Awal%')
          ->orWhere('description', 'like', '%saldo awal%');
    })
    ->get();

echo "\nTotal Buku Putih Import Trxs: " . $putihImps->count() . "\n";
foreach ($putihImps->take(10) as $t) {
    echo sprintf(
        "ID: %d | Member: %d | Date: %s | Receipt: %-25s | Number: %-25s | Amount: %10s | Desc: %s\n",
        $t->id,
        $t->member_id,
        $t->transaction_date,
        $t->receipt_number,
        $t->transaction_number,
        number_format($t->amount, 0, ',', '.'),
        $t->description
    );
}
