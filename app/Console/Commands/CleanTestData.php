<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class CleanTestData extends Command
{
    /**
     * Nama command artisan.
     *
     * @var string
     */
    protected $signature = 'system:clean-test-data
                            {--force : Langsung jalankan tanpa konfirmasi}';

    /**
     * Deskripsi command.
     *
     * @var string
     */
    protected $description = 'Membersihkan seluruh data testing/dummy (transaksi, pinjaman, jurnal, anggota) tanpa menghapus master COA dan Users.';

    /**
     * Tabel yang akan dibersihkan secara berurutan (urutan penting untuk FK constraint).
     */
    private array $tablesToTruncate = [
        'loan_installments',
        'loan_approvals',
        'loan_applications',
        'loans',
        'journal_details',
        'journal_items',
        'journal_entries',
        'cash_flows',
        'transactions',
        'member_shu_distributions',
        'shu_distributions',
        'activity_logs',
        'audit_logs',
        'notifications',
        'members',
    ];

    public function handle(): int
    {
        $this->newLine();
        $this->info('╔══════════════════════════════════════════════════╗');
        $this->info('║    PEMBERSIHAN DATA TESTING — CUM PELITA Backend ║');
        $this->info('╚══════════════════════════════════════════════════╝');
        $this->newLine();

        $this->warn('⚠️  Operasi ini akan MENGHAPUS PERMANEN data berikut:');
        $this->line('   • Seluruh data anggota (members)');
        $this->line('   • Seluruh data pinjaman & angsuran');
        $this->line('   • Seluruh transaksi & jurnal keuangan');
        $this->line('   • Seluruh distribusi SHU');
        $this->line('   • Seluruh notifikasi & log aktivitas');
        $this->newLine();
        $this->info('✅  Yang DIPERTAHANKAN:');
        $this->line('   • Users & Roles');
        $this->line('   • Chart of Accounts (master COA)');
        $this->line('   • Periode Akuntansi');
        $this->newLine();

        if (!$this->option('force') && !$this->confirm('Lanjutkan pembersihan data?', false)) {
            $this->warn('Pembersihan dibatalkan.');
            return Command::FAILURE;
        }

        try {
            $this->line('Menonaktifkan FK Constraint...');
            DB::statement('SET FOREIGN_KEY_CHECKS=0;');

            foreach ($this->tablesToTruncate as $table) {
                if (Schema::hasTable($table)) {
                    DB::table($table)->truncate();
                    $this->line("  ✓ TRUNCATE {$table}");
                } else {
                    $this->line("  - SKIP {$table} (tabel tidak ditemukan)");
                }
            }

            // Reset saldo Kas menjadi 0
            $this->line('Mereset saldo akun Kas...');
            $kasRowsReset = DB::table('accounts')
                ->where('account_type', 'kas')
                ->orWhere('account_type', 'bank')
                ->update(['balance' => 0.00]);
            $this->line("  ✓ Reset saldo {$kasRowsReset} akun Kas/Bank ke Rp 0");

            DB::statement('SET FOREIGN_KEY_CHECKS=1;');

            $this->newLine();
            $this->info('✅  Pembersihan data testing berhasil!');
            $this->info('   Master COA dan Users tetap aman.');
            $this->newLine();

            return Command::SUCCESS;

        } catch (\Exception $e) {
            DB::statement('SET FOREIGN_KEY_CHECKS=1;');
            $this->error('❌ Gagal membersihkan data: ' . $e->getMessage());
            return Command::FAILURE;
        }
    }
}

