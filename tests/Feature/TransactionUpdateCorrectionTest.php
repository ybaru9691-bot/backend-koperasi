<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\ChartOfAccount;
use App\Models\JournalDetail;
use App\Models\JournalEntry;
use App\Models\Loan;
use App\Models\LoanInstallment;
use App\Models\Member;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class TransactionUpdateCorrectionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(\Database\Seeders\ChartOfAccountSeeder::class);
    }

    public function test_correct_savings_deposit_transaction_delta(): void
    {
        $admin = User::create([
            'name'     => 'Admin Koreksi',
            'email'    => 'admin.koreksi@koperasi.com',
            'password' => bcrypt('password123'),
            'role'     => 'admin',
            'nik'      => '1234567890123456',
        ]);
        Sanctum::actingAs($admin);

        $member = Member::create([
            'member_number'     => '001.010',
            'name'              => 'Bpk. Tumpal Hutapea',
            'nik'               => '3201010101010010',
            'phone'             => '081234567891',
            'status'            => 'active',
            'principal_savings' => 100000.00,
            'mandatory_savings' => 50000.00,
            'voluntary_savings' => 500000.00,
            'daily_savings'     => 200000.00,
        ]);

        // 1. Buat transaksi awal: Setoran Sukarela Rp 200.000
        $t = Transaction::create([
            'transaction_number' => 'KM-20260901-0010',
            'receipt_number'     => 'KM-010',
            'member_id'          => $member->id,
            'type'               => 'deposit',
            'amount'             => 200000.00,
            'transaction_date'   => '2026-09-01',
            'description'        => 'Setoran Simpanan Sukarela',
            'status'             => 'approved',
        ]);
        $member->increment('voluntary_savings', 200000.00);
        $this->assertEquals(700000.00, (float) $member->fresh()->voluntary_savings);

        // 2. Koreksi Transaksi: Naikkan nominal menjadi Rp 250.000 (Delta = +50.000)
        $res1 = $this->putJson("/api/v1/transactions/{$t->id}", [
            'amount'      => 250000.00,
            'description' => 'Koreksi: Setoran Simpanan Sukarela',
            'audit_note'  => 'Koreksi salah ketik kasir (200k -> 250k)',
        ]);
        $res1->assertStatus(200);
        $res1->assertJson(['success' => true]);

        // Verifikasi saldo member bertambah sesuai delta +50.000
        $this->assertEquals(750000.00, (float) $member->fresh()->voluntary_savings);
        $this->assertEquals(250000.00, (float) $t->fresh()->amount);

        // Verifikasi Jurnal terupdate ke 250.000
        $journal = JournalEntry::where('transaction_id', $t->id)->first();
        $this->assertNotNull($journal);
        $kasDetail = $journal->details()->whereHas('account', fn($q) => $q->where('account_code', '1000'))->first();
        $this->assertEquals(250000.00, (float) $kasDetail->debit);

        // 3. Koreksi Transaksi: Turunkan nominal menjadi Rp 150.000 (Delta = -100.000)
        $res2 = $this->putJson("/api/transactions/{$t->id}", [
            'amount'      => 150000.00,
            'description' => 'Koreksi 2: Setoran Simpanan Sukarela',
        ]);
        $res2->assertStatus(200);

        // Verifikasi saldo member berkurang sesuai delta -100.000
        $this->assertEquals(650000.00, (float) $member->fresh()->voluntary_savings);
        $this->assertEquals(150000.00, (float) $t->fresh()->amount);

        // Verifikasi Jurnal terupdate ke 150.000
        $kasDetail2 = $journal->fresh()->details()->whereHas('account', fn($q) => $q->where('account_code', '1000'))->first();
        $this->assertEquals(150000.00, (float) $kasDetail2->debit);

        // 4. Verifikasi ActivityLog tercatat
        $this->assertTrue(ActivityLog::where('subject_id', $t->id)->exists());
    }

    public function test_correct_withdrawal_transaction_delta(): void
    {
        $admin = User::create([
            'name'     => 'Admin Koreksi KK',
            'email'    => 'admin.kk@koperasi.com',
            'password' => bcrypt('password123'),
            'role'     => 'admin',
            'nik'      => '1234567890123457',
        ]);
        Sanctum::actingAs($admin);

        $member = Member::create([
            'member_number'     => '001.011',
            'name'              => 'Ibu Marta Siregar',
            'nik'               => '3201010101010011',
            'phone'             => '081234567892',
            'status'            => 'active',
            'voluntary_savings' => 500000.00,
        ]);

        // Buat penarikan awal Rp 100.000
        $t = Transaction::create([
            'transaction_number' => 'KK-20260901-0011',
            'receipt_number'     => 'KK-011',
            'member_id'          => $member->id,
            'type'               => 'withdrawal',
            'amount'             => 100000.00,
            'transaction_date'   => '2026-09-01',
            'description'        => 'Penarikan Simpanan Sukarela',
            'status'             => 'approved',
        ]);
        $member->decrement('voluntary_savings', 100000.00);
        $this->assertEquals(400000.00, (float) $member->fresh()->voluntary_savings);

        // Koreksi penarikan menjadi Rp 150.000 (Delta = +50.000 penarikan lebih banyak)
        $res = $this->putJson("/api/v1/transactions/{$t->id}", [
            'amount' => 150000.00,
        ]);
        $res->assertStatus(200);

        // Saldo member berkurang 50.000 lagi menjadi 350.000
        $this->assertEquals(350000.00, (float) $member->fresh()->voluntary_savings);
        $this->assertEquals(150000.00, (float) $t->fresh()->amount);

        // Jurnal Kas Keluar terupdate ke 150.000 (Kredit Kas 1000 = 150k)
        $journal = JournalEntry::where('transaction_id', $t->id)->first();
        $kasDetail = $journal->details()->whereHas('account', fn($q) => $q->where('account_code', '1000'))->first();
        $this->assertEquals(150000.00, (float) $kasDetail->credit);
    }

    public function test_correct_loan_installment_transaction_updates_loan_balance(): void
    {
        $admin = User::create([
            'name'     => 'Admin Loan Correct',
            'email'    => 'admin.loan@koperasi.com',
            'password' => bcrypt('password123'),
            'role'     => 'admin',
            'nik'      => '1234567890123458',
        ]);
        Sanctum::actingAs($admin);

        $member = Member::create([
            'member_number' => '001.012',
            'name'          => 'Bpk. Robert Panjaitan',
            'nik'           => '3201010101010012',
            'status'        => 'active',
        ]);

        $loan = Loan::create([
            'loan_code'           => 'PJ-2026-001',
            'member_id'           => $member->id,
            'amount'              => 10000000.00,
            'interest_rate'       => 2.50,
            'interest_method'     => 'declining_balance',
            'duration_months'     => 10,
            'tenor_months'        => 10,
            'monthly_installment' => 1250000.00,
            'application_date'    => '2026-09-01',
            'remaining_principal' => 9000000.00,
            'remaining_amount'    => 9000000.00,
            'status'              => 'approved',
        ]);

        $installment = LoanInstallment::create([
            'loan_id'            => $loan->id,
            'installment_number' => 1,
            'receipt_number'     => 'KM-ANG-001',
            'principal_amount'   => 1000000.00,
            'interest_amount'    => 250000.00,
            'total_amount'       => 1250000.00,
            'due_date'           => '2026-09-15',
            'status'             => 'paid',
            'paid_at'            => '2026-09-01',
            'paid_by_member_id'  => $member->id,
        ]);

        $trx = Transaction::create([
            'transaction_number' => 'KM-20260901-0099',
            'receipt_number'     => 'KM-ANG-001',
            'member_id'          => $member->id,
            'type'               => 'deposit',
            'amount'             => 1250000.00,
            'transaction_date'   => '2026-09-01',
            'description'        => 'Pembayaran Angsuran Pinjaman ke-1 (PJ-2026-001)',
            'status'             => 'approved',
        ]);

        // Koreksi pembayaran pokok angsuran menjadi Rp 1.500.000 (Total Rp 1.750.000)
        $res = $this->putJson("/api/v1/transactions/{$trx->id}", [
            'amount'           => 1750000.00,
            'principal_amount' => 1500000.00,
            'interest_amount'  => 250000.00,
            'description'      => 'Koreksi: Pembayaran Angsuran Pinjaman ke-1 (PJ-2026-001)',
        ]);
        $res->assertStatus(200);

        // Verifikasi cicilan diperbarui
        $this->assertEquals(1500000.00, (float) $installment->fresh()->principal_amount);
        $this->assertEquals(1750000.00, (float) $installment->fresh()->total_amount);

        // Verifikasi sisa pokok pinjaman berkurang sesuai delta pokok +500.000 (9.000.000 - 500.000 = 8.500.000)
        $this->assertEquals(8500000.00, (float) $loan->fresh()->remaining_principal);
    }

    public function test_validation_rejects_zero_or_negative_amounts(): void
    {
        $admin = User::create([
            'name'     => 'Admin Val',
            'email'    => 'admin.val@koperasi.com',
            'password' => bcrypt('password123'),
            'role'     => 'admin',
            'nik'      => '1234567890123459',
        ]);
        Sanctum::actingAs($admin);

        $member = Member::create([
            'member_number' => '001.013',
            'name'          => 'Test Member',
            'nik'           => '3201010101010013',
            'status'        => 'active',
        ]);

        $t = Transaction::create([
            'transaction_number' => 'KM-20260901-0013',
            'member_id'          => $member->id,
            'type'               => 'deposit',
            'amount'             => 100000.00,
            'transaction_date'   => '2026-09-01',
            'status'             => 'approved',
        ]);

        // Uji nominal <= 0 ditolak
        $res = $this->putJson("/api/v1/transactions/{$t->id}", [
            'amount' => 0,
        ]);
        $res->assertStatus(422);

        $res2 = $this->putJson("/api/v1/transactions/{$t->id}", [
            'amount' => -50000,
        ]);
        $res2->assertStatus(422);
    }
}
