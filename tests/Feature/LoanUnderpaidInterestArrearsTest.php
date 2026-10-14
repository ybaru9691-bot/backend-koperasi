<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Loan;
use App\Models\LoanInstallment;
use App\Models\Member;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class LoanUnderpaidInterestArrearsTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;
    protected Member $member;
    protected Loan $loan;
    protected Account $kasAccount;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(\Database\Seeders\ChartOfAccountSeeder::class);

        $this->kasAccount = Account::forceCreate([
            'account_number' => 'KAS-101',
            'account_name'   => 'Kas Koperasi',
            'account_type'   => 'kas',
            'category'       => 'asset',
            'balance'        => 50000000.00,
        ]);

        $this->admin = User::create([
            'name'     => 'Admin Kasir',
            'email'    => 'admin.kasir@koperasi.com',
            'password' => bcrypt('password123'),
            'role'     => 'admin',
            'nik'      => '1234567890123456',
        ]);

        $this->member = Member::create([
            'member_number'     => 'PELITA-0099',
            'name'              => 'Bpk. Rudi Siregar',
            'nik'               => '3201010101010099',
            'phone'             => '081234567999',
            'status'            => 'active',
            'principal_savings' => 200000.00,
            'mandatory_savings' => 100000.00,
            'voluntary_savings' => 500000.00,
            'has_buku_biru'     => true,
        ]);

        // Buat pinjaman Rp 5.000.000 tenor 5 bulan
        $this->loan = Loan::create([
            'loan_code'           => 'PJ-2026-0099',
            'member_id'           => $this->member->id,
            'amount'              => 5000000.00,
            'interest_rate'       => 20.00, // Misal tagihan bunga Rp 1.000.000 flat untuk menguji skenario user
            'interest_method'     => 'flat',
            'duration_months'     => 5,
            'tenor_months'        => 5,
            'monthly_installment' => 2000000.00,
            'remaining_amount'    => 5000000.00,
            'remaining_principal' => 5000000.00,
            'interest_arrears'    => 0.00,
            'application_date'    => now()->toDateString(),
            'status'              => 'DISBURSED',
        ]);

        // Buat 5 jadwal angsuran
        // Angsuran ke-1: pokok 1.000.000, bunga 1.000.000, total 2.000.000
        for ($i = 1; $i <= 5; $i++) {
            LoanInstallment::create([
                'loan_id'                 => $this->loan->id,
                'installment_number'      => $i,
                'beginning_balance'       => 5000000.00 - (($i - 1) * 1000000.00),
                'principal_amount'        => 1000000.00,
                'interest_amount'         => 1000000.00,
                'scheduled_interest'      => 1000000.00,
                'arrears_interest_billed' => 0.00,
                'unpaid_interest'         => 0.00,
                'ending_balance'          => 5000000.00 - ($i * 1000000.00),
                'penalty_fee'             => 0.00,
                'penalty_amount'          => 0.00,
                'total_amount'            => 2000000.00,
                'due_date'                => now()->addMonths($i)->toDateString(),
                'paid_by_member_id'       => $this->member->id,
                'status'                  => 'unpaid',
            ]);
        }
    }

    /**
     * Uji Kasus 1:
     * Tagihan bunga Rp 1.000.000, dibayar Rp 500.000 dan pokok dibayar penuh Rp 1.000.000.
     * Status angsuran berjalan harus 'paid' (lunas periode berjalan).
     * Selisih bunga Rp 500.000 dicatat sebagai unpaid_interest dan disimpan ke interest_arrears tabel loans.
     * Otomatis dialihkan ke angsuran berikutnya (nextInstallment.arrears_interest_billed = 500.000).
     */
    public function test_underpaid_interest_marks_installment_paid_and_forwards_arrears(): void
    {
        Sanctum::actingAs($this->admin);

        $inst1 = LoanInstallment::where('loan_id', $this->loan->id)->where('installment_number', 1)->first();
        $this->assertNotNull($inst1);

        // Anggota membayar pokok Rp 1.000.000 dan bunga Rp 500.000 (kurang bayar Rp 500.000)
        $res = $this->postJson("/api/loans/installments/{$inst1->id}/pay", [
            'principal_amount' => 1000000.00,
            'interest_amount'  => 500000.00,
            'penalty_amount'   => 0.00,
            'receipt_number'   => 'KM-20261015-0001',
        ]);

        $res->assertStatus(200);
        $this->assertEquals('paid', $res->json('data.status'));
        $this->assertEquals(1000000.00, (float) $res->json('data.principal_paid'));
        $this->assertEquals(500000.00, (float) $res->json('data.interest_paid'));
        $this->assertEquals(500000.00, (float) $res->json('data.unpaid_interest'));
        $this->assertEquals(500000.00, (float) $res->json('data.loan_interest_arrears'));

        // 1. Verifikasi angsuran ke-1 di database
        $inst1->refresh();
        $this->assertEquals('paid', $inst1->status);
        $this->assertEquals(1000000.00, (float) $inst1->principal_amount);
        $this->assertEquals(500000.00, (float) $inst1->interest_amount);
        $this->assertEquals(1000000.00, (float) $inst1->scheduled_interest);
        $this->assertEquals(0.00, (float) $inst1->arrears_interest_billed);
        $this->assertEquals(500000.00, (float) $inst1->unpaid_interest);
        $this->assertEquals(1500000.00, (float) $inst1->total_amount);

        // 2. Verifikasi model Loan
        $this->loan->refresh();
        $this->assertEquals(4000000.00, (float) $this->loan->remaining_principal);
        $this->assertEquals(500000.00, (float) $this->loan->interest_arrears);

        // 3. Verifikasi angsuran ke-2 (nextInstallment)
        $inst2 = LoanInstallment::where('loan_id', $this->loan->id)->where('installment_number', 2)->first();
        $this->assertNotNull($inst2);
        $this->assertEquals('unpaid', $inst2->status);
        $this->assertEquals(500000.00, (float) $inst2->arrears_interest_billed);
        $this->assertEquals(800000.00, (float) $inst2->scheduled_interest); // 4.000.000 * 20%
        $this->assertEquals(1300000.00, (float) $inst2->interest_amount); // 800.000 bunga berjalan + 500.000 tunggakan
        $this->assertEquals(2300000.00, (float) $inst2->total_amount); // 1.000.000 pokok + 1.300.000 bunga
    }

    /**
     * Uji Kasus 2:
     * Angsuran ke-2 dibayar penuh termasuk sisa tunggakan bulan lalu.
     * interest_arrears pada loan harus kembali menjadi 0.
     */
    public function test_paying_second_installment_clears_accumulated_arrears(): void
    {
        Sanctum::actingAs($this->admin);

        $inst1 = LoanInstallment::where('loan_id', $this->loan->id)->where('installment_number', 1)->first();

        // Bayar angsuran 1 dengan kurang bayar bunga Rp 500.000
        $this->postJson("/api/loans/installments/{$inst1->id}/pay", [
            'principal_amount' => 1000000.00,
            'interest_amount'  => 500000.00,
        ])->assertStatus(200);

        // Ambil angsuran 2
        $inst2 = LoanInstallment::where('loan_id', $this->loan->id)->where('installment_number', 2)->first();
        $this->assertEquals(1300000.00, (float) $inst2->interest_amount);

        // Bayar angsuran 2 secara penuh (pokok 1.000.000 + bunga 1.300.000 = 2.300.000)
        $res2 = $this->postJson("/api/loans/installments/{$inst2->id}/pay", [
            'principal_amount' => 1000000.00,
            'interest_amount'  => 1300000.00,
            'receipt_number'   => 'KM-20261015-0002',
        ]);

        $res2->assertStatus(200);
        $this->assertEquals('paid', $res2->json('data.status'));
        $this->assertEquals(0.00, (float) $res2->json('data.unpaid_interest'));
        $this->assertEquals(0.00, (float) $res2->json('data.loan_interest_arrears'));

        $this->loan->refresh();
        $this->assertEquals(3000000.00, (float) $this->loan->remaining_principal);
        $this->assertEquals(0.00, (float) $this->loan->interest_arrears);

        // Angsuran ke-3 tidak lagi membawa tunggakan
        $inst3 = LoanInstallment::where('loan_id', $this->loan->id)->where('installment_number', 3)->first();
        $this->assertEquals(0.00, (float) $inst3->arrears_interest_billed);
        $this->assertEquals(600000.00, (float) $inst3->interest_amount);
    }

    /**
     * Uji Kasus 3:
     * Pembayaran via lump sum (total_amount = 1.500.000) tanpa menyebutkan rincian bunga.
     * Mengalokasikan 1.000.000 ke pokok, sisa 500.000 ke bunga, unpaid_interest = 500.000.
     */
    public function test_lump_sum_payment_allocates_principal_first_and_tracks_unpaid_interest(): void
    {
        Sanctum::actingAs($this->admin);

        $inst1 = LoanInstallment::where('loan_id', $this->loan->id)->where('installment_number', 1)->first();

        $res = $this->postJson("/api/loans/installments/{$inst1->id}/pay", [
            'total_amount'   => 1500000.00,
            'receipt_number' => 'KM-20261015-0003',
        ]);

        $res->assertStatus(200);
        $this->assertEquals('paid', $res->json('data.status'));
        $this->assertEquals(1000000.00, (float) $res->json('data.principal_paid'));
        $this->assertEquals(500000.00, (float) $res->json('data.interest_paid'));
        $this->assertEquals(500000.00, (float) $res->json('data.unpaid_interest'));
        $this->assertEquals(500000.00, (float) $res->json('data.loan_interest_arrears'));

        $inst2 = LoanInstallment::where('loan_id', $this->loan->id)->where('installment_number', 2)->first();
        $this->assertEquals(500000.00, (float) $inst2->arrears_interest_billed);
    }

    /**
     * Uji Kasus 4:
     * Pembayaran Kasir Kas Masuk (KM) via TransactionController::store dengan item COA 1024 dan 4180
     * berhasil memperbarui target installment menjadi 'paid', mencatat unpaid_interest, dan memforward ke next installment.
     */
    public function test_cashier_transaction_store_records_unpaid_interest_and_forwards_arrears(): void
    {
        Sanctum::actingAs($this->admin);

        $res = $this->postJson('/api/transactions', [
            'account_id'       => $this->kasAccount->id,
            'member_id'        => $this->member->id,
            'type'             => 'deposit',
            'category'         => 'angsuran_pinjaman',
            'amount'           => 1500000.00,
            'voucher_number'   => 'KM-20261015-ITEM1',
            'payment_method'   => 'cash',
            'transaction_date' => now()->toDateString(),
            'description'      => 'Pembayaran Angsuran Pinjaman',
            'items'            => [
                [
                    'account_code' => '1024',
                    'amount'       => 1000000.00,
                    'description'  => 'Pokok Pinjaman',
                ],
                [
                    'account_code' => '4180',
                    'amount'       => 500000.00,
                    'description'  => 'Jasa Pinjaman',
                ],
            ],
        ]);

        $res->assertStatus(200);

        $inst1 = LoanInstallment::where('loan_id', $this->loan->id)->where('installment_number', 1)->first();
        $this->assertEquals('paid', $inst1->status);
        $this->assertEquals(1000000.00, (float) $inst1->principal_amount);
        $this->assertEquals(500000.00, (float) $inst1->interest_amount);
        $this->assertEquals(500000.00, (float) $inst1->unpaid_interest);

        $this->loan->refresh();
        $this->assertEquals(4000000.00, (float) $this->loan->remaining_principal);
        $this->assertEquals(500000.00, (float) $this->loan->interest_arrears);

        $inst2 = LoanInstallment::where('loan_id', $this->loan->id)->where('installment_number', 2)->first();
        $this->assertEquals(500000.00, (float) $inst2->arrears_interest_billed);
        $this->assertEquals(1300000.00, (float) $inst2->interest_amount);
    }
}
