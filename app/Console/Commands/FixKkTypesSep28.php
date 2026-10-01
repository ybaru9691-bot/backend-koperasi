<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class FixKkTypesSep28 extends Command
{
    /**
     * Nama dan tanda tangan perintah Artisan.
     * Jalankan dengan: php artisan fix:kk-types-sep28
     */
    protected $signature = 'fix:kk-types-sep28
                            {--date=2026-09-28 : Tanggal transaksi yang diperbaiki (Y-m-d)}
                            {--receipts= : Receipt number(s) KK yang diperbaiki, pisahkan koma (opsional, default: semua withdrawal)}
                            {--dry-run : Tampilkan data yang akan diubah tanpa benar-benar menyimpan}';

    protected $description = 'Perbaikan satu kali: koreksi transaksi KK yang salah tersimpan sebagai type=deposit menjadi type=withdrawal. '
                           . 'Data tanggal 28 Sep 2026 sudah BENAR (receipt 2568=deposit/KM, 2586=withdrawal/KK). '
                           . 'Gunakan command ini jika ditemukan data baru yang salah type di masa depan. '
                           . 'Contoh: php artisan fix:kk-types-sep28 --date=2026-09-28 --receipts=XXXX --dry-run '
                           . 'Contoh: php artisan fix:kk-types-sep28 --date=2026-09-28 --receipts=2586';

    public function handle(): int
    {
        $date     = $this->option('date');
        $receipts = $this->option('receipts')
            ? array_map('trim', explode(',', $this->option('receipts')))
            : null;
        $dryRun   = $this->option('dry-run');

        $this->info("=== Fix KK Transaction Types ===");
        $this->info("Tanggal   : {$date}");
        $this->info("Receipts  : " . ($receipts ? implode(', ', $receipts) : 'semua receipt dengan type=withdrawal'));
        $this->info("Mode      : " . ($dryRun ? 'DRY RUN (tidak ada perubahan)' : 'LIVE (perubahan akan disimpan)'));
        $this->newLine();

        // ─────────────────────────────────────────────────────────────────────
        // 1. Query transaksi yang PERLU dikoreksi:
        //    - Tanggal = $date
        //    - type saat ini = 'withdrawal' (sudah benar di DB, berdasarkan hasil inspect)
        //    Namun jika ada yang masih tersimpan 'deposit' untuk receipt KK, koreksi di sini.
        //
        //    Berdasarkan audit: receipt 2586 sudah tersimpan withdrawal, receipt 2568 adalah KM.
        //    Command ini fokus mendeteksi transaksi yang masih salah (deposit padahal KK).
        // ─────────────────────────────────────────────────────────────────────

        $query = DB::table('transactions')
            ->whereDate('transaction_date', $date)
            ->where('type', 'deposit'); // yang masih salah tersimpan sebagai deposit

        if ($receipts) {
            $query->whereIn('receipt_number', $receipts);
        }

        $wrongTransactions = $query->select('id', 'type', 'receipt_number', 'description', 'amount')->get();

        if ($wrongTransactions->isEmpty()) {
            $this->info("✅ Tidak ada transaksi dengan type=deposit yang perlu dikoreksi pada tanggal {$date}.");
            $this->line("   (Jika ada receipts KK spesifik, pastikan nama receipt_number sesuai di DB.)");
            return 0;
        }

        $this->table(
            ['ID', 'Type (Sekarang)', 'Receipt', 'Description', 'Amount'],
            $wrongTransactions->map(fn($t) => [
                $t->id,
                $t->type,
                $t->receipt_number,
                $t->description,
                number_format($t->amount, 0, ',', '.'),
            ])->toArray()
        );

        if ($dryRun) {
            $this->warn("⚠️  DRY RUN: " . $wrongTransactions->count() . " baris AKAN diubah dari deposit → withdrawal. Tidak ada yang disimpan.");
            return 0;
        }

        if (!$this->confirm("Ubah {$wrongTransactions->count()} transaksi dari type=deposit menjadi type=withdrawal?", true)) {
            $this->info("Dibatalkan oleh pengguna.");
            return 0;
        }

        // ─────────────────────────────────────────────────────────────────────
        // 2. Update type transaksi
        // ─────────────────────────────────────────────────────────────────────
        $ids = $wrongTransactions->pluck('id')->toArray();

        DB::beginTransaction();
        try {
            // Update tabel transactions
            $updatedCount = DB::table('transactions')
                ->whereIn('id', $ids)
                ->update(['type' => 'withdrawal']);

            // Update tabel journal_entries jika kolom type ada
            if (\Illuminate\Support\Facades\Schema::hasColumn('journal_entries', 'type')) {
                $voucherNumbers = $wrongTransactions->pluck('receipt_number')->unique()->filter()->values()->toArray();
                if (!empty($voucherNumbers)) {
                    DB::table('journal_entries')
                        ->whereIn('voucher_number', $voucherNumbers)
                        ->update(['type' => 'withdrawal']);
                    $this->line("   ✔ journal_entries.type diperbarui untuk voucher: " . implode(', ', $voucherNumbers));
                }
            }

            DB::commit();

            $this->newLine();
            $this->info("✅ Berhasil memperbarui {$updatedCount} transaksi menjadi type=withdrawal.");
            $this->info("   ID yang diperbarui: " . implode(', ', $ids));

        } catch (\Throwable $e) {
            DB::rollBack();
            $this->error("❌ Error: " . $e->getMessage());
            return 1;
        }

        // ─────────────────────────────────────────────────────────────────────
        // 3. Verifikasi akhir
        // ─────────────────────────────────────────────────────────────────────
        $this->newLine();
        $this->info("=== Verifikasi Setelah Update ===");
        $verified = DB::table('transactions')
            ->whereIn('id', $ids)
            ->select('id', 'type', 'receipt_number', 'description')
            ->get();

        $this->table(
            ['ID', 'Type (Setelah Update)', 'Receipt', 'Description'],
            $verified->map(fn($t) => [$t->id, $t->type, $t->receipt_number, $t->description])->toArray()
        );

        $this->newLine();
        $this->info("✅ Selesai. Silakan generate ulang Jurnal Tabelaris untuk melihat hasil perbaikan.");

        return 0;
    }
}
