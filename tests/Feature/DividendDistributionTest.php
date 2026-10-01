<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\ChartOfAccount;
use App\Models\JournalDetail;
use App\Models\JournalEntry;
use App\Models\Member;
use App\Models\ShuDistribution;
use App\Models\Transaction;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class DividendDistributionTest extends TestCase
{
    use RefreshDatabase;

    protected User $manager;
    protected Account $kasAccount;
    protected ChartOfAccount $coaBeban;
    protected ChartOfAccount $coaSukarela;

    protected function setUp(): void
    {
        parent::setUp();

        $this->manager = User::factory()->create([
            'role' => 'manager',
            'name' => 'Manager Koperasi',
        ]);

        $this->kasAccount = Account::create([
            'account_number' => 'KAS-101',
            'account_name'   => 'Kas Koperasi',
            'account_type'   => 'kas',
            'category'       => 'asset',
            'balance'        => 100000000.00,
        ]);

        $this->coaBeban = ChartOfAccount::create([
            'account_code'   => '7145',
            'account_name'   => 'Jasa Simpanan',
            'account_type'   => 'EXPENSE',
            'normal_balance' => 'DEBIT',
            'is_active'      => true,
        ]);

        $this->coaSukarela = ChartOfAccount::create([
            'account_code'   => '2020',
            'account_name'   => 'Simpanan Sukarela / Saham',
            'account_type'   => 'LIABILITY',
            'normal_balance' => 'CREDIT',
            'is_active'      => true,
        ]);
    }

    public function test_preview_calculates_buku_biru_dividend_correctly()
    {
        Sanctum::actingAs($this->manager);

        // Buat data transaksi pendapatan dan beban bulan September 2026
        Transaction::create([
            'transaction_number' => 'KM-TEST-01',
            'receipt_number'     => 'KM 0001',
            'type'               => 'KM',
            'amount'             => 10000000.00,
            'transaction_date'   => '2026-09-15',
            'status'             => 'approved',
        ]);

        Transaction::create([
            'transaction_number' => 'KK-TEST-01',
            'receipt_number'     => 'KK 0001',
            'type'               => 'KK',
            'amount'             => 2000000.00,
            'transaction_date'   => '2026-09-20',
            'status'             => 'approved',
        ]);

        // Member Buku Biru 1 (Saham: 6,000,000)
        $member1 = Member::create([
            'name'              => 'Anggota Biru 1',
            'nik'               => '1234567890123451',
            'member_number'     => '0001',
            'has_buku_biru'     => true,
            'principal_savings' => 1000000.00,
            'mandatory_savings' => 2000000.00,
            'voluntary_savings' => 3000000.00,
            'status'            => 'active',
            'created_at'        => Carbon::parse('2025-01-01'),
        ]);

        // Member Buku Biru 2 (Saham: 2,000,000)
        $member2 = Member::create([
            'name'              => 'Anggota Biru 2',
            'nik'               => '1234567890123452',
            'member_number'     => '0002',
            'has_buku_biru'     => true,
            'principal_savings' => 500000.00,
            'mandatory_savings' => 1500000.00,
            'voluntary_savings' => 0.00,
            'status'            => 'active',
            'created_at'        => Carbon::parse('2025-01-01'),
        ]);

        // Member Buku Putih murni (Harus dilewati)
        $memberWhite = Member::create([
            'name'              => 'Anggota Putih Murni',
            'nik'               => '1234567890123453',
            'member_number'     => 'BP-0001',
            'has_buku_biru'     => false,
            'has_buku_putih'    => true,
            'daily_savings'     => 5000000.00,
            'principal_savings' => 0.00,
            'mandatory_savings' => 0.00,
            'voluntary_savings' => 0.00,
            'status'            => 'active',
            'created_at'        => Carbon::parse('2025-01-01'),
        ]);

        $response = $this->getJson('/api/manager/dividends/preview?year=2026&month=9&percentage=25');

        $response->assertStatus(200);
        $response->assertJson([
            'success' => true,
            'status'  => 'success',
            'data'    => [
                'summary' => [
                    'month'                   => 9,
                    'year'                    => 2026,
                    'percentage'              => 25.0,
                    'total_income'            => 10000000.00,
                    'total_expense'           => 2000000.00,
                    'shu_bersih'              => 8000000.00,
                    'dividend_pool'           => 2000000.00, // 25% dari 8,000,000
                    'total_buku_biru_members' => 2,
                    'eligible_members_count'  => 2,
                    'total_eligible_shares'   => 8000000.00,
                ],
            ],
        ]);

        $data = $response->json('data');
        $this->assertCount(2, $data['members']);
        
        // Member 1: Saham 6,000,000 dari 8,000,000 (75%) -> Deviden 1,500,000
        $this->assertEquals(1500000.00, $data['members'][0]['deviden_amount']);
        $this->assertEquals(75.0, $data['members'][0]['share_percentage']);

        // Member 2: Saham 2,000,000 dari 8,000,000 (25%) -> Deviden 500,000
        $this->assertEquals(500000.00, $data['members'][1]['deviden_amount']);
        $this->assertEquals(25.0, $data['members'][1]['share_percentage']);
    }

    public function test_sw_arrears_rule_excludes_ineligible_members()
    {
        Sanctum::actingAs($this->manager);

        Transaction::create([
            'transaction_number' => 'KM-TEST-02',
            'receipt_number'     => 'KM 0002',
            'type'               => 'KM',
            'amount'             => 4000000.00,
            'transaction_date'   => '2026-09-15',
            'status'             => 'approved',
        ]);

        // Anggota 1: Normal dan aktif
        Member::create([
            'name'              => 'Anggota Normal',
            'nik'               => '1234567890123454',
            'member_number'     => '0010',
            'has_buku_biru'     => true,
            'principal_savings' => 1000000.00,
            'mandatory_savings' => 500000.00,
            'voluntary_savings' => 500000.00,
            'status'            => 'active',
            'created_at'        => Carbon::parse('2025-01-01'),
        ]);

        // Anggota 2: Terdaftar > 6 bulan lalu, SW <= 20000, dan tidak pernah setor dalam 6 bulan terakhir -> Gugur Hak SHU
        Member::create([
            'name'              => 'Anggota Menunggak SW',
            'nik'               => '1234567890123455',
            'member_number'     => '0011',
            'has_buku_biru'     => true,
            'principal_savings' => 100000.00,
            'mandatory_savings' => 20000.00,
            'voluntary_savings' => 10000.00,
            'status'            => 'active',
            'created_at'        => Carbon::parse('2025-01-01'),
        ]);

        $response = $this->getJson('/api/manager/dividends/preview?year=2026&month=9&percentage=25');
        $response->assertStatus(200);

        $summary = $response->json('data.summary');
        $this->assertEquals(1, $summary['eligible_members_count']);
        $this->assertEquals(1, $summary['sw_arrears_count']);

        $members = $response->json('data.members');
        $this->assertTrue($members[0]['is_eligible']);
        $this->assertFalse($members[1]['is_eligible']);
        $this->assertTrue($members[1]['is_sw_arrears']);
        $this->assertEquals('Gugur Hak SHU - Tunggakan SW ≥ 6 Bulan', $members[1]['ineligibility_reason']);
        $this->assertEquals(0.0, $members[1]['deviden_amount']);
    }

    public function test_distribute_mutates_voluntary_savings_and_creates_journal_and_shu_distribution()
    {
        Sanctum::actingAs($this->manager);

        Transaction::create([
            'transaction_number' => 'KM-TEST-03',
            'receipt_number'     => 'KM 0003',
            'type'               => 'KM',
            'amount'             => 10000000.00,
            'transaction_date'   => '2026-09-10',
            'status'             => 'approved',
        ]);

        $member = Member::create([
            'name'              => 'Bapak Marbun',
            'nik'               => '1234567890123456',
            'member_number'     => '0020',
            'has_buku_biru'     => true,
            'principal_savings' => 1000000.00,
            'mandatory_savings' => 3000000.00,
            'voluntary_savings' => 1000000.00,
            'status'            => 'active',
            'created_at'        => Carbon::parse('2025-01-01'),
        ]);

        $payload = [
            'month'      => 9,
            'year'       => 2026,
            'percentage' => 25,
            'voucher_no' => 'BM-DIV-202609',
            'notes'      => 'Pembagian Deviden Buku Biru September 2026',
        ];

        $response = $this->postJson('/api/manager/dividends/distribute', $payload);

        $response->assertStatus(200);
        $response->assertJson([
            'success' => true,
            'status'  => 'success',
            'data'    => [
                'processed_count'   => 1,
                'total_distributed' => 2500000.00,
                'voucher_number'    => 'BM-DIV-202609',
            ],
        ]);

        // Verifikasi saldo Simpanan Sukarela bertambah (1,000,000 + 2,500,000 = 3,500,000)
        $member->refresh();
        $this->assertEquals(3500000.00, (float) $member->voluntary_savings);

        // Verifikasi mutasi transaksi
        $this->assertDatabaseHas('transactions', [
            'member_id'        => $member->id,
            'book_type'        => 'BUKU_BIRU',
            'category'         => 'bunga_saham',
            'type'             => 'deposit',
            'amount'           => 2500000.00,
            'receipt_number'   => 'BM-DIV-202609',
            'status'           => 'approved',
        ]);

        // Verifikasi record shu_distributions
        $this->assertDatabaseHas('shu_distributions', [
            'member_id'        => $member->id,
            'member_number'    => '0020',
            'deviden'          => 2500000.00,
            'net_shu'          => 2500000.00,
            'status'           => 'distributed',
        ]);

        // Verifikasi Jurnal Umum & Detail Double-Entry
        $journal = JournalEntry::where('voucher_number', 'BM-DIV-202609')->first();
        $this->assertNotNull($journal);
        $this->assertTrue($journal->is_balanced);
        $this->assertEquals(2500000.00, $journal->total_debit);
        $this->assertEquals(2500000.00, $journal->total_credit);

        // Debet: 7145, Kredit: 2020
        $this->assertDatabaseHas('journal_details', [
            'journal_entry_id' => $journal->id,
            'account_id'       => $this->coaBeban->id,
            'debit'            => 2500000.00,
            'credit'           => 0.00,
        ]);

        $this->assertDatabaseHas('journal_details', [
            'journal_entry_id' => $journal->id,
            'account_id'       => $this->coaSukarela->id,
            'debit'            => 0.00,
            'credit'           => 2500000.00,
        ]);
    }

    public function test_distribute_rejects_duplicate_voucher_number()
    {
        Sanctum::actingAs($this->manager);

        Transaction::create([
            'transaction_number' => 'KM-TEST-04',
            'receipt_number'     => 'KM 0004',
            'type'               => 'KM',
            'amount'             => 5000000.00,
            'transaction_date'   => '2026-09-10',
            'status'             => 'approved',
        ]);

        Member::create([
            'name'              => 'Bapak Simanjuntak',
            'nik'               => '1234567890123457',
            'member_number'     => '0030',
            'has_buku_biru'     => true,
            'principal_savings' => 1000000.00,
            'mandatory_savings' => 1000000.00,
            'voluntary_savings' => 0.00,
            'status'            => 'active',
        ]);

        // Percobaan 1: Berhasil
        $response1 = $this->postJson('/api/manager/dividends/distribute', [
            'month'      => 9,
            'year'       => 2026,
            'percentage' => 25,
            'voucher_no' => 'BM-DIV-DUP',
        ]);
        $response1->assertStatus(200);

        // Percobaan 2 dengan voucher_no yang sama: Ditolak dengan 422
        $response2 = $this->postJson('/api/manager/dividends/distribute', [
            'month'      => 10,
            'year'       => 2026,
            'percentage' => 25,
            'voucher_no' => 'BM-DIV-DUP',
        ]);
        $response2->assertStatus(422);
        $response2->assertJson([
            'success' => false,
            'status'  => 'error',
        ]);
    }

    public function test_export_dividend_pdf_success()
    {
        Sanctum::actingAs($this->manager);

        Transaction::create([
            'transaction_number' => 'KM-TEST-05',
            'receipt_number'     => 'KM 0005',
            'type'               => 'KM',
            'amount'             => 10000000.00,
            'transaction_date'   => '2026-09-10',
            'status'             => 'approved',
        ]);

        Member::create([
            'name'              => 'Bapak Test PDF',
            'nik'               => '1234567890123458',
            'member_number'     => '0040',
            'has_buku_biru'     => true,
            'principal_savings' => 1000000.00,
            'mandatory_savings' => 1000000.00,
            'voluntary_savings' => 500000.00,
            'status'            => 'active',
        ]);

        $response = $this->get('/api/manager/dividends/export-pdf?month=9&year=2026&percentage=25');

        $response->assertStatus(200);
        $this->assertEquals('application/pdf', $response->headers->get('Content-Type'));
        $this->assertStringContainsString('Laporan_Pembagian_Deviden_Buku_Biru_9_2026.pdf', $response->headers->get('Content-Disposition') ?? '');
    }

    public function test_member_dividend_statement_and_pdf_export()
    {
        Sanctum::actingAs($this->manager);

        $member = Member::create([
            'name'              => 'Ibu Hotmaida',
            'nik'               => '1234567890123499',
            'member_number'     => '0099',
            'has_buku_biru'     => true,
            'principal_savings' => 50000.00,
            'mandatory_savings' => 120000.00,
            'voluntary_savings' => 1000000.00,
            'status'            => 'active',
        ]);

        Transaction::create([
            'transaction_number' => 'KM-TEST-06',
            'receipt_number'     => 'KM 0006',
            'member_id'          => $member->id,
            'book_type'          => 'BUKU_BIRU',
            'type'               => 'KM',
            'category'           => 'simpanan_wajib',
            'amount'             => 20000.00,
            'transaction_date'   => '2026-09-15',
            'description'        => 'Setoran Simpanan Wajib September',
            'status'             => 'approved',
        ]);

        // Test Endpoint JSON Statement
        $response = $this->getJson("/api/manager/members/{$member->id}/dividend-statement?fiscal_year=2027");
        $response->assertStatus(200);
        $response->assertJson([
            'success' => true,
            'status'  => 'success',
            'data'    => [
                'member' => [
                    'id'            => $member->id,
                    'name'          => 'Ibu Hotmaida',
                    'member_number' => '0099',
                ],
                'fiscal_year' => 2027,
            ],
        ]);

        $data = $response->json('data');
        $this->assertCount(12, $data['monthly_records']);
        $this->assertCount(12, $data['coop_benchmarks']);
        $this->assertArrayHasKey('rekapitulasi', $data);

        // Test Endpoint PDF Statement
        $pdfResponse = $this->get("/api/manager/members/{$member->id}/dividend-statement/export-pdf?fiscal_year=2027");
        $pdfResponse->assertStatus(200);
        $this->assertEquals('application/pdf', $pdfResponse->headers->get('Content-Type'));
        $this->assertStringContainsString('Buku_Saham_Deviden_Anggota_0099_2027.pdf', $pdfResponse->headers->get('Content-Disposition') ?? '');
    }

    public function test_cycle_cutoff_and_mathematical_precision()
    {
        Sanctum::actingAs($this->manager);

        // 1. Transaksi di luar siklus (20 Agustus 2026 -> tidak boleh masuk siklus September)
        Transaction::create([
            'transaction_number' => 'KM-OUT-01',
            'receipt_number'     => 'KM OUT1',
            'type'               => 'KM',
            'category'           => 'pendapatan_lain',
            'amount'             => 5000000.00,
            'transaction_date'   => '2026-08-20',
            'status'             => 'approved',
        ]);

        // 2. Transaksi di awal siklus (21 Agustus 2026 -> masuk siklus September)
        Transaction::create([
            'transaction_number' => 'KM-IN-01',
            'receipt_number'     => 'KM IN1',
            'type'               => 'KM',
            'category'           => 'jasa_pinjaman',
            'amount'             => 10000000.00,
            'transaction_date'   => '2026-08-21',
            'status'             => 'approved',
        ]);

        // 3. Transaksi di akhir siklus (20 September 2026 -> masuk siklus September)
        Transaction::create([
            'transaction_number' => 'KK-IN-01',
            'receipt_number'     => 'KK IN1',
            'type'               => 'KK',
            'category'           => 'beban_operasional',
            'amount'             => 2000000.00,
            'transaction_date'   => '2026-09-20',
            'status'             => 'approved',
        ]);

        // 4. Transaksi setelah siklus (21 September 2026 -> tidak boleh masuk siklus September)
        Transaction::create([
            'transaction_number' => 'KM-OUT-02',
            'receipt_number'     => 'KM OUT2',
            'type'               => 'KM',
            'category'           => 'pendapatan_lain',
            'amount'             => 7000000.00,
            'transaction_date'   => '2026-09-21',
            'status'             => 'approved',
        ]);

        // Anggota 1: Saham 8,000,000 (SP: 1M, SW: 3M, SS: 4M) -> Lembar = 8,000
        $memberA = Member::create([
            'name'              => 'Anggota A',
            'nik'               => '1234567890123471',
            'member_number'     => '0101',
            'has_buku_biru'     => true,
            'principal_savings' => 1000000.00,
            'mandatory_savings' => 3000000.00,
            'voluntary_savings' => 4000000.00,
            'status'            => 'active',
            'created_at'        => Carbon::parse('2025-01-01'),
        ]);

        // Anggota 2: Saham 2,000,000 (SP: 500k, SW: 1.5M, SS: 0) -> Lembar = 2,000
        $memberB = Member::create([
            'name'              => 'Anggota B',
            'nik'               => '1234567890123472',
            'member_number'     => '0102',
            'has_buku_biru'     => true,
            'principal_savings' => 500000.00,
            'mandatory_savings' => 1500000.00,
            'voluntary_savings' => 0.00,
            'status'            => 'active',
            'created_at'        => Carbon::parse('2025-01-01'),
        ]);

        // Setoran SW Siklus September agar berhak Jasa Saham
        Transaction::create([
            'transaction_number' => 'KM-SW-A',
            'receipt_number'     => 'KM 0101',
            'member_id'          => $memberA->id,
            'book_type'          => 'BUKU_BIRU',
            'type'               => 'deposit',
            'category'           => 'simpanan_wajib',
            'amount'             => 20000.00,
            'transaction_date'   => '2026-09-01',
            'description'        => 'Simpanan Wajib September',
            'status'             => 'approved',
        ]);
        $memberA->increment('mandatory_savings', 20000.00);

        Transaction::create([
            'transaction_number' => 'KM-SW-B',
            'receipt_number'     => 'KM 0102',
            'member_id'          => $memberB->id,
            'book_type'          => 'BUKU_BIRU',
            'type'               => 'deposit',
            'category'           => 'simpanan_wajib',
            'amount'             => 20000.00,
            'transaction_date'   => '2026-09-01',
            'description'        => 'Simpanan Wajib September',
            'status'             => 'approved',
        ]);
        $memberB->increment('mandatory_savings', 20000.00);

        $response = $this->getJson('/api/manager/dividends/preview?year=2026&month=9&percentage=25');
        $response->assertStatus(200);

        $summary = $response->json('data.summary');
        
        // Cek SHU siklus 21-20 (10,000,000 - 2,000,000 = 8,000,000)
        $this->assertEquals(10000000.00, $summary['total_income']);
        $this->assertEquals(2000000.00, $summary['total_expense']);
        $this->assertEquals(8000000.00, $summary['shu_bersih']);
        $this->assertEquals(2000000.00, $summary['dividend_pool']); // 25% dari 8jt

        // Total Saham Koperasi = 10,040,000 -> Total Lembar = 10,040
        $this->assertEquals(10040000.00, $summary['total_eligible_shares']);
        $this->assertEquals(10040.00, $summary['total_lembar_koperasi']);

        $members = $response->json('data.members');
        // Anggota A: Dasar Jasa = 8,020,000 - 20,000 = 8,000,000 -> Jasa = 48,000
        $this->assertEquals(8020.0, $members[0]['lembar_saham']);
        $this->assertEquals(48000.00, $members[0]['jasa_saham']); // 8,000,000 x 0.006 = 48,000

        // Anggota B: Dasar Jasa = 2,020,000 - 20,000 = 2,000,000 -> Jasa = 12,000
        $this->assertEquals(2020.0, $members[1]['lembar_saham']);
        $this->assertEquals(12000.00, $members[1]['jasa_saham']); // 2,000,000 x 0.006 = 12,000
    }

    public function test_oktober_cycle_and_30_september_transaction_and_dynamic_shares()
    {
        Sanctum::actingAs($this->manager);

        $member = Member::create([
            'name'              => 'RAWATI RAPINA S',
            'nik'               => '1234567890123999',
            'member_number'     => '2020-1995',
            'has_buku_biru'     => true,
            'principal_savings' => 200000.00,
            'mandatory_savings' => 600000.00,
            'voluntary_savings' => 821000.00,
            'status'            => 'active',
            'created_at'        => Carbon::parse('2020-01-01'),
        ]);

        // Transaksi tanggal 30 September 2025 (Wajib masuk ke siklus OKTOBER: 21 Sep - 20 Okt)
        Transaction::create([
            'transaction_number' => 'KM-TRX-30SEP',
            'receipt_number'     => 'KM 9988',
            'member_id'          => $member->id,
            'book_type'          => 'BUKU_BIRU',
            'type'               => 'deposit',
            'category'           => 'simpanan_sukarela',
            'amount'             => 50000.00,
            'transaction_date'   => '2025-09-30',
            'description'        => 'Simpanan Sukarela 30 September',
            'status'             => 'approved',
        ]);

        $response = $this->getJson("/api/manager/members/{$member->id}/dividend-statement?fiscal_year=2026");
        $response->assertStatus(200);

        $data = $response->json('data');
        $records = $data['monthly_records'];
        $benchmarks = $data['coop_benchmarks'];

        // Cek bahwa perulangan 12 bulan tepat (sesuai format DividendService)
        $expectedCodes = ['Jun', 'Jul', 'AUG', 'Sep', 'OKT', 'NOV', 'DES', 'Jan', 'FEB', 'MAR', 'APR', 'MEI'];
        $benchmarkCodes = array_map(fn($b) => $b['month_name'], $benchmarks);
        $this->assertEquals($expectedCodes, $benchmarkCodes);

        // Cari record transaksi 30 September
        $trx30SepRecord = collect($records)->first(fn($r) => str_starts_with($r['transaction_date'] ?? '', '30-Sep'));
        $this->assertNotNull($trx30SepRecord, 'Transaksi 30 September harus ada di records');
        $this->assertEquals('OKT', $trx30SepRecord['month_name'], 'Transaksi 30 September harus masuk siklus OKT');
        $this->assertEquals(10, $trx30SepRecord['month']);

        // Seed benchmark manual untuk bulan Oktober (siklus 21 Sep – 20 Okt 2025)
        // agar $shuBulan tidak 0 (sesuai perilaku baru: no dummy fallback)
        \App\Models\MonthlyCooperativeBenchmark::updateOrCreate(
            ['fiscal_year' => 2026, 'month' => 10],
            [
                'cycle_start_date'            => '2025-09-21',
                'cycle_end_date'              => '2025-10-20',
                'net_income'                  => 75000000.00,
                'dividend_allocation_percent' => 25.00,
            ]
        );

        $response2 = $this->getJson("/api/manager/members/{$member->id}/dividend-statement?fiscal_year=2026");
        $response2->assertStatus(200);
        $data2      = $response2->json('data');
        $benchmarks = $data2['coop_benchmarks'];

        // Cek bahwa benchmark koperasi memiliki 12 bulan
        $this->assertCount(12, $benchmarks);

        // Verifikasi bahwa bulan Oktober (yang punya benchmark manual) menghasilkan dana_deviden > 0
        $oktBenchmark = collect($benchmarks)->firstWhere('month_name', 'OKT');
        $this->assertNotNull($oktBenchmark, 'Benchmark OKT harus ada');
        $this->assertGreaterThan(0, $oktBenchmark['total_saham_koperasi']);
        $this->assertGreaterThan(0, $oktBenchmark['jumlah_lembar_koperasi']);
        $this->assertGreaterThan(0, $oktBenchmark['dana_deviden_25']);

        // Bulan tanpa data riil dan tanpa benchmark manual: dana_deviden boleh = 0 (tidak ada dummy)
        foreach ($benchmarks as $b) {
            $this->assertGreaterThanOrEqual(0, $b['dana_deviden_25']);
            $this->assertGreaterThanOrEqual(0, $b['total_saham_koperasi']);
        }
    }

    public function test_jasa_saham_applies_accrual_n_plus_one_rule()
    {
        Sanctum::actingAs($this->manager);

        // Anggota terdaftar sejak 2025 dengan saldo awal SP=1.000.000, SW=2.000.000, SS=2.000.000 (Total Saham = 5.000.000)
        $member = Member::create([
            'name'              => 'Bapak Lag Jasa',
            'nik'               => '1234567890123911',
            'member_number'     => '0911',
            'has_buku_biru'     => true,
            'principal_savings' => 1000000.00,
            'mandatory_savings' => 2000000.00,
            'voluntary_savings' => 2000000.00,
            'status'            => 'active',
            'created_at'        => Carbon::parse('2025-01-01'),
        ]);

        // Transaksi setoran SW pada September (21 Agu - 20 Sep) agar memenuhi syarat Jasa Saham
        Transaction::create([
            'transaction_number' => 'KM-TRX-SW-SEP',
            'receipt_number'     => 'KM 2501',
            'member_id'          => $member->id,
            'book_type'          => 'BUKU_BIRU',
            'type'               => 'deposit',
            'category'           => 'simpanan_wajib',
            'amount'             => 20000.00,
            'transaction_date'   => '2026-08-25',
            'description'        => 'Setoran SW 25 Agustus',
            'status'             => 'approved',
        ]);
        $member->increment('mandatory_savings', 20000.00);

        // Transaksi setoran baru SS pada 25 Agustus 2026 (masuk siklus September / M: 21 Agu - 20 Sep) sebesar Rp 5.000.000
        Transaction::create([
            'transaction_number' => 'KM-TRX-25AUG',
            'receipt_number'     => 'KM 2508',
            'member_id'          => $member->id,
            'book_type'          => 'BUKU_BIRU',
            'type'               => 'deposit',
            'category'           => 'simpanan_sukarela',
            'amount'             => 5000000.00,
            'transaction_date'   => '2026-08-25',
            'description'        => 'Setoran SS 25 Agustus',
            'status'             => 'approved',
        ]);
        $member->increment('voluntary_savings', 5000000.00);

        // Transaksi setoran baru SS pada 5 September 2026 (masuk siklus September / M: 21 Agu - 20 Sep) sebesar Rp 2.000.000
        Transaction::create([
            'transaction_number' => 'KM-TRX-05SEP',
            'receipt_number'     => 'KM 0509',
            'member_id'          => $member->id,
            'book_type'          => 'BUKU_BIRU',
            'type'               => 'deposit',
            'category'           => 'simpanan_sukarela',
            'amount'             => 2000000.00,
            'transaction_date'   => '2026-09-05',
            'description'        => 'Setoran SS 5 September',
            'status'             => 'approved',
        ]);
        $member->increment('voluntary_savings', 2000000.00);

        // Total Saham saat ini = 12.020.000 (SP 1M + SW 2.02M + SS 9M)
        // Setoran siklus September (5jt + 2jt + 20rb = 7.02jt) baru berbunga di bulan Oktober (M+1)
        // Dasar Jasa September = 12.020.000 - 7.020.000 = 5.000.000
        // Jasa September = 5.000.000 * 0.006 = 30.000
        $responseSep = $this->getJson('/api/manager/dividends/preview?month=9&year=2026&percentage=25');
        $responseSep->assertStatus(200);

        $memberDataSep = collect($responseSep->json('data.members'))->firstWhere('member_id', $member->id);
        $this->assertNotNull($memberDataSep);
        $this->assertEquals(12020000.00, $memberDataSep['total_saham']);
        $this->assertEquals(5000000.00, $memberDataSep['qualifying_saham']);
        $this->assertEquals(30000.00, $memberDataSep['jasa_saham']); // 5.000.000 * 0.006 = 30.000
    }

    public function test_jasa_saham_applies_direct_deduction_on_withdrawal()
    {
        Sanctum::actingAs($this->manager);

        // Anggota terdaftar sejak 2025 dengan saldo awal SP=1.000.000, SW=4.000.000, SS=5.000.000 (Total Saham = 10.000.000)
        $member = Member::create([
            'name'              => 'Bapak Proteksi Penarikan',
            'nik'               => '1234567890123912',
            'member_number'     => '0912',
            'has_buku_biru'     => true,
            'principal_savings' => 1000000.00,
            'mandatory_savings' => 4000000.00,
            'voluntary_savings' => 5000000.00,
            'status'            => 'active',
            'created_at'        => Carbon::parse('2025-01-01'),
        ]);

        // Setoran SW siklus September agar berhak Jasa Saham
        Transaction::create([
            'transaction_number' => 'KM-TRX-SW-PROT',
            'receipt_number'     => 'KM 2502',
            'member_id'          => $member->id,
            'book_type'          => 'BUKU_BIRU',
            'type'               => 'deposit',
            'category'           => 'simpanan_wajib',
            'amount'             => 20000.00,
            'transaction_date'   => '2026-08-25',
            'description'        => 'Setoran SW 25 Agustus',
            'status'             => 'approved',
        ]);
        $member->increment('mandatory_savings', 20000.00);

        // Penarikan SS pada 25 Agustus 2026 (masuk siklus September: 21 Agu - 20 Sep) sebesar Rp 3.000.000
        Transaction::create([
            'transaction_number' => 'KK-TRX-25AUG',
            'receipt_number'     => 'KK 2508',
            'member_id'          => $member->id,
            'book_type'          => 'BUKU_BIRU',
            'type'               => 'withdrawal',
            'category'           => 'simpanan_sukarela',
            'amount'             => 3000000.00,
            'transaction_date'   => '2026-08-25',
            'description'        => 'Penarikan SS 25 Agustus',
            'status'             => 'approved',
        ]);
        $member->decrement('voluntary_savings', 3000000.00);

        // Setoran baru SS pada 5 September 2026 (masuk siklus September: 21 Agu - 20 Sep) sebesar Rp 5.000.000
        Transaction::create([
            'transaction_number' => 'KM-TRX-05SEP',
            'receipt_number'     => 'KM 0509',
            'member_id'          => $member->id,
            'book_type'          => 'BUKU_BIRU',
            'type'               => 'deposit',
            'category'           => 'simpanan_sukarela',
            'amount'             => 5000000.00,
            'transaction_date'   => '2026-09-05',
            'description'        => 'Setoran SS 5 September',
            'status'             => 'approved',
        ]);
        $member->increment('voluntary_savings', 5000000.00);

        // Total Saham saat ini = 12.020.000 (10jt + 20rb - 3jt + 5jt)
        // Dasar Jasa September = Saldo Awal (10jt) - Penarikan (3jt) = 7.000.000
        // (atau Saldo Akhir 12.02jt - Setoran Siklus 5.02jt = 7.000.000)
        // Jasa September = 7.000.000 * 0.006 = 42.000
        $response = $this->getJson('/api/manager/dividends/preview?month=9&year=2026&percentage=25');
        $response->assertStatus(200);

        $memberData = collect($response->json('data.members'))->firstWhere('member_id', $member->id);
        $this->assertNotNull($memberData);
        $this->assertEquals(12020000.00, $memberData['total_saham']);
        $this->assertEquals(7000000.00, $memberData['qualifying_saham']);
        $this->assertEquals(42000.00, $memberData['jasa_saham']);
    }

    public function test_member_dividend_statement_shows_accrual_n_plus_one_and_withdrawal_deduction()
    {
        Sanctum::actingAs($this->manager);

        $member = Member::create([
            'name'              => 'Ibu Statement Jasa',
            'nik'               => '1234567890123913',
            'member_number'     => '0913',
            'has_buku_biru'     => true,
            'principal_savings' => 200000.00,
            'mandatory_savings' => 800000.00,
            'voluntary_savings' => 1000000.00, // Total = 2.000.000
            'status'            => 'active',
            'created_at'        => Carbon::parse('2024-01-01'),
        ]);

        // Setoran SW rutin tiap bulan dari Juni s/d Oktober
        $monthlySwDates = [
            6 => '2026-06-10',
            7 => '2026-07-10',
            8 => '2026-08-10',
            9 => '2026-09-10',
            10 => '2026-10-10',
        ];
        foreach ($monthlySwDates as $mIdx => $swDate) {
            Transaction::create([
                'transaction_number' => "KM-SW-{$mIdx}",
                'receipt_number'     => "KM 0{$mIdx}",
                'member_id'          => $member->id,
                'book_type'          => 'BUKU_BIRU',
                'type'               => 'deposit',
                'category'           => 'simpanan_wajib',
                'amount'             => 20000.00,
                'transaction_date'   => $swDate,
                'description'        => "Setoran SW Bulan {$mIdx}",
                'status'             => 'approved',
            ]);
            $member->increment('mandatory_savings', 20000.00);
        }

        // Setoran baru SS di bulan Juli 2026 (siklus Agustus: 21 Juli - 20 Agustus) sebesar 1.000.000
        Transaction::create([
            'transaction_number' => 'KM-TRX-JULY',
            'receipt_number'     => 'KM 0725',
            'member_id'          => $member->id,
            'book_type'          => 'BUKU_BIRU',
            'type'               => 'deposit',
            'category'           => 'simpanan_sukarela',
            'amount'             => 1000000.00,
            'transaction_date'   => '2026-07-25',
            'description'        => 'Setoran SS 25 Juli',
            'status'             => 'approved',
        ]);
        $member->increment('voluntary_savings', 1000000.00);

        $response = $this->getJson("/api/manager/members/{$member->id}/dividend-statement?fiscal_year=2027");
        $response->assertStatus(200);

        $data = $response->json('data');
        $records = $data['monthly_records'];

        // Cek baris Juni (M=6, Saldo = 2.000.000) -> Jasa = 2.000.000 * 0.006 = 12.000
        $junRecord = collect($records)->firstWhere('month', 6);
        $this->assertEquals(12000.00, $junRecord['jasa_saham']);

        // Cek baris Juli (M=7, Saldo Awal = 2.020.000, Setoran SW Juli dihitung di Agust) -> Jasa = 2.020.000 * 0.006 = 12.120
        $julRecord = collect($records)->firstWhere('month', 7);
        $this->assertEquals(12120.00, $julRecord['jasa_saham']);

        // Cek baris Agustus (M=8, Saldo Awal = 2.040.000, Setoran SS 25 Juli & SW Agust dihitung di Sept) -> Jasa = 2.040.000 * 0.006 = 12.240
        $augRecord = collect($records)->firstWhere('month', 8);
        $this->assertEquals(12240.00, $augRecord['jasa_saham']);
        $this->assertEquals(true, $augRecord['has_paid_sw']);

        // Cek baris November (M=11, Tidak ada setoran SW) -> Jasa = 0.0 (Sanksi SW)
        $novRecord = collect($records)->firstWhere('month', 11);
        $this->assertEquals(0.0, $novRecord['jasa_saham']);
        $this->assertEquals(false, $novRecord['has_paid_sw']);
    }

    public function test_admin_cannot_access_dividend_distribution()
    {
        $admin = User::factory()->create([
            'role' => 'admin',
            'name' => 'Admin Koperasi',
        ]);

        Sanctum::actingAs($admin);

        $response = $this->postJson('/api/dividends/distribute', [
            'month' => 9,
            'year'  => 2026,
        ]);

        $response->assertStatus(403);
    }
}

