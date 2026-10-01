<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\ChartOfAccount;
use App\Models\JournalEntry;
use App\Models\Member;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class PureDailySavingsBukuPutihTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;
    protected User $manager;
    protected Member $memberWithBukuPutih;
    protected Member $memberWithoutBukuPutih;
    protected Account $kasAccount;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(\Database\Seeders\ChartOfAccountSeeder::class);

        $this->admin = User::create([
            'name'     => 'Admin Kasir',
            'email'    => 'admin.kasir@koperasi.com',
            'password' => bcrypt('password123'),
            'role'     => 'admin',
            'nik'      => '1122334455667701',
        ]);

        $this->manager = User::create([
            'name'     => 'Manager Koperasi',
            'email'    => 'manager.pure@koperasi.com',
            'password' => bcrypt('password123'),
            'role'     => 'manager',
            'nik'      => '1122334455667702',
        ]);

        $this->kasAccount = Account::firstOrCreate(
            ['account_number' => 'KAS-101'],
            [
                'account_name'      => 'Kas Koperasi',
                'account_type'      => 'kas',
                'category'          => 'asset',
                'balance'           => 10000000.00,
                'beginning_balance' => 10000000.00,
            ]
        );

        $this->memberWithBukuPutih = Member::create([
            'member_number'     => 'PELITA-001',
            'name'              => 'Basalina Hutauruk',
            'nik'               => '1234567890123456',
            'phone'             => '081234567890',
            'principal_savings' => 1000000.00,
            'mandatory_savings' => 500000.00,
            'voluntary_savings' => 2000000.00,
            'daily_savings'     => 7049240.00,
            'has_buku_biru'     => true,
            'has_buku_putih'    => true,
            'buku_putih_no'     => '2021-0017',
            'status'            => 'active',
        ]);

        $this->memberWithoutBukuPutih = Member::create([
            'member_number'     => 'PELITA-002',
            'name'              => 'Anggota Baru',
            'nik'               => '1234567890123457',
            'phone'             => '081234567891',
            'principal_savings' => 200000.00,
            'mandatory_savings' => 20000.00,
            'voluntary_savings' => 0.00,
            'daily_savings'     => 0.00,
            'has_buku_biru'     => true,
            'has_buku_putih'    => false,
            'buku_putih_no'     => null,
            'status'            => 'active',
        ]);
    }

    public function test_member_detail_and_balances_return_pure_real_database_daily_savings(): void
    {
        Sanctum::actingAs($this->admin);

        // 1. Check Member with Buku Putih
        $res1 = $this->getJson("/api/members/{$this->memberWithBukuPutih->id}");
        $res1->assertStatus(200);
        $res1->assertJson([
            'success' => true,
            'data'    => [
                'daily_savings'  => 7049240.00,
                'has_buku_putih' => true,
                'buku_putih_no'  => '2021-0017',
            ]
        ]);

        $resBal1 = $this->getJson("/api/members/{$this->memberWithBukuPutih->id}/balances");
        $resBal1->assertStatus(200);
        $resBal1->assertJson([
            'success' => true,
            'data'    => [
                'daily_savings'  => 7049240.00,
                'has_buku_putih' => true,
                'buku_putih_no'  => '2021-0017',
            ]
        ]);

        // 2. Check Member without Buku Putih (daily_savings = 0.00)
        $res2 = $this->getJson("/api/members/{$this->memberWithoutBukuPutih->id}");
        $res2->assertStatus(200);
        $res2->assertJson([
            'success' => true,
            'data'    => [
                'daily_savings'  => 0.00,
                'has_buku_putih' => false,
                'buku_putih_no'  => null,
            ]
        ]);
    }

    public function test_daily_savings_deposit_transaction_updates_balance_ledger_and_physical_cash(): void
    {
        Sanctum::actingAs($this->admin);

        $initialCash = (float) $this->kasAccount->fresh()->balance;
        $depositAmount = 500000.00;

        $response = $this->postJson('/api/daily-savings/transaction', [
            'member_id'      => $this->memberWithoutBukuPutih->id,
            'type'           => 'deposit',
            'amount'         => $depositAmount,
            'payment_method' => 'cash',
            'notes'          => 'Setoran Awal Tabungan Harian Kasir',
        ]);

        $response->assertStatus(200);
        $response->assertJson([
            'success' => true,
            'data'    => [
                'member' => [
                    'id'             => $this->memberWithoutBukuPutih->id,
                    'daily_savings'  => 500000.00,
                    'has_buku_putih' => true,
                ]
            ]
        ]);

        // Verify Member DB
        $freshMember = $this->memberWithoutBukuPutih->fresh();
        $this->assertEquals(500000.00, (float) $freshMember->daily_savings);
        $this->assertTrue((bool) $freshMember->has_buku_putih);

        // Verify Transaction Ledger DB
        $this->assertDatabaseHas('transactions', [
            'member_id'         => $this->memberWithoutBukuPutih->id,
            'book_type'         => 'BUKU_PUTIH',
            'type'              => 'deposit',
            'category'          => 'simpanan_harian',
            'amount'            => $depositAmount,
            'beginning_balance' => 0.00,
            'ending_balance'    => 500000.00,
            'status'            => 'approved',
        ]);

        // Verify Physical Cash Sync
        $freshCash = (float) $this->kasAccount->fresh()->balance;
        $this->assertEquals($initialCash + $depositAmount, $freshCash);

        // Verify Auto Journal Generated (Kas 1000 Debet, Simpanan Harian 2021 Kredit)
        $tx = Transaction::where('member_id', $this->memberWithoutBukuPutih->id)->latest()->first();
        $journal = JournalEntry::where('transaction_id', $tx->id)->first();
        $this->assertNotNull($journal);

        $coaHarian = ChartOfAccount::where('account_code', '2021')->first();
        $this->assertDatabaseHas('journal_details', [
            'journal_entry_id' => $journal->id,
            'account_id'       => $coaHarian->id,
            'credit'           => $depositAmount,
        ]);
    }

    public function test_daily_savings_withdrawal_rejected_when_insufficient_balance(): void
    {
        Sanctum::actingAs($this->admin);

        // Current balance: 7,049,240 -> Withdraw 8,000,000 should fail
        $response = $this->postJson('/api/daily-savings/transaction', [
            'member_id'      => $this->memberWithBukuPutih->id,
            'type'           => 'withdrawal',
            'amount'         => 8000000.00,
            'payment_method' => 'cash',
            'notes'          => 'Tarik Melebihi Saldo',
        ]);

        $response->assertStatus(422);
        $response->assertJson([
            'success' => false,
            'status'  => 'error',
        ]);

        // Member balance unchanged
        $this->assertEquals(7049240.00, (float) $this->memberWithBukuPutih->fresh()->daily_savings);
    }

    public function test_daily_savings_withdrawal_success_updates_balance_ledger_and_physical_cash(): void
    {
        Sanctum::actingAs($this->admin);

        $initialMemberSavings = (float) $this->memberWithBukuPutih->daily_savings; // 7,049,240
        $initialCash = (float) $this->kasAccount->fresh()->balance;
        $withdrawalAmount = 1000000.00;

        $response = $this->postJson('/api/daily-savings/transaction', [
            'member_id'      => $this->memberWithBukuPutih->id,
            'type'           => 'withdrawal',
            'amount'         => $withdrawalAmount,
            'payment_method' => 'cash',
            'notes'          => 'Penarikan Buku Putih Kasir',
        ]);

        $response->assertStatus(200);
        $response->assertJson([
            'success' => true,
            'data'    => [
                'member' => [
                    'id'            => $this->memberWithBukuPutih->id,
                    'daily_savings' => $initialMemberSavings - $withdrawalAmount,
                ]
            ]
        ]);

        // Verify Member DB
        $freshMember = $this->memberWithBukuPutih->fresh();
        $this->assertEquals(6049240.00, (float) $freshMember->daily_savings);

        // Verify Transaction Ledger DB
        $this->assertDatabaseHas('transactions', [
            'member_id'         => $this->memberWithBukuPutih->id,
            'book_type'         => 'BUKU_PUTIH',
            'type'              => 'withdrawal',
            'category'          => 'tarik_buku_putih',
            'amount'            => $withdrawalAmount,
            'beginning_balance' => $initialMemberSavings,
            'ending_balance'    => 6049240.00,
            'status'            => 'approved',
        ]);

        // Verify Physical Cash Decremented
        $freshCash = (float) $this->kasAccount->fresh()->balance;
        $this->assertEquals($initialCash - $withdrawalAmount, $freshCash);
    }

    public function test_dashboard_aggregates_real_daily_savings_and_physical_cash(): void
    {
        Sanctum::actingAs($this->admin);

        // Total active daily savings in DB = 7,049,240 + 0 = 7,049,240
        $response = $this->getJson('/api/dashboard-summary');
        $response->assertStatus(200);
        $response->assertJson([
            'success' => true,
            'data'    => [
                'total_daily_savings'   => 7049240.00,
                'tabungan_harian'       => 7049240.00,
                'simpanan_bisa_ditarik' => 7049240.00,
            ]
        ]);

        // Manager Dashboard
        Sanctum::actingAs($this->manager);
        $managerRes = $this->getJson('/api/manager/dashboard-summary');
        $managerRes->assertStatus(200);
        $managerRes->assertJson([
            'success' => true,
            'data'    => [
                'total_daily_savings'   => 7049240.00,
                'tabungan_harian'       => 7049240.00,
                'simpanan_bisa_ditarik' => 7049240.00,
            ]
        ]);
    }
}
