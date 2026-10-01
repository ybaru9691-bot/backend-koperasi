<?php

require 'vendor/autoload.php';
$app = require_once 'bootstrap/app.php';
$kernel = $app->make('Illuminate\Contracts\Console\Kernel');
$kernel->bootstrap();

$trxs = App\Models\Transaction::where('book_type', 'BUKU_PUTIH')
    ->orWhere('receipt_number', 'like', '%IMP%')
    ->get(['id', 'member_id', 'transaction_number', 'receipt_number', 'book_type', 'type', 'amount', 'beginning_balance', 'ending_balance', 'transaction_date', 'description']);

foreach ($trxs as $t) {
    echo sprintf(
        "ID: %d | Member: %d | Date: %s | Receipt: %-15s | Type: %-10s | Amount: %10s | Desc: %s\n",
        $t->id,
        $t->member_id,
        $t->transaction_date,
        $t->receipt_number,
        $t->type,
        number_format($t->amount, 0, ',', '.'),
        $t->description
    );
}
