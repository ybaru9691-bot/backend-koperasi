<?php
require __DIR__ . '/vendor/autoload.php';
$app = require_once __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use App\Models\ChartOfAccount;
use Illuminate\Support\Facades\DB;

$coa = ChartOfAccount::where('account_code', '1024')->first();
if ($coa) {
    echo "COA 1024 Found:\n";
    echo " - ID: {$coa->id}\n";
    echo " - Code: {$coa->account_code}\n";
    echo " - Name: {$coa->account_name}\n";
    echo " - Account Type: {$coa->account_type}\n";
    echo " - Normal Balance: {$coa->normal_balance}\n";
} else {
    echo "COA 1024 not found!\n";
}

// Check initial balance / journal details for 1024 in database
$awalData = DB::table('journal_details')
    ->join('journal_entries', 'journal_details.journal_entry_id', '=', 'journal_entries.id')
    ->where('journal_details.account_id', $coa->id)
    ->selectRaw('COALESCE(SUM(journal_details.debit), 0) as total_debit, COALESCE(SUM(journal_details.credit), 0) as total_credit')
    ->first();

echo "All time totals for 1024 in DB:\n";
echo " - Total Debit: " . number_format($awalData->total_debit, 2) . "\n";
echo " - Total Credit: " . number_format($awalData->total_credit, 2) . "\n";
echo " - Net (Debit - Credit): " . number_format($awalData->total_debit - $awalData->total_credit, 2) . "\n";
echo " - Net (Credit - Debit): " . number_format($awalData->total_credit - $awalData->total_debit, 2) . "\n";
