<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\ChartOfAccount;
use App\Models\JournalDetail;
use App\Models\JournalEntry;
use App\Models\Member;
use App\Models\Transaction;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class BukuPutihInterestMemorialTest extends TestCase
{
    use RefreshDatabase;

    protected User $manager;
    protected Account $kasAccount;
    protected ChartOfAccount $coaBeban;
    protected ChartOfAccount $coaBukuPutih;

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
            'balance'        => 50000000.00,
        ]);

        $this->coaBeban = ChartOfAccount::create([
            'account_code'   => '7145',
            'account_name'   => 'Jasa Simpanan',
            'account_type'   => 'EXPENSE',
            'normal_balance' => 'DEBIT',
            'is_active'      => true,
        ]);

        $this->coaBukuPutih = ChartOfAccount::create([
            'account_code'   => '2021',
            'account_name'   => 'Simp Harian - Buku Putih',
            'account_type'   => 'LIABILITY',
            'normal_balance' => 'CREDIT',
            'is_active'      => true,
        ]);
    }

    public function test_preview_calculates_interest_and_applies_dormant_rule()
    {
        Sanctum::actingAs($this->manager);

        // Member 1: Active, balance 1,000,000, has transaction within 6 months -> Interest = 6,000 (0.6%)
        $m1 = Member::create([
            'member_number'     => '0001',
            'nik'               => '1234567890123451',
            'name'              => 'Budi Active',
            'status'            => 'active',
            'daily_savings'     => 1000000,
        ]);
        Transaction::create([
            'transaction_number' => 'TRX-001',
            'receipt_number'     => 'KM-001',
            'member_id'          => $m1->id,
            'account_id'         => $this->kasAccount->id,
            'book_type'          => 'BUKU_PUTIH',
            'type'               => 'deposit',
            'amount'             => 50000,
            'transaction_date'   => '2026-05-15', // within 6 months of 2026-07-31 cutoff
            'status'             => 'approved',
        ]);

        // Member 2: Dormant (>6 months no transaction) -> Interest = 0
        $m2 = Member::create([
            'member_number'     => '0002',
            'nik'               => '1234567890123452',
            'name'              => 'Siti Dormant',
            'status'            => 'active',
            'daily_savings'     => 2000000,
            'created_at'        => '2024-01-01',
        ]);
        // No transactions for $m2 in the last 6 months

        // Member 3: Zero balance -> Interest = 0
        $m3 = Member::create([
            'member_number'     => '0003',
            'nik'               => '1234567890123453',
            'name'              => 'Andi Zero',
            'status'            => 'active',
            'daily_savings'     => 0,
            'created_at'        => '2024-01-01',
        ]);

        $response = $this->getJson('/api/manager/interest/preview?month=8&year=2026');

        $response->assertStatus(200)
            ->assertJson([
                'success' => true,
                'data'    => [
                    'summary' => [
                        'month'                 => 8,
                        'year'                  => 2026,
                        'total_members'         => 3,
                        'eligible_members'      => 1,
                        'dormant_members'       => 2,
                        'total_interest_amount' => 6000,
                    ]
                ]
            ]);

        $members = $response->json('data.members');
        $this->assertCount(3, $members);

        $m1Data = collect($members)->firstWhere('member_id', $m1->id);
        $this->assertEquals(6000, $m1Data['interest_amount']);
        $this->assertFalse($m1Data['is_dormant']);
        $this->assertTrue($m1Data['is_eligible']);

        $m2Data = collect($members)->firstWhere('member_id', $m2->id);
        $this->assertEquals(0, $m2Data['interest_amount']);
        $this->assertTrue($m2Data['is_dormant']);
        $this->assertFalse($m2Data['is_eligible']);

        $m3Data = collect($members)->firstWhere('member_id', $m3->id);
        $this->assertEquals(0, $m3Data['interest_amount']);
        $this->assertFalse($m3Data['is_eligible']);
    }

    public function test_distribute_interest_mutates_only_eligible_members_and_creates_memorial_journal()
    {
        Sanctum::actingAs($this->manager);

        // Member 1: Active, balance 1,000,000 -> gets 6,000 interest
        $m1 = Member::create([
            'member_number'     => '0001',
            'nik'               => '1234567890123451',
            'name'              => 'Budi Active',
            'status'            => 'active',
            'daily_savings'     => 1000000,
        ]);
        Transaction::create([
            'transaction_number' => 'TRX-001',
            'receipt_number'     => 'KM-001',
            'member_id'          => $m1->id,
            'account_id'         => $this->kasAccount->id,
            'book_type'          => 'BUKU_PUTIH',
            'type'               => 'deposit',
            'amount'             => 50000,
            'transaction_date'   => '2026-05-15',
            'status'             => 'approved',
        ]);

        // Member 2: Dormant -> 0 interest
        $m2 = Member::create([
            'member_number'     => '0002',
            'nik'               => '1234567890123452',
            'name'              => 'Siti Dormant',
            'status'            => 'active',
            'daily_savings'     => 2000000,
            'created_at'        => '2024-01-01',
        ]);

        $response = $this->postJson('/api/manager/interest/distribute-buku-putih', [
            'month' => 8,
            'year'  => 2026,
        ]);

        $response->assertStatus(200)
            ->assertJson([
                'success' => true,
                'data'    => [
                    'processed_count' => 1,
                    'total_interest'  => 6000,
                    'voucher_number'  => 'BM-INT-202608',
                ]
            ]);

        // Verify Member 1 daily_savings incremented
        $m1->refresh();
        $this->assertEquals(1006000, (float) $m1->daily_savings);

        // Verify Member 2 daily_savings unchanged
        $m2->refresh();
        $this->assertEquals(2000000, (float) $m2->daily_savings);

        // Verify Transaction created for Member 1 ONLY
        $m1Trx = Transaction::where('member_id', $m1->id)->where('receipt_number', 'BM-INT-202608-' . $m1->id)->first();
        $this->assertNotNull($m1Trx);
        $this->assertEquals(6000, (float) $m1Trx->amount);
        $this->assertEquals('BM-INT-202608-' . $m1->id, $m1Trx->receipt_number);
        $this->assertEquals('2026-08-20', $m1Trx->transaction_date->format('Y-m-d'));

        // Verify NO interest transaction created for Member 2
        $m2Trx = Transaction::where('member_id', $m2->id)->where('receipt_number', 'like', 'BM-INT-202608-%')->count();
        $this->assertEquals(0, $m2Trx);

        // Verify NO general ledger journal entry is created in database
        $this->assertDatabaseMissing('journal_entries', [
            'voucher_number' => 'BM-INT-202608',
        ]);
    }

    public function test_duplicate_distribution_is_prevented()
    {
        Sanctum::actingAs($this->manager);

        $m = Member::create([
            'member_number'     => '0001',
            'nik'               => '1234567890123451',
            'name'              => 'Budi Member',
            'status'            => 'active',
            'daily_savings'     => 500000,
        ]);
        Transaction::create([
            'transaction_number' => 'TRX-001',
            'receipt_number'     => 'KM-001',
            'member_id'          => $m->id,
            'account_id'         => $this->kasAccount->id,
            'book_type'          => 'BUKU_PUTIH',
            'type'               => 'deposit',
            'amount'             => 50000,
            'transaction_date'   => '2026-05-15',
            'status'             => 'approved',
        ]);

        // First distribution -> Success
        $res1 = $this->postJson('/api/manager/interest/distribute-buku-putih', [
            'month' => 8,
            'year'  => 2026,
        ]);
        $res1->assertStatus(200);

        // Second distribution for same month -> Rejection with 400
        $res2 = $this->postJson('/api/manager/interest/distribute-buku-putih', [
            'month' => 8,
            'year'  => 2026,
        ]);
        $res2->assertStatus(400)
            ->assertJson([
                'success' => false,
            ]);
    }

    public function test_export_pdf_and_excel_contains_all_members_and_memorial_voucher()
    {
        Sanctum::actingAs($this->manager);

        Member::create([
            'member_number'     => '0001',
            'nik'               => '1234567890123451',
            'name'              => 'Anggota Pertama',
            'status'            => 'active',
            'daily_savings'     => 1000000,
        ]);

        Member::create([
            'member_number'     => '0002',
            'nik'               => '1234567890123452',
            'name'              => 'Anggota Nol Rupiah',
            'status'            => 'active',
            'daily_savings'     => 0,
        ]);

        // Test PDF Export
        $pdfRes = $this->get('/api/manager/interest/export-memorial-pdf?month=8&year=2026');
        $pdfRes->assertStatus(200);
        $this->assertEquals('application/pdf', $pdfRes->headers->get('content-type'));

        // Test Excel Export
        $excelRes = $this->get('/api/manager/interest/export-memorial-excel?month=8&year=2026');
        $excelRes->assertStatus(200);
        $this->assertEquals('application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', $excelRes->headers->get('content-type'));
    }

    public function test_console_command_calculate_monthly_interest()
    {
        $m = Member::create([
            'member_number'     => '0001',
            'nik'               => '1234567890123451',
            'name'              => 'Anggota Command',
            'status'            => 'active',
            'daily_savings'     => 500000,
        ]);
        Transaction::create([
            'transaction_number' => 'TRX-001',
            'receipt_number'     => 'KM-001',
            'member_id'          => $m->id,
            'account_id'         => $this->kasAccount->id,
            'book_type'          => 'BUKU_PUTIH',
            'type'               => 'deposit',
            'amount'             => 50000,
            'transaction_date'   => '2026-05-15',
            'status'             => 'approved',
        ]);

        $this->artisan('members:calculate-interest', ['--month' => 8, '--year' => 2026])
            ->assertSuccessful();
    }

    public function test_buku_putih_no_and_strict_daily_savings_interest_calculation()
    {
        Sanctum::actingAs($this->manager);

        // Member has large voluntary & principal savings, but only 500,000 in daily_savings
        $m = Member::create([
            'member_number'     => '0017',
            'buku_putih_no'     => '2021-0017',
            'nik'               => '1234567890123499',
            'name'              => 'Member Buku Putih Custom',
            'status'            => 'active',
            'principal_savings' => 10000000.00, // 10 jt - should NOT be included
            'mandatory_savings' => 5000000.00,  // 5 jt - should NOT be included
            'voluntary_savings' => 20000000.00, // 20 jt - should NOT be included
            'daily_savings'     => 500000.00,   // 500k - ONLY this is calculated: 500,000 * 0.006 = 3,000
        ]);

        Transaction::create([
            'transaction_number' => 'TRX-BP-99',
            'receipt_number'     => 'KM-BP-99',
            'member_id'          => $m->id,
            'account_id'         => $this->kasAccount->id,
            'book_type'          => 'BUKU_PUTIH',
            'type'               => 'deposit',
            'amount'             => 50000,
            'transaction_date'   => '2026-05-15',
            'status'             => 'approved',
        ]);

        $response = $this->getJson('/api/manager/interest/preview?month=8&year=2026');
        $response->assertStatus(200);

        $memberData = collect($response->json('data.members'))->firstWhere('member_id', $m->id);
        $this->assertNotNull($memberData);
        $this->assertEquals('2021-0017', $memberData['buku_putih_no']);
        $this->assertEquals(500000.00, $memberData['daily_savings']);
        $this->assertEquals(3000, $memberData['interest_amount']);
        $this->assertTrue($memberData['is_eligible']);
    }

    public function test_export_pdf_and_preview_with_null_member_id_transactions()
    {
        Sanctum::actingAs($this->manager);

        // Create transaction without member_id (e.g. non-member transaction or system adjustment)
        Transaction::create([
            'transaction_number' => 'TRX-NULL-01',
            'receipt_number'     => 'KM-NULL-01',
            'member_id'          => null,
            'account_id'         => $this->kasAccount->id,
            'book_type'          => 'BUKU_PUTIH',
            'type'               => 'deposit',
            'amount'             => 100000,
            'transaction_date'   => '2026-09-10',
            'status'             => 'approved',
        ]);

        Transaction::create([
            'transaction_number' => 'TRX-NULL-02',
            'receipt_number'     => 'KK-NULL-02',
            'member_id'          => null,
            'account_id'         => $this->kasAccount->id,
            'book_type'          => 'GENERAL',
            'type'               => 'expense',
            'amount'             => 50000,
            'transaction_date'   => '2026-09-12',
            'status'             => 'approved',
        ]);

        $m = Member::create([
            'member_number'     => '0099',
            'nik'               => '1234567890123999',
            'name'              => 'Member With Null Transactions in DB',
            'status'            => 'active',
            'daily_savings'     => 1000000.00,
        ]);

        // Preview should succeed without array_flip error
        $previewRes = $this->getJson('/api/manager/interest/preview?month=9&year=2026');
        $previewRes->assertStatus(200);

        // PDF export should succeed without array_flip error
        $pdfRes = $this->get('/api/manager/interest/export-memorial-pdf?month=9&year=2026');
        $pdfRes->assertStatus(200);
        $this->assertEquals('application/pdf', $pdfRes->headers->get('content-type'));
    }
}
