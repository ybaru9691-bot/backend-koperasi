<?php

require 'vendor/autoload.php';
$app = require_once 'bootstrap/app.php';
$kernel = $app->make('Illuminate\Contracts\Console\Kernel');
$kernel->bootstrap();

$m = App\Models\Member::find(1);
echo "Member 1: " . $m->name . " | Daily Savings: " . $m->daily_savings . "\n";

$trxs = App\Models\Transaction::where('member_id', 1)->get();
foreach ($trxs as $t) {
    echo sprintf(
        "ID: %d | Date: %s | Receipt: %-25s | Number: %-25s | Book: %-12s | Type: %-10s | Cat: %-15s | Amount: %10s | Beg: %10s | End: %10s | Desc: %s\n",
        $t->id,
        $t->transaction_date,
        $t->receipt_number,
        $t->transaction_number,
        $t->book_type,
        $t->type,
        $t->category ?? '-',
        number_format($t->amount, 0, ',', '.'),
        number_format($t->beginning_balance, 0, ',', '.'),
        number_format($t->ending_balance, 0, ',', '.'),
        $t->description
    );
}
