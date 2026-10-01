<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\ChartOfAccount;
use App\Models\JournalEntry;
use App\Models\Member;
use App\Models\MonthlyCooperativeBenchmark;
use App\Models\ShuDistribution;
use App\Models\Transaction;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ShuModuleTest extends TestCase
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

    public function test_record_monthly_shu_with_string_and_integer_month()
    {
        Sanctum::actingAs($this->manager);

        // Buat 2 member Buku Biru
        Member::create([
            'name'              => 'Member A',
            'nik'               => '1234567890123001',
            'member_number'     => '0001',
            'has_buku_biru'     => true,
            'principal_savings' => 1000000.00,
            'mandatory_savings' => 2000000.00,
            'voluntary_savings' => 3000000.00, // Total 6.000.000
            'status'            => 'active',
            'created_at'        => Carbon::parse('2025-01-01'),
        ]);

        Member::create([
            'name'              => 'Member B',
            'nik'               => '1234567890123002',
            'member_number'     => '0002',
            'has_buku_biru'     => true,
            'principal_savings' => 500000.00,
            'mandatory_savings' => 1500000.00,
            'voluntary_savings' => 2000000.00, // Total 4.000.000
            'status'            => 'active',
            'created_at'        => Carbon::parse('2025-01-01'),
        ]);

        // Total seluruh saham = 10.000.000 -> 10.000 lembar

        // 1. Input SHU bulan Agustus dengan string 'AUG'
        $response = $this->postJson('/api/shu/monthly-record', [
            'year'       => 2026,
            'month'      => 'AUG',
            'net_profit' => 40000000.00, // Laba bersih 40.000.000
            'notes'      => 'SHU Agustus 2026',
        ]);

        $response->assertStatus(200);
        $response->assertJson([
            'success' => true,
            'status'  => 'success',
            'data'    => [
                'record' => [
                    'fiscal_year'          => 2026,
                    'month'                => 8,
                    'month_name'           => 'AUG',
                    'net_profit'           => 40000000.00,
                    'allocated_shu_25'     => 10000000.00, // 40jt * 0.25 = 10jt
                    'total_shares_capital' => 10000000.00,
                    'total_shares_units'   => 10000.0,
                    'share_unit_price'     => 1000.0,      // 10jt / 10.000 = 1.000 per lembar
                ],
            ],
        ]);

        // Verifikasi tersimpan di tabel database
        $this->assertDatabaseHas('monthly_cooperative_benchmarks', [
            'fiscal_year'                 => 2026,
            'month'                       => 8,
            'net_income'                  => 40000000.00,
            'dividend_allocation_percent' => 25.00,
        ]);

        // 2. Input SHU bulan September dengan integer 9
        $responseSep = $this->postJson('/api/shu/monthly-record', [
            'year'       => 2026,
            'month'      => 9,
            'net_profit' => 36000000.00,
        ]);

        $responseSep->assertStatus(200);
        $this->assertEquals(9000000.00, $responseSep->json('data.record.allocated_shu_25'));
        $this->assertEquals(900.0, $responseSep->json('data.record.share_unit_price'));

        // Cek rekap 12 bulan dalam response
        $recap = $responseSep->json('data.recap_12_months');
        $this->assertCount(12, $recap['monthly_recap']);
        $this->assertEquals(2026, $recap['fiscal_year']);
    }

    public function test_distribute_shu_mutates_savings_and_returns_recap()
    {
        Sanctum::actingAs($this->manager);

        // Member 1 (Saham: 6jt, 6.000 lembar)
        $member1 = Member::create([
            'name'              => 'Anggota Distribusi 1',
            'nik'               => '1234567890123011',
            'member_number'     => '0011',
            'has_buku_biru'     => true,
            'principal_savings' => 1000000.00,
            'mandatory_savings' => 2000000.00,
            'voluntary_savings' => 3000000.00,
            'status'            => 'active',
            'created_at'        => Carbon::parse('2025-01-01'),
        ]);

        // Member 2 (Saham: 4jt, 4.000 lembar)
        $member2 = Member::create([
            'name'              => 'Anggota Distribusi 2',
            'nik'               => '1234567890123012',
            'member_number'     => '0012',
            'has_buku_biru'     => true,
            'principal_savings' => 500000.00,
            'mandatory_savings' => 1500000.00,
            'voluntary_savings' => 2000000.00,
            'status'            => 'active',
            'created_at'        => Carbon::parse('2025-01-01'),
        ]);

        // 1. Catat SHU bulan Agustus 2026 (Laba 40.000.000, 25% = 10.000.000)
        $this->postJson('/api/shu/monthly-record', [
            'year'       => 2026,
            'month'      => 'AUG',
            'net_profit' => 40000000.00,
        ]);

        // 2. Eksekusi Pembagian Deviden SHU
        $response = $this->postJson('/api/shu/distribute', [
            'year'       => 2026,
            'month'      => 'AUG',
            'voucher_no' => 'BM-DIV-202508',
            'notes'      => 'Distribusi Deviden Agustus 2026',
        ]);

        $response->assertStatus(200);
        $response->assertJson([
            'success' => true,
            'status'  => 'success',
            'data'    => [
                'distribution' => [
                    'processed_count'   => 2,
                    'total_distributed' => 10000000.00,
                    'voucher_number'    => 'BM-DIV-202508',
                ],
            ],
        ]);

        // Verifikasi saldo Simpanan Sukarela Anggota 1 bertambah 6jt (3jt + 6jt = 9jt)
        $member1->refresh();
        $this->assertEquals(9000000.00, (float) $member1->voluntary_savings);

        // Verifikasi saldo Simpanan Sukarela Anggota 2 bertambah 4jt (2jt + 4jt = 6jt)
        $member2->refresh();
        $this->assertEquals(6000000.00, (float) $member2->voluntary_savings);

        // Verifikasi transaksi mutasi
        $this->assertDatabaseHas('transactions', [
            'member_id'      => $member1->id,
            'book_type'      => 'BUKU_BIRU',
            'category'       => 'bunga_saham',
            'type'           => 'deposit',
            'amount'         => 6000000.00,
            'receipt_number' => 'BM-DIV-202508',
        ]);

        // Verifikasi record shu_distributions
        $this->assertDatabaseHas('shu_distributions', [
            'member_id' => $member1->id,
            'deviden'   => 6000000.00,
            'net_shu'   => 6000000.00,
            'status'    => 'distributed',
        ]);

        // Verifikasi record member_shu_distributions
        $this->assertDatabaseHas('member_shu_distributions', [
            'member_id' => $member1->id,
            'deviden'   => 6000000.00,
            'net_shu'   => 6000000.00,
            'status'    => 'distributed',
        ]);

        // Verifikasi Jurnal Umum Double-Entry
        $journal = JournalEntry::where('voucher_number', 'BM-DIV-202508')->first();
        $this->assertNotNull($journal);
        $this->assertTrue($journal->is_balanced);
        $this->assertEquals(10000000.00, $journal->total_debit);
        $this->assertEquals(10000000.00, $journal->total_credit);

        // Verifikasi rekap 12 bulan menunjukkan status 'sudah dibagikan' pada bulan Agustus
        $recap = $response->json('data.recap_12_months');
        $augRecap = collect($recap['monthly_recap'])->firstWhere('month', 8);
        $this->assertNotNull($augRecap);
        $this->assertTrue($augRecap['is_distributed']);
        $this->assertEquals('sudah dibagikan', $augRecap['status']);
        $this->assertEquals(10000000.00, $augRecap['total_distributed']);
    }

    public function test_get_shu_summary_endpoint()
    {
        Sanctum::actingAs($this->manager);

        // Seed data Juni dan Juli
        MonthlyCooperativeBenchmark::create([
            'fiscal_year'                 => 2026,
            'month'                       => 6,
            'net_income'                  => 48000000.00,
            'dividend_allocation_percent' => 25.00,
            'total_coop_shares'           => 100000000.00,
        ]);

        MonthlyCooperativeBenchmark::create([
            'fiscal_year'                 => 2026,
            'month'                       => 7,
            'net_income'                  => 42000000.00,
            'dividend_allocation_percent' => 25.00,
            'total_coop_shares'           => 100000000.00,
        ]);

        $response = $this->getJson('/api/shu/summary?fiscal_year=2026');

        $response->assertStatus(200);
        $response->assertJson([
            'success' => true,
            'status'  => 'success',
            'data'    => [
                'fiscal_year'            => 2026,
                'total_net_profit'       => 90000000.00, // 48jt + 42jt
                'total_allocated_shu_25' => 22500000.00, // 12jt + 10.5jt
            ],
        ]);

        $months = $response->json('data.monthly_recap');
        $this->assertCount(12, $months);
        $this->assertEquals('Jun', $months[0]['month_name']);
        $this->assertEquals(48000000.00, $months[0]['net_profit']);
        $this->assertEquals('Jul', $months[1]['month_name']);
        $this->assertEquals(42000000.00, $months[1]['net_profit']);
        $this->assertEquals('AUG', $months[2]['month_name']);
        $this->assertEquals(0.0, $months[2]['net_profit']);
    }
}
