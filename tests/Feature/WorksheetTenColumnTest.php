<?php

namespace Tests\Feature;

use App\Models\ChartOfAccount;
use App\Models\InitialAccountBalance;
use App\Models\JournalDetail;
use App\Models\JournalEntry;
use App\Models\User;
use App\Services\WorksheetReportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class WorksheetTenColumnTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(\Database\Seeders\ChartOfAccountSeeder::class);
    }

    public function test_worksheet_10_column_with_cutoff_saldo_awal(): void
    {
        $manager = User::create([
            'name'     => 'Manager Akuntansi',
            'email'    => 'manager@koperasi.com',
            'password' => bcrypt('password123'),
            'role'     => 'manager',
            'nik'      => '1234567890123456',
        ]);

        Sanctum::actingAs($manager);

        // 1. Setup Saldo Awal Cut-Off per 01 Mei 2026 di initial_account_balances
        // Aset (Debet): Kas (1000) = 5.000.000, Piutang (1024) = 15.000.000 (Total Debet: 20.000.000)
        // Kewajiban & Ekuitas (Kredit): Simpanan Saham (2020) = 10.000.000, Cadangan Modal (3020) = 10.000.000 (Total Kredit: 20.000.000)
        InitialAccountBalance::create([
            'cutoff_date'  => '2026-05-01',
            'account_code' => '1000',
            'debit'        => 5000000.00,
            'credit'       => 0.00,
        ]);
        InitialAccountBalance::create([
            'cutoff_date'  => '2026-05-01',
            'account_code' => '1024',
            'debit'        => 15000000.00,
            'credit'       => 0.00,
        ]);
        InitialAccountBalance::create([
            'cutoff_date'  => '2026-05-01',
            'account_code' => '2020',
            'debit'        => 0.00,
            'credit'       => 10000000.00,
        ]);
        InitialAccountBalance::create([
            'cutoff_date'  => '2026-05-01',
            'account_code' => '3020',
            'debit'        => 0.00,
            'credit'       => 10000000.00,
        ]);

        $coaKas     = ChartOfAccount::where('account_code', '1000')->first();
        $coaPiutang = ChartOfAccount::where('account_code', '1024')->first();
        $coaSaham   = ChartOfAccount::where('account_code', '2020')->first();
        $coaJasa    = ChartOfAccount::where('account_code', '4180')->first();
        $coaBeban   = ChartOfAccount::where('account_code', '7100')->first();

        // 2. Mutasi antara Cut-Off (2026-05-01) dan Sebelum StartDate (misal di bulan Juni 2026)
        // Angsuran Piutang masuk kas Rp 2.000.000 pada 2026-06-15 (Kas Debet 2jt, Piutang Kredit 2jt)
        $jPrior = JournalEntry::create([
            'entry_date'     => '2026-06-15',
            'voucher_number' => 'JV-20260615-0001',
            'description'    => 'Angsuran Piutang Juni',
        ]);
        JournalDetail::create([
            'journal_entry_id' => $jPrior->id,
            'account_id'       => $coaKas->id,
            'debit'            => 2000000.00,
            'credit'           => 0.00,
        ]);
        JournalDetail::create([
            'journal_entry_id' => $jPrior->id,
            'account_id'       => $coaPiutang->id,
            'debit'            => 0.00,
            'credit'           => 2000000.00,
        ]);

        // 3. Mutasi Periode Laporan (Juli 2026: 2026-07-01 s/d 2026-07-31)
        // a. Pendapatan Jasa Pinjaman Kas Masuk Rp 1.000.000 (Kas Debet, Jasa Pinjaman Kredit)
        $jRev = JournalEntry::create([
            'entry_date'     => '2026-07-10',
            'voucher_number' => 'JV-20260710-0001',
            'description'    => 'Pendapatan Jasa Pinjaman Juli',
        ]);
        JournalDetail::create([
            'journal_entry_id' => $jRev->id,
            'account_id'       => $coaKas->id,
            'debit'            => 1000000.00,
            'credit'           => 0.00,
        ]);
        JournalDetail::create([
            'journal_entry_id' => $jRev->id,
            'account_id'       => $coaJasa->id,
            'debit'            => 0.00,
            'credit'           => 1000000.00,
        ]);

        // b. Beban Operasional Rp 300.000 (Beban Debet, Kas Kredit)
        $jExp = JournalEntry::create([
            'entry_date'     => '2026-07-20',
            'voucher_number' => 'JV-20260720-0001',
            'description'    => 'Biaya Operasional Juli',
        ]);
        JournalDetail::create([
            'journal_entry_id' => $jExp->id,
            'account_id'       => $coaBeban->id,
            'debit'            => 300000.00,
            'credit'           => 0.00,
        ]);
        JournalDetail::create([
            'journal_entry_id' => $jExp->id,
            'account_id'       => $coaKas->id,
            'debit'            => 0.00,
            'credit'           => 300000.00,
        ]);

        // 4. Test Service generateWorksheet untuk periode 2026-07-01 s/d 2026-07-31
        $service = app(WorksheetReportService::class);
        $result = $service->generateWorksheet('2026-07-01', '2026-07-31', 'Juli 2026');

        $accounts = collect($result['accounts'])->keyBy('account_code');

        // Verifikasi Akun 1000 (Kas):
        // Saldo Awal Cut-Off (5jt) + Mutasi Juni (2jt) = NSA Debet 7.000.000, NSA Kredit 0
        // Mutasi Juli: Adj Debet 1.000.000, Adj Kredit 300.000
        // Trial Balance: Trial Debet 8.000.000, Trial Kredit 300.000
        // L/R: 0, 0
        // Neraca: Neraca Debet 7.700.000 (8jt - 300rb), Neraca Kredit 0
        $kasRow = $accounts->get('1000');
        $this->assertNotNull($kasRow);
        $this->assertEquals(7000000.00, $kasRow['nsa_debit']);
        $this->assertEquals(0.00, $kasRow['nsa_credit']);
        $this->assertEquals(1000000.00, $kasRow['adj_debit']);
        $this->assertEquals(300000.00, $kasRow['adj_credit']);
        $this->assertEquals(8000000.00, $kasRow['trial_debit']);
        $this->assertEquals(300000.00, $kasRow['trial_credit']);
        $this->assertEquals(0.00, $kasRow['lr_debit']);
        $this->assertEquals(0.00, $kasRow['lr_credit']);
        $this->assertEquals(7700000.00, $kasRow['neraca_debit']);
        $this->assertEquals(0.00, $kasRow['neraca_credit']);

        // Verifikasi Akun 1024 (Piutang):
        // Saldo Awal Cut-Off (15jt) - Mutasi Kredit Juni (2jt) = NSA Debet 13.000.000, NSA Kredit 0
        // Neraca Debet 13.000.000
        $piutangRow = $accounts->get('1024');
        $this->assertNotNull($piutangRow);
        $this->assertEquals(13000000.00, $piutangRow['nsa_debit']);
        $this->assertEquals(0.00, $piutangRow['nsa_credit']);
        $this->assertEquals(13000000.00, $piutangRow['neraca_debit']);

        // Verifikasi Akun 4180 (Pendapatan Jasa):
        // NSA: 0, 0 (Akun Nominal)
        // Mutasi: Adj Debet 0, Adj Kredit 1.000.000
        // L/R: LR Debet 0, LR Kredit 1.000.000
        // Neraca: 0, 0
        $jasaRow = $accounts->get('4180');
        $this->assertNotNull($jasaRow);
        $this->assertEquals(0.00, $jasaRow['nsa_debit']);
        $this->assertEquals(0.00, $jasaRow['nsa_credit']);
        $this->assertEquals(1000000.00, $jasaRow['lr_credit']);
        $this->assertEquals(0.00, $jasaRow['neraca_credit']);

        // Verifikasi Akun 7100 (Beban):
        // NSA: 0, 0
        // Mutasi: Adj Debet 300.000, Adj Kredit 0
        // L/R: LR Debet 300.000, LR Kredit 0
        // Neraca: 0, 0
        $bebanRow = $accounts->get('7100');
        $this->assertNotNull($bebanRow);
        $this->assertEquals(0.00, $bebanRow['nsa_debit']);
        $this->assertEquals(300000.00, $bebanRow['lr_debit']);

        // Verifikasi Row 9900 (Ikhtisar R/L):
        // Net Income (SHU) = 1.000.000 - 300.000 = 700.000 (Surplus)
        // Masuk di LR Debet: 700.000
        // Masuk di Neraca Kredit: 700.000
        $ikhtisarRow = $accounts->get('9900');
        $this->assertNotNull($ikhtisarRow);
        $this->assertEquals(700000.00, $ikhtisarRow['lr_debit']);
        $this->assertEquals(0.00, $ikhtisarRow['lr_credit']);
        $this->assertEquals(0.00, $ikhtisarRow['neraca_debit']);
        $this->assertEquals(700000.00, $ikhtisarRow['neraca_credit']);

        // Verifikasi Grand Total Balance
        $summary = $result['summary'];
        $this->assertEquals(20000000.00, $summary['total_nsa_debit']);
        $this->assertEquals(20000000.00, $summary['total_nsa_credit']);
        $this->assertEquals(1300000.00, $summary['total_adj_debit']);
        $this->assertEquals(1300000.00, $summary['total_adj_credit']);
        $this->assertEquals(21300000.00, $summary['total_trial_debit']);
        $this->assertEquals(21300000.00, $summary['total_trial_credit']);
        $this->assertEquals(1000000.00, $summary['total_lr_debit']);
        $this->assertEquals(1000000.00, $summary['total_lr_credit']);
        $this->assertEquals(20700000.00, $summary['total_neraca_debit']);
        $this->assertEquals(20700000.00, $summary['total_neraca_credit']);
        $this->assertEquals(700000.00, $summary['net_income']);
        $this->assertTrue($summary['is_balanced']);

        // 5. Test Endpoint API GET /api/accounting/worksheet
        $response = $this->getJson('/api/accounting/worksheet?start_date=2026-07-01&end_date=2026-07-31');
        $response->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.summary.net_income', 700000)
            ->assertJsonPath('data.summary.is_balanced', true);
    }

    public function test_weekly_m3_rolling_net_balance_and_pure_mutations(): void
    {
        $manager = User::create([
            'name'     => 'Manager Akuntansi',
            'email'    => 'manager2@koperasi.com',
            'password' => bcrypt('password123'),
            'role'     => 'manager',
            'nik'      => '9876543210987654',
        ]);

        Sanctum::actingAs($manager);

        // Saldo Cut-Off 01 Mei 2026:
        // Kas (1000): Debet 10.000.000
        // Simpanan Saham (2020): Kredit 10.000.000
        InitialAccountBalance::create([
            'cutoff_date'  => '2026-05-01',
            'account_code' => '1000',
            'debit'        => 10000000.00,
            'credit'       => 0.00,
        ]);
        InitialAccountBalance::create([
            'cutoff_date'  => '2026-05-01',
            'account_code' => '2020',
            'debit'        => 0.00,
            'credit'       => 10000000.00,
        ]);

        $coaKas     = ChartOfAccount::where('account_code', '1000')->first();
        $coaPiutang = ChartOfAccount::where('account_code', '1024')->first();
        $coaJasa    = ChartOfAccount::where('account_code', '4180')->first();
        $coaBeban   = ChartOfAccount::where('account_code', '7100')->first();

        // Minggu 1 (01 s/d 07 Mei 2026):
        // Jasa Pinjaman Rp 500.000 (Kas Debet 500rb, Jasa Kredit 500rb) pada 2026-05-03
        $jM1 = JournalEntry::create(['entry_date' => '2026-05-03', 'voucher_number' => 'JV-M1-001', 'description' => 'Jasa M1']);
        JournalDetail::create(['journal_entry_id' => $jM1->id, 'account_id' => $coaKas->id, 'debit' => 500000, 'credit' => 0]);
        JournalDetail::create(['journal_entry_id' => $jM1->id, 'account_id' => $coaJasa->id, 'debit' => 0, 'credit' => 500000]);

        // Minggu 2 (08 s/d 14 Mei 2026):
        // Biaya ATK Rp 200.000 (Beban Debet 200rb, Kas Kredit 200rb) pada 2026-05-10
        $jM2 = JournalEntry::create(['entry_date' => '2026-05-10', 'voucher_number' => 'JV-M2-001', 'description' => 'Beban M2']);
        JournalDetail::create(['journal_entry_id' => $jM2->id, 'account_id' => $coaBeban->id, 'debit' => 200000, 'credit' => 0]);
        JournalDetail::create(['journal_entry_id' => $jM2->id, 'account_id' => $coaKas->id, 'debit' => 0, 'credit' => 200000]);

        // Minggu 3 (15 s/d 21 Mei 2026):
        // Pencairan Piutang Rp 1.000.000 (Piutang Debet 1jt, Kas Kredit 1jt) pada 2026-05-16
        $jM3 = JournalEntry::create(['entry_date' => '2026-05-16', 'voucher_number' => 'JV-M3-001', 'description' => 'Pencairan M3']);
        JournalDetail::create(['journal_entry_id' => $jM3->id, 'account_id' => $coaPiutang->id, 'debit' => 1000000, 'credit' => 0]);
        JournalDetail::create(['journal_entry_id' => $jM3->id, 'account_id' => $coaKas->id, 'debit' => 0, 'credit' => 1000000]);

        // Evaluasi Minggu 3 (M3: 2026-05-15 s/d 2026-05-21)
        // Saldo Awal Bersih Kas per 15 Mei 2026 = 10.000.000 (Cutoff) + 500.000 (M1) - 200.000 (M2) = 10.300.000 Debet
        // Mutasi M3 Kas = Debet 0, Kredit 1.000.000
        // Trial Balance Kas = Debet 10.300.000, Kredit 1.000.000
        // Neraca Kas = Debet 9.300.000
        $service = app(WorksheetReportService::class);
        $resM3 = $service->generateWorksheet('2026-05-15', '2026-05-21', '2026-05 M3');
        $accM3 = collect($resM3['accounts'])->keyBy('account_code');

        $kasM3 = $accM3->get('1000');
        $this->assertNotNull($kasM3);
        $this->assertEquals(10300000.00, $kasM3['nsa_debit']);
        $this->assertEquals(0.00, $kasM3['nsa_credit']);
        $this->assertEquals(0.00, $kasM3['adj_debit']);
        $this->assertEquals(1000000.00, $kasM3['adj_credit']);
        $this->assertEquals(10300000.00, $kasM3['trial_debit']);
        $this->assertEquals(1000000.00, $kasM3['trial_credit']);
        $this->assertEquals(9300000.00, $kasM3['neraca_debit']);

        // Akun Nominal (Jasa & Beban) pada M3 wajib bersaldo awal 0:
        $jasaM3 = $accM3->get('4180');
        if ($jasaM3) {
            $this->assertEquals(0.00, $jasaM3['nsa_debit']);
            $this->assertEquals(0.00, $jasaM3['nsa_credit']);
        }

        // Test Endpoint API GET /api/neraca-lajur?year=2026&month=5&week=M3
        $respNeraca = $this->getJson('/api/neraca-lajur?year=2026&month=5&week=M3');
        $respNeraca->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.start_date', '2026-05-15')
            ->assertJsonPath('data.end_date', '2026-05-21')
            ->assertJsonPath('data.summary.is_balanced', true);
    }

    public function test_migration_saldo_awal_placed_in_column_1_and_excluded_from_column_2(): void
    {
        $manager = User::create([
            'name'     => 'Manager Akuntansi',
            'email'    => 'manager3@koperasi.com',
            'password' => bcrypt('password123'),
            'role'     => 'manager',
            'nik'      => '1122334455667788',
        ]);

        Sanctum::actingAs($manager);

        // 1. Setup Master Saldo Awal Koperasi di initial_account_balances
        InitialAccountBalance::create([
            'cutoff_date'  => '2026-05-01',
            'account_code' => '1000',
            'debit'        => 10000000.00,
            'credit'       => 0.00,
        ]);
        InitialAccountBalance::create([
            'cutoff_date'  => '2026-05-01',
            'account_code' => '2020',
            'debit'        => 0.00,
            'credit'       => 10000000.00,
        ]);

        $coaKas    = ChartOfAccount::where('account_code', '1000')->first();
        $coaSaham  = ChartOfAccount::where('account_code', '2020')->first();
        $coaHarian = ChartOfAccount::where('account_code', '2021')->first();

        // 2. Transaksi Impor Saldo Awal Simpanan Subledger Anggota (KM-IMP) pada database
        // Record ini untuk kartu anggota perorangan, TIDAK BOLEH mendobelkan Kas pada Neraca Lajur Induk
        $jSaham = JournalEntry::create([
            'entry_date'     => '2026-08-31',
            'voucher_number' => 'KM-IMP-20260901-001',
            'description'    => 'Saldo Awal Simpanan Saham (Migrasi) - Anggota #1',
        ]);
        JournalDetail::create([
            'journal_entry_id' => $jSaham->id,
            'account_id'       => $coaKas->id,
            'debit'            => 5000000.00,
            'credit'           => 0.00,
        ]);
        JournalDetail::create([
            'journal_entry_id' => $jSaham->id,
            'account_id'       => $coaSaham->id,
            'debit'            => 0.00,
            'credit'           => 5000000.00,
        ]);

        // 3. Transaksi Mutasi Reguler Operasional Berjalan di September 2026
        // Kas Masuk Setoran Saham Rp 500.000
        $jReguler = JournalEntry::create([
            'entry_date'     => '2026-09-15',
            'voucher_number' => 'KM-20260915-0001',
            'description'    => 'Setoran Simpanan Sukarela - Anggota #1',
        ]);
        JournalDetail::create([
            'journal_entry_id' => $jReguler->id,
            'account_id'       => $coaKas->id,
            'debit'            => 500000.00,
            'credit'           => 0.00,
        ]);
        JournalDetail::create([
            'journal_entry_id' => $jReguler->id,
            'account_id'       => $coaSaham->id,
            'debit'            => 0.00,
            'credit'           => 500000.00,
        ]);

        // 4. Generate Worksheet untuk periode September 2026 (2026-09-01 s/d 2026-09-30)
        $service = app(WorksheetReportService::class);
        $result = $service->generateWorksheet('2026-09-01', '2026-09-30', 'September 2026');

        $accounts = collect($result['accounts'])->keyBy('account_code');

        // Verifikasi Akun 1000 (Kas):
        // NSA: 10.000.000 (Murni dari initial_account_balances, TIDAK bertambah 5jt dari KM-IMP)
        // Mutasi (Adj): 500.000 (hanya transaksi reguler KM-20260915-0001)
        // Trial Balance: 10.500.000 Debet
        // Neraca Akhir: 10.500.000 Debet
        $kasRow = $accounts->get('1000');
        $this->assertNotNull($kasRow);
        $this->assertEquals(10000000.00, $kasRow['nsa_debit']);
        $this->assertEquals(0.00, $kasRow['nsa_credit']);
        $this->assertEquals(500000.00, $kasRow['adj_debit']);
        $this->assertEquals(0.00, $kasRow['adj_credit']);
        $this->assertEquals(10500000.00, $kasRow['trial_debit']);
        $this->assertEquals(0.00, $kasRow['trial_credit']);
        $this->assertEquals(10500000.00, $kasRow['neraca_debit']);
        $this->assertEquals(0.00, $kasRow['neraca_credit']);

        // Verifikasi Akun 2020 (Simpanan Saham):
        // NSA: 10.000.000 Kredit (Murni dari initial_account_balances)
        // Mutasi (Adj): 500.000 Kredit (dari KM-20260915-0001)
        // Trial Balance: 10.500.000 Kredit
        // Neraca Akhir: 10.500.000 Kredit
        $sahamRow = $accounts->get('2020');
        $this->assertNotNull($sahamRow);
        $this->assertEquals(0.00, $sahamRow['nsa_debit']);
        $this->assertEquals(10000000.00, $sahamRow['nsa_credit']);
        $this->assertEquals(0.00, $sahamRow['adj_debit']);
        $this->assertEquals(500000.00, $sahamRow['adj_credit']);
        $this->assertEquals(0.00, $sahamRow['trial_debit']);
        $this->assertEquals(10500000.00, $sahamRow['trial_credit']);
        $this->assertEquals(0.00, $sahamRow['neraca_debit']);
        $this->assertEquals(10500000.00, $sahamRow['neraca_credit']);

        // Verifikasi Summary / Total Keseluruhan:
        $summary = $result['summary'];
        $this->assertEquals(10000000.00, $summary['total_nsa_debit']);
        $this->assertEquals(10000000.00, $summary['total_nsa_credit']);
        $this->assertEquals(500000.00, $summary['total_adj_debit']);
        $this->assertEquals(500000.00, $summary['total_adj_credit']);
        $this->assertEquals(10500000.00, $summary['total_trial_debit']);
        $this->assertEquals(10500000.00, $summary['total_trial_credit']);
        $this->assertEquals(10500000.00, $summary['total_neraca_debit']);
        $this->assertEquals(10500000.00, $summary['total_neraca_credit']);
        $this->assertTrue($summary['is_balanced']);
    }
}
