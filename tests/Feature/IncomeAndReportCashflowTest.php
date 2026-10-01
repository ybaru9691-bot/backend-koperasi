<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\ChartOfAccount;
use App\Models\Member;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class IncomeAndReportCashflowTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Seed basic accounts
        Account::firstOrCreate(
            ['account_number' => 'KAS-101'],
            [
                'account_name' => 'Kas Koperasi',
                'account_type' => 'kas',
                'category'     => 'asset',
                'balance'      => 1000000.00,
            ]
        );

        // Seed basic COA
        ChartOfAccount::create([
            'account_code'   => '1000',
            'account_name'   => 'Kas Tunai',
            'account_type'   => 'ASSET',
            'normal_balance' => 'DEBIT',
        ]);

        ChartOfAccount::create([
            'account_code'   => '1010',
            'account_name'   => 'Kas Bank',
            'account_type'   => 'ASSET',
            'normal_balance' => 'DEBIT',
        ]);

        ChartOfAccount::create([
            'account_code'   => '4180',
            'account_name'   => 'Jasa Pinjaman',
            'account_type'   => 'REVENUE',
            'normal_balance' => 'CREDIT',
        ]);

        ChartOfAccount::create([
            'account_code'   => '4191',
            'account_name'   => 'Uang Pangkal / Administrasi',
            'account_type'   => 'REVENUE',
            'normal_balance' => 'CREDIT',
        ]);

        ChartOfAccount::create([
            'account_code'   => '4195',
            'account_name'   => 'Pendapatan Lain-lain',
            'account_type'   => 'REVENUE',
            'normal_balance' => 'CREDIT',
        ]);

        ChartOfAccount::create([
            'account_code'   => '5100',
            'account_name'   => 'Beban Operasional',
            'account_type'   => 'EXPENSE',
            'normal_balance' => 'DEBIT',
        ]);
    }

    /**
     * 1. Test input Pendapatan Lain-lain menambah saldo Kas dan membuat transaksi KM
     */
    public function test_input_other_income_creates_km_transaction_and_increments_cash(): void
    {
        $admin = User::create([
            'name'     => 'Admin Teller',
            'email'    => 'teller.income@koperasi.com',
            'password' => bcrypt('password123'),
            'role'     => 'admin',
            'nik'      => '1122334455667791',
        ]);

        Sanctum::actingAs($admin);

        $initialCash = (float) Account::where('account_type', 'kas')->value('balance');

        $payload = [
            'amount'           => 750000.00,
            'description'      => 'Penjualan Merchandise & Formulir',
            'account_code'     => '4195',
            'payment_method'   => 'cash',
            'transaction_date' => now()->toDateString(),
        ];

        $response = $this->postJson('/api/incomes', $payload);

        $response->assertStatus(201);
        $response->assertJson([
            'success' => true,
            'status'  => 'success',
            'data'    => [
                'transaction' => [
                    'type'     => 'deposit',
                    'category' => 'pendapatan_lain',
                    'amount'   => '750000.00',
                    'status'   => 'approved',
                ]
            ]
        ]);

        // Verifikasi saldo kas bertambah
        $newCash = (float) Account::where('account_type', 'kas')->value('balance');
        $this->assertEquals($initialCash + 750000.00, $newCash);

        // Verifikasi transaksi tercatat di database
        $trx = Transaction::where('category', 'pendapatan_lain')->first();
        $this->assertNotNull($trx);
        $this->assertStringStartsWith('KM-', $trx->transaction_number);
        $this->assertEquals('approved', $trx->status);
        $this->assertNotNull($trx->journalEntry);
    }

    /**
     * 2. Test pemisahan Total Kas Masuk (Arus Kas) vs SHU 25% (Laba Operasional)
     */
    public function test_report_separates_cash_inflow_from_operational_revenue(): void
    {
        $manager = User::create([
            'name'     => 'Manager Laporan',
            'email'    => 'manager.report@koperasi.com',
            'password' => bcrypt('password123'),
            'role'     => 'manager',
            'nik'      => '1122334455667792',
        ]);

        $member = Member::create([
            'nik'                => '3201010101010099',
            'member_number'      => 'ANG-099',
            'name'               => 'Member Uji',
            'email'              => 'uji@koperasi.com',
            'phone'              => '08123456799',
            'status'             => 'active',
            'principal_savings'  => 1000000.00,
            'mandatory_savings'  => 200000.00,
            'voluntary_savings'  => 500000.00,
        ]);

        $today = now()->toDateString();
        $m = now()->month;
        $y = now()->year;

        // A. Transaksi Simpanan Anggota (Modal / Arus Kas Masuk, BUKAN SHU) = Rp 1.000.000
        Transaction::create([
            'transaction_number' => 'KM-SAV-01',
            'member_id'          => $member->id,
            'type'               => 'deposit',
            'amount'             => 1000000.00,
            'transaction_date'   => $today,
            'description'        => 'Setoran Simpanan Wajib & Pokok',
            'status'             => 'approved',
        ]);

        // B. Transaksi Jasa Pinjaman (Pendapatan Operasional & SHU) = Rp 300.000
        Transaction::create([
            'transaction_number' => 'KM-REV-01',
            'member_id'          => $member->id,
            'type'               => 'deposit',
            'amount'             => 300000.00,
            'transaction_date'   => $today,
            'description'        => 'Jasa Pinjaman Angsuran #1',
            'status'             => 'approved',
        ]);

        // C. Transaksi Pendapatan Lain-lain (Pendapatan Operasional & SHU) = Rp 200.000
        Transaction::create([
            'transaction_number' => 'KM-INC-01',
            'member_id'          => null,
            'type'               => 'deposit',
            'category'           => 'pendapatan_lain',
            'amount'             => 200000.00,
            'transaction_date'   => $today,
            'description'        => 'Penerimaan Pendapatan Lain-lain: Biaya Cetak Buku',
            'status'             => 'approved',
        ]);

        Sanctum::actingAs($manager);

        // Panggil endpoint ringkasan keuangan manajer
        $response = $this->getJson("/api/manager/financial-summary?month={$m}&year={$y}");

        $response->assertStatus(200);
        $data = $response->json('data');

        // Total Kas Masuk (Arus Kas) = Simpanan (1.000.000) + Jasa Pinjaman (300.000) + Pendapatan Lain (200.000) = 1.500.000
        $this->assertEquals(1500000.00, (float) $data['total_kas_masuk']);

        // Total Pendapatan Operasional (SHU Basis) = Jasa Pinjaman (300.000) + Pendapatan Lain (200.000) = 500.000 (Simpanan tidak masuk laba!)
        $this->assertEquals(500000.00, (float) $data['total_revenue']);
        $this->assertEquals(500000.00, (float) $data['net_shu']);

        // Alokasi SHU (25% dari 500.000 = 125.000)
        $this->assertEquals(25.0, (float) $data['shu_percentage']);
        $this->assertEquals(125000.00, (float) $data['allocated_shu']);
    }

    /**
     * 3. Test Dashboard Total Kas & Bank sinkron dengan Saldo Awal + SUM(KM) - SUM(KK)
     */
    public function test_dashboard_total_kas_bank_matches_opening_plus_cashflow(): void
    {
        $admin = User::create([
            'name'     => 'Admin Dashboard',
            'email'    => 'admin.dashboard@koperasi.com',
            'password' => bcrypt('password123'),
            'role'     => 'admin',
            'nik'      => '1122334455667793',
        ]);

        Sanctum::actingAs($admin);

        $today = now()->toDateString();

        // 1. Transaksi Masuk = Rp 2.500.000
        Transaction::create([
            'transaction_number' => 'KM-DASH-01',
            'type'               => 'deposit',
            'amount'             => 2500000.00,
            'transaction_date'   => $today,
            'description'        => 'Setoran Simpanan Anggota',
            'status'             => 'approved',
        ]);

        // 2. Transaksi Keluar = Rp 500.000
        Transaction::create([
            'transaction_number' => 'KK-DASH-02',
            'type'               => 'withdrawal',
            'amount'             => 500000.00,
            'transaction_date'   => $today,
            'description'        => 'Pencairan Pinjaman / Beban',
            'status'             => 'approved',
        ]);

        $response = $this->getJson('/api/dashboard-summary');
        $response->assertStatus(200);
        $data = $response->json('data');

        // Total Kas & Bank = SUM(KM) 2.500.000 - SUM(KK) 500.000 = 2.000.000
        $this->assertEquals(2000000.00, (float) $data['total_kas_bank']);
        $this->assertEquals(2500000.00, (float) $data['total_kas_masuk_current_month']);
        $this->assertEquals(500000.00, (float) $data['total_kas_keluar_current_month']);
    }

    /**
     * 4. Test Penyesuaian Saldo Manajer tercatat di tabel transactions & sinkron ke dashboard
     */
    public function test_manager_balance_adjustment_creates_transaction_and_syncs_dashboard(): void
    {
        $manager = User::create([
            'name'     => 'Manager Adjust',
            'email'    => 'manager.adjust@koperasi.com',
            'password' => bcrypt('password123'),
            'role'     => 'manager',
            'nik'      => '1122334455667794',
        ]);

        Sanctum::actingAs($manager);

        $today = now()->toDateString();

        // Eksekusi penyesuaian saldo (Income / Tambah Kas)
        $response = $this->postJson('/api/manager/transactions/adjust-balance', [
            'account_code'     => '4195',
            'type'             => 'income',
            'amount'           => 1200000.00,
            'description'      => 'Penyesuaian Saldo Kas Tambahan',
            'transaction_date' => $today,
        ]);

        $response->assertStatus(201);
        $response->assertJson(['success' => true]);

        // Verifikasi tercatat di tabel transactions
        $trx = Transaction::where('amount', 1200000.00)
            ->where('status', 'approved')
            ->first();

        $this->assertNotNull($trx);
        $this->assertEquals('deposit', $trx->type);
        $this->assertEquals('pendapatan_lain', $trx->category);
        $this->assertStringContainsString('Penyesuaian Manajer', $trx->description);

        // Verifikasi Dashboard mencatat transaksi masuk penyesuaian ini
        $dashRes = $this->getJson('/api/dashboard-summary');
        $dashRes->assertStatus(200);
        $dashData = $dashRes->json('data');

        $this->assertEquals(1200000.00, (float) $dashData['total_kas_bank']);
        $this->assertEquals(1200000.00, (float) $dashData['total_kas_masuk_current_month']);
    }

    /**
     * 5. Test multiple sequential manager balance adjustments to ensure unique sequential voucher numbers
     */
    public function test_manager_multiple_balance_adjustments_generate_sequential_vouchers(): void
    {
        $manager = User::create([
            'name'     => 'Manager Multi Adjust',
            'email'    => 'manager.multi.adjust@koperasi.com',
            'password' => bcrypt('password123'),
            'role'     => 'manager',
            'nik'      => '1122334455667795',
        ]);

        Sanctum::actingAs($manager);

        $today = now()->format('Ymd');
        $dateStr = now()->toDateString();

        // 1st adjustment
        $res1 = $this->postJson('/api/manager/transactions/adjust-balance', [
            'account_code'     => '4195',
            'type'             => 'income',
            'amount'           => 100000.00,
            'description'      => 'Adj 1',
            'transaction_date' => $dateStr,
        ]);
        $res1->assertStatus(201);
        $res1->assertJsonPath('data.voucher_number', "KM-ADJ-{$today}-0001");

        // 2nd adjustment
        $res2 = $this->postJson('/api/manager/transactions/adjust-balance', [
            'account_code'     => '4195',
            'type'             => 'income',
            'amount'           => 200000.00,
            'description'      => 'Adj 2',
            'transaction_date' => $dateStr,
        ]);
        $res2->assertStatus(201);
        $res2->assertJsonPath('data.voucher_number', "KM-ADJ-{$today}-0002");

        // 3rd adjustment
        $res3 = $this->postJson('/api/manager/transactions/adjust-balance', [
            'account_code'     => '4195',
            'type'             => 'income',
            'amount'           => 300000.00,
            'description'      => 'Adj 3',
            'transaction_date' => $dateStr,
        ]);
        $res3->assertStatus(201);
        $res3->assertJsonPath('data.voucher_number', "KM-ADJ-{$today}-0003");
    }
}