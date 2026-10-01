<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\ChartOfAccount;
use App\Models\JournalDetail;
use App\Models\JournalEntry;
use App\Models\Member;
use App\Models\Transaction;
use App\Models\User;
use App\Services\WorksheetReportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class WorksheetReportTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(\Database\Seeders\ChartOfAccountSeeder::class);
    }

    public function test_worksheet_report_formulas_and_balance(): void
    {
        $manager = User::create([
            'name'     => 'Manager Akuntansi',
            'email'    => 'manager.akuntansi@koperasi.com',
            'password' => bcrypt('password123'),
            'role'     => 'manager',
            'nik'      => '1122334455667799',
        ]);

        Sanctum::actingAs($manager);

        $coaKas     = ChartOfAccount::where('account_code', '1000')->first();
        $coaPiutang = ChartOfAccount::where('account_code', '1024')->first();
        $coaSaham   = ChartOfAccount::where('account_code', '2020')->first();
        $coaJasa    = ChartOfAccount::where('account_code', '4180')->first();
        $coaBeban   = ChartOfAccount::where('account_code', '7100')->first();

        // 1. Entri Jurnal Saldo Awal (Januari 2026)
        $jAwal = JournalEntry::create([
            'entry_date'     => '2026-01-15',
            'voucher_number' => 'JV-20260115-0001',
            'description'    => 'Saldo Awal Modal & Kas',
        ]);
        JournalDetail::create([
            'journal_entry_id' => $jAwal->id,
            'account_id'       => $coaKas->id,
            'debit'            => 10000000.00,
            'credit'           => 0.00,
        ]);
        JournalDetail::create([
            'journal_entry_id' => $jAwal->id,
            'account_id'       => $coaSaham->id,
            'debit'            => 0.00,
            'credit'           => 10000000.00,
        ]);

        // 2. Entri Jurnal Periode Berjalan (Februari 2026)
        // a. Pendapatan Jasa Pinjaman Rp 2.000.000 (Kas Debet, Jasa Pinjaman Kredit)
        $jRev = JournalEntry::create([
            'entry_date'     => '2026-02-10',
            'voucher_number' => 'JV-20260210-0001',
            'description'    => 'Pendapatan Jasa Pinjaman Kas Masuk',
        ]);
        JournalDetail::create([
            'journal_entry_id' => $jRev->id,
            'account_id'       => $coaKas->id,
            'debit'            => 2000000.00,
            'credit'           => 0.00,
        ]);
        JournalDetail::create([
            'journal_entry_id' => $jRev->id,
            'account_id'       => $coaJasa->id,
            'debit'            => 0.00,
            'credit'           => 2000000.00,
        ]);

        // b. Biaya ATK Rp 500.000 (Beban ATK Debet, Kas Kredit)
        $jExp = JournalEntry::create([
            'entry_date'     => '2026-02-20',
            'voucher_number' => 'JV-20260220-0001',
            'description'    => 'Pembelian ATK',
        ]);
        JournalDetail::create([
            'journal_entry_id' => $jExp->id,
            'account_id'       => $coaBeban->id,
            'debit'            => 500000.00,
            'credit'           => 0.00,
        ]);
        JournalDetail::create([
            'journal_entry_id' => $jExp->id,
            'account_id'       => $coaKas->id,
            'debit'            => 0.00,
            'credit'           => 500000.00,
        ]);

        // Panggil endpoint Neraca Lajur untuk periode Februari 2026
        $response = $this->getJson('/api/reports/trial-balance?start_date=2026-02-01&end_date=2026-02-28');
        $response->assertStatus(200);
        $response->assertJson(['success' => true]);

        $data = $response->json('data');
        $accounts = collect($data['accounts']);

        // 1. Akun Kas (1000 - Harta / Asset)
        $rowKas = $accounts->firstWhere('account_code', '1000');
        $this->assertNotNull($rowKas);
        $this->assertEquals(10000000.00, (float) $rowKas['initial_debit']);
        $this->assertEquals(0.00, (float) $rowKas['initial_credit']);
        $this->assertEquals(2000000.00, (float) $rowKas['adjustment_debit']);
        $this->assertEquals(500000.00, (float) $rowKas['adjustment_credit']);
        $this->assertEquals(12000000.00, (float) $rowKas['trial_debit']); // 10M + 2M
        $this->assertEquals(500000.00, (float) $rowKas['trial_credit']);  // 0 + 500k
        $this->assertEquals(11500000.00, (float) $rowKas['neraca_debit']); // 12M - 500k
        $this->assertEquals(0.00, (float) $rowKas['neraca_credit']);
        $this->assertEquals(0.00, (float) $rowKas['rugi_laba_debit']);
        $this->assertEquals(0.00, (float) $rowKas['rugi_laba_credit']);

        // 2. Akun Saham (2020 - Kewajiban / Liability)
        $rowSaham = $accounts->firstWhere('account_code', '2020');
        $this->assertNotNull($rowSaham);
        $this->assertEquals(0.00, (float) $rowSaham['initial_debit']);
        $this->assertEquals(10000000.00, (float) $rowSaham['initial_credit']);
        $this->assertEquals(0.00, (float) $rowSaham['adjustment_debit']);
        $this->assertEquals(0.00, (float) $rowSaham['adjustment_credit']);
        $this->assertEquals(0.00, (float) $rowSaham['trial_debit']);
        $this->assertEquals(10000000.00, (float) $rowSaham['trial_credit']);
        $this->assertEquals(0.00, (float) $rowSaham['neraca_debit']);
        $this->assertEquals(10000000.00, (float) $rowSaham['neraca_credit']);

        // 3. Akun Pendapatan Jasa Pinjaman (4180 - Revenue)
        $rowJasa = $accounts->firstWhere('account_code', '4180');
        $this->assertNotNull($rowJasa);
        $this->assertEquals(2000000.00, (float) $rowJasa['adjustment_credit']);
        $this->assertEquals(2000000.00, (float) $rowJasa['trial_credit']);
        $this->assertEquals(2000000.00, (float) $rowJasa['rugi_laba_credit']);
        $this->assertEquals(0.00, (float) $rowJasa['rugi_laba_debit']);
        $this->assertEquals(0.00, (float) $rowJasa['neraca_debit']);
        $this->assertEquals(0.00, (float) $rowJasa['neraca_credit']);

        // 4. Akun Beban ATK (7100 - Expense)
        $rowBeban = $accounts->firstWhere('account_code', '7100');
        $this->assertNotNull($rowBeban);
        $this->assertEquals(500000.00, (float) $rowBeban['adjustment_debit']);
        $this->assertEquals(500000.00, (float) $rowBeban['trial_debit']);
        $this->assertEquals(500000.00, (float) $rowBeban['rugi_laba_debit']);
        $this->assertEquals(0.00, (float) $rowBeban['rugi_laba_credit']);
        $this->assertEquals(0.00, (float) $rowBeban['neraca_debit']);
        $this->assertEquals(0.00, (float) $rowBeban['neraca_credit']);

        // 5. Baris 9900 (Ikhtisar R/L & Laba Periode)
        // SHU = 2.000.000 (Pendapatan) - 500.000 (Beban) = 1.500.000
        $rowIkhtisar = $accounts->firstWhere('account_code', '9900');
        $this->assertNotNull($rowIkhtisar);
        $this->assertEquals(1500000.00, (float) $rowIkhtisar['rugi_laba_debit']);
        $this->assertEquals(0.00, (float) $rowIkhtisar['rugi_laba_credit']);
        $this->assertEquals(0.00, (float) $rowIkhtisar['neraca_debit']);
        $this->assertEquals(1500000.00, (float) $rowIkhtisar['neraca_credit']);

        // 6. Verifikasi Keseimbangan Grand Total (Balance Sempurna)
        $summary = $data['summary'];
        $this->assertEquals(1500000.00, (float) $summary['net_income']);
        
        // Total Rugi Laba: Debit = Kredit = 2.000.000
        $this->assertEquals(2000000.00, (float) $summary['total_rugi_laba_debit']);
        $this->assertEquals(2000000.00, (float) $summary['total_rugi_laba_credit']);

        // Total Neraca: Debit = Kredit = 11.500.000 (Kas 11.5M = Saham 10M + Laba 1.5M)
        $this->assertEquals(11500000.00, (float) $summary['total_neraca_debit']);
        $this->assertEquals(11500000.00, (float) $summary['total_neraca_credit']);
        $this->assertTrue($summary['is_balanced']);
    }

    public function test_daily_km_provisi_denda_journaling_and_worksheet_placement(): void
    {
        $admin = User::create([
            'name'     => 'Admin Kasir',
            'email'    => 'admin.kasir@koperasi.com',
            'password' => bcrypt('password123'),
            'role'     => 'admin',
            'nik'      => '1122334455667788',
        ]);

        $member = Member::create([
            'member_number'      => 'MBR-2026-0099',
            'name'               => 'Debitur Uji Coba',
            'nik'                => '3201010101010099',
            'phone'              => '081299998888',
            'status'             => 'active',
            'principal_savings'  => 100000.00,
            'mandatory_savings'  => 100000.00,
            'voluntary_savings'  => 100000.00,
            'daily_savings'      => 0.00,
        ]);

        Sanctum::actingAs($admin);

        // 1. Simpan Transaksi KM Provisi Rp 250.000 pada 05 September 2026
        $resProvisi = $this->postJson('/api/transactions', [
            'member_id'        => $member->id,
            'payment_method'   => 'cash',
            'transaction_date' => '2026-09-05',
            'type'             => 'deposit',
            'items'            => [
                [
                    'amount'       => 250000.00,
                    'account_code' => '4170',
                    'label'        => 'Provisi Pinjaman',
                ]
            ]
        ]);
        $resProvisi->assertStatus(200);

        // 2. Simpan Transaksi KM Denda Rp 50.000 pada 05 September 2026
        $resDenda = $this->postJson('/api/transactions', [
            'member_id'        => $member->id,
            'payment_method'   => 'cash',
            'transaction_date' => '2026-09-05',
            'type'             => 'deposit',
            'items'            => [
                [
                    'amount'       => 50000.00,
                    'account_code' => '4182',
                    'label'        => 'Denda Keterlambatan',
                ]
            ]
        ]);
        $resDenda->assertStatus(200);

        // 3. Verifikasi Saldo Simpanan Anggota TIDAK Bertambah (Tetap 100k)
        $memberFresh = $member->fresh();
        $this->assertEquals(100000.00, (float) $memberFresh->principal_savings);
        $this->assertEquals(100000.00, (float) $memberFresh->mandatory_savings);
        $this->assertEquals(100000.00, (float) $memberFresh->voluntary_savings);
        $this->assertEquals(0.00, (float) $memberFresh->daily_savings);

        // 4. Verifikasi Detail Jurnal:
        // Provisi: Debit Kas (1000) 250k, Kredit Pendapatan Provisi (4170) 250k
        $jProvisi = JournalEntry::where('description', 'LIKE', '%Provisi%')->with('details.account')->first();
        $this->assertNotNull($jProvisi);
        $creditProvisi = $jProvisi->details->firstWhere('account.account_code', '4170');
        $this->assertNotNull($creditProvisi, 'Jurnal kredit harus masuk ke COA 4170 (Pendapatan Provisi)');
        $this->assertEquals(250000.00, (float) $creditProvisi->credit);

        // Denda: Debit Kas (1000) 50k, Kredit Pendapatan Denda (4182) 50k
        $jDenda = JournalEntry::where('description', 'LIKE', '%Denda%')->with('details.account')->first();
        $this->assertNotNull($jDenda);
        $creditDenda = $jDenda->details->firstWhere('account.account_code', '4182');
        $this->assertNotNull($creditDenda, 'Jurnal kredit harus masuk ke COA 4182 (Pendapatan Denda)');
        $this->assertEquals(50000.00, (float) $creditDenda->credit);

        // 5. Verifikasi Penempatan di Neraca Lajur Periode September 2026 (01-09-2026 s/d 30-09-2026)
        $resWorksheet = $this->getJson('/api/reports/trial-balance?start_date=2026-09-01&end_date=2026-09-30');
        $resWorksheet->assertStatus(200);

        $accounts = collect($resWorksheet->json('data.accounts'));

        // Baris Akun 4170 (Provisi)
        $row4170 = $accounts->firstWhere('account_code', '4170');
        $this->assertNotNull($row4170);
        $this->assertEquals(0.00, (float) $row4170['initial_debit'], 'Saldo awal 4170 debit harus 0');
        $this->assertEquals(0.00, (float) $row4170['initial_credit'], 'Saldo awal 4170 kredit harus 0');
        $this->assertEquals(250000.00, (float) $row4170['adjustment_credit'], 'Mutasi periode aktif harus masuk Kolom 2 Penyesuaian');
        $this->assertEquals(250000.00, (float) $row4170['trial_credit'], 'Percobaan kredit harus 250k');
        $this->assertEquals(250000.00, (float) $row4170['rugi_laba_credit'], 'Laba Rugi kredit harus 250k');
        $this->assertEquals(0.00, (float) $row4170['neraca_debit'], 'Neraca debit harus 0');
        $this->assertEquals(0.00, (float) $row4170['neraca_credit'], 'Neraca kredit harus 0');

        // Baris Akun 4182 (Denda)
        $row4182 = $accounts->firstWhere('account_code', '4182');
        $this->assertNotNull($row4182);
        $this->assertEquals(0.00, (float) $row4182['initial_debit'], 'Saldo awal 4182 debit harus 0');
        $this->assertEquals(0.00, (float) $row4182['initial_credit'], 'Saldo awal 4182 kredit harus 0');
        $this->assertEquals(50000.00, (float) $row4182['adjustment_credit'], 'Mutasi periode aktif harus masuk Kolom 2 Penyesuaian');
        $this->assertEquals(50000.00, (float) $row4182['trial_credit'], 'Percobaan kredit harus 50k');
        $this->assertEquals(50000.00, (float) $row4182['rugi_laba_credit'], 'Laba Rugi kredit harus 50k');
        $this->assertEquals(0.00, (float) $row4182['neraca_debit'], 'Neraca debit harus 0');
        $this->assertEquals(0.00, (float) $row4182['neraca_credit'], 'Neraca kredit harus 0');
    }

    public function test_weekly_period_m1_m5_filtering_and_placement(): void
    {
        $manager = User::create([
            'name'     => 'Manager Evaluasi Mingguan',
            'email'    => 'manager.mingguan@koperasi.com',
            'password' => bcrypt('password123'),
            'role'     => 'manager',
            'nik'      => '1122334455667700',
        ]);

        Sanctum::actingAs($manager);

        $coaKas     = ChartOfAccount::where('account_code', '1000')->first();
        $coaSaham   = ChartOfAccount::where('account_code', '2020')->first();
        $coaProvisi = ChartOfAccount::where('account_code', '4170')->first();
        $coaDenda   = ChartOfAccount::where('account_code', '4182')->first();

        // 1. Transaksi Saldo Awal SEBELUM September 2026 M1 (misal 31 Agustus 2026)
        $jAwal = JournalEntry::create([
            'entry_date'     => '2026-08-31',
            'voucher_number' => 'JV-20260831-0001',
            'description'    => 'Saldo Awal Kas & Saham Agustus',
        ]);
        JournalDetail::create([
            'journal_entry_id' => $jAwal->id,
            'account_id'       => $coaKas->id,
            'debit'            => 5000000.00,
            'credit'           => 0.00,
        ]);
        JournalDetail::create([
            'journal_entry_id' => $jAwal->id,
            'account_id'       => $coaSaham->id,
            'debit'            => 0.00,
            'credit'           => 5000000.00,
        ]);

        // 2. Transaksi yang Terjadi PADA Rentang M1 (01 Sep 2026 s/d 07 Sep 2026)
        // a. Transaksi tanggal 01 September 2026 (Provisi Rp 200.000)
        $jM1_1 = JournalEntry::create([
            'entry_date'     => '2026-09-01',
            'voucher_number' => 'JV-20260901-0001',
            'description'    => 'Pendapatan Provisi M1',
        ]);
        JournalDetail::create([
            'journal_entry_id' => $jM1_1->id,
            'account_id'       => $coaKas->id,
            'debit'            => 200000.00,
            'credit'           => 0.00,
        ]);
        JournalDetail::create([
            'journal_entry_id' => $jM1_1->id,
            'account_id'       => $coaProvisi->id,
            'debit'            => 0.00,
            'credit'           => 200000.00,
        ]);

        // b. Transaksi tanggal 05 September 2026 (Denda Rp 50.000)
        $jM1_2 = JournalEntry::create([
            'entry_date'     => '2026-09-05',
            'voucher_number' => 'JV-20260905-0001',
            'description'    => 'Pendapatan Denda M1',
        ]);
        JournalDetail::create([
            'journal_entry_id' => $jM1_2->id,
            'account_id'       => $coaKas->id,
            'debit'            => 50000.00,
            'credit'           => 0.00,
        ]);
        JournalDetail::create([
            'journal_entry_id' => $jM1_2->id,
            'account_id'       => $coaDenda->id,
            'debit'            => 0.00,
            'credit'           => 50000.00,
        ]);

        // 3. Transaksi di luar M1 (misal M2: 10 September 2026) -> TIDAK boleh masuk ke M1
        $jM2 = JournalEntry::create([
            'entry_date'     => '2026-09-10',
            'voucher_number' => 'JV-20260910-0001',
            'description'    => 'Pendapatan Provisi M2',
        ]);
        JournalDetail::create([
            'journal_entry_id' => $jM2->id,
            'account_id'       => $coaKas->id,
            'debit'            => 100000.00,
            'credit'           => 0.00,
        ]);
        JournalDetail::create([
            'journal_entry_id' => $jM2->id,
            'account_id'       => $coaProvisi->id,
            'debit'            => 0.00,
            'credit'           => 100000.00,
        ]);

        // 4. Request Neraca Lajur untuk September 2026 M1 via ?period=2026-09-M1
        $resM1 = $this->getJson('/api/reports/trial-balance?period=2026-09-M1');
        $resM1->assertStatus(200);

        $data = $resM1->json('data');
        $this->assertEquals('2026-09-01', $data['period']['start_date']);
        $this->assertEquals('2026-09-07', $data['period']['end_date']);

        $accounts = collect($data['accounts']);

        // a. Akun Kas (1000)
        $rowKas = $accounts->firstWhere('account_code', '1000');
        $this->assertNotNull($rowKas);
        $this->assertEquals(5000000.00, (float) $rowKas['initial_debit'], 'Saldo awal kas harus dari akumulasi sebelum 1 Sep');
        $this->assertEquals(0.00, (float) $rowKas['initial_credit']);
        $this->assertEquals(250000.00, (float) $rowKas['adjustment_debit'], 'Mutasi debit M1 harus 200k + 50k = 250k (tidak termasuk M2)');
        $this->assertEquals(0.00, (float) $rowKas['adjustment_credit']);
        $this->assertEquals(5250000.00, (float) $rowKas['trial_debit']);
        $this->assertEquals(5250000.00, (float) $rowKas['neraca_debit']);

        // b. Akun Provisi (4170)
        $rowProvisi = $accounts->firstWhere('account_code', '4170');
        $this->assertNotNull($rowProvisi);
        $this->assertEquals(0.00, (float) $rowProvisi['initial_credit'], 'Saldo awal provisi 0');
        $this->assertEquals(200000.00, (float) $rowProvisi['adjustment_credit'], 'Mutasi provisi M1 harus 200k (tidak termasuk 100k di M2)');
        $this->assertEquals(200000.00, (float) $rowProvisi['trial_credit']);
        $this->assertEquals(200000.00, (float) $rowProvisi['rugi_laba_credit']);
        $this->assertEquals(0.00, (float) $rowProvisi['neraca_credit']);

        // c. Akun Denda (4182)
        $rowDenda = $accounts->firstWhere('account_code', '4182');
        $this->assertNotNull($rowDenda);
        $this->assertEquals(0.00, (float) $rowDenda['initial_credit']);
        $this->assertEquals(50000.00, (float) $rowDenda['adjustment_credit'], 'Mutasi denda M1 harus 50k');
        $this->assertEquals(50000.00, (float) $rowDenda['trial_credit']);
        $this->assertEquals(50000.00, (float) $rowDenda['rugi_laba_credit']);
        $this->assertEquals(0.00, (float) $rowDenda['neraca_credit']);

        // d. Baris 9900 (Ikhtisar R/L & Laba SHU M1 = 250.000)
        $row9900 = $accounts->firstWhere('account_code', '9900');
        $this->assertNotNull($row9900);
        $this->assertEquals(250000.00, (float) $row9900['rugi_laba_debit']);
        $this->assertEquals(250000.00, (float) $row9900['neraca_credit']);

        // e. Grand Total Balance
        $summary = $data['summary'];
        $this->assertEquals(250000.00, (float) $summary['total_rugi_laba_debit']);
        $this->assertEquals(250000.00, (float) $summary['total_rugi_laba_credit']);
        $this->assertEquals(5250000.00, (float) $summary['total_neraca_debit']);
        $this->assertEquals(5250000.00, (float) $summary['total_neraca_credit']);
        $this->assertTrue($summary['is_balanced']);
    }

    public function test_export_trial_balance_pdf_and_csv(): void
    {
        $manager = User::create([
            'name'     => 'Manager Export',
            'email'    => 'manager.export@koperasi.com',
            'password' => bcrypt('password123'),
            'role'     => 'manager',
            'nik'      => '1122334455667701',
        ]);

        $token = $manager->createToken('test-token')->plainTextToken;

        // 1. Export CSV
        $resCsv = $this->get("/api/reports/trial-balance/export/excel?token={$token}&start_date=2026-09-01&end_date=2026-09-30");
        $resCsv->assertStatus(200);

        // 2. Export PDF
        $resPdf = $this->get("/api/reports/trial-balance/export/pdf?token={$token}&start_date=2026-09-01&end_date=2026-09-30");
        $resPdf->assertStatus(200);
    }

    public function test_nominal_accounts_force_initial_balance_zero_even_with_prior_journals(): void
    {
        $manager = User::create([
            'name'     => 'Manager Nominal Test',
            'email'    => 'manager.nominal@koperasi.com',
            'password' => bcrypt('password123'),
            'role'     => 'manager',
            'nik'      => '1122334455667702',
        ]);

        Sanctum::actingAs($manager);

        $coaKas     = ChartOfAccount::where('account_code', '1000')->first();
        $coaJasa    = ChartOfAccount::where('account_code', '4180')->first();
        $coaBeban   = ChartOfAccount::where('account_code', '7100')->first();
        $coaModal   = ChartOfAccount::where('account_code', '2020')->first();

        // 1. Jurnal di masa lalu (sebelum periode: 2026-05-15)
        $jPast = JournalEntry::create([
            'entry_date'     => '2026-05-15',
            'voucher_number' => 'JV-20260515-0001',
            'description'    => 'Transaksi Tahun Buku Lalu',
        ]);
        // Pendapatan masa lalu Rp 5.000.000
        JournalDetail::create([
            'journal_entry_id' => $jPast->id,
            'account_id'       => $coaKas->id,
            'debit'            => 5000000.00,
            'credit'           => 0.00,
        ]);
        JournalDetail::create([
            'journal_entry_id' => $jPast->id,
            'account_id'       => $coaJasa->id,
            'debit'            => 0.00,
            'credit'           => 5000000.00,
        ]);

        // Beban masa lalu Rp 1.000.000
        JournalDetail::create([
            'journal_entry_id' => $jPast->id,
            'account_id'       => $coaBeban->id,
            'debit'            => 1000000.00,
            'credit'           => 0.00,
        ]);
        JournalDetail::create([
            'journal_entry_id' => $jPast->id,
            'account_id'       => $coaKas->id,
            'debit'            => 0.00,
            'credit'           => 1000000.00,
        ]);

        // 2. Jurnal di periode berjalan (2026-06-10)
        $jCurrent = JournalEntry::create([
            'entry_date'     => '2026-06-10',
            'voucher_number' => 'JV-20260610-0001',
            'description'    => 'Pendapatan Bulan Juni',
        ]);
        JournalDetail::create([
            'journal_entry_id' => $jCurrent->id,
            'account_id'       => $coaKas->id,
            'debit'            => 2000000.00,
            'credit'           => 0.00,
        ]);
        JournalDetail::create([
            'journal_entry_id' => $jCurrent->id,
            'account_id'       => $coaJasa->id,
            'debit'            => 0.00,
            'credit'           => 2000000.00,
        ]);

        // Panggil Neraca Lajur per 1 Juni 2026 s/d 30 Juni 2026
        $response = $this->getJson('/api/reports/trial-balance?start_date=2026-06-01&end_date=2026-06-30');
        $response->assertStatus(200);

        $accounts = collect($response->json('data.accounts'));

        // Akun Riil (1000 Kas) HARUS membawa saldo awal dari transaksi masa lalu (+5M - 1M = 4M)
        $rowKas = $accounts->firstWhere('account_code', '1000');
        $this->assertEquals(4000000.00, (float) $rowKas['initial_debit']);

        // Akun Nominal Pendapatan (4180) HARUS 0 di saldo awal, walau ada 5M di masa lalu
        $rowJasa = $accounts->firstWhere('account_code', '4180');
        $this->assertEquals(0.00, (float) $rowJasa['initial_credit'], 'Saldo awal pendapatan harus Rp 0');
        $this->assertEquals(0.00, (float) $rowJasa['initial_debit'], 'Saldo awal pendapatan harus Rp 0');
        $this->assertEquals(2000000.00, (float) $rowJasa['adjustment_credit'], 'Mutasi periode berjalan 2M');
        $this->assertEquals(2000000.00, (float) $rowJasa['rugi_laba_credit']);

        // Akun Nominal Beban (7100) yang tidak ada mutasi di Juni harus di-skip / saldo awal 0
        $rowBeban = $accounts->firstWhere('account_code', '7100');
        $this->assertNull($rowBeban, 'Akun beban tanpa mutasi di periode berjalan tidak muncul karena saldo awal dipaksa 0');
    }
}
