<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\ChartOfAccount;
use App\Models\JournalDetail;
use App\Models\JournalEntry;
use App\Models\Loan;
use App\Models\LoanInstallment;
use App\Models\Member;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class DashboardSummaryOptimizationTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_dashboard_summary_caching_and_aggregation()
    {
        Cache::flush();

        // 1. Create Admin User
        $admin = User::create([
            'name'     => 'Admin Utama',
            'email'    => 'admin.opt@koperasi.com',
            'password' => bcrypt('123456'),
            'role'     => 'admin',
            'nik'      => '1234567890123456',
        ]);
        Sanctum::actingAs($admin);

        // 2. Create Members with Savings
        $member1 = Member::create([
            'member_number'      => '001.001',
            'nik'                => '3201010000000001',
            'name'               => 'Member 1',
            'email'              => 'm1@pelita.local',
            'phone'              => '081234567801',
            'status'             => 'active',
            'principal_savings'  => 200000,
            'mandatory_savings'  => 50000,
            'voluntary_savings'  => 25000,
            'daily_savings'      => 100000,
        ]);

        $member2 = Member::create([
            'member_number'      => '001.002',
            'nik'                => '3201010000000002',
            'name'               => 'Member 2',
            'email'              => 'm2@pelita.local',
            'phone'              => '081234567802',
            'status'             => 'active',
            'principal_savings'  => 200000,
            'mandatory_savings'  => 50000,
            'voluntary_savings'  => 0,
            'daily_savings'      => 50000,
        ]);

        // 3. Create Cash Account & Transactions
        $kasAccount = Account::forceCreate([
            'account_number' => '1000',
            'account_name'   => 'Kas',
            'account_type'   => 'kas',
            'category'       => 'asset',
            'balance'        => 1000000,
        ]);

        Transaction::create([
            'transaction_number' => 'TRX-KM-01',
            'receipt_number'     => 'KM-01',
            'member_id'          => $member1->id,
            'account_id'         => $kasAccount->id,
            'type'               => 'deposit',
            'amount'             => 200000,
            'transaction_date'   => now()->toDateString(),
            'description'        => 'Setoran Simpanan',
            'status'             => 'approved',
            'approved_at'        => now(),
        ]);

        Transaction::create([
            'transaction_number' => 'TRX-KK-01',
            'receipt_number'     => 'KK-01',
            'member_id'          => $member1->id,
            'account_id'         => $kasAccount->id,
            'type'               => 'withdrawal',
            'amount'             => 50000,
            'transaction_date'   => now()->toDateString(),
            'description'        => 'Penarikan Tabungan',
            'status'             => 'approved',
            'approved_at'        => now(),
        ]);

        $this->assertFalse(Cache::has('dashboard_summary_data'));

        // 4. Request Dashboard Summary
        $response = $this->getJson('/api/dashboard-summary');
        $response->assertStatus(200);
        $response->assertJson([
            'success' => true,
            'role'    => 'admin',
        ]);

        $data = $response->json('data');
        $this->assertEquals(2, $data['total_members']);
        $this->assertEquals(675000, $data['total_savings']); // 375000 + 300000
        $this->assertEquals(500000, $data['total_saham_tetap']); // (200k+50k) + (200k+50k)
        $this->assertEquals(25000, $data['total_simpanan_sukarela']); // 25000 + 0
        $this->assertEquals(150000, $data['total_tabungan_harian']); // 100000 + 50000
        $this->assertEquals(150000, $data['total_kas_bank']); // 200000 - 50000
        $this->assertEquals(0, $data['cadangan_dana_duka']);

        // 5. Verify Cache is set
        $this->assertTrue(Cache::has('dashboard_summary_data'));
        $cachedData = Cache::get('dashboard_summary_data');
        $this->assertEquals(2, $cachedData['total_members']);
        $this->assertEquals(675000, $cachedData['total_savings']);
        $this->assertEquals(500000, $cachedData['total_saham_tetap']);
        $this->assertEquals(25000, $cachedData['total_simpanan_sukarela']);
        $this->assertEquals(150000, $cachedData['total_tabungan_harian']);
    }

    public function test_manager_dashboard_summary_caching_and_aggregation()
    {
        Cache::flush();

        $manager = User::create([
            'name'     => 'Manager Test',
            'email'    => 'manager.opt@koperasi.com',
            'password' => bcrypt('123456'),
            'role'     => 'manager',
            'nik'      => '1234567890123457',
        ]);
        Sanctum::actingAs($manager);

        $member = Member::create([
            'member_number'      => '001.003',
            'nik'                => '3201010000000003',
            'name'               => 'Member 3',
            'email'              => 'm3@pelita.local',
            'phone'              => '081234567803',
            'status'             => 'active',
            'principal_savings'  => 200000,
            'mandatory_savings'  => 50000,
            'voluntary_savings'  => 35000,
            'daily_savings'      => 75000,
        ]);

        $response = $this->getJson('/api/manager/dashboard-summary');
        $response->assertStatus(200);
        $response->assertJson(['success' => true]);

        $data = $response->json('data');
        $this->assertEquals(1, $data['total_members']);
        $this->assertEquals(360000, $data['total_savings']);
        $this->assertEquals(250000, $data['total_saham_tetap']);
        $this->assertEquals(35000, $data['total_simpanan_sukarela']);
        $this->assertEquals(75000, $data['total_tabungan_harian']);

        $this->assertTrue(Cache::has('manager_dashboard_summary_data'));
    }

    public function test_dashboard_latest_transactions_timezone_wib_and_deduplication()
    {
        Cache::flush();

        $admin = User::create([
            'name'     => 'Admin Timezone',
            'email'    => 'admin.tz@koperasi.com',
            'password' => bcrypt('123456'),
            'role'     => 'admin',
            'nik'      => '1234567890123499',
        ]);
        Sanctum::actingAs($admin);

        $member = Member::create([
            'member_number'      => '001.099',
            'nik'                => '3201010000000099',
            'name'               => 'Member Timezone',
            'email'              => 'tz@pelita.local',
            'phone'              => '081234567899',
            'status'             => 'active',
        ]);

        $kasAccount = Account::firstOrCreate(
            ['account_number' => '1000'],
            [
                'account_name' => 'Kas',
                'account_type' => 'kas',
                'category'     => 'asset',
                'balance'      => 1000000,
            ]
        );

        // Create transaction with double prefix input
        $trx = Transaction::create([
            'transaction_number' => 'KK-KK 0012',
            'receipt_number'     => 'KK-KK 0012',
            'member_id'          => $member->id,
            'account_id'         => $kasAccount->id,
            'type'               => 'withdrawal',
            'amount'             => 50000,
            'transaction_date'   => now()->toDateString(),
            'description'        => 'Uji Potong Saldo',
            'status'             => 'approved',
            'approved_at'        => now(),
        ]);

        $this->assertEquals('KK 0012', $trx->transaction_number);
        $this->assertEquals('KK 0012', $trx->receipt_number);
        $this->assertEquals('KK 0012', $trx->formatted_receipt_no);

        $response = $this->getJson('/api/dashboard-summary');
        $response->assertStatus(200);

        $latest = $response->json('data.latest_transactions');
        $this->assertNotEmpty($latest);
        $first = $latest[0];
        $this->assertEquals('KK 0012', $first['formatted_receipt_no']);
        $this->assertNotEmpty($first['date']);
        $this->assertEquals('withdrawal', $first['type']);
        $this->assertFalse($first['isIncome']);
        $this->assertEquals('Member Timezone', $first['member']['full_name']);
    }
}

