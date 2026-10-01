<?php
require 'vendor/autoload.php';
$app = require_once 'bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

$transactions = \App\Models\Transaction::all();
$service = app(\App\Services\JournalService::class);
$count = 0;
$skipped = 0;
$errors = 0;

foreach ($transactions as $t) {
    $existing = \App\Models\JournalEntry::where('transaction_id', $t->id)->first();
    if ($existing) {
        $skipped++;
        continue;
    }
    
    try {
        $service->generateJournal($t);
        $count++;
    } catch (\Exception $e) {
        echo "Error on TRX " . $t->id . ": " . $e->getMessage() . "\n";
        $errors++;
    }
}

echo "Retroactive Sync Complete!\n";
echo "Successfully generated journals for $count transactions.\n";
echo "Skipped (already have journal): $skipped\n";
echo "Errors (mapping failed): $errors\n";
