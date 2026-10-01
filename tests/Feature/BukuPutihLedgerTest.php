<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\AccountingPeriod;
use App\Models\Member;
use App\Models\Transaction;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class BukuPutihLedgerTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;
    protected User $manager;
    protected Account $kasAccount;
    protected Member $member;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(\Database\Seeders\ChartOfAccountSeeder::class);

        $this->admin = User::create([
            'name'     => 'Admin Kasir',
            'email'    => 'admin.kasir@koperasi.com',
            'password' => bcrypt('password123'),
            'role'     => 'admin',
            'nik'      => '1122334455667701',
        ]);

        $this->manager = User::create([
            'name'     => 'Manager Koperasi',
            'email'    => 'manager.ledger@koperasi.com',
            'password' => bcrypt('password123'),
            'role'     => 'manager',
            'nik'      => '1122334455667702',
        ]);

        $this->kasAccount = Account::firstOrCreate(
            ['account_number' => 'KAS-101'],
            [
                'account_name'      => 'Kas Koperasi',
                'account_type'      => 'kas',
                'category'          => 'asset',
                'balance'           => 10000000.00,
                'beginning_balance' => 10000000.00,
            ]
        );

        $this->member = Member::create([
            'member_number'     => '0017',
            'name'              => 'Basalina Hutauruk',
            'nik'               => '1234567890123456',
            'phone'             => '081234567890',
            'principal_savings' => 1000000.00,
            'mandatory_savings' => 500000.00,
            'voluntary_savings' => 2000000.00,
            'daily_savings'     => 1000000.00,
            'has_buku_biru'     => true,
            'has_buku_putih'    => true,
            'buku_putih_no'     => '2021-0017',
            'status'            => 'active',
            'created_at'        => Carbon::parse('2025-01-01'),
        ]);
    }

    public function test_get_buku_putih_ledger_returns_multi_row_cycles_and_m_minus_1_interest_for_active_member(): void
    {
        Sanctum::actingAs($this->manager);

        // Buat mutasi kas riil di 6 bulan berbeda agar anggota berstatus "AKTIF"
        // 1. Siklus Juni (21 Mei - 20 Juni)
        Transaction::create([
            'transaction_number' => 'KM-BP-001',
            'receipt_number'     => 'KM 0525',
            'member_id'          => $this->member->id,
            'account_id'         => $this->kasAccount->id,
            'book_type'          => 'BUKU_PUTIH',
            'type'               => 'deposit',
            'amount'             => 500000.00,
            'transaction_date'   => '2026-05-25',
            'description'        => 'Setoran Simpanan Harian Mei',
            'status'             => 'approved',
        ]);
        Transaction::create([
            'transaction_number' => 'KK-BP-001',
            'receipt_number'     => 'KK 0605',
            'member_id'          => $this->member->id,
            'account_id'         => $this->kasAccount->id,
            'book_type'          => 'BUKU_PUTIH',
            'type'               => 'withdrawal',
            'amount'             => 200000.00,
            'transaction_date'   => '2026-06-05',
            'description'        => 'Penarikan Simpanan Harian Juni',
            'status'             => 'approved',
        ]);

        // 2. Siklus Juli (21 Juni - 20 Juli)
        Transaction::create([
            'transaction_number' => 'KM-BP-002',
            'receipt_number'     => 'KM 0705',
            'member_id'          => $this->member->id,
            'account_id'         => $this->kasAccount->id,
            'book_type'          => 'BUKU_PUTIH',
            'type'               => 'deposit',
            'amount'             => 100000.00,
            'transaction_date'   => '2026-07-05',
            'description'        => 'Setoran Juli',
            'status'             => 'approved',
        ]);

        // 3. Siklus Agustus (21 Juli - 20 Ags)
        Transaction::create([
            'transaction_number' => 'KM-BP-003',
            'receipt_number'     => 'KM 0805',
            'member_id'          => $this->member->id,
            'account_id'         => $this->kasAccount->id,
            'book_type'          => 'BUKU_PUTIH',
            'type'               => 'deposit',
            'amount'             => 100000.00,
            'transaction_date'   => '2026-08-05',
            'description'        => 'Setoran Agustus',
            'status'             => 'approved',
        ]);

        // 4. Siklus September (21 Ags - 20 Sep)
        Transaction::create([
            'transaction_number' => 'KM-BP-004',
            'receipt_number'     => 'KM 0904',
            'member_id'          => $this->member->id,
            'account_id'         => $this->kasAccount->id,
            'book_type'          => 'BUKU_PUTIH',
            'type'               => 'deposit',
            'amount'             => 500000.00,
            'transaction_date'   => '2026-09-04',
            'description'        => 'Setoran Simpanan Harian 4 Sept',
            'status'             => 'approved',
        ]);

        // 5. Siklus Oktober (21 Sep - 20 Okt)
        Transaction::create([
            'transaction_number' => 'KM-BP-005',
            'receipt_number'     => 'KM 1005',
            'member_id'          => $this->member->id,
            'account_id'         => $this->kasAccount->id,
            'book_type'          => 'BUKU_PUTIH',
            'type'               => 'deposit',
            'amount'             => 100000.00,
            'transaction_date'   => '2026-10-05',
            'description'        => 'Setoran Oktober',
            'status'             => 'approved',
        ]);

        // 6. Siklus Mei (21 Apr - 20 Mei)
        Transaction::create([
            'transaction_number' => 'KM-BP-006',
            'receipt_number'     => 'KM 0505',
            'member_id'          => $this->member->id,
            'account_id'         => $this->kasAccount->id,
            'book_type'          => 'BUKU_PUTIH',
            'type'               => 'deposit',
            'amount'             => 100000.00,
            'transaction_date'   => '2027-05-05',
            'description'        => 'Setoran Mei',
            'status'             => 'approved',
        ]);

        $this->member->increment('daily_savings', 1200000.00);

        $response = $this->getJson("/api/members/{$this->member->id}/buku-putih-ledger?fiscal_year=2027");

        $response->assertStatus(200);
        $data = $response->json('data');

        // Validasi Payload Top-Level & Status Keaktifan
        $this->assertEquals($this->member->id, $data['member_id']);
        $this->assertEquals('2026/2027', $data['period']);
        $this->assertEquals('AKTIF', $data['status_keaktifan']);
        $this->assertTrue($data['is_active']);
        $this->assertEquals(6, $data['active_months_count']);
        $this->assertEquals(6, $data['passive_months_count']);
        $this->assertEquals(1000000.00, $data['opening_balance']);
        $this->assertArrayHasKey('cycles', $data);
        $this->assertCount(12, $data['cycles']);

        // 1. BULAN JUNI (M=6):
        // Memiliki 3 rows tersendiri (KM 0525, KK 0605, dan BM-INT-202606)
        $jun = collect($data['cycles'])->firstWhere('month', 6);
        $this->assertEquals('Juni 2026', $jun['month_name']);
        $this->assertEquals(1000000.00, $jun['opening_balance']);
        $this->assertEquals(6000.00, $jun['interest']);
        $this->assertEquals(500000.00, $jun['total_deposit']);
        $this->assertEquals(200000.00, $jun['total_withdrawal']);
        $this->assertEquals(1306000.00, $jun['closing_balance']);

        $rowsJun = $jun['rows'];
        $this->assertCount(3, $rowsJun);
        $this->assertEquals('KM', $rowsJun[0]['type']);
        $this->assertEquals('KM 0525', $rowsJun[0]['evidence_no']);
        $this->assertEquals(500000.00, $rowsJun[0]['deposit']);
        $this->assertEquals(1500000.00, $rowsJun[0]['balance']);

        $this->assertEquals('KK', $rowsJun[1]['type']);
        $this->assertEquals('KK 0605', $rowsJun[1]['evidence_no']);
        $this->assertEquals(200000.00, $rowsJun[1]['withdrawal']);
        $this->assertEquals(1300000.00, $rowsJun[1]['balance']);

        $this->assertEquals('BM', $rowsJun[2]['type']);
        $this->assertEquals('BM-INT-202606', $rowsJun[2]['evidence_no']);
        $this->assertEquals(6000.00, $rowsJun[2]['interest']);
        $this->assertEquals(1306000.00, $rowsJun[2]['balance']);
    }

    public function test_inactive_member_without_cash_transactions_loses_all_interest(): void
    {
        Sanctum::actingAs($this->manager);

        // Anggota TIDAK memiliki mutasi kas sama sekali di periode berjalan -> TIDAK AKTIF
        $response = $this->getJson("/api/members/{$this->member->id}/buku-putih-ledger?fiscal_year=2027");

        $response->assertStatus(200);
        $data = $response->json('data');

        // Status Keaktifan = TIDAK AKTIF
        $this->assertStringContainsString('TIDAK AKTIF', $data['status_keaktifan']);
        $this->assertFalse($data['is_active']);
        $this->assertEquals(0, $data['active_months_count']);
        $this->assertEquals(12, $data['passive_months_count']);

        // Seluruh bunga dihanguskan (total_interest = 0)
        $this->assertEquals(0.0, $data['total_interest']);
        $this->assertEquals(0.0, $data['summary']['total_jasa']);

        // Pastikan setiap siklus bulanan memiliki bunga 0
        foreach ($data['cycles'] as $cycle) {
            $this->assertEquals(0.0, $cycle['interest']);
            $this->assertEquals(0.0, $cycle['jasa']);
        }

        // Saldo akhir tetap sama dengan saldo awal (1.000.000)
        $this->assertEquals(1000000.00, $data['closing_balance']);
    }

    public function test_j_ambarita_single_deposit_in_september_is_active_with_full_interest(): void
    {
        Sanctum::actingAs($this->manager);

        // Kasus Nyata J. Ambarita: Saldo Awal Rp 50.000 per 31 Mei, Setor Rp 150.000 pada 17 Sept 2026
        $jAmbarita = Member::create([
            'member_number'  => '0888',
            'name'           => 'J. Ambarita',
            'nik'            => '1234567890120888',
            'phone'          => '081299998888',
            'daily_savings'  => 50000.00,
            'has_buku_putih' => true,
            'buku_putih_no'  => '2021-0888',
            'status'         => 'active',
        ]);

        Transaction::create([
            'transaction_number' => 'KM-BP-0888-0917',
            'receipt_number'     => 'KM 0917',
            'member_id'          => $jAmbarita->id,
            'account_id'         => $this->kasAccount->id,
            'book_type'          => 'BUKU_PUTIH',
            'type'               => 'deposit',
            'amount'             => 150000.00,
            'transaction_date'   => '2026-09-17', // Siklus September
            'description'        => 'Setoran Simpanan Harian 17 Sept',
            'status'             => 'approved',
        ]);
        $jAmbarita->increment('daily_savings', 150000.00);

        $response = $this->getJson("/api/members/{$jAmbarita->id}/buku-putih-ledger?fiscal_year=2027");
        $response->assertStatus(200);

        $data = $response->json('data');

        // Status WAJIB AKTIF
        $this->assertEquals('AKTIF', $data['status_keaktifan']);
        $this->assertTrue($data['is_active']);
        $this->assertEquals(50000.00, $data['opening_balance']);
        $this->assertEquals(150000.00, $data['total_deposit']);

        // Bunga 0,6% mengalir penuh 12 bulan:
        // Total Jasa = Rp 11.074 (pembulatan sen: 11.074,24)
        // Saldo Akhir = Rp 211.074 (pembulatan sen: 211.074,24)
        $this->assertEquals(11074.24, round($data['total_interest'], 2));
        $this->assertEquals(211074.24, round($data['closing_balance'], 2));

        // Verifikasi tidak ada bulan yang bunganya dipotong / 0 (selama saldo > 0)
        foreach ($data['cycles'] as $cycle) {
            $this->assertGreaterThan(0, $cycle['interest']);
        }
    }

    public function test_member_resets_inactivity_counter_on_new_cash_transaction(): void
    {
        Sanctum::actingAs($this->manager);

        // Anggota vakum 6 bulan (Juni s/d Nov), lalu di bulan Des (siklus Jan) atau Feb bertransaksi KM
        Transaction::create([
            'transaction_number' => 'KM-BP-001',
            'receipt_number'     => 'KM 0525',
            'member_id'          => $this->member->id,
            'account_id'         => $this->kasAccount->id,
            'book_type'          => 'BUKU_PUTIH',
            'type'               => 'deposit',
            'amount'             => 100000.00,
            'transaction_date'   => '2026-05-25', // Siklus Juni
            'description'        => 'Setoran Mei/Juni',
            'status'             => 'approved',
        ]);

        // Vakum 6 bulan berturut-turut: Juli, Ags, Sept, Okt, Nov, Des
        // Lalu di bulan Januari 2027 setor lagi -> Counter reset ke 0!
        Transaction::create([
            'transaction_number' => 'KM-BP-002',
            'receipt_number'     => 'KM 0105',
            'member_id'          => $this->member->id,
            'account_id'         => $this->kasAccount->id,
            'book_type'          => 'BUKU_PUTIH',
            'type'               => 'deposit',
            'amount'             => 200000.00,
            'transaction_date'   => '2027-01-05', // Siklus Jan
            'description'        => 'Setoran Jan (Reset)',
            'status'             => 'approved',
        ]);

        // Lalu di bulan Mei setor lagi -> Sisa bulan vakum hanya 3 bulan (Feb, Mar, Apr)
        Transaction::create([
            'transaction_number' => 'KM-BP-003',
            'receipt_number'     => 'KM 0505',
            'member_id'          => $this->member->id,
            'account_id'         => $this->kasAccount->id,
            'book_type'          => 'BUKU_PUTIH',
            'type'               => 'deposit',
            'amount'             => 100000.00,
            'transaction_date'   => '2027-05-05', // Siklus Mei
            'description'        => 'Setoran Mei (Reset)',
            'status'             => 'approved',
        ]);

        $this->member->increment('daily_savings', 400000.00);

        $response = $this->getJson("/api/members/{$this->member->id}/buku-putih-ledger?fiscal_year=2027");
        $response->assertStatus(200);

        $data = $response->json('data');
        $this->assertEquals('AKTIF', $data['status_keaktifan']);
        $this->assertTrue($data['is_active']);
    }

    public function test_evaluate_membership_status_helper_logic(): void
    {
        $ledgerService = app(\App\Services\BukuPutihLedgerService::class);

        // Simulasi 1: Kosong 6 bulan berturut-turut tanpa transaksi
        $cycles1 = [
            ['transactions' => [['type' => 'BM']]],
            ['transactions' => []],
            ['transactions' => []],
            ['transactions' => []],
            ['transactions' => []],
            ['transactions' => []],
            ['transactions' => []],
        ];
        $res1 = $ledgerService->evaluateMembershipStatus($cycles1);
        $this->assertStringContainsString('TIDAK AKTIF', $res1['status_keaktifan']);
        $this->assertFalse($res1['is_active']);

        // Simulasi 2: Kosong 5 bulan, bulan ke-6 setor KM -> Reset ke 0
        $cycles2 = [
            ['transactions' => []],
            ['transactions' => []],
            ['transactions' => []],
            ['transactions' => []],
            ['transactions' => []],
            ['transactions' => [['type' => 'KM']]], // Reset
            ['transactions' => []],
        ];
        $res2 = $ledgerService->evaluateMembershipStatus($cycles2);
        $this->assertEquals('AKTIF', $res2['status_keaktifan']);
        $this->assertTrue($res2['is_active']);
        $this->assertEquals(1, $res2['consecutive_inactive_months']);
    }

    public function test_manager_alias_route_and_filter_by_period_id(): void
    {
        Sanctum::actingAs($this->manager);

        $period = AccountingPeriod::create([
            'period_name' => 'Tahun Buku 2026/2027',
            'start_date'  => '2026-06-01',
            'end_date'    => '2027-05-31',
            'status'      => 'OPEN',
            'is_locked'   => false,
            'is_active'   => true,
        ]);

        $response = $this->getJson("/api/manager/members/{$this->member->id}/buku-putih-ledger?period_id={$period->id}");
        $response->assertStatus(200);

        $data = $response->json('data');
        $this->assertEquals('2026/2027', $data['period']);
        $this->assertEquals($period->id, $data['period_info']['id']);
        $this->assertEquals('Tahun Buku 2026/2027', $data['period_info']['period_name']);
        $this->assertEquals('Juni 2026 - Mei 2027', $data['period_info']['period_label']);
    }

    public function test_white_book_statement_endpoint_returns_consistent_calculation(): void
    {
        Sanctum::actingAs($this->manager);

        // Buat mutasi di 6 bulan tersebar
        $months = [
            ['m' => 6, 'y' => 2026],
            ['m' => 8, 'y' => 2026],
            ['m' => 10, 'y' => 2026],
            ['m' => 12, 'y' => 2026],
            ['m' => 2, 'y' => 2027],
            ['m' => 4, 'y' => 2027],
        ];
        foreach ($months as $item) {
            $m = $item['m'];
            $y = $item['y'];
            Transaction::create([
                'transaction_number' => "KM-BP-{$y}-{$m}",
                'receipt_number'     => "KM-{$y}-{$m}",
                'member_id'          => $this->member->id,
                'account_id'         => $this->kasAccount->id,
                'book_type'          => 'BUKU_PUTIH',
                'type'               => 'deposit',
                'amount'             => 10000.00,
                'transaction_date'   => "{$y}-" . str_pad((string) $m, 2, '0', STR_PAD_LEFT) . "-01",
                'description'        => "Setoran Bulan {$m}",
                'status'             => 'approved',
            ]);
        }
        $this->member->increment('daily_savings', 60000.00);

        $response = $this->getJson("/api/manager/members/{$this->member->id}/white-book-statement?fiscal_year=2027");
        $response->assertStatus(200);

        $data = $response->json('data');
        $this->assertEquals('AKTIF', $data['status_keaktifan']);
        $records = $data['monthly_records'];
        $this->assertCount(12, $records);

        // Bulan Juni jasa = 6000
        $jun = collect($records)->firstWhere('month', 6);
        $this->assertEquals(6000.00, $jun['interest']);
        $this->assertEquals(1016000.00, $jun['closing_balance']);
    }

    public function test_export_pdf_endpoints_success(): void
    {
        Sanctum::actingAs($this->manager);

        $response = $this->get("/api/members/{$this->member->id}/buku-putih-ledger/export-pdf?fiscal_year=2027");
        $response->assertStatus(200);
        $this->assertEquals('application/pdf', $response->headers->get('content-type'));

        $managerResponse = $this->get("/api/manager/members/{$this->member->id}/buku-putih-ledger/export-pdf?fiscal_year=2027");
        $managerResponse->assertStatus(200);
        $this->assertEquals('application/pdf', $managerResponse->headers->get('content-type'));

        $whiteBookPdf = $this->get("/api/manager/members/{$this->member->id}/white-book-statement/export-pdf?fiscal_year=2027");
        $whiteBookPdf->assertStatus(200);
        $this->assertEquals('application/pdf', $whiteBookPdf->headers->get('content-type'));
    }

    public function test_buku_putih_mutation_maps_correctly_to_october_cycle_with_october_transaction_date(): void
    {
        Sanctum::actingAs($this->manager);

        // 1. Input transaksi tanggal 18 Oktober 2026 via endpoint /api/daily-transactions
        $postResponse = $this->postJson('/api/daily-transactions', [
            'member_id'        => $this->member->id,
            'payment_method'   => 'cash',
            'transaction_date' => '2026-10-18',
            'type'             => 'deposit',
            'items'            => [
                [
                    'account_code' => '2021',
                    'amount'       => 250000.00,
                    'description'  => 'Setoran Simpanan Harian 18 Oktober',
                ],
            ],
            'receipt_number'   => 'KM 1018',
        ]);

        $postResponse->assertStatus(200);

        // 2. Cek mutasi ledger Buku Putih
        $response = $this->getJson("/api/members/{$this->member->id}/buku-putih-ledger?fiscal_year=2027");
        $response->assertStatus(200);

        $data = $response->json('data');
        $cycles = $data['cycles'];

        // Siklus September (21 Agu - 20 Sep 2026) TIDAK boleh memuat transaksi 18/10
        $sep = collect($cycles)->firstWhere('month', 9);
        $this->assertNotNull($sep);
        $sepTrxRows = collect($sep['rows'] ?? [])->filter(fn($r) => ($r['type'] ?? '') === 'KM');
        $this->assertCount(0, $sepTrxRows, 'Siklus September tidak boleh memiliki transaksi KM dari Oktober');

        // Siklus Oktober (21 Sep - 20 Okt 2026) WAJIB memuat transaksi tanggal 2026-10-18
        $okt = collect($cycles)->firstWhere('month', 10);
        $this->assertNotNull($okt);
        $oktTrxRows = collect($okt['rows'] ?? [])->filter(fn($r) => ($r['type'] ?? '') === 'KM')->values();
        $this->assertCount(1, $oktTrxRows, 'Siklus Oktober wajib memuat 1 baris KM');
        $this->assertEquals('2026-10-18', $oktTrxRows[0]['date']);
        $this->assertEquals(250000.00, $oktTrxRows[0]['deposit']);
        $this->assertStringContainsString('1018', (string) $oktTrxRows[0]['evidence_no']);
    }

    public function test_km_4897_input_with_october_date_maps_to_october_cycle(): void
    {
        Sanctum::actingAs($this->manager);

        // Simulasi input transaksi KM-4897 dengan tanggal 18 Oktober 2026 dalam format camelCase / format Indo
        $postResponse = $this->postJson('/api/transactions', [
            'member_id'        => $this->member->id,
            'payment_method'   => 'cash',
            'transactionDate'  => '2026-10-18',
            'book_type'        => 'BUKU_PUTIH',
            'type'             => 'deposit',
            'receipt_number'   => 'KM-4897',
            'items'            => [
                [
                    'account_code'    => '2021',
                    'amount'          => 350000.00,
                    'description'     => 'Setoran Simpanan Harian KM-4897',
                    'transactionDate' => '2026-10-18',
                ],
            ],
        ]);

        $postResponse->assertStatus(200);

        // Verifikasi langsung di DB
        $dbTrx = Transaction::where('receipt_number', 'KM-4897')
            ->orWhere('receipt_number', '4897')
            ->orWhere('description', 'like', '%KM-4897%')
            ->first();

        $this->assertNotNull($dbTrx);
        $this->assertEquals('2026-10-18', $dbTrx->transaction_date->toDateString());

        // Verifikasi mutasi Buku Putih
        $response = $this->getJson("/api/members/{$this->member->id}/buku-putih-ledger?fiscal_year=2027");
        $response->assertStatus(200);

        $data = $response->json('data');
        $cycles = $data['cycles'];

        // September tidak boleh ada transaksi KM-4897
        $sep = collect($cycles)->firstWhere('month', 9);
        $sepRows = collect($sep['rows'] ?? [])->filter(fn($r) => str_contains((string) ($r['evidence_no'] ?? ''), '4897'));
        $this->assertCount(0, $sepRows);

        // Oktober WAJIB memuat KM-4897
        $okt = collect($cycles)->firstWhere('month', 10);
        $oktRows = collect($okt['rows'] ?? [])->filter(fn($r) => str_contains((string) ($r['evidence_no'] ?? ''), '4897'))->values();
        $this->assertCount(1, $oktRows);
        $this->assertEquals('2026-10-18', $oktRows[0]['date']);
        $this->assertEquals(350000.00, $oktRows[0]['deposit']);
    }

    public function test_non_existent_member_returns_404(): void
    {
        Sanctum::actingAs($this->manager);

        $response = $this->getJson('/api/members/999999/buku-putih-ledger');
        $response->assertStatus(404);
        $response->assertJson([
            'success' => false,
            'message' => 'Data anggota tidak ditemukan.',
        ]);
    }

    public function test_buku_putih_migration_initial_balance_historical_placement_and_sync(): void
    {
        Sanctum::actingAs($this->manager);

        $m = Member::create([
            'member_number'     => '0001',
            'name'              => 'Basalina Hutauruk',
            'nik'               => '1403094112820007',
            'phone'             => '081234567890',
            'daily_savings'     => 748953.00,
            'has_buku_putih'    => true,
            'buku_putih_no'     => '2021-0017',
            'status'            => 'active',
        ]);

        // Migration transaction on 2026-08-20 (end of August cycle)
        Transaction::create([
            'transaction_number' => 'TRX-IMP-20260924141246-1-PUT-N0I5Z',
            'receipt_number'     => 'KM-IMP-P-20260924141246-1-ULKM',
            'member_id'          => $m->id,
            'account_id'         => $this->kasAccount->id,
            'book_type'          => 'BUKU_PUTIH',
            'type'               => 'deposit',
            'amount'             => 744486.00,
            'beginning_balance'  => 0.00,
            'ending_balance'     => 744486.00,
            'payment_method'     => 'cash',
            'transaction_date'   => '2026-08-20',
            'description'        => "Saldo Awal Buku Putih - {$m->name}",
            'status'             => 'approved',
        ]);

        // September interest distributed on 2026-09-20
        Transaction::create([
            'transaction_number' => 'TRX-INT-202609-0001',
            'receipt_number'     => 'BM-INT-202609-1',
            'member_id'          => $m->id,
            'account_id'         => null,
            'book_type'          => 'BUKU_PUTIH',
            'type'               => 'deposit',
            'category'           => 'bunga_simpanan',
            'amount'             => 4467.00,
            'beginning_balance'  => 744486.00,
            'ending_balance'     => 748953.00,
            'payment_method'     => 'memorial',
            'transaction_date'   => '2026-09-20',
            'description'        => 'Bunga Simpanan Buku Putih (0.6%) Periode September 2026',
            'status'             => 'approved',
        ]);

        $response = $this->getJson("/api/members/{$m->id}/buku-putih-ledger?fiscal_year=2027");
        $response->assertStatus(200);

        $data = $response->json('data');
        $cycles = $data['cycles'];

        // 1. August Cycle: Contains KM-IMP with 744,486 and balance 744,486
        $agu = collect($cycles)->firstWhere('month', 8);
        $this->assertNotNull($agu);
        $this->assertEquals(744486.00, $agu['total_deposit']);
        $this->assertEquals(744486.00, $agu['closing_balance']);
        $this->assertCount(1, $agu['rows']);
        $this->assertEquals('2026-08-20', $agu['rows'][0]['date']);
        $this->assertEquals('KM-IMP-P-20260924141246-1-ULKM', $agu['rows'][0]['evidence_no']);
        $this->assertEquals(744486.00, $agu['rows'][0]['deposit']);
        $this->assertEquals(744486.00, $agu['rows'][0]['balance']);

        // 2. September Cycle: Opening 744,486, Interest 4,467, Closing 748,953
        $sep = collect($cycles)->firstWhere('month', 9);
        $this->assertNotNull($sep);
        $this->assertEquals(744486.00, $sep['opening_balance']);
        $this->assertEquals(4467.00, $sep['interest']);
        $this->assertEquals(748953.00, $sep['closing_balance']);
        $this->assertCount(1, $sep['rows']);
        $this->assertEquals('2026-09-20', $sep['rows'][0]['date']);
        $this->assertEquals('BM-INT-202609-1', $sep['rows'][0]['evidence_no']);
        $this->assertEquals(4467.00, $sep['rows'][0]['interest']);
        $this->assertEquals(748953.00, $sep['rows'][0]['balance']);

        // 3. October Cycle: Opening 748,953, Interest 4,494 (0.6% of 748,953), Closing 753,447
        $okt = collect($cycles)->firstWhere('month', 10);
        $this->assertNotNull($okt);
        $this->assertEquals(748953.00, $okt['opening_balance']);
        $this->assertEquals(4494.00, round($okt['interest']));
        $this->assertEquals(753447.00, round($okt['closing_balance']));
        $this->assertCount(1, $okt['rows']);
        $this->assertEquals('2026-10-20', $okt['rows'][0]['date']);
        $this->assertEquals(4494.00, round($okt['rows'][0]['interest']));
        $this->assertEquals(753447.00, round($okt['rows'][0]['balance']));

        // 4. Verify PDF export endpoint
        $pdfRes = $this->get("/api/members/{$m->id}/buku-putih-ledger/export-pdf?fiscal_year=2027");
        $pdfRes->assertStatus(200);
        $this->assertEquals('application/pdf', $pdfRes->headers->get('content-type'));
    }

    public function test_buku_putih_excel_reference_multi_cycle_calculation(): void
    {
        Sanctum::actingAs($this->manager);

        // Member with initial balance 434,809 on 31 May 2026 and current balance 848,953 as of September closing
        $member = Member::create([
            'member_number'  => '0088',
            'name'           => 'Anggota Acuan Excel',
            'nik'            => '1403094112820088',
            'phone'          => '081234567888',
            'daily_savings'  => 848953.00,
            'has_buku_putih' => true,
            'buku_putih_no'  => '2021-0088',
            'status'         => 'active',
        ]);

        // June Setoran 100,000 (2026-06-05) and BM 2,609 (2026-06-20)
        Transaction::create([
            'transaction_number' => 'TRX-BP-06',
            'receipt_number'     => 'KM-0601',
            'member_id'          => $member->id,
            'account_id'         => $this->kasAccount->id,
            'book_type'          => 'BUKU_PUTIH',
            'type'               => 'deposit',
            'amount'             => 100000.00,
            'beginning_balance'  => 434809.00,
            'ending_balance'     => 534809.00,
            'payment_method'     => 'cash',
            'transaction_date'   => '2026-06-05',
            'description'        => 'Setoran Juni',
            'status'             => 'approved',
        ]);
        Transaction::create([
            'transaction_number' => 'TRX-INT-202606-0088',
            'receipt_number'     => 'BM-INT-202606-88',
            'member_id'          => $member->id,
            'account_id'         => null,
            'book_type'          => 'BUKU_PUTIH',
            'type'               => 'deposit',
            'category'           => 'bunga_simpanan',
            'amount'             => 2609.00,
            'beginning_balance'  => 534809.00,
            'ending_balance'     => 537418.00,
            'payment_method'     => 'memorial',
            'transaction_date'   => '2026-06-20',
            'description'        => 'Bunga Simpanan Buku Putih (0.6%) Periode Juni 2026',
            'status'             => 'approved',
        ]);

        // July Setoran 100,000 (2026-07-05) and BM 3,225 (2026-07-20)
        Transaction::create([
            'transaction_number' => 'TRX-BP-07',
            'receipt_number'     => 'KM-0701',
            'member_id'          => $member->id,
            'account_id'         => $this->kasAccount->id,
            'book_type'          => 'BUKU_PUTIH',
            'type'               => 'deposit',
            'amount'             => 100000.00,
            'beginning_balance'  => 537418.00,
            'ending_balance'     => 637418.00,
            'payment_method'     => 'cash',
            'transaction_date'   => '2026-07-05',
            'description'        => 'Setoran Juli',
            'status'             => 'approved',
        ]);
        Transaction::create([
            'transaction_number' => 'TRX-INT-202607-0088',
            'receipt_number'     => 'BM-INT-202607-88',
            'member_id'          => $member->id,
            'account_id'         => null,
            'book_type'          => 'BUKU_PUTIH',
            'type'               => 'deposit',
            'category'           => 'bunga_simpanan',
            'amount'             => 3225.00,
            'beginning_balance'  => 637418.00,
            'ending_balance'     => 640642.00,
            'payment_method'     => 'memorial',
            'transaction_date'   => '2026-07-20',
            'description'        => 'Bunga Simpanan Buku Putih (0.6%) Periode Juli 2026',
            'status'             => 'approved',
        ]);

        // August Setoran 100,000 (2026-08-05) and BM 3,844 (2026-08-20)
        Transaction::create([
            'transaction_number' => 'TRX-BP-08',
            'receipt_number'     => 'KM-0801',
            'member_id'          => $member->id,
            'account_id'         => $this->kasAccount->id,
            'book_type'          => 'BUKU_PUTIH',
            'type'               => 'deposit',
            'amount'             => 100000.00,
            'beginning_balance'  => 640642.00,
            'ending_balance'     => 740642.00,
            'payment_method'     => 'cash',
            'transaction_date'   => '2026-08-05',
            'description'        => 'Setoran Agustus',
            'status'             => 'approved',
        ]);
        Transaction::create([
            'transaction_number' => 'TRX-INT-202608-0088',
            'receipt_number'     => 'BM-INT-202608-88',
            'member_id'          => $member->id,
            'account_id'         => null,
            'book_type'          => 'BUKU_PUTIH',
            'type'               => 'deposit',
            'category'           => 'bunga_simpanan',
            'amount'             => 3844.00,
            'beginning_balance'  => 740642.00,
            'ending_balance'     => 744486.00,
            'payment_method'     => 'memorial',
            'transaction_date'   => '2026-08-20',
            'description'        => 'Bunga Simpanan Buku Putih (0.6%) Periode Agustus 2026',
            'status'             => 'approved',
        ]);

        // September Setoran 100,000 (2026-09-05)
        Transaction::create([
            'transaction_number' => 'TRX-BP-09',
            'receipt_number'     => 'KM-0901',
            'member_id'          => $member->id,
            'account_id'         => $this->kasAccount->id,
            'book_type'          => 'BUKU_PUTIH',
            'type'               => 'deposit',
            'amount'             => 100000.00,
            'beginning_balance'  => 744486.00,
            'ending_balance'     => 844486.00,
            'payment_method'     => 'cash',
            'transaction_date'   => '2026-09-05',
            'description'        => 'Setoran September',
            'status'             => 'approved',
        ]);

        // September BM-INT-202609 created in DB with 4,467
        Transaction::create([
            'transaction_number' => 'TRX-INT-202609-0088',
            'receipt_number'     => 'BM-INT-202609-88',
            'member_id'          => $member->id,
            'account_id'         => null,
            'book_type'          => 'BUKU_PUTIH',
            'type'               => 'deposit',
            'category'           => 'bunga_simpanan',
            'amount'             => 4467.00,
            'beginning_balance'  => 844486.00,
            'ending_balance'     => 848953.00,
            'payment_method'     => 'memorial',
            'transaction_date'   => '2026-09-20',
            'description'        => 'Bunga Simpanan Buku Putih (0.6%) Periode September 2026',
            'status'             => 'approved',
        ]);

        $response = $this->getJson("/api/members/{$member->id}/buku-putih-ledger?fiscal_year=2027");
        $response->assertStatus(200);

        $data = $response->json('data');
        $cycles = $data['cycles'];

        // September: Opening 744,486, Setoran 100,000, Interest 4,467, Closing 848,953
        $sep = collect($cycles)->firstWhere('month', 9);
        $this->assertEquals(4467.00, $sep['interest']);
        $this->assertEquals(848953.00, $sep['closing_balance']);

        // October: Opening 848,953, Setoran 0, Interest 5,094 (or 5,093.72), Closing 854,047
        $okt = collect($cycles)->firstWhere('month', 10);
        $this->assertEquals(848953.00, $okt['opening_balance']);
        $this->assertEquals(5094.00, round($okt['interest']));
        $this->assertEquals(854047.00, round($okt['closing_balance']));
    }

    public function test_buku_putih_october_deposit_and_interest_calculation_with_existing_zero_amount_bm(): void
    {
        Sanctum::actingAs($this->manager);

        // Member with balance 216,015 as of September closing and 316,015 after 28 Sep deposit
        $member = Member::create([
            'member_number'  => '0099',
            'name'           => 'Anggota Uji Setoran 28 Sep',
            'nik'            => '1403094112820099',
            'phone'          => '081234567899',
            'daily_savings'  => 316015.00,
            'has_buku_putih' => true,
            'buku_putih_no'  => '2021-0099',
            'status'         => 'active',
        ]);

        // September initial balance / closing: deposit & BM in September
        Transaction::create([
            'transaction_number' => 'TRX-BP-SEP',
            'receipt_number'     => 'KM-0901',
            'member_id'          => $member->id,
            'account_id'         => $this->kasAccount->id,
            'book_type'          => 'BUKU_PUTIH',
            'type'               => 'deposit',
            'amount'             => 214727.00,
            'beginning_balance'  => 0.00,
            'ending_balance'     => 214727.00,
            'payment_method'     => 'cash',
            'transaction_date'   => '2026-08-25',
            'description'        => 'Setoran Awal',
            'status'             => 'approved',
        ]);

        Transaction::create([
            'transaction_number' => 'TRX-INT-202609-0099',
            'receipt_number'     => 'BM-INT-202609-99',
            'member_id'          => $member->id,
            'account_id'         => null,
            'book_type'          => 'BUKU_PUTIH',
            'type'               => 'deposit',
            'category'           => 'bunga_simpanan',
            'amount'             => 1288.00,
            'beginning_balance'  => 214727.00,
            'ending_balance'     => 216015.00,
            'payment_method'     => 'memorial',
            'transaction_date'   => '2026-09-20',
            'description'        => 'Bunga Simpanan Buku Putih (0.6%) Periode September 2026',
            'status'             => 'approved',
        ]);

        // Setoran 28 September (falls into October cycle: 21 Sep - 20 Oct)
        Transaction::create([
            'transaction_number' => 'TRX-BP-OCT-28',
            'receipt_number'     => 'KM-1258',
            'member_id'          => $member->id,
            'account_id'         => $this->kasAccount->id,
            'book_type'          => 'BUKU_PUTIH',
            'type'               => 'deposit',
            'amount'             => 100000.00,
            'beginning_balance'  => 216015.00,
            'ending_balance'     => 316015.00,
            'payment_method'     => 'cash',
            'transaction_date'   => '2026-09-28',
            'description'        => 'Setoran Simpanan Harian KM-1258',
            'status'             => 'approved',
        ]);

        // BM-INT-202610 exists in DB with 0.00 amount (simulating previous zero amount issue)
        Transaction::create([
            'transaction_number' => 'TRX-INT-202610-0099',
            'receipt_number'     => 'BM-INT-202610-99',
            'member_id'          => $member->id,
            'account_id'         => null,
            'book_type'          => 'BUKU_PUTIH',
            'type'               => 'deposit',
            'category'           => 'bunga_simpanan',
            'amount'             => 0.00,
            'beginning_balance'  => 316015.00,
            'ending_balance'     => 316015.00,
            'payment_method'     => 'memorial',
            'transaction_date'   => '2026-10-20',
            'description'        => 'Bunga Simpanan Buku Putih (0.6%) Periode Oktober 2026',
            'status'             => 'approved',
        ]);

        $response = $this->getJson("/api/members/{$member->id}/buku-putih-ledger?fiscal_year=2027");
        $response->assertStatus(200);

        $data = $response->json('data');
        $cycles = $data['cycles'];

        // September cycle: Closing balance = 216,015, Jasa = 1,288
        $sep = collect($cycles)->firstWhere('month', 9);
        $this->assertEquals(1288.00, $sep['interest']);
        $this->assertEquals(216015.00, $sep['closing_balance']);

        // October cycle: Opening balance (dasar bunga) = 216,015
        $okt = collect($cycles)->firstWhere('month', 10);
        $this->assertEquals(216015.00, $okt['opening_balance']);
        $this->assertEquals(100000.00, $okt['total_deposit']);

        // Setoran 28/09 is mapped to October cycle
        $oktRows = collect($okt['rows']);
        $kmRow = $oktRows->firstWhere('evidence_no', 'KM-1258');
        $this->assertNotNull($kmRow);
        $this->assertEquals('2026-09-28', $kmRow['date']);
        $this->assertEquals(100000.00, $kmRow['deposit']);
        $this->assertEquals(316015.00, $kmRow['balance']);

        // October interest MUST NOT be 0, must be round(216,015 * 0.006) = 1,296
        $this->assertEquals(1296.00, round($okt['interest']));

        // October closing balance MUST be 316,015 + 1,296 = 317,311
        $this->assertEquals(317311.00, round($okt['closing_balance']));
    }
}