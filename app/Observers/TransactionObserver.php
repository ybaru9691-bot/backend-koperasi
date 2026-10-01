<?php

namespace App\Observers;

use App\Models\Transaction;
use App\Services\JournalService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class TransactionObserver
{
    /**
     * Listener: setiap kali transaksi di-update (termasuk saat di-approve).
     * Jika status berubah menjadi 'approved', otomatis buat jurnal.
     */
    public function updated(Transaction $transaction): void
    {
        $this->invalidateCache($transaction);

        // Transaksi bunga simpanan / memorial / KK / KM / pinjaman tidak posting otomatis generic ke jurnal
        if ($transaction->category === 'bunga_simpanan' 
            || $transaction->payment_method === 'memorial'
            || in_array($transaction->type, ['KK', 'KM'])
            || in_array($transaction->category, ['beban_operasional', 'pendapatan_lain', 'angsuran_pinjaman'])
            || str_contains(strtolower($transaction->description ?? ''), 'angsuran pinjaman')
            || str_contains(strtolower($transaction->description ?? ''), 'pencairan pinjaman')) {
            return;
        }

        // Hanya proses jika status berubah menjadi 'approved'
        if ($transaction->isDirty('status') && strtolower($transaction->status) === 'approved') {
            $this->createJournal($transaction);
        }
    }

    /**
     * Listener: jika transaksi langsung dibuat dengan status 'approved'.
     */
    public function created(Transaction $transaction): void
    {
        $this->invalidateCache($transaction);

        // Transaksi bunga simpanan / memorial / KK / KM / pinjaman tidak posting otomatis generic ke jurnal
        if ($transaction->category === 'bunga_simpanan' 
            || $transaction->payment_method === 'memorial'
            || in_array($transaction->type, ['KK', 'KM'])
            || in_array($transaction->category, ['beban_operasional', 'pendapatan_lain', 'angsuran_pinjaman'])
            || str_contains(strtolower($transaction->description ?? ''), 'angsuran pinjaman')
            || str_contains(strtolower($transaction->description ?? ''), 'pencairan pinjaman')) {
            return;
        }

        if (strtolower($transaction->status ?? '') === 'approved') {
            $this->createJournal($transaction);
        }
    }

    /**
     * Listener: setiap kali transaksi disimpan (created / updated).
     */
    public function saved(Transaction $transaction): void
    {
        $this->invalidateCache($transaction);
    }

    /**
     * Listener: jika transaksi dihapus.
     */
    public function deleted(Transaction $transaction): void
    {
        $this->invalidateCache($transaction);
    }

    /**
     * Invalidate caches related to dashboard summaries.
     */
    private function invalidateCache(Transaction $transaction): void
    {
        Cache::forget('dashboard_summary_data');
        Cache::forget('manager_dashboard_summary_data');

        $periodKey = $transaction->transaction_date 
            ? \Carbon\Carbon::parse($transaction->transaction_date)->format('Y_m') 
            : now()->format('Y_m');

        Cache::forget("dashboard_summary_{$periodKey}");
    }

    /**
     * Buat jurnal otomatis via JournalService.
     */
    private function createJournal(Transaction $transaction): void
    {
        try {
            $service = app(JournalService::class);
            $journal = $service->generateJournal($transaction);

            if ($journal) {
                Log::info("[TransactionObserver] Auto-journal #{$journal->voucher_number} dibuat untuk transaksi #{$transaction->id}");
            }
        } catch (\Exception $e) {
            // Log error tapi jangan gagalkan transaksi utama
            Log::error("[TransactionObserver] Gagal buat jurnal untuk transaksi #{$transaction->id}: " . $e->getMessage());
        }
    }
}
