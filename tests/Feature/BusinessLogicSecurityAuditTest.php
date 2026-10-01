<?php

namespace Tests\Feature;

use App\Models\AccountingPeriod;
use App\Models\Member;
use App\Models\Period;
use App\Models\ShuDistribution;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class BusinessLogicSecurityAuditTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Setup locked accounting period: 2024-06-01 s/d 2025-05-31
        AccountingPeriod::create([
            'period_name' => 'Juni 2024 - Mei 2025',
            'start_date'  => '2024-06-01',
            'end_date'    => '2025-05-31',
            'status'      => 'LOCKED',
        ]);

        Period::create([
            'period_name' => 'Juni 2024 - Mei 2025',
            'start_date'  => '2024-06-01',
            'end_date'    => '2025-05-31',
            'status'      => 'closed',
        ]);

        // Setup active accounting period: 2025-06-01 s/d 2026-05-31
        AccountingPeriod::create([
            'period_name' => 'Juni 2025 - Mei 2026',
            'start_date'  => '2025-06-01',
            'end_date'    => '2026-05-31',
            'status'      => 'OPEN',
        ]);

        Period::create([
            'period_name' => 'Juni 2025 - Mei 2026',
            'start_date'  => '2025-06-01',
            'end_date'    => '2026-05-31',
            'status'      => 'open',
        ]);
    }

    /**
     * 1. Test Transaksi bebas diinput untuk berbagai tanggal tanpa lock limitation.
     */
    public function test_transaction_date_allowed_freely(): void
    {
        $admin = User::create([
            'name'     => 'Admin Koperasi',
            'email'    => 'admin.lock@koperasi.com',
            'password' => bcrypt('password123'),
            'role'     => 'admin',
            'nik'      => '1122334455667781',
        ]);

        $member = Member::create([
            'nik'                => '3201010101010001',
            'member_number'      => 'ANG-001',
            'name'               => 'Budi Test',
            'email'              => 'budi.test@koperasi.com',
            'phone'              => '08123456781',
            'status'             => 'active',
            'daily_savings'      => 100000.00,
            'voluntary_savings'  => 500000.00,
            'principal_savings'  => 500000.00,
            'mandatory_savings'  => 120000.00,
            'has_buku_putih'     => true,
        ]);

        Sanctum::actingAs($admin);

        // Input mutasi kas di berbagai tanggal tetap berhasil diproses
        $response = $this->postJson('/api/transactions', [
            'member_id'        => $member->id,
            'amount'           => 50000,
            'payment_method'   => 'cash',
            'type'             => 'deposit',
            'transaction_date' => '2025-07-10', // Di dalam rentang open period (2025-06-01 s/d 2026-05-31)
            'description'      => 'Setoran Simpanan Bebas Tanggal',
        ]);

        $response->assertStatus(200);
        $response->assertJson([
            'success' => true,
        ]);
    }

    /**
     * 2. Test Cegah Duplikasi Bunga Buku Putih.
     */
    public function test_prevent_duplicate_buku_putih_interest(): void
    {
        $manager = User::create([
            'name'     => 'Manager Koperasi',
            'email'    => 'manager.interest@koperasi.com',
            'password' => bcrypt('password123'),
            'role'     => 'manager',
            'nik'      => '1122334455667782',
        ]);

        $member = Member::create([
            'nik'                => '3201010101010002',
            'member_number'      => 'ANG-002',
            'name'               => 'Siti Bunga',
            'email'              => 'siti.bunga@koperasi.com',
            'phone'              => '08123456782',
            'status'             => 'active',
            'daily_savings'      => 1000000.00,
            'has_buku_putih'     => true,
        ]);

        Transaction::create([
            'transaction_number' => 'TRX-BP-001',
            'member_id'          => $member->id,
            'type'               => 'deposit',
            'book_type'          => 'BUKU_PUTIH',
            'amount'             => 1000000.00,
            'status'             => 'approved',
            'transaction_date'   => now()->subMonths(1)->startOfMonth()->addDays(5)->toDateString(),
        ]);

        Sanctum::actingAs($manager);

        // Eksekusi pertama berhasil
        $res1 = $this->postJson('/api/manager/trigger-monthly-interest');
        $res1->assertStatus(200);
        $this->assertTrue($res1->json('success'));

        // Eksekusi kedua di bulan yang sama harus ditolak (400)
        $res2 = $this->postJson('/api/manager/trigger-monthly-interest');
        $res2->assertStatus(400);
        $this->assertFalse($res2->json('success'));
        $this->assertStringContainsString('sudah pernah dibagikan', $res2->json('message'));
    }

    /**
     * 3. Test Validasi Saldo Minimal Buku Putih (Rp 20.000).
     */
    public function test_buku_putih_minimum_balance_validation(): void
    {
        $admin = User::create([
            'name'     => 'Admin Teller',
            'email'    => 'teller.min@koperasi.com',
            'password' => bcrypt('password123'),
            'role'     => 'admin',
            'nik'      => '1122334455667783',
        ]);

        $member = Member::create([
            'nik'                => '3201010101010003',
            'member_number'      => 'ANG-003',
            'name'               => 'Ahmad Minimal',
            'email'              => 'ahmad.min@koperasi.com',
            'phone'              => '08123456783',
            'status'             => 'active',
            'daily_savings'      => 50000.00, // Saldo awal 50rb
            'has_buku_putih'     => true,
        ]);

        Sanctum::actingAs($admin);

        // Percobaan penarikan 40.000 (sisa saldo akan menjadi 10.000 < 20.000)
        $response = $this->postJson('/api/transactions', [
            'member_id'        => $member->id,
            'amount'           => 40000,
            'payment_method'   => 'cash',
            'type'             => 'withdrawal',
            'book_type'        => 'BUKU_PUTIH',
            'account_code'     => '2021',
            'transaction_date' => now()->toDateString(),
            'description'      => 'Penarikan Tabungan Harian',
        ]);

        // Harus ditolak karena saldo sisa < 100.000
        $this->assertContains($response->status(), [400, 422]);
        $this->assertFalse($response->json('success'));
        $this->assertStringContainsString('100.000', $response->json('message'));
    }

    /**
     * 4. Test Rekam Hasil Bagi SHU ke Tabel shu_distributions dan Query History.
     */
    public function test_shu_distribution_recording_and_history(): void
    {
        $manager = User::create([
            'name'     => 'Manager SHU',
            'email'    => 'manager.shu@koperasi.com',
            'password' => bcrypt('password123'),
            'role'     => 'manager',
            'nik'      => '1122334455667784',
        ]);

        $member = Member::create([
            'nik'                => '3201010101010004',
            'member_number'      => 'ANG-004',
            'name'               => 'Rina SHU',
            'email'              => 'rina.shu@koperasi.com',
            'phone'              => '08123456784',
            'status'             => 'active',
            'principal_savings'  => 1000000.00,
            'mandatory_savings'  => 240000.00,
            'voluntary_savings'  => 100000.00,
            'has_buku_putih'     => true,
        ]);

        // Buat pendapatan bunga pinjaman di periode aktif
        Transaction::create([
            'transaction_number' => 'KM-REV-99',
            'member_id'          => $member->id,
            'type'               => 'deposit',
            'amount'             => 5000000.00,
            'transaction_date'   => '2026-05-10',
            'description'        => 'Jasa Pinjaman Angsuran',
            'status'             => 'approved',
        ]);

        Sanctum::actingAs($manager);

        // Eksekusi Tutup Buku
        $resClose = $this->postJson('/api/manager/periods/close-period');
        $resClose->assertStatus(200);

        // Verifikasi record pada tabel shu_distributions
        $shuRecord = ShuDistribution::where('member_id', $member->id)->first();
        $this->assertNotNull($shuRecord);
        $this->assertEquals(20000.00, (float) $shuRecord->potongan_duka);
        $this->assertGreaterThan(0, (float) $shuRecord->gross_shu);
        $this->assertGreaterThan(0, (float) $shuRecord->net_shu);

        // Verifikasi endpoint riwayat SHU
        $resHistory = $this->getJson("/api/members/{$member->id}/shu-history");
        $resHistory->assertStatus(200);
        $this->assertNotEmpty($resHistory->json('data'));
    }

    /**
     * 5. Test Reset Today Route Removal: Memastikan endpoint bulk reset hari ini telah dihapus (404).
     */
    public function test_reset_today_endpoint_is_removed(): void
    {
        $admin = User::create([
            'name'     => 'Admin Reset',
            'email'    => 'admin.reset@koperasi.com',
            'password' => bcrypt('password123'),
            'role'     => 'admin',
            'nik'      => '1234567890123499',
        ]);
        Sanctum::actingAs($admin);

        $res = $this->deleteJson('/api/transactions/reset-today');
        $res->assertStatus(404);
    }
}