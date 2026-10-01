<?php
require __DIR__ . '/vendor/autoload.php';
$app = require_once __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use App\Models\Transaction;
use App\Models\JournalEntry;

$trxs = Transaction::where('receipt_number', 'like', '%1245%')
    ->orWhere('transaction_number', 'like', '%1245%')
    ->get();

echo "Found " . $trxs->count() . " transactions for 1245:\n";
foreach ($trxs as $t) {
    echo "Trx ID: {$t->id} | Voucher: {$t->receipt_number} | TrxNo: {$t->transaction_number} | Desc: {$t->description} | Amount: {$t->amount} | PaymentMethod: {$t->payment_method}\n";
    $j = JournalEntry::where('transaction_id', $t->id)->first();
    if ($j) {
        echo "  Journal ID: {$j->id} | Voucher: {$j->voucher_number}\n";
        foreach ($j->details as $d) {
            $d->loadMissing('account');
            echo "    Detail ID: {$d->id} | COA Code: " . ($d->account->account_code ?? 'NULL') . " (" . ($d->account->account_name ?? 'N/A') . ") | Debit: {$d->debit} | Credit: {$d->credit}\n";
        }
    } else {
        echo "  No Journal Entry found!\n";
    }
}
