<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Member;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ExecutiveSummaryReportTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(\Database\Seeders\ChartOfAccountSeeder::class);
    }

    public function test_executive_summary_report_for_pure_savings_period_has_zero_shu(): void
    {
        $manager = User::create([
            'name'     => 'Manager Koperasi',
            'email'    => 'manager@koperasi.com',
            'password' => bcrypt('123456'),
            'role'     => 'manager',
            'nik'      => '1122334455667788',
        ]);

        $member = Member::create([
            'member_number' => 'MBR-001',
            'name'          => 'Anggota 1',
            'nik'           => '1234567890123456',
            'phone'         => '08123456789',
            'status'        => 'active',
        ]);

        $account = Account::create([
            'account_number' => 'KAS-01',
            'account_name'   => 'Kas Utama',
            'account_type'   => 'kas',
            'category'       => 'asset',
        ]);

        // Simpanan Pokok Rp 50.000.000 (Pos Modal / Simpanan)
        Transaction::create([
            'transaction_number' => 'KM-202608-001',
            'member_id'          => $member->id,
            'account_id'         => $account->id,
            'type'               => 'deposit',
            'amount'             => 50000000.00,
            'transaction_date'   => '2026-08-19',
            'description'        => 'Simpanan Pokok',
            'status'             => 'approved',
        ]);

        // Simpanan Wajib Rp 100.000.000 (Pos Modal / Simpanan)
        Transaction::create([
            'transaction_number' => 'KM-202608-002',
            'member_id'          => $member->id,
            'account_id'         => $account->id,
            'type'               => 'deposit',
            'amount'             => 100000000.00,
            'transaction_date'   => '2026-08-19',
            'description'        => 'Simpanan Wajib',
            'status'             => 'approved',
        ]);

        // Setoran Simpanan Awal Anggota Rp 510.000
        Transaction::create([
            'transaction_number' => 'KM-202608-003',
            'member_id'          => $member->id,
            'account_id'         => $account->id,
            'type'               => 'deposit',
            'amount'             => 510000.00,
            'transaction_date'   => '2026-08-19',
            'description'        => 'Setoran Simpanan Awal Anggota (Buku Biru)',
            'status'             => 'approved',
        ]);

        Sanctum::actingAs($manager);

        $response = $this->getJson('/api/manager/reports/executive-summary?month=8&year=2026');

        $response->assertStatus(200);
        $response->assertJsonStructure([
            'success',
            'data' => [
                'total_cash_in',
                'total_cash_out',
                'net_cashflow',
                'net_shu',
                'shu_percentage',
                'allocated_shu',
                'in_categories',
                'out_categories',
            ]
        ]);

        $data = $response->json('data');
        $this->assertEquals(150510000.00, $data['total_cash_in']);
        $this->assertEquals(0.00, $data['total_cash_out']);
        $this->assertEquals(150510000.00, $data['net_cashflow']);
        $this->assertEquals(0.00, $data['net_shu']);
        $this->assertEquals(25.00, $data['shu_percentage']);
        $this->assertEquals(0.00, $data['allocated_shu']);
        $this->assertNotEmpty($data['in_categories']);
    }

    public function test_executive_summary_report_with_revenue_transactions_calculates_shu_properly(): void
    {
        $manager = User::create([
            'name'     => 'Manager Koperasi',
            'email'    => 'manager2@koperasi.com',
            'password' => bcrypt('123456'),
            'role'     => 'manager',
            'nik'      => '1122334455667799',
        ]);

        $member = Member::create([
            'member_number' => 'MBR-002',
            'name'          => 'Anggota 2',
            'nik'           => '1234567890123457',
            'phone'         => '08123456788',
            'status'        => 'active',
        ]);

        $account = Account::create([
            'account_number' => 'KAS-02',
            'account_name'   => 'Kas Operasional',
            'account_type'   => 'kas',
            'category'       => 'asset',
        ]);

        // Pendapatan Jasa Pinjaman Rp 1.000.000
        Transaction::create([
            'transaction_number' => 'KM-202608-101',
            'member_id'          => $member->id,
            'account_id'         => $account->id,
            'type'               => 'deposit',
            'amount'             => 1000000.00,
            'transaction_date'   => '2026-08-19',
            'description'        => 'Jasa Pinjaman Angsuran #1',
            'status'             => 'approved',
        ]);

        // Beban Operasional / Listrik Rp 200.000
        Transaction::create([
            'transaction_number' => 'KK-202608-102',
            'member_id'          => $member->id,
            'account_id'         => $account->id,
            'type'               => 'withdrawal',
            'amount'             => 200000.00,
            'transaction_date'   => '2026-08-19',
            'description'        => 'Beban Operasional Listrik Kantor',
            'status'             => 'approved',
        ]);

        Sanctum::actingAs($manager);

        $response = $this->getJson('/api/manager/reports/executive-summary?month=8&year=2026');

        $response->assertStatus(200);
        $data = $response->json('data');

        $this->assertEquals(1000000.00, $data['total_cash_in']);
        $this->assertEquals(200000.00, $data['total_cash_out']);
        $this->assertEquals(800000.00, $data['net_cashflow']);
        $this->assertEquals(800000.00, $data['net_shu']);
        $this->assertEquals(25.00, $data['shu_percentage']);
        // 25% of 800,000 = 200,000
        $this->assertEquals(200000.00, $data['allocated_shu']);
    }

    public function test_manager_can_get_and_update_shu_percentage(): void
    {
        $manager = User::create([
            'name'     => 'Manager Koperasi',
            'email'    => 'manager3@koperasi.com',
            'password' => bcrypt('123456'),
            'role'     => 'manager',
            'nik'      => '1122334455667700',
        ]);

        Sanctum::actingAs($manager);

        // 1. GET initial SHU percentage (default 25%)
        $getResponse = $this->getJson('/api/manager/settings/shu-percentage');
        $getResponse->assertStatus(200);
        $getResponse->assertJson([
            'success' => true,
            'data' => [
                'shu_percentage' => 25.0,
            ]
        ]);

        // 2. PUT update SHU percentage to 30%
        $putResponse = $this->putJson('/api/manager/settings/shu-percentage', [
            'percentage'     => 30.0,
            'shu_percentage' => 30.0,
        ]);
        $putResponse->assertStatus(200);
        $putResponse->assertJson([
            'success' => true,
            'data' => [
                'shu_percentage' => 30.0,
            ]
        ]);

        // 3. Verify GET returns the newly updated percentage
        $getUpdatedResponse = $this->getJson('/api/manager/settings/shu-percentage');
        $getUpdatedResponse->assertStatus(200);
        $this->assertEquals(30.0, $getUpdatedResponse->json('data.shu_percentage'));
    }

    public function test_manager_report_summary_rekapitulasi_filtered_by_month_and_year(): void
    {
        $manager = User::create([
            'name'     => 'Manager Audit Test',
            'email'    => 'manager_audit@koperasi.com',
            'password' => bcrypt('123456'),
            'role'     => 'manager',
            'nik'      => '1122334455667711',
        ]);

        $account = Account::create([
            'account_number' => 'KAS-03',
            'account_name'   => 'Kas Operasional',
            'account_type'   => 'kas',
            'category'       => 'asset',
        ]);

        // Transactions in August 2026 (Month 8):
        // 2 Approved, 1 Pending, 1 Rejected = 4 Total
        Transaction::create([
            'transaction_number' => 'KM-202608-A1',
            'account_id'         => $account->id,
            'type'               => 'deposit',
            'amount'             => 100000.00,
            'transaction_date'   => '2026-08-10',
            'description'        => 'Setoran A1',
            'status'             => 'approved',
        ]);

        Transaction::create([
            'transaction_number' => 'KM-202608-A2',
            'account_id'         => $account->id,
            'type'               => 'deposit',
            'amount'             => 200000.00,
            'transaction_date'   => '2026-08-15',
            'description'        => 'Setoran A2',
            'status'             => 'approved',
        ]);

        Transaction::create([
            'transaction_number' => 'KM-202608-P1',
            'account_id'         => $account->id,
            'type'               => 'deposit',
            'amount'             => 300000.00,
            'transaction_date'   => '2026-08-20',
            'description'        => 'Setoran P1',
            'status'             => 'pending',
        ]);

        Transaction::create([
            'transaction_number' => 'KM-202608-R1',
            'account_id'         => $account->id,
            'type'               => 'deposit',
            'amount'             => 400000.00,
            'transaction_date'   => '2026-08-25',
            'description'        => 'Setoran R1',
            'status'             => 'rejected',
        ]);

        // Transactions in July 2026 (Month 7) - should NOT be included in August query:
        Transaction::create([
            'transaction_number' => 'KM-202607-O1',
            'account_id'         => $account->id,
            'type'               => 'deposit',
            'amount'             => 500000.00,
            'transaction_date'   => '2026-07-10',
            'description'        => 'Setoran Juli',
            'status'             => 'approved',
        ]);

        Sanctum::actingAs($manager);

        // Query for August 2026
        $response = $this->getJson('/api/manager/financial-summary?month=8&year=2026');

        $response->assertStatus(200);
        $data = $response->json('data');

        // Verify Rekapitulasi Audit strictly matches August 2026 transactions only
        $this->assertEquals(4, $data['total_input_admin']);
        $this->assertEquals(2, $data['auto_approved_count']);
        $this->assertEquals(1, $data['manual_approval_pending_count']);
        $this->assertEquals(1, $data['rejected_count']);
    }
}