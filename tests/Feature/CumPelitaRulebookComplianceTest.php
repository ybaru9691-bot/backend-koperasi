<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\AccountingPeriod;
use App\Models\Loan;
use App\Models\Member;
use App\Models\Period;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class CumPelitaRulebookComplianceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(\Database\Seeders\ChartOfAccountSeeder::class);

        Account::create([
            'account_number' => 'KAS-101',
            'account_name'   => 'Kas Koperasi',
            'account_type'   => 'kas',
            'category'       => 'asset',
            'balance'        => 50000000.00,
        ]);
    }

    /**
     * 1. Test Validasi Nominal Setoran Awal Pendaftaran (Biru 270k, Putih 90k, Both 360k)
     */
    public function test_member_registration_initial_deposit_rules(): void
    {
        $admin = User::create([
            'name'     => 'Admin Pendaftaran',
            'email'    => 'admin.reg@koperasi.com',
            'password' => bcrypt('password123'),
            'role'     => 'admin',
            'nik'      => '1122334455667701',
        ]);

        Sanctum::actingAs($admin);

        // a. Pendaftaran Buku Biru dengan setoran < 270k harus ditolak
        $payloadBiruInvalid = [
            'nik'               => '3201010101010011',
            'name'              => 'Anggota Biru Gagal',
            'has_buku_biru'     => true,
            'has_buku_putih'    => false,
            'principal_savings' => 200000,
            'mandatory_savings' => 20000,
            'registration_fee'  => 20000,
            'grief_fund'        => 20000,
            'voluntary_savings' => 0, // Total = 260.000 < 270.000
            'total_pembayaran'  => 260000,
        ];
        $resBiruInvalid = $this->postJson('/api/members', $payloadBiruInvalid);
        $resBiruInvalid->assertStatus(422);

        // b. Pendaftaran Buku Biru valid (270k)
        $payloadBiruValid = [
            'nik'               => '3201010101010012',
            'name'              => 'Anggota Biru Sukses',
            'has_buku_biru'     => true,
            'has_buku_putih'    => false,
            'principal_savings' => 200000,
            'mandatory_savings' => 20000,
            'registration_fee'  => 20000,
            'grief_fund'        => 20000,
            'voluntary_savings' => 10000,
            'total_pembayaran'  => 270000,
        ];
        $resBiruValid = $this->postJson('/api/members', $payloadBiruValid);
        $resBiruValid->assertStatus(201);
        $this->assertEquals(200000, $resBiruValid->json('data.principal_savings'));
        $this->assertEquals(20000, $resBiruValid->json('data.mandatory_savings'));
        $this->assertEquals(10000, $resBiruValid->json('data.voluntary_savings'));

        // c. Pendaftaran Buku Putih valid (90k)
        $payloadPutihValid = [
            'nik'              => '3201010101010013',
            'name'             => 'Anggota Putih Sukses',
            'has_buku_biru'    => false,
            'has_buku_putih'   => true,
            'initial_daily_savings' => 50000,
            'total_pembayaran' => 90000,
        ];
        $resPutihValid = $this->postJson('/api/members', $payloadPutihValid);
        $resPutihValid->assertStatus(201);
        $this->assertEquals(50000, $resPutihValid->json('data.daily_savings'));

        // d. Pendaftaran KEDUA BUKU (Biru + Putih) valid (360k)
        $payloadBothValid = [
            'nik'                       => '3201010101010014',
            'name'                      => 'Anggota Kedua Buku',
            'has_buku_biru'             => true,
            'has_buku_putih'            => true,
            'initial_principal_savings' => 200000,
            'initial_mandatory_savings' => 20000,
            'initial_voluntary_savings' => 10000,
            'initial_daily_savings'     => 50000,
            'total_pembayaran'          => 360000,
        ];
        $resBothValid = $this->postJson('/api/members', $payloadBothValid);
        $resBothValid->assertStatus(201);
        $this->assertEquals(200000, $resBothValid->json('data.principal_savings'));
        $this->assertEquals(20000, $resBothValid->json('data.mandatory_savings'));
        $this->assertEquals(10000, $resBothValid->json('data.voluntary_savings'));
        $this->assertEquals(50000, $resBothValid->json('data.daily_savings'));
        $this->assertEquals(40000, $resBothValid->json('data.registration_fee'));
        $this->assertEquals(40000, $resBothValid->json('data.grief_fund'));

        // Verifikasi transaksi tidak bertipe interest / bunga
        $trxs = Transaction::where('member_id', $resBothValid->json('data.id'))->get();
        foreach ($trxs as $trx) {
            $this->assertEquals('deposit', $trx->type);
            $this->assertStringNotContainsString('interest', strtolower($trx->type));
            $this->assertStringContainsString('Setoran Awal Pembukaan Rekening', $trx->description);
        }
    }

    /**
     * 2. Test Larangan Pinjaman bagi Anggota Buku Putih Murni
     */
    public function test_loan_application_forbidden_for_buku_putih_only(): void
    {
        $memberPutih = Member::create([
            'nik'            => '3201010101010021',
            'member_number'  => 'ANG-PUTIH-01',
            'name'           => 'Budi Putih',
            'email'          => 'budi.putih@koperasi.com',
            'status'         => 'active',
            'has_buku_biru'  => false,
            'has_buku_putih' => true,
            'daily_savings'  => 500000.00,
        ]);

        $memberBiru = Member::create([
            'nik'               => '3201010101010022',
            'member_number'     => 'ANG-BIRU-01',
            'name'              => 'Andi Biru',
            'email'             => 'andi.biru@koperasi.com',
            'status'            => 'active',
            'has_buku_biru'     => true,
            'has_buku_putih'    => false,
            'principal_savings' => 200000.00,
            'mandatory_savings' => 100000.00,
        ]);

        // Anggota Buku Putih apply loan -> 403 Forbidden
        Sanctum::actingAs($memberPutih);
        $resPutih = $this->postJson('/api/user/loans/apply', [
            'amount' => 5000000,
            'tenor'  => 12,
        ]);
        $resPutih->assertStatus(403);
        $this->assertFalse($resPutih->json('success'));

        // Anggota Buku Biru apply loan -> 201 Created
        Sanctum::actingAs($memberBiru);
        $resBiru = $this->postJson('/api/user/loans/apply', [
            'amount' => 5000000,
            'tenor'  => 12,
        ]);
        $resBiru->assertStatus(201);
        $this->assertTrue($resBiru->json('success'));
    }

    /**
     * 3. Test Validasi Penarikan: Blokir SP/SW & Minimal Saldo Mengendap
     */
    public function test_withdrawal_restrictions_and_minimum_balance(): void
    {
        $admin = User::create([
            'name'     => 'Admin Kasir',
            'email'    => 'admin.kasir@koperasi.com',
            'password' => bcrypt('password123'),
            'role'     => 'admin',
            'nik'      => '1122334455667702',
        ]);

        $member = Member::create([
            'nik'               => '3201010101010031',
            'member_number'     => 'ANG-TARIK-01',
            'name'              => 'Candra Penarikan',
            'status'            => 'active',
            'has_buku_biru'     => true,
            'has_buku_putih'    => true,
            'principal_savings' => 200000.00,
            'mandatory_savings' => 100000.00,
            'voluntary_savings' => 50000.00,
            'daily_savings'     => 150000.00,
        ]);

        Sanctum::actingAs($admin);

        // a. Percobaan tarik Simpanan Pokok -> Ditolak (400)
        $resTarikSP = $this->postJson('/api/transactions', [
            'member_id'        => $member->id,
            'amount'           => 50000,
            'type'             => 'withdrawal',
            'book_type'        => 'BUKU_BIRU',
            'description'      => 'Penarikan Simpanan Pokok',
            'payment_method'   => 'cash',
            'transaction_date' => now()->toDateString(),
        ]);
        $resTarikSP->assertStatus(400);

        // b. Tarik Simpanan Sukarela menyisakan < 10.000 -> Ditolak (400)
        $resTarikSSInvalid = $this->postJson('/api/transactions', [
            'member_id'        => $member->id,
            'amount'           => 45000, // sisa 5.000 < 10.000
            'type'             => 'withdrawal',
            'book_type'        => 'BUKU_BIRU',
            'description'      => 'Penarikan Simpanan Sukarela',
            'payment_method'   => 'cash',
            'transaction_date' => now()->toDateString(),
        ]);
        $resTarikSSInvalid->assertStatus(400);

        // c. Tarik Simpanan Sukarela valid (sisa >= 10.000) -> Sukses
        $resTarikSSValid = $this->postJson('/api/transactions', [
            'member_id'        => $member->id,
            'amount'           => 30000, // sisa 20.000 >= 10.000
            'type'             => 'withdrawal',
            'book_type'        => 'BUKU_BIRU',
            'description'      => 'Penarikan Simpanan Sukarela',
            'payment_method'   => 'cash',
            'transaction_date' => now()->toDateString(),
        ]);
        $resTarikSSValid->assertStatus(200);
        $this->assertEquals(20000.00, $member->fresh()->voluntary_savings);

        // d. Tarik Tabungan Harian menyisakan < 100.000 -> Ditolak (400)
        $resTarikHarianInvalid = $this->postJson('/api/transactions', [
            'member_id'        => $member->id,
            'amount'           => 80000, // sisa 70.000 < 100.000
            'type'             => 'withdrawal',
            'book_type'        => 'BUKU_PUTIH',
            'description'      => 'Penarikan Tabungan Harian',
            'payment_method'   => 'cash',
            'transaction_date' => now()->toDateString(),
        ]);
        $resTarikHarianInvalid->assertStatus(400);
    }

    /**
     * 4. Test Layanan Pengunduran Diri / Resign Anggota dengan Kalkulasi Penalti & Pelunasan Pinjaman
     */
    public function test_member_resignation_service_with_penalties(): void
    {
        $manager = User::create([
            'name'     => 'Manager Koperasi',
            'email'    => 'manager.resign@koperasi.com',
            'password' => bcrypt('password123'),
            'role'     => 'manager',
            'nik'      => '1122334455667703',
        ]);

        // Anggota dengan Saham Rp 5.000.000 (Tier Penalti 1M - 10M = Rp 150.000)
        // dan memiliki sisa pinjaman Rp 1.000.000
        $member = Member::create([
            'nik'               => '3201010101010041',
            'member_number'     => 'ANG-RESIGN-01',
            'name'              => 'Doni Resign',
            'status'            => 'active',
            'has_buku_biru'     => true,
            'has_buku_putih'    => false,
            'principal_savings' => 1000000.00,
            'mandatory_savings' => 3000000.00,
            'voluntary_savings' => 1000000.00, // Total Saham = 5.000.000
            'daily_savings'     => 0.00,
        ]);

        $loan = Loan::create([
            'loan_code'           => 'LOAN-RESIGN-01',
            'member_id'           => $member->id,
            'amount'              => 2000000.00,
            'interest_rate'       => 1.5,
            'duration_months'     => 12,
            'monthly_installment' => 180000.00,
            'remaining_amount'    => 1000000.00,
            'application_date'    => now()->toDateString(),
            'status'              => 'active',
        ]);

        Sanctum::actingAs($manager);

        $resResign = $this->postJson("/api/manager/members/{$member->id}/resign");
        $resResign->assertStatus(200);
        $resResign->assertJson([
            'success' => true,
            'data'    => [
                'total_saham'    => 5000000.00,
                'penalty'        => 150000.00,
                'loan_deduction' => 1000000.00,
                'net_refund'     => 3850000.00, // 5M - 150k - 1M = 3.850.000
                'status'         => 'inactive',
            ]
        ]);

        // Verifikasi saldo member sekarang 0 dan status inactive
        $memberFresh = $member->fresh();
        $this->assertEquals('inactive', $memberFresh->status);
        $this->assertEquals(0, $memberFresh->principal_savings);
        $this->assertEquals(0, $memberFresh->mandatory_savings);
        $this->assertEquals(0, $memberFresh->voluntary_savings);

        // Verifikasi loan sudah lunas
        $this->assertEquals('paid_off', $loan->fresh()->status);
        $this->assertEquals(0, $loan->fresh()->remaining_amount);

        // Verifikasi Jurnal Resign
        $journal = \App\Models\JournalEntry::where('description', 'LIKE', '%Resign%')->with('details.account')->first();
        $this->assertNotNull($journal);

        // Verifikasi detail jurnal:
        // - DEBIT 2020 (Saham) = 5.000.000
        // - KREDIT 4183 (Penalti) = 150.000
        // - KREDIT 1024 (Piutang) = 1.000.000
        // - KREDIT 1000 (Kas) = 3.850.000
        $debit2020 = $journal->details->firstWhere('account.account_code', '2020');
        $credit4183 = $journal->details->firstWhere('account.account_code', '4183');
        $credit1024 = $journal->details->firstWhere('account.account_code', '1024');
        $credit1000 = $journal->details->firstWhere('account.account_code', '1000');

        $this->assertNotNull($debit2020);
        $this->assertEquals(5000000.00, (float) $debit2020->debit);
        $this->assertEquals(0, (float) $debit2020->credit);

        $this->assertNotNull($credit4183);
        $this->assertEquals(150000.00, (float) $credit4183->credit);
        $this->assertEquals(0, (float) $credit4183->debit);

        $this->assertNotNull($credit1024);
        $this->assertEquals(1000000.00, (float) $credit1024->credit);
        $this->assertEquals(0, (float) $credit1024->debit);

        $this->assertNotNull($credit1000);
        $this->assertEquals(3850000.00, (float) $credit1000->credit);
        $this->assertEquals(0, (float) $credit1000->debit);

        // Verifikasi pada Buku Besar Kas (1000), hanya ada 1 baris di sisi KREDIT (Kas Keluar)
        $cashDetails = $journal->details->where('account.account_code', '1000');
        $this->assertCount(1, $cashDetails);
    }

    /**
     * 5. Test Endpoint /api/members/{id}/balances Menghitung Saldo Tersedia Ditarik dengan Minimum Rp 100.000
     */
    public function test_get_balances_withdrawable_amount_with_100k_minimum(): void
    {
        $admin = User::create([
            'name'     => 'Admin Balances',
            'email'    => 'admin.bal@koperasi.com',
            'password' => bcrypt('password123'),
            'role'     => 'admin',
            'nik'      => '1122334455667704',
        ]);

        $member = Member::create([
            'nik'               => '3201010101010051',
            'member_number'     => 'ANG-BAL-01',
            'name'              => 'Eka Saldo',
            'status'            => 'active',
            'has_buku_putih'    => true,
            'daily_savings'     => 350000.00, // Saldo 350rb
            'principal_savings' => 200000.00,
            'mandatory_savings' => 100000.00,
            'voluntary_savings' => 50000.00,
        ]);

        Sanctum::actingAs($admin);

        $res = $this->getJson("/api/members/{$member->id}/balances");
        $res->assertStatus(200);
        $res->assertJson([
            'success' => true,
            'data'    => [
                'minimal_saldo_mengendap' => 100000.00,
                'saldo_minimal_mengendap' => 100000.00,
                'saldo_tersedia_ditarik'  => 250000.00, // 350.000 - 100.000 = 250.000
                'saldo_bisa_ditarik'      => 250000.00,
                'max_withdrawal'          => 250000.00,
                'saldo_buku_putih'        => 350000.00,
            ]
        ]);
    }

    public function test_member_registration_initial_deposit_with_custom_larger_amounts(): void
    {
        $admin = User::create([
            'name'     => 'Admin Dynamic',
            'email'    => 'admin.dyn@koperasi.com',
            'password' => bcrypt('password123'),
            'role'     => 'admin',
            'nik'      => '1122334455667705',
        ]);

        Sanctum::actingAs($admin);

        // Pendaftaran Buku Biru dengan custom voluntary_savings Rp 150.000
        $payloadBiru = [
            'nik'               => '3201010101010091',
            'name'              => 'Anggota Biru Kaya',
            'has_buku_biru'     => true,
            'has_buku_putih'    => false,
            'principal_savings' => 200000,
            'mandatory_savings' => 20000,
            'registration_fee'  => 20000,
            'grief_fund'        => 20000,
            'voluntary_savings' => 150000, // custom larger voluntary savings
            'total_pembayaran'  => 410000,
        ];
        $resBiru = $this->postJson('/api/members', $payloadBiru);
        $resBiru->assertStatus(201);
        $this->assertEquals(150000, $resBiru->json('data.voluntary_savings'));

        // Pendaftaran Buku Putih dengan custom daily_savings Rp 120.000
        $payloadPutih = [
            'nik'              => '3201010101010092',
            'name'             => 'Anggota Putih Mapan',
            'has_buku_biru'    => false,
            'has_buku_putih'   => true,
            'initial_daily_savings' => 120000, // custom larger daily savings
            'total_pembayaran' => 160000,
        ];
        $resPutih = $this->postJson('/api/members', $payloadPutih);
        $resPutih->assertStatus(201);
        $this->assertEquals(120000, $resPutih->json('data.daily_savings'));
    }
}
