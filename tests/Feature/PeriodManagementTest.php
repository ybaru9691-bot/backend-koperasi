<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Member;
use App\Models\MemberShuDistribution;
use App\Models\Period;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class PeriodManagementTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(\Database\Seeders\ChartOfAccountSeeder::class);
    }

    public function test_get_active_period_returns_dynamic_open_period_and_history(): void
    {
        $manager = User::create([
            'name'     => 'Manager Koperasi',
            'email'    => 'manager.period@koperasi.com',
            'password' => bcrypt('123456'),
            'role'     => 'manager',
            'nik'      => '1122334455667711',
        ]);

        Sanctum::actingAs($manager);

        $response = $this->getJson('/api/manager/periods/active');

        $response->assertStatus(200);
        $response->assertJsonStructure([
            'success',
            'data' => [
                'id',
                'period_name',
                'start_date',
                'end_date',
                'status',
                'is_locked',
                'total_transactions',
                'days_remaining',
                'history_locked_periods',
            ]
        ]);

        $data = $response->json('data');
        $this->assertEquals('OPEN', $data['status']);
        $this->assertFalse($data['is_locked']);
        $this->assertIsArray($data['history_locked_periods']);
    }

    public function test_close_period_locks_active_period_distributes_shu_and_opens_new_period(): void
    {
        $manager = User::create([
            'name'     => 'Manager Koperasi',
            'email'    => 'manager.close@koperasi.com',
            'password' => bcrypt('123456'),
            'role'     => 'manager',
            'nik'      => '1122334455667722',
        ]);

        $member = Member::create([
            'member_number'      => 'MBR-001',
            'name'               => 'Marni Tampubolon',
            'nik'                => '1234567890123456',
            'phone'              => '08123456789',
            'principal_savings'  => 5000000.00,
            'mandatory_savings'  => 5000000.00,
            'voluntary_savings'  => 1000000.00,
            'status'             => 'active',
        ]);

        $account = Account::create([
            'account_number' => 'KAS-01',
            'account_name'   => 'Kas Operasional',
            'account_type'   => 'kas',
            'category'       => 'asset',
        ]);

        // Create revenue transaction of Jasa Pinjaman Rp 2.000.000
        Transaction::create([
            'transaction_number' => 'KM-REV-01',
            'member_id'          => $member->id,
            'account_id'         => $account->id,
            'type'               => 'deposit',
            'amount'             => 2000000.00,
            'transaction_date'   => '2026-07-15',
            'description'        => 'Jasa Pinjaman Angsuran #1',
            'status'             => 'approved',
        ]);

        Sanctum::actingAs($manager);

        // Ensure active period exists
        $this->getJson('/api/manager/periods/active');

        // Execute Tutup Buku
        $response = $this->postJson('/api/manager/periods/close-period', [
            'notes' => 'Tutup Buku Tahun Berjalan',
        ]);

        $response->assertStatus(200);
        $response->assertJson([
            'success' => true,
            'status'  => 'success',
        ]);

        $responseData = $response->json('data');
        $this->assertEquals('LOCKED', $responseData['closed_period']['status']);
        $this->assertEquals('OPEN', $responseData['new_period']['status']);
        $this->assertGreaterThan(0, $responseData['distribution_summary']['total_members_processed']);

        // Verify SHU distribution record was created
        $distribution = MemberShuDistribution::where('member_id', $member->id)->first();
        $this->assertNotNull($distribution);
        $this->assertEquals(20000.00, (float) $distribution->potongan_duka);
        $this->assertEquals('distributed', $distribution->status);

        // Verify active period is now the newly opened period
        $activeResponse = $this->getJson('/api/manager/periods/active');
        $activeResponse->assertStatus(200);
        $this->assertEquals($responseData['new_period']['id'], $activeResponse->json('data.id'));
        $this->assertEquals('OPEN', $activeResponse->json('data.status'));
    }

    public function test_create_period_with_flexible_date_range(): void
    {
        $manager = User::create([
            'name'     => 'Manager Koperasi',
            'email'    => 'manager.create@koperasi.com',
            'password' => bcrypt('123456'),
            'role'     => 'manager',
            'nik'      => '1122334455667733',
        ]);

        Sanctum::actingAs($manager);

        $payload = [
            'name'       => 'Tahun Buku Khusus 2026 - 2027',
            'start_date' => '2026-06-01',
            'end_date'   => '2027-05-31',
            'notes'      => 'Periode custom yang dibuat manajer',
            'status'     => 'OPEN',
        ];

        $response = $this->postJson('/api/manager/periods/create', $payload);

        $response->assertStatus(201);
        $response->assertJson([
            'success' => true,
            'data' => [
                'period_name'    => 'Tahun Buku Khusus 2026 - 2027',
                'raw_start_date' => '2026-06-01',
                'raw_end_date'   => '2027-05-31',
                'status'         => 'OPEN',
                'is_locked'      => false,
            ]
        ]);

        // Verify active period endpoint returns this newly created period
        $activeResponse = $this->getJson('/api/manager/periods/active');
        $activeResponse->assertStatus(200);
        $this->assertEquals('Tahun Buku Khusus 2026 - 2027', $activeResponse->json('data.period_name'));
        $this->assertEquals('OPEN', $activeResponse->json('data.status'));
    }

    public function test_open_period_locks_old_period_and_creates_new_period(): void
    {
        $manager = User::create([
            'name'     => 'Manager Koperasi',
            'email'    => 'manager.open@koperasi.com',
            'password' => bcrypt('123456'),
            'role'     => 'manager',
            'nik'      => '1122334455667744',
        ]);

        Sanctum::actingAs($manager);

        // Ensure active period exists first
        $this->getJson('/api/manager/periods/active');

        $payload = [
            'period_name' => 'Juni 2026 - Mei 2027',
            'start_date'  => '2026-06-01',
            'end_date'    => '2027-05-31',
        ];

        $response = $this->postJson('/api/manager/periods/open-period', $payload);

        $response->assertStatus(200);
        $response->assertJson([
            'success' => true,
            'message' => 'Periode baru berhasil dibuka!',
            'data'    => [
                'period_name' => 'Juni 2026 - Mei 2027',
                'status'      => 'OPEN',
            ]
        ]);

        // Verify active period now reflects the newly opened period
        $activeResponse = $this->getJson('/api/manager/periods/active');
        $activeResponse->assertStatus(200);
        $this->assertEquals('Juni 2026 - Mei 2027', $activeResponse->json('data.period_name'));
        $this->assertEquals('OPEN', $activeResponse->json('data.status'));
    }

    public function test_manager_can_unlock_and_delete_period(): void
    {
        $manager = User::create([
            'name'     => 'Manager Koperasi',
            'email'    => 'manager.unlock@koperasi.com',
            'password' => bcrypt('123456'),
            'role'     => 'manager',
            'nik'      => '1122334455667755',
        ]);

        Sanctum::actingAs($manager);

        // Inisialisasi periode default
        $this->getJson('/api/manager/periods/active');

        $lockedPeriod = \App\Models\AccountingPeriod::where('status', 'LOCKED')->first();
        $this->assertNotNull($lockedPeriod);

        // 1. Test Unlock
        $unlockRes = $this->postJson("/api/manager/periods/{$lockedPeriod->id}/unlock");
        $unlockRes->assertStatus(200);
        $unlockRes->assertJson(['success' => true]);

        $this->assertContains(strtolower($lockedPeriod->fresh()->status), ['open', 'terbuka']);
        $this->assertFalse($lockedPeriod->fresh()->is_locked);
        $this->assertTrue($lockedPeriod->fresh()->is_active);

        // 2. Test Delete
        $deleteRes = $this->deleteJson("/api/manager/periods/{$lockedPeriod->id}");
        $deleteRes->assertStatus(200);
        $deleteRes->assertJson(['success' => true]);

        $this->assertNull(\App\Models\AccountingPeriod::find($lockedPeriod->id));
    }
}