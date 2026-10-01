<?php

namespace App\Console\Commands;

use App\Models\JournalEntry;
use App\Models\Transaction;
use App\Services\JournalService;
use Illuminate\Console\Command;

class FixKm1111JournalCommand extends Command
{
    /**
     * Nama dan Tanda Tangan Command
     *
     * @var string
     */
    protected $signature = 'journal:fix-km1111 {receipt=1111 : Nomor Bukti Transaksi}';

    /**
     * Deskripsi Command
     *
     * @var string
     */
    protected $description = 'Perbaiki dan regenerasi jurnal akuntansi untuk transaksi KM-1111 agar COA 4183, 4180, 2038, 2032 akurat.';

    public function handle(): int
    {
        $receipt = $this->argument('receipt');
        $this->info("Pembersihan & Regenerasi Jurnal Bukti '{$receipt}'...");

        $transactions = Transaction::where('receipt_number', 'like', "%{$receipt}%")
            ->orWhere('transaction_number', 'like', "%{$receipt}%")
            ->get();

        if ($transactions->isEmpty()) {
            $this->warn("Tidak ditemukan transaksi dengan nomor bukti '{$receipt}'.");
            return Command::SUCCESS;
        }

        $journalService = app(JournalService::class);
        $updatedCount = 0;

        foreach ($transactions as $t) {
            $descLower = strtolower($t->description ?? '');
            $explicitCode = null;

            if (str_contains($descLower, 'finalty') || str_contains($descLower, 'penalti')) {
                $explicitCode = '4183';
            } elseif (str_contains($descLower, 'denda')) {
                $explicitCode = '4182';
            } elseif (str_contains($descLower, 'provisi')) {
                $explicitCode = '4170';
            } elseif (str_contains($descLower, 'uang pangkal') || str_contains($descLower, 'pendaftaran')) {
                $explicitCode = '4191';
            } elseif (trim($descLower) === 'dana' || str_contains($descLower, 'dana duka')) {
                $explicitCode = '2038';
            } elseif (str_contains($descLower, 'asuransi')) {
                $explicitCode = '2032';
            } elseif (str_contains($descLower, 'jasa piutang') || str_contains($descLower, 'jasa pinjaman')) {
                $explicitCode = '4180';
            } elseif (str_contains($descLower, 'angsuran piutang') || str_contains($descLower, 'angsuran pokok')) {
                $explicitCode = '1024';
            } elseif (str_contains($descLower, 'diakonia')) {
                $explicitCode = '2022';
            } elseif (str_contains($descLower, 'bank') && !str_contains($descLower, 'jasa bank')) {
                $explicitCode = '1010';
            } elseif (str_contains($descLower, 'pendapatan lain')) {
                $explicitCode = '4192';
            }

            $journal = $journalService->updateJournal($t, $explicitCode);
            if ($journal) {
                $updatedCount++;
                $this->line("<info>[FIXED]</info> Trx #{$t->id} ('{$t->description}') -> Journal {$journal->voucher_number}");
                foreach ($journal->details as $d) {
                    $d->loadMissing('account');
                    $this->line("   - COA {$d->account->account_code} ({$d->account->account_name}) | Debit: {$d->debit} | Credit: {$d->credit}");
                }
            }
        }

        $this->info("Proses regenerasi selesai! Total {$updatedCount} jurnal transaksi berhasil diperbarui.");
        return Command::SUCCESS;
    }
}
