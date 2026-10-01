<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\ChartOfAccount;
use App\Models\JournalDetail;
use App\Models\JournalEntry;
use App\Models\Member;
use App\Models\Transaction;
use App\Models\User;
use App\Models\WeeklyPeriodLock;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class WeeklyPeriodLockAndWorksheetTest extends TestCase
{
    use RefreshDatabase;

    protected User $manager;
    protected User $admin;
    protected Member $member;
    protected Account $kasAccount;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(\Database\Seeders\ChartOfAccountSeeder::class);

        $this->manager = User::create([
            'name'     => 'Manager Koperasi',
            'email'    => 'manager.weekly@koperasi.com',
            'password' => bcrypt('password123'),
            'role'     => 'manager',
            'nik'      => '1122334455667788',
        ]);

        $this->admin = User::create([
            'name'     => 'Admin Koperasi',
            'email'    => 'admin.weekly@koperasi.com',
            'password' => bcrypt('password123'),
            'role'     => 'admin',
            'nik'      => '1122334455667799',
        ]);

        $this->member = Member::create([
            'member_number'     => 'PELITA-001',
            'name'              => 'Budi Member',
            'nik'               => '1234567890123456',
            'phone'             => '081234567890',
            'principal_savings' => 1000000,
            'mandatory_savings' => 500000,
            'voluntary_savings' => 2000000,
            'daily_savings'     => 1500000,
            'status'            => 'active',
        ]);

        $this->kasAccount = Account::create([
            'account_number' => 'KAS-101',
            'account_name'   => 'Kas Koperasi',
            'account_type'   => 'kas',
            'category'       => 'asset',
            'balance'        => 50000000.00,
        ]);
    }

    public function test_get_worksheet_with_weekly_filter_and_active_status(): void
    {
        Sanctum::actingAs($this->manager);

        $coaKas  = ChartOfAccount::where('account_code', '1000')->first();
        $coaJasa = ChartOfAccount::where('account_code', '4180')->first();

        // Jurnal di Pekan M1 (2026-09-03)
        $j1 = JournalEntry::create([
            'entry_date'     => '2026-09-03',
            'voucher_number' => 'JV-20260903-0001',
            'description'    => 'Pendapatan Jasa M1',
        ]);
        JournalDetail::create([
            'journal_entry_id' => $j1->id,
            'account_id'       => $coaKas->id,
            'debit'            => 1000000.00,
            'credit'           => 0.00,
        ]);
        JournalDetail::create([
            'journal_entry_id' => $j1->id,
            'account_id'       => $coaJasa->id,
            'debit'            => 0.00,
            'credit'           => 1000000.00,
        ]);

        $response = $this->getJson('/api/accounting/worksheet?month=9&year=2026&week=M1');

        $response->assertStatus(200);
        $response->assertJson([
            'success' => true,
            'status'  => 'success',
            'data'    => [
                'status'       => 'active',
                'is_locked'    => false,
                'month'        => 9,
                'year'         => 2026,
                'week'         => 'M1',
                'start_date'   => '2026-09-01',
                'end_date'     => '2026-09-07',
            ]
        ]);

        $accounts = $response->json('data.accounts');
        $this->assertNotEmpty($accounts);
    }

    public function test_lock_weekly_period_success_and_updates_worksheet_status(): void
    {
        Sanctum::actingAs($this->manager);

        // 1. Kunci pekan M1 September 2026
        $lockResponse = $this->postJson('/api/accounting/lock-week', [
            'month' => 9,
            'year'  => 2026,
            'week'  => 'M1',
            'notes' => 'Evaluasi Pembukuan Pekan 1 Selesai',
        ]);

        $lockResponse->assertStatus(200);
        $lockResponse->assertJson([
            'success' => true,
            'status'  => 'success',
            'data'    => [
                'month'     => 9,
                'year'      => 2026,
                'week'      => 'M1',
                'is_locked' => true,
                'status'    => 'locked',
            ]
        ]);

        // Verify in DB
        $this->assertDatabaseHas('weekly_period_locks', [
            'year'      => 2026,
            'month'     => 9,
            'week'      => 'M1',
            'is_locked' => true,
        ]);

        // 2. Cek endpoint worksheet, status harus "locked", memiliki is_locked = true, locked_at, dan accounts
        $worksheetRes = $this->getJson('/api/accounting/worksheet?month=9&year=2026&week=M1');
        $worksheetRes->assertStatus(200);
        $worksheetRes->assertJson([
            'success' => true,
            'data'    => [
                'status'    => 'locked',
                'is_locked' => true,
                'week'      => 'M1',
            ]
        ]);
        $this->assertNotNull($worksheetRes->json('data.locked_at'));
        $this->assertIsArray($worksheetRes->json('data.accounts'));
        $this->assertNotEmpty($worksheetRes->json('data.accounts'));
    }

    public function test_transaction_creation_allowed_freely(): void
    {
        Sanctum::actingAs($this->admin);

        // Record pekan M1 (1 s/d 7 September 2026)
        WeeklyPeriodLock::create([
            'year'       => 2026,
            'month'      => 9,
            'week'       => 'M1',
            'start_date' => '2026-09-01',
            'end_date'   => '2026-09-07',
            'is_locked'  => true,
            'status'     => 'locked',
            'locked_by'  => $this->manager->id,
            'locked_at'  => now(),
        ]);

        // Simpan transaksi di tanggal 2026-09-05 (jatuh pada M1) tetap berhasil
        $response = $this->postJson('/api/transactions', [
            'member_id'        => $this->member->id,
            'payment_method'   => 'cash',
            'type'             => 'deposit',
            'transaction_date' => '2026-09-05',
            'items'            => [
                [
                    'account_code' => '2021',
                    'amount'       => 500000,
                    'description'  => 'Setoran Simpanan Harian',
                ]
            ]
        ]);

        $response->assertStatus(200);
        $response->assertJson([
            'success' => true,
            'status'  => 'success',
        ]);
    }

    public function test_transaction_update_allowed_freely(): void
    {
        Sanctum::actingAs($this->admin);

        // Buat transaksi di tanggal 2026-09-04
        $trx = Transaction::create([
            'transaction_number' => 'TRX-LOCK-01',
            'receipt_number'     => 'KM-LOCK-01',
            'member_id'          => $this->member->id,
            'account_id'         => $this->kasAccount->id,
            'book_type'          => 'BUKU_PUTIH',
            'type'               => 'deposit',
            'amount'             => 250000,
            'transaction_date'   => '2026-09-04',
            'status'             => 'approved',
        ]);

        // Record pekan M1
        WeeklyPeriodLock::create([
            'year'       => 2026,
            'month'      => 9,
            'week'       => 'M1',
            'start_date' => '2026-09-01',
            'end_date'   => '2026-09-07',
            'is_locked'  => true,
            'status'     => 'locked',
        ]);

        // Edit transaksi berhasil tanpa hambatan
        $updateRes = $this->putJson("/api/transactions/{$trx->id}", [
            'amount' => 300000,
        ]);

        $updateRes->assertStatus(200);
        $updateRes->assertJson([
            'success' => true,
            'status'  => 'success',
        ]);
    }

    public function test_transaction_allowed_when_week_is_unlocked_or_different_week(): void
    {
        Sanctum::actingAs($this->admin);

        // Kunci M1 (1 s/d 7 September)
        WeeklyPeriodLock::create([
            'year'       => 2026,
            'month'      => 9,
            'week'       => 'M1',
            'start_date' => '2026-09-01',
            'end_date'   => '2026-09-07',
            'is_locked'  => true,
            'status'     => 'locked',
        ]);

        // Transaksi di tanggal 2026-09-10 (jatuh pada M2, tidak terkunci)
        $response = $this->postJson('/api/transactions', [
            'member_id'        => $this->member->id,
            'payment_method'   => 'cash',
            'type'             => 'deposit',
            'transaction_date' => '2026-09-10',
            'items'            => [
                [
                    'account_code' => '2021',
                    'amount'       => 500000,
                    'description'  => 'Setoran Simpanan Harian Pekan 2',
                ]
            ]
        ]);

        $response->assertStatus(200);
        $response->assertJson([
            'success' => true,
            'status'  => 'success',
        ]);
    }

    public function test_unlock_weekly_period_allows_transactions_again(): void
    {
        Sanctum::actingAs($this->manager);

        // 1. Kunci M1
        $this->postJson('/api/accounting/lock-week', [
            'month' => 9,
            'year'  => 2026,
            'week'  => 'M1',
        ])->assertStatus(200);

        // 2. Buka kunci M1
        $unlockRes = $this->postJson('/api/accounting/unlock-week', [
            'month' => 9,
            'year'  => 2026,
            'week'  => 'M1',
        ]);

        $unlockRes->assertStatus(200);
        $unlockRes->assertJson([
            'success' => true,
            'status'  => 'success',
            'data'    => [
                'is_locked' => false,
                'status'    => 'active',
            ]
        ]);

        // 3. Simpan transaksi di M1 (2026-09-05) sekarang berhasil
        $txRes = $this->postJson('/api/transactions', [
            'member_id'        => $this->member->id,
            'payment_method'   => 'cash',
            'type'             => 'deposit',
            'transaction_date' => '2026-09-05',
            'items'            => [
                [
                    'account_code' => '2021',
                    'amount'       => 150000,
                    'description'  => 'Setoran Setelah Unlocked',
                ]
            ]
        ]);

        $txRes->assertStatus(200);
        $txRes->assertJson(['success' => true]);
    }

    public function test_worksheet_all_weeks_m1_through_m5_parsing(): void
    {
        Sanctum::actingAs($this->manager);

        $expectedRanges = [
            'M1' => ['2026-09-01', '2026-09-07'],
            'M2' => ['2026-09-08', '2026-09-14'],
            'M3' => ['2026-09-15', '2026-09-21'],
            'M4' => ['2026-09-22', '2026-09-28'],
            'M5' => ['2026-09-29', '2026-09-30'],
        ];

        foreach ($expectedRanges as $week => [$start, $end]) {
            $res = $this->getJson("/api/accounting/worksheet?month=9&year=2026&week={$week}");
            $res->assertStatus(200);
            $res->assertJson([
                'success' => true,
                'data'    => [
                    'week'       => $week,
                    'start_date' => $start,
                    'end_date'   => $end,
                    'is_locked'  => false,
                    'status'     => 'active',
                ]
            ]);
        }
    }

    public function test_worksheet_rolling_balance_m1_through_m5(): void
    {
        Sanctum::actingAs($this->manager);

        $kasCoa = ChartOfAccount::where('account_code', '1001')->first()
            ?? ChartOfAccount::create([
                'account_code'   => '1001',
                'account_name'   => 'Kas Kasir',
                'account_type'   => 'ASSET',
                'normal_balance' => 'DEBIT',
                'is_active'      => true,
            ]);

        $revCoa = ChartOfAccount::where('account_code', '4001')->first()
            ?? ChartOfAccount::create([
                'account_code'   => '4001',
                'account_name'   => 'Pendapatan Lain',
                'account_type'   => 'REVENUE',
                'normal_balance' => 'CREDIT',
                'is_active'      => true,
            ]);

        $createEntry = function ($date, $amount, $ref) use ($kasCoa, $revCoa) {
            $je = JournalEntry::create([
                'voucher_number'   => 'VN-' . $ref,
                'entry_date'       => $date,
                'description'      => 'Test Entry ' . $ref,
                'created_by'       => $this->admin->id,
            ]);

            JournalDetail::create([
                'journal_entry_id' => $je->id,
                'account_id'       => $kasCoa->id,
                'debit'            => $amount,
                'credit'           => 0,
            ]);

            JournalDetail::create([
                'journal_entry_id' => $je->id,
                'account_id'       => $revCoa->id,
                'debit'            => 0,
                'credit'           => $amount,
            ]);
        };

        // Saldo awal sebelum September (Agustus 2026): 1.000.000
        $createEntry('2026-08-15', 1000000, 'AUG-01');

        // M1 (01 - 07 Sep): Mutasi +500.000
        $createEntry('2026-09-03', 500000, 'SEP-M1');

        // M2 (08 - 14 Sep): Mutasi +300.000
        $createEntry('2026-09-10', 300000, 'SEP-M2');

        // M3 (15 - 21 Sep): Mutasi +200.000
        $createEntry('2026-09-17', 200000, 'SEP-M3');

        // M4 (22 - 28 Sep): Mutasi +150.000
        $createEntry('2026-09-24', 150000, 'SEP-M4');

        // M5 (29 - 30 Sep): Mutasi +100.000
        $createEntry('2026-09-30', 100000, 'SEP-M5');

        // Helper untuk mencari akun kas di response worksheet
        $findKas = function ($response) use ($kasCoa) {
            $accounts = $response->json('data.accounts') ?? [];
            foreach ($accounts as $acc) {
                if ($acc['account_code'] == $kasCoa->account_code) {
                    return $acc;
                }
            }
            return null;
        };

        // --- Verifikasi M1 ---
        $resM1 = $this->getJson('/api/accounting/worksheet?month=9&year=2026&week=M1');
        $kasM1 = $findKas($resM1);
        $this->assertNotNull($kasM1);
        $this->assertEquals(1000000, $kasM1['initial_debit'], 'Saldo awal M1 harus mencakup transaksi s.d akhir bulan sebelumnya (Agustus)');
        $this->assertEquals(500000, $kasM1['adjustment_debit'], 'Mutasi M1 harus 500.000');
        $this->assertEquals(1500000, $kasM1['trial_debit'], 'Saldo Neraca Percobaan M1 = Saldo Awal + Mutasi M1 = 1.500.000');

        // --- Verifikasi M2 ---
        $resM2 = $this->getJson('/api/accounting/worksheet?month=9&year=2026&week=M2');
        $kasM2 = $findKas($resM2);
        $this->assertNotNull($kasM2);
        $this->assertEquals(1500000, $kasM2['initial_debit'], 'Saldo awal M2 harus membaca saldo akhir M1 (1.500.000)');
        $this->assertEquals(300000, $kasM2['adjustment_debit'], 'Mutasi M2 harus 300.000');
        $this->assertEquals(1800000, $kasM2['trial_debit'], 'Saldo Neraca Percobaan M2 = Saldo Awal M2 + Mutasi M2 = 1.800.000');

        // --- Verifikasi M3 ---
        $resM3 = $this->getJson('/api/accounting/worksheet?month=9&year=2026&week=M3');
        $kasM3 = $findKas($resM3);
        $this->assertNotNull($kasM3);
        $this->assertEquals(1800000, $kasM3['initial_debit'], 'Saldo awal M3 harus membaca saldo akhir M2 (1.800.000)');
        $this->assertEquals(200000, $kasM3['adjustment_debit'], 'Mutasi M3 harus 200.000');
        $this->assertEquals(2000000, $kasM3['trial_debit'], 'Saldo Neraca Percobaan M3 = 2.000.000');

        // --- Verifikasi M4 ---
        $resM4 = $this->getJson('/api/accounting/worksheet?month=9&year=2026&week=M4');
        $kasM4 = $findKas($resM4);
        $this->assertNotNull($kasM4);
        $this->assertEquals(2000000, $kasM4['initial_debit'], 'Saldo awal M4 harus membaca saldo akhir M3 (2.000.000)');
        $this->assertEquals(1500000 - 1500000 + 150000, $kasM4['adjustment_debit'], 'Mutasi M4 harus 150.000');
        $this->assertEquals(2150000, $kasM4['trial_debit'], 'Saldo Neraca Percobaan M4 = 2.150.000');

        // --- Verifikasi M5 ---
        $resM5 = $this->getJson('/api/accounting/worksheet?month=9&year=2026&week=M5');
        $kasM5 = $findKas($resM5);
        $this->assertNotNull($kasM5);
        $this->assertEquals(2150000, $kasM5['initial_debit'], 'Saldo awal M5 harus membaca saldo akhir M4 (2.150.000)');
        $this->assertEquals(100000, $kasM5['adjustment_debit'], 'Mutasi M5 harus 100.000');
        $this->assertEquals(2250000, $kasM5['trial_debit'], 'Saldo Neraca Percobaan M5 = 2.250.000');
    }
}

