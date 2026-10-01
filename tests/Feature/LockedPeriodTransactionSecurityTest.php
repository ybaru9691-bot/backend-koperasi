<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\AccountingPeriod;
use App\Models\Member;
use App\Models\Period;
use App\Models\Transaction;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class LockedPeriodTransactionSecurityTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;
    protected User $manager;
    protected Member $member;
    protected Account $account;
    protected Period $lockedPeriod;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(\Database\Seeders\ChartOfAccountSeeder::class);

        $this->admin = User::create([
            'name'     => 'Admin Test',
            'email'    => 'admin.lock@koperasi.com',
            'password' => bcrypt('password'),
            'role'     => 'admin',
            'nik'      => '1122334455660001',
        ]);

        $this->manager = User::create([
            'name'     => 'Manager Test',
            'email'    => 'manager.lock@koperasi.com',
            'password' => bcrypt('password'),
            'role'     => 'manager',
            'nik'      => '1122334455660002',
        ]);

        $this->member = Member::create([
            'member_number'     => '2020-0001',
            'name'              => 'Anggota Uji Coba',
            'nik'               => '1234567890123001',
            'phone'             => '081234567890',
            'has_buku_biru'     => true,
            'has_buku_putih'    => true,
            'principal_savings' => 500000.00,
            'mandatory_savings' => 500000.00,
            'voluntary_savings' => 500000.00,
            'status'            => 'active',
        ]);

        $this->account = Account::firstOrCreate(
            ['account_number' => 'KAS-101'],
            ['account_name' => 'Kas Koperasi', 'account_type' => 'kas', 'category' => 'asset', 'balance' => 10000000.00]
        );

        // Kunci Periode Akuntansi: 1 Juni 2025 s/d 31 Mei 2026 (LOCKED)
        $this->lockedPeriod = Period::create([
            'period_name' => 'Tahun Buku 2025/2026',
            'start_date'  => '2025-06-01',
            'end_date'    => '2026-05-31',
            'status'      => 'closed',
            'is_locked'   => true,
            'is_active'   => false,
        ]);

        AccountingPeriod::create([
            'period_name' => 'Tahun Buku 2025/2026',
            'start_date'  => '2025-06-01',
            'end_date'    => '2026-05-31',
            'status'      => 'LOCKED',
            'is_locked'   => true,
            'is_active'   => false,
        ]);

        // Periode Aktif Baru (OPEN): 1 Juni 2026 s/d 31 Mei 2027
        Period::create([
            'period_name' => 'Tahun Buku 2026/2027',
            'start_date'  => '2026-06-01',
            'end_date'    => '2027-05-31',
            'status'      => 'open',
            'is_locked'   => false,
            'is_active'   => true,
        ]);
    }

    public function test_post_transaction_in_locked_period_is_rejected_with_403(): void
    {
        Sanctum::actingAs($this->admin);

        $payload = [
            'member_id'        => $this->member->id,
            'payment_method'   => 'cash',
            'transaction_date' => '2025-10-15', // Di dalam rentang 2025-06-01 s/d 2026-05-31 (LOCKED)
            'items'            => [
                [
                    'amount'       => 100000,
                    'account_code' => '2020',
                    'description'  => 'Setoran Simpanan Wajib',
                ]
            ],
        ];

        $response = $this->postJson('/api/transactions', $payload);

        $response->assertStatus(403);
        $this->assertStringContainsString('Periode akuntansi telah ditutup dan dikunci', $response->json('message'));
    }

    public function test_post_daily_transaction_in_locked_period_is_rejected_with_403(): void
    {
        Sanctum::actingAs($this->admin);

        $payload = [
            'member_id'        => $this->member->id,
            'payment_method'   => 'cash',
            'transaction_date' => '2026-01-20', // Di dalam rentang LOCKED
            'items'            => [
                [
                    'amount'       => 50000,
                    'account_code' => '2021',
                    'description'  => 'Setoran Harian',
                ]
            ],
        ];

        $response = $this->postJson('/api/daily-transactions', $payload);

        $response->assertStatus(403);
        $this->assertStringContainsString('Periode akuntansi telah ditutup dan dikunci', $response->json('message'));
    }

    public function test_post_income_km_in_locked_period_is_rejected_with_403(): void
    {
        Sanctum::actingAs($this->admin);

        $payload = [
            'amount'           => 150000,
            'date'             => '2025-11-10', // LOCKED
            'payment_method'   => 'cash',
            'account_code'     => '4191',
            'description'      => 'Pendapatan Administrasi',
        ];

        $response = $this->postJson('/api/incomes', $payload);

        $response->assertStatus(403);
        $this->assertStringContainsString('Periode akuntansi telah ditutup dan dikunci', $response->json('message'));
    }

    public function test_post_expense_kk_in_locked_period_is_rejected_with_403(): void
    {
        Sanctum::actingAs($this->manager);

        $payload = [
            'amount'           => 250000,
            'date'             => '2025-12-05', // LOCKED
            'payment_method'   => 'cash',
            'account_code'     => '7100',
            'description'      => 'Beban ATK Kantor',
        ];

        $response = $this->postJson('/api/manager/expense', $payload);

        $response->assertStatus(403);
        $this->assertStringContainsString('Periode akuntansi telah ditutup dan dikunci', $response->json('message'));
    }

    public function test_put_transaction_in_locked_period_is_rejected_with_403(): void
    {
        Sanctum::actingAs($this->admin);

        // Buat transaksi historis sebelum diuji
        $trx = Transaction::withoutPeriodLock(function () {
            return Transaction::create([
                'transaction_number' => 'KM-HIST-01',
                'receipt_number'     => 'KM 001',
                'member_id'          => $this->member->id,
                'account_id'         => $this->account->id,
                'type'               => 'deposit',
                'amount'             => 100000.00,
                'transaction_date'   => '2025-08-15', // Tanggal di dalam periode locked
                'status'             => 'approved',
                'description'        => 'Setoran Sukarela',
            ]);
        });

        $response = $this->putJson("/api/transactions/{$trx->id}", [
            'amount'      => 200000,
            'description' => 'Koreksi Setoran Sukarela',
        ]);

        $response->assertStatus(403);
        $this->assertStringContainsString('Periode akuntansi telah ditutup dan dikunci', $response->json('message'));
    }

    public function test_put_transaction_moving_date_into_locked_period_is_rejected_with_403(): void
    {
        Sanctum::actingAs($this->admin);

        // Buat transaksi pada periode terbuka (2026-08-01)
        $trx = Transaction::create([
            'transaction_number' => 'KM-OPEN-01',
            'receipt_number'     => 'KM 002',
            'member_id'          => $this->member->id,
            'account_id'         => $this->account->id,
            'type'               => 'deposit',
            'amount'             => 100000.00,
            'transaction_date'   => '2026-08-01', // Periode terbuka
            'status'             => 'approved',
            'description'        => 'Setoran Sukarela Periode Baru',
        ]);

        // Coba geser tanggal transaksi ke periode yang terkunci (2025-10-10)
        $response = $this->putJson("/api/transactions/{$trx->id}", [
            'transaction_date' => '2025-10-10', // Locked!
        ]);

        $response->assertStatus(403);
        $this->assertStringContainsString('Periode akuntansi telah ditutup dan dikunci', $response->json('message'));
    }

    public function test_delete_transaction_in_locked_period_is_rejected_with_403(): void
    {
        Sanctum::actingAs($this->admin);

        $trx = Transaction::withoutPeriodLock(function () {
            return Transaction::create([
                'transaction_number' => 'KM-HIST-02',
                'receipt_number'     => 'KM 003',
                'member_id'          => $this->member->id,
                'account_id'         => $this->account->id,
                'type'               => 'deposit',
                'amount'             => 50000.00,
                'transaction_date'   => '2025-09-20', // Locked
                'status'             => 'approved',
                'description'        => 'Setoran Simpanan',
            ]);
        });

        $response = $this->deleteJson("/api/transactions/{$trx->id}");

        $response->assertStatus(403);
        $this->assertStringContainsString('Periode akuntansi telah ditutup dan dikunci', $response->json('message'));

        // Verifikasi transaksi tidak terhapus di database
        $this->assertDatabaseHas('transactions', ['id' => $trx->id]);
    }

    public function test_transaction_in_open_period_is_allowed(): void
    {
        Sanctum::actingAs($this->admin);

        $payload = [
            'member_id'        => $this->member->id,
            'payment_method'   => 'cash',
            'transaction_date' => '2026-07-10', // Di dalam rentang periode terbuka (2026-06-01 s/d 2027-05-31)
            'items'            => [
                [
                    'amount'       => 200000,
                    'account_code' => '2020',
                    'description'  => 'Setoran Simpanan Sukarela',
                ]
            ],
        ];

        $response = $this->postJson('/api/transactions', $payload);

        $response->assertStatus(200);
        $response->assertJson(['success' => true]);
    }

    public function test_create_period_rejects_2_year_duration_with_422(): void
    {
        Sanctum::actingAs($this->manager);

        // Coba buat periode dengan rentang 24 bulan (2 tahun)
        $payload = [
            'name'       => 'Periode 2 Tahun Tidak Valid',
            'start_date' => '2027-06-01',
            'end_date'   => '2029-05-31', // 24 Bulan!
        ];

        $response = $this->postJson('/api/manager/periods/create', $payload);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['end_date']);
        $this->assertStringContainsString('tepat 12 bulan', $response->json('message'));
    }

    public function test_create_period_accepts_exact_12_months(): void
    {
        Sanctum::actingAs($this->manager);

        $payload = [
            'name'       => 'Tahun Buku 2027 - 2028',
            'start_date' => '2027-06-01',
            'end_date'   => '2028-05-31', // Tepat 12 bulan
        ];

        $response = $this->postJson('/api/manager/periods/create', $payload);

        $response->assertStatus(201);
        $response->assertJson(['success' => true]);
    }

    public function test_member_savings_rollover_preserves_balance_in_new_period(): void
    {
        Sanctum::actingAs($this->manager);

        // 1. Simpan saldo simpanan awal
        $this->member->update([
            'principal_savings' => 200000.00,
            'mandatory_savings' => 800000.00,
            'voluntary_savings' => 1500000.00,
        ]);

        // 2. Eksekusi rollover melalui PeriodClosingService
        $activePeriod = Period::where('status', 'open')->first();
        $nextPeriod = Period::create([
            'period_name' => 'Tahun Buku 2027/2028',
            'start_date'  => '2027-06-01',
            'end_date'    => '2028-05-31',
            'status'      => 'open',
        ]);

        $res = \App\Services\PeriodClosingService::rolloverMemberSavings($activePeriod, $nextPeriod, $this->manager);

        $this->assertGreaterThan(0, $res['total_members_processed']);
        $this->assertEquals(2500000.00, $res['total_rollover_shares']);

        // Pastikan saldo anggota tetap utuh melanjutkan periode sebelumnya
        $freshMember = $this->member->fresh();
        $this->assertEquals(200000.00, (float) $freshMember->principal_savings);
        $this->assertEquals(800000.00, (float) $freshMember->mandatory_savings);
        $this->assertEquals(1500000.00, (float) $freshMember->voluntary_savings);
        $this->assertEquals(2500000.00, (float) ($freshMember->principal_savings + $freshMember->mandatory_savings + $freshMember->voluntary_savings));
    }
}