<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\ChartOfAccount;
use App\Models\Member;
use App\Models\MonthlyCooperativeBenchmark;
use App\Models\Transaction;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class MonthlyCooperativeBenchmarkTest extends TestCase
{
    use RefreshDatabase;

    protected User $manager;
    protected User $regularUser;
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

        $this->regularUser = User::factory()->create([
            'role' => 'anggota',
            'name' => 'Regular Member',
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

    public function test_get_benchmarks_returns_12_months_for_fiscal_year()
    {
        Sanctum::actingAs($this->manager);

        $response = $this->getJson('/api/manager/coop-benchmarks?fiscal_year=2026');

        $response->assertStatus(200)
            ->assertJson([
                'success' => true,
                'data' => [
                    'fiscal_year' => 2026,
                ]
            ]);

        $this->assertCount(12, $response->json('data.benchmarks'));
        $this->assertEquals('Jun', $response->json('data.benchmarks.0.month_name'));
        $this->assertEquals(6, $response->json('data.benchmarks.0.month'));
        $this->assertEquals('MEI', $response->json('data.benchmarks.11.month_name'));
        $this->assertEquals(5, $response->json('data.benchmarks.11.month'));
    }

    public function test_update_single_month_benchmark()
    {
        Sanctum::actingAs($this->manager);

        $payload = [
            'fiscal_year'                 => 2026,
            'month'                       => 9,
            'net_income'                  => 75000000.00,
            'dividend_allocation_percent' => 25.00,
            'total_coop_shares'           => 1450000000.00,
            'notes'                       => 'Audit SHU September 2026',
        ];

        $response = $this->postJson('/api/manager/coop-benchmarks/update', $payload);

        $response->assertStatus(200)
            ->assertJson([
                'success' => true,
                'data' => [
                    'fiscal_year' => 2026,
                    'updated_count' => 1,
                ]
            ]);

        $this->assertDatabaseHas('monthly_cooperative_benchmarks', [
            'fiscal_year'                 => 2026,
            'month'                       => 9,
            'net_income'                  => 75000000.00,
            'dividend_allocation_percent' => 25.00,
            'total_coop_shares'           => 1450000000.00,
            'notes'                       => 'Audit SHU September 2026',
        ]);
    }

    public function test_update_batch_month_benchmarks()
    {
        Sanctum::actingAs($this->manager);

        $payload = [
            'fiscal_year' => 2026,
            'benchmarks'  => [
                [
                    'month'                       => 6,
                    'net_income'                  => 50000000.00,
                    'dividend_allocation_percent' => 25.00,
                    'total_coop_shares'           => 1200000000.00,
                    'notes'                       => 'Juni',
                ],
                [
                    'month'                       => 7,
                    'net_income'                  => 55000000.00,
                    'dividend_allocation_percent' => 25.00,
                    'total_coop_shares'           => 1250000000.00,
                    'notes'                       => 'Juli',
                ],
            ],
        ];

        $response = $this->postJson('/api/manager/coop-benchmarks/update', $payload);

        $response->assertStatus(200)
            ->assertJson([
                'success' => true,
                'data' => [
                    'fiscal_year' => 2026,
                    'updated_count' => 2,
                ]
            ]);

        $this->assertDatabaseHas('monthly_cooperative_benchmarks', [
            'fiscal_year' => 2026,
            'month'       => 6,
            'net_income'  => 50000000.00,
        ]);

        $this->assertDatabaseHas('monthly_cooperative_benchmarks', [
            'fiscal_year' => 2026,
            'month'       => 7,
            'net_income'  => 55000000.00,
        ]);
    }

    public function test_benchmark_overrides_are_reflected_in_member_dividend_statement()
    {
        Sanctum::actingAs($this->manager);

        // Member Buku Biru
        $member = Member::create([
            'name'              => 'Test Member Biru',
            'nik'               => '1234567890123456',
            'member_number'     => '0001',
            'has_buku_biru'     => true,
            'principal_savings' => 200000.00,
            'mandatory_savings' => 1000000.00,
            'voluntary_savings' => 5000000.00,
            'status'            => 'active',
            'created_at'        => Carbon::parse('2025-01-01'),
        ]);

        // Override Benchmark for Sept 2026 (m=9, y=2026)
        MonthlyCooperativeBenchmark::create([
            'fiscal_year'                 => 2026,
            'month'                       => 9,
            'cycle_start_date'            => '2026-08-21',
            'cycle_end_date'              => '2026-09-20',
            'net_income'                  => 80000000.00, // 80 Juta
            'dividend_allocation_percent' => 25.00,
            'total_coop_shares'           => 1000000000.00, // 1 Milyar (1,000,000 lembar)
        ]);

        $response = $this->getJson("/api/manager/members/{$member->id}/dividend-statement?fiscal_year=2026");

        $response->assertStatus(200)
            ->assertJson([
                'success' => true,
            ]);

        $coopBenchmarks = $response->json('data.coop_benchmarks');
        $this->assertNotEmpty($coopBenchmarks);

        // Cari baris SEP (month = 9)
        $sepRow = collect($coopBenchmarks)->firstWhere('month', 9);
        $this->assertNotNull($sepRow);
        
        // Cek patokan koperasi SEP
        $this->assertEquals(80000000.00, $sepRow['net_income']);
        $this->assertEquals(1000000000.00, $sepRow['total_saham_koperasi']);
        $this->assertEquals(20000000.00, $sepRow['dana_deviden']); // 25% dari 80jt = 20jt
        $this->assertEquals(20.0, $sepRow['harga_deviden_per_lembar']); // 20jt / 1jt lembar = 20
    }

    public function test_validation_fails_on_invalid_month_or_fiscal_year()
    {
        Sanctum::actingAs($this->manager);

        $response = $this->postJson('/api/manager/coop-benchmarks/update', [
            'fiscal_year' => 1990, // below 2000
            'month'       => 13,   // above 12
        ]);

        $response->assertStatus(422);
    }
}
