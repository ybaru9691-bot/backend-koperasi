<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\ChartOfAccount;
use App\Models\Loan;
use App\Models\LoanInstallment;
use App\Models\Member;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class LoanApprovalFlowTest extends TestCase
{
    use RefreshDatabase;

    private function setupCoa(): void
    {
        ChartOfAccount::create(['account_code' => '1000', 'account_name' => 'Kas', 'account_type' => 'asset', 'normal_balance' => 'debit']);
        ChartOfAccount::create(['account_code' => '1024', 'account_name' => 'Piutang Pinjaman Anggota', 'account_type' => 'asset', 'normal_balance' => 'debit']);
        ChartOfAccount::create(['account_code' => '4180', 'account_name' => 'Pendapatan Jasa Pinjaman', 'account_type' => 'revenue', 'normal_balance' => 'credit']);
        ChartOfAccount::create(['account_code' => '4182', 'account_name' => 'Pendapatan Denda Pinjaman', 'account_type' => 'revenue', 'normal_balance' => 'credit']);
    }

    public function test_multi_level_loan_approval_flow()
    {
        $this->setupCoa();

        // 1. Setup Cash Account
        $account = Account::forceCreate([
            'account_number' => 'KAS-101',
            'account_name'   => 'Kas Koperasi',
            'account_type'   => 'kas',
            'category'       => 'asset',
            'balance'        => 10000000.00,
        ]);

        // 2. Setup Member and Authenticate
        $member = Member::create([
            'member_number' => 'PELITA-202608-0001',
            'nik'           => '1234567890123456',
            'name'          => 'John Anggota',
            'email'         => 'john.anggota@koperasi.com',
            'phone'         => '081234567890',
            'password'      => bcrypt('123456'),
            'pin_code'      => '123456',
            'address'       => 'Jl. Anggota No. 1',
            'status'        => 'active',
            'has_buku_biru' => true,
        ]);

        Sanctum::actingAs($member);

        // 3. Member Applies for a Loan (Rp 5.000.000, Tenor 12 Bulan, Bunga Saldo Menurun 2.50%)
        $applyResponse = $this->postJson('/api/user/loans/apply', [
            'amount'     => 'Rp 5.000.000',
            'tenor'      => 12,
            'purpose'    => 'Modal Usaha Kelontong',
            'collateral' => 'BPKB Motor',
        ]);

        $applyResponse->assertStatus(201);
        $loanData = $applyResponse->json('data');

        $this->assertEquals(5000000.00, $loanData['amount']);
        $this->assertEquals(12, $loanData['duration_months']);
        $this->assertEquals(2.50, $loanData['interest_rate']);
        $this->assertEquals('declining_balance', $loanData['interest_method']);
        $this->assertEquals('pending_admin', $loanData['status']);
        $this->assertNotEmpty($loanData['loan_code']);
        $this->assertEquals(5000000.00, $loanData['remaining_amount']);
        
        // Saldo Menurun Bulan ke-1: Pokok = ceil(5.000.000/12) = 416.667; Jasa = 5.000.000 * 2.5% = 125.000 => 541.667
        $this->assertEquals(541667.00, $loanData['monthly_installment']);

        $loanId = $loanData['id'];

        // 4. Authenticate as Admin Level-1
        $admin = User::create([
            'name'     => 'Admin Level 1',
            'email'    => 'admin1@koperasi.com',
            'password' => bcrypt('123456'),
            'role'     => 'admin',
            'nik'      => '1111222233334444',
        ]);

        Sanctum::actingAs($admin);

        // Check pending list
        $pendingListResponse = $this->getJson('/api/admin/loans/pending');
        $pendingListResponse->assertStatus(200);
        $pendingLoans = $pendingListResponse->json('data');
        $this->assertCount(1, $pendingLoans);
        $this->assertEquals($loanId, $pendingLoans[0]['id']);
        $this->assertEquals('Modal Usaha Kelontong', $pendingLoans[0]['purpose']);
        $this->assertEquals('BPKB Motor', $pendingLoans[0]['collateral']);

        // Admin cannot call manager approve endpoint -> 403 Forbidden
        $adminUnauthorizedApprove = $this->postJson("/api/loans/{$loanId}/approve-manager");
        $adminUnauthorizedApprove->assertStatus(403);
        $this->assertStringContainsString('Hanya Manajer/Ketua yang berhak memberikan persetujuan pinjaman.', $adminUnauthorizedApprove->json('message'));

        // Approve as Admin Level-1 (Verify)
        $adminApproveResponse = $this->postJson("/api/admin/loans/{$loanId}/approve-admin");
        $adminApproveResponse->assertStatus(200);
        $this->assertEquals('WAITING_MANAGER_APPROVAL', $adminApproveResponse->json('data.status'));
        $this->assertNotNull($adminApproveResponse->json('data.admin_verified_at'));
        $this->assertEquals($admin->id, $adminApproveResponse->json('data.admin_verified_by'));

        // Admin tries to disburse before Manager approves -> 400 Bad Request
        $prematureDisburse = $this->postJson("/api/admin/loans/{$loanId}/disburse");
        $prematureDisburse->assertStatus(400);

        // 5. Authenticate as Manager Level-2
        $manager = User::create([
            'name'     => 'Manager Level 2',
            'email'    => 'manager2@koperasi.com',
            'password' => bcrypt('123456'),
            'role'     => 'manager',
            'nik'      => '5555666677778888',
        ]);

        Sanctum::actingAs($manager);

        // Manager checks pending approvals
        $managerPending = $this->getJson('/api/manager/approvals/loans');
        $managerPending->assertStatus(200);
        $this->assertCount(1, $managerPending->json('data'));

        // Approve as Manager Level-2 (Final - ACC)
        $managerApproveResponse = $this->postJson("/api/loans/{$loanId}/approve-manager");
        $managerApproveResponse->assertStatus(200);
        $this->assertEquals('APPROVED_BY_MANAGER', $managerApproveResponse->json('data.status'));
        $this->assertNotNull($managerApproveResponse->json('data.manager_approved_at'));
        $this->assertEquals($manager->id, $managerApproveResponse->json('data.manager_approved_by'));

        // 6. Admin Disburses Loan (Cairkan Pinjaman)
        Sanctum::actingAs($admin);
        $disburseResponse = $this->postJson("/api/admin/loans/{$loanId}/disburse");
        $disburseResponse->assertStatus(200);
        $this->assertEquals('DISBURSED', $disburseResponse->json('data.status'));

        // Verify Disbursement Cash-Out Transaction & Installment Generation
        $loan = Loan::find($loanId);
        $this->assertEquals('DISBURSED', $loan->status);
        $this->assertEquals($manager->id, $loan->approved_by);
        $this->assertNotNull($loan->approved_at);
        $this->assertNotNull($loan->disbursement_date);
        $this->assertNotNull($loan->due_date);
        $this->assertEquals($admin->id, $loan->disbursed_by);

        $disbursementTx = Transaction::where('receipt_number', 'KK-' . $loan->loan_code)
            ->orWhere('receipt_number', 'LNC-' . $loan->loan_code)
            ->first();
        $this->assertNotNull($disbursementTx);
        $this->assertEquals('withdrawal', $disbursementTx->type);
        $this->assertEquals(5000000.00, $disbursementTx->amount);
        $this->assertEquals('approved', $disbursementTx->status);
        $this->assertEquals($admin->id, $disbursementTx->operator_id);

        // Verify 12 installments generated
        $this->assertCount(12, $loan->installments);
    }

    public function test_declining_balance_installment_schedule_generated_on_approval()
    {
        $this->setupCoa();

        Account::forceCreate([
            'account_number' => 'KAS-101',
            'account_name'   => 'Kas Koperasi',
            'account_type'   => 'kas',
            'category'       => 'asset',
            'balance'        => 20000000.00,
        ]);

        $member = Member::create([
            'member_number' => 'PELITA-202608-0002',
            'nik'           => '1234567890123457',
            'name'          => 'Jane Anggota',
            'email'         => 'jane.anggota@koperasi.com',
            'phone'         => '081234567891',
            'password'      => bcrypt('123456'),
            'pin_code'      => '123456',
            'address'       => 'Jl. Anggota No. 2',
            'status'        => 'active',
            'has_buku_biru' => true,
        ]);

        $admin = User::create([
            'name'     => 'Admin Test',
            'email'    => 'admin_test@koperasi.com',
            'password' => bcrypt('123456'),
            'role'     => 'admin',
            'nik'      => '2222333344445555',
        ]);

        $manager = User::create([
            'name'     => 'Manager Test',
            'email'    => 'manager_test@koperasi.com',
            'password' => bcrypt('123456'),
            'role'     => 'manager',
            'nik'      => '6666777788889999',
        ]);

        // Plafon 12 Juta, Tenor 12 Bulan (Pokok: Rp 1.000.000 / bulan)
        Sanctum::actingAs($member);
        $applyRes = $this->postJson('/api/user/loans/apply', [
            'amount' => 12000000,
            'tenor'  => 12,
        ]);
        $applyRes->assertStatus(201);
        $loanId = $applyRes->json('data.id');

        // Admin approve
        Sanctum::actingAs($admin);
        $this->postJson("/api/admin/loans/{$loanId}/approve-admin")->assertStatus(200);

        // Manager approve
        Sanctum::actingAs($manager);
        $this->postJson("/api/admin/loans/{$loanId}/approve-manager")->assertStatus(200);

        $installments = LoanInstallment::where('loan_id', $loanId)->orderBy('installment_number')->get();
        $this->assertCount(12, $installments);

        // Bulan 1: Saldo Awal 12M, Pokok 1M, Jasa 2.5% x 12M = 300k, Saldo Akhir 11M, Total 1.3M
        $first = $installments[0];
        $this->assertEquals(1, $first->installment_number);
        $this->assertEquals(12000000.00, $first->beginning_balance);
        $this->assertEquals(1000000.00, $first->principal_amount);
        $this->assertEquals(300000.00, $first->interest_amount);
        $this->assertEquals(11000000.00, $first->ending_balance);
        $this->assertEquals(1300000.00, $first->total_amount);
        $this->assertEquals('unpaid', $first->status);

        // Bulan 2: Saldo Awal 11M, Pokok 1M, Jasa 2.5% x 11M = 275k, Saldo Akhir 10M, Total 1.275M
        $second = $installments[1];
        $this->assertEquals(2, $second->installment_number);
        $this->assertEquals(11000000.00, $second->beginning_balance);
        $this->assertEquals(1000000.00, $second->principal_amount);
        $this->assertEquals(275000.00, $second->interest_amount);
        $this->assertEquals(10000000.00, $second->ending_balance);
        $this->assertEquals(1275000.00, $second->total_amount);

        // Bulan 12: Saldo Awal 1M, Pokok 1M, Jasa 2.5% x 1M = 25k, Saldo Akhir 0, Total 1.025M
        $last = $installments[11];
        $this->assertEquals(12, $last->installment_number);
        $this->assertEquals(1000000.00, $last->beginning_balance);
        $this->assertEquals(1000000.00, $last->principal_amount);
        $this->assertEquals(25000.00, $last->interest_amount);
        $this->assertEquals(0.00, $last->ending_balance);
        $this->assertEquals(1025000.00, $last->total_amount);
    }

    public function test_pay_installment_records_journal_and_updates_balance()
    {
        $this->setupCoa();

        $account = Account::forceCreate([
            'account_number' => 'KAS-101',
            'account_name'   => 'Kas Koperasi',
            'account_type'   => 'kas',
            'category'       => 'asset',
            'balance'        => 20000000.00,
        ]);

        $member = Member::create([
            'member_number' => 'PELITA-202608-0003',
            'nik'           => '1234567890123458',
            'name'          => 'Bob Anggota',
            'email'         => 'bob.anggota@koperasi.com',
            'phone'         => '081234567892',
            'password'      => bcrypt('123456'),
            'pin_code'      => '123456',
            'address'       => 'Jl. Anggota No. 3',
            'status'        => 'active',
            'has_buku_biru' => true,
        ]);

        $manager = User::create([
            'name'     => 'Manager Pay',
            'email'    => 'manager_pay@koperasi.com',
            'password' => bcrypt('123456'),
            'role'     => 'manager',
            'nik'      => '8888999900001111',
        ]);

        Sanctum::actingAs($member);
        $applyRes = $this->postJson('/api/user/loans/apply', ['amount' => 12000000, 'tenor' => 12]);
        $loanId = $applyRes->json('data.id');

        Sanctum::actingAs($manager);
        $this->postJson("/api/admin/loans/{$loanId}/approve-manager")->assertStatus(200);

        $firstInst = LoanInstallment::where('loan_id', $loanId)->where('installment_number', 1)->first();

        // Bayar angsuran ke-1 (Total: Pokok 1M + Jasa 300k = 1.3M)
        $payRes = $this->postJson("/api/loans/installments/{$firstInst->id}/pay", [
            'receipt_number' => 'KM-TEST-001',
            'penalty_fee'    => 0.0,
        ]);

        $payRes->assertStatus(200);
        $this->assertEquals('paid', $payRes->json('data.installment.status'));
        $this->assertEquals(11000000.00, $payRes->json('data.loan_sisa_pokok'));
        $this->assertEquals(1300000.00, $payRes->json('data.total_dibayar'));

        // Cek sisa pokok pada Loan
        $loan = Loan::find($loanId);
        $this->assertEquals(11000000.00, $loan->remaining_principal);

        // Cek transaksi Kas Masuk tercatat
        $trx = Transaction::where('receipt_number', 'KM-TEST-001')->first();
        $this->assertNotNull($trx);
        $this->assertEquals('deposit', $trx->type);
        $this->assertEquals(1300000.00, $trx->amount);
    }

    public function test_get_loan_card_endpoint()
    {
        $this->setupCoa();

        Account::forceCreate([
            'account_number' => 'KAS-101',
            'account_name'   => 'Kas Koperasi',
            'account_type'   => 'kas',
            'category'       => 'asset',
            'balance'        => 20000000.00,
        ]);

        $member = Member::create([
            'member_number' => 'PELITA-202608-0004',
            'nik'           => '1234567890123459',
            'name'          => 'Charlie Anggota',
            'email'         => 'charlie.anggota@koperasi.com',
            'phone'         => '081234567893',
            'password'      => bcrypt('123456'),
            'pin_code'      => '123456',
            'address'       => 'Jl. Kartu Kuning No. 4',
            'status'        => 'active',
            'has_buku_biru' => true,
        ]);

        $manager = User::create([
            'name'     => 'Manager Card',
            'email'    => 'manager_card@koperasi.com',
            'password' => bcrypt('123456'),
            'role'     => 'manager',
            'nik'      => '9999000011112222',
        ]);

        Sanctum::actingAs($member);
        $applyRes = $this->postJson('/api/user/loans/apply', [
            'amount'     => 6000000,
            'tenor'      => 6,
            'purpose'    => 'Renovasi',
            'collateral' => 'Sertifikat Tanah',
        ]);
        $loanId = $applyRes->json('data.id');

        Sanctum::actingAs($manager);
        $this->postJson("/api/admin/loans/{$loanId}/approve-manager")->assertStatus(200);

        // Panggil endpoint Kartu Kuning
        $cardRes = $this->getJson("/api/loans/{$loanId}/card");
        $cardRes->assertStatus(200);
        $cardData = $cardRes->json('data');

        $this->assertArrayHasKey('header', $cardData);
        $this->assertArrayHasKey('installments', $cardData);
        $this->assertArrayHasKey('summary', $cardData);

        $header = $cardData['header'];
        $this->assertEquals('Charlie Anggota', $header['nama_anggota']);
        $this->assertEquals(6000000.00, $header['plafon_pinjaman']);
        $this->assertEquals(6, $header['tenor_bulan']);
        $this->assertEquals(2.50, $header['suku_bunga']);
        $this->assertEquals('declining_balance', $header['interest_method']);
        $this->assertEquals('Sertifikat Tanah', $header['agunan']);

        $this->assertCount(6, $cardData['installments']);
    }

    public function test_reject_loan_endpoint()
    {
        $this->setupCoa();

        $member = Member::create([
            'member_number' => 'PELITA-202608-0005',
            'nik'           => '1234567890123460',
            'name'          => 'Dave Anggota',
            'email'         => 'dave.anggota@koperasi.com',
            'phone'         => '081234567894',
            'password'      => bcrypt('123456'),
            'pin_code'      => '123456',
            'status'        => 'active',
            'has_buku_biru' => true,
        ]);

        $admin = User::create([
            'name'     => 'Admin Reject',
            'email'    => 'admin_reject@koperasi.com',
            'password' => bcrypt('123456'),
            'role'     => 'admin',
            'nik'      => '3333444455556666',
        ]);

        Sanctum::actingAs($member);
        $applyRes = $this->postJson('/api/user/loans/apply', ['amount' => 5000000, 'tenor' => 12]);
        $loanId = $applyRes->json('data.id');

        Sanctum::actingAs($admin);
        $rejectRes = $this->postJson("/api/admin/loans/{$loanId}/reject", [
            'reason' => 'Agunan tidak memenuhi syarat nilai taksasi',
        ]);

        $rejectRes->assertStatus(200);
        $this->assertEquals('rejected', $rejectRes->json('data.status'));
        $this->assertEquals('Agunan tidak memenuhi syarat nilai taksasi', $rejectRes->json('data.notes'));

        $loan = Loan::find($loanId);
        $this->assertEquals('rejected', $loan->status);
    }

    public function test_admin_loans_filter_pending_manager_and_transactions_pending()
    {
        $this->setupCoa();

        $member = Member::create([
            'member_number' => 'PELITA-202608-0006',
            'nik'           => '1234567890123461',
            'name'          => 'Eva Anggota',
            'email'         => 'eva.anggota@koperasi.com',
            'phone'         => '081234567895',
            'password'      => bcrypt('123456'),
            'pin_code'      => '123456',
            'status'        => 'active',
            'has_buku_biru' => true,
        ]);

        $admin = User::create([
            'name'     => 'Admin Queue',
            'email'    => 'admin_queue@koperasi.com',
            'password' => bcrypt('123456'),
            'role'     => 'admin',
            'nik'      => '4444555566667777',
        ]);

        Sanctum::actingAs($member);
        $applyRes = $this->postJson('/api/user/loans/apply', ['amount' => 8000000, 'tenor' => 12]);
        $loanId = $applyRes->json('data.id');

        Sanctum::actingAs($admin);
        // Admin approves -> status becomes WAITING_MANAGER_APPROVAL
        $this->postJson("/api/admin/loans/{$loanId}/approve-admin")->assertStatus(200);

        // Check GET /api/admin/loans?status=pending_manager
        $filterRes = $this->getJson('/api/admin/loans?status=pending_manager');
        $filterRes->assertStatus(200);
        $loans = $filterRes->json('data.data');
        $this->assertNotEmpty($loans);
        $this->assertEquals($loanId, $loans[0]['id']);
        $this->assertEquals('WAITING_MANAGER_APPROVAL', $loans[0]['status']);

        // Check GET /api/transactions/pending
        $pendingTrxRes = $this->getJson('/api/transactions/pending');
        $pendingTrxRes->assertStatus(200);
    }

    public function test_approve_by_manager_rejects_non_buku_biru_member()
    {
        $this->setupCoa();

        $account = Account::forceCreate([
            'account_number' => 'KAS-101',
            'account_name'   => 'Kas Koperasi',
            'account_type'   => 'kas',
            'category'       => 'asset',
            'balance'        => 20000000.00,
        ]);

        $memberPutih = Member::create([
            'member_number' => 'PELITA-202608-0007',
            'nik'           => '1234567890123462',
            'name'          => 'Frank Putih',
            'email'         => 'frank.putih@koperasi.com',
            'phone'         => '081234567896',
            'password'      => bcrypt('123456'),
            'pin_code'      => '123456',
            'status'        => 'active',
            'has_buku_biru' => false,
            'has_buku_putih'=> true,
        ]);

        $loan = Loan::create([
            'loan_code'           => 'LOAN-FORCE-001',
            'member_id'           => $memberPutih->id,
            'amount'              => 5000000,
            'interest_rate'       => 2.50,
            'duration_months'     => 12,
            'monthly_installment' => 541667,
            'remaining_amount'    => 5000000,
            'status'              => 'pending_manager',
            'application_date'    => now()->toDateString(),
        ]);

        $manager = User::create([
            'name'     => 'Manager Force',
            'email'    => 'manager_force@koperasi.com',
            'password' => bcrypt('123456'),
            'role'     => 'manager',
            'nik'      => '5555666677779999',
        ]);

        Sanctum::actingAs($manager);
        $approveRes = $this->postJson("/api/admin/loans/{$loan->id}/approve-manager");
        $approveRes->assertStatus(422);
        $this->assertFalse($approveRes->json('success'));
    }

    public function test_multi_level_loan_approval_and_installment_sequence(): void
    {
        $this->setupCoa();

        Account::forceCreate([
            'account_number' => 'KAS-101',
            'account_name'   => 'Kas Koperasi',
            'account_type'   => 'kas',
            'category'       => 'asset',
            'balance'        => 20000000.00,
        ]);

        $member = Member::create([
            'member_number' => 'PELITA-SEQ-001',
            'nik'           => '1234567890123999',
            'name'          => 'George Seq',
            'email'         => 'george.seq@koperasi.com',
            'phone'         => '081234567999',
            'password'      => bcrypt('123456'),
            'pin_code'      => '123456',
            'status'        => 'active',
            'has_buku_biru' => true,
        ]);

        $admin = User::create([
            'name'     => 'Admin Seq',
            'email'    => 'admin.seq@koperasi.com',
            'password' => bcrypt('123456'),
            'role'     => 'admin',
            'nik'      => '2222333344449999',
        ]);

        $manager = User::create([
            'name'     => 'Manager Seq',
            'email'    => 'manager.seq@koperasi.com',
            'password' => bcrypt('123456'),
            'role'     => 'manager',
            'nik'      => '6666777788888888',
        ]);

        // 1. Apply Loan (Diajukan) -> pending_admin
        Sanctum::actingAs($member);
        $applyRes = $this->postJson('/api/user/loans/apply', [
            'amount' => 2000000,
            'tenor'  => 2,
        ]);
        $applyRes->assertStatus(201);
        $loanId = $applyRes->json('data.id');

        // 2. Admin Verify
        Sanctum::actingAs($admin);
        $verifyRes = $this->postJson("/api/admin/loans/{$loanId}/verify", [
            'collateral'    => 'BPKB Motor',
            'tenor'         => 2,
            'interest_rate' => 2.50,
        ]);
        $verifyRes->assertStatus(200);
        $this->assertEquals('WAITING_MANAGER_APPROVAL', $verifyRes->json('data.status'));
        $this->assertEquals('BPKB Motor', $verifyRes->json('data.collateral'));

        // Check pending manager endpoints
        Sanctum::actingAs($manager);
        $pendingRes1 = $this->getJson('/api/manager/approvals');
        $pendingRes1->assertStatus(200);
        $this->assertCount(1, $pendingRes1->json('data'));

        $pendingRes2 = $this->getJson('/api/manager/loans/pending');
        $pendingRes2->assertStatus(200);
        $this->assertCount(1, $pendingRes2->json('data'));

        // 3. Manager Approve
        // Non-manager role fails
        Sanctum::actingAs($admin);
        $this->postJson("/api/manager/loans/{$loanId}/approve")->assertStatus(403);

        // Manager role succeeds
        Sanctum::actingAs($manager);
        $approveRes = $this->postJson("/api/manager/loans/{$loanId}/approve");
        $approveRes->assertStatus(200);
        $this->assertEquals('APPROVED_BY_MANAGER', $approveRes->json('data.status'));

        // Admin disburses
        Sanctum::actingAs($admin);
        $disbRes = $this->postJson("/api/admin/loans/{$loanId}/disburse");
        $disbRes->assertStatus(200);
        $this->assertEquals('DISBURSED', $disbRes->json('data.status'));

        // 4. Pay Installment Sequence Validation
        $inst1 = LoanInstallment::where('loan_id', $loanId)->where('installment_number', 1)->first();
        $inst2 = LoanInstallment::where('loan_id', $loanId)->where('installment_number', 2)->first();

        Sanctum::actingAs($admin);
        // Try paying installment 2 first -> fails
        $pay2Fail = $this->postJson("/api/admin/loans/installments/{$inst2->id}/pay");
        $pay2Fail->assertStatus(400);
        $this->assertFalse($pay2Fail->json('success'));
        $this->assertStringContainsString('Angsuran sebelumnya', $pay2Fail->json('message'));

        // Pay installment 1 -> succeeds
        $pay1 = $this->postJson("/api/admin/loans/installments/{$inst1->id}/pay");
        $pay1->assertStatus(200);
        $this->assertEquals('paid', $pay1->json('data.installment.status'));

        // Pay installment 2 -> succeeds and completes the loan
        $pay2 = $this->postJson("/api/admin/loans/installments/{$inst2->id}/pay");
        $pay2->assertStatus(200);
        $this->assertEquals('paid', $pay2->json('data.installment.status'));
        $this->assertEquals('completed', $pay2->json('data.loan_status'));
    }

    public function test_loan_rejection_flow(): void
    {
        $member = Member::create([
            'member_number' => 'PELITA-REJ-001',
            'nik'           => '1234567890123888',
            'name'          => 'Harry Rej',
            'email'         => 'harry.rej@koperasi.com',
            'phone'         => '081234567888',
            'password'      => bcrypt('123456'),
            'pin_code'      => '123456',
            'status'        => 'active',
            'has_buku_biru' => true,
        ]);

        $admin = User::create([
            'name'     => 'Admin Rej',
            'email'    => 'admin.rej@koperasi.com',
            'password' => bcrypt('123456'),
            'role'     => 'admin',
            'nik'      => '2222333344448888',
        ]);

        Sanctum::actingAs($member);
        $applyRes = $this->postJson('/api/user/loans/apply', [
            'amount' => 1000000,
            'tenor'  => 12,
        ]);
        $applyRes->assertStatus(201);
        $loanId = $applyRes->json('data.id');

        Sanctum::actingAs($admin);
        $rejectRes = $this->postJson("/api/loans/{$loanId}/reject", [
            'rejection_reason' => 'Persyaratan agunan kurang lengkap',
        ]);
        $rejectRes->assertStatus(200);
        $this->assertEquals('rejected', $rejectRes->json('data.status'));
        $this->assertEquals('Persyaratan agunan kurang lengkap', $rejectRes->json('data.notes'));
    }

    public function test_flat_interest_loan_schedule_and_payment_journal(): void
    {
        $this->setupCoa();

        Account::forceCreate([
            'account_number' => 'KAS-101',
            'account_name'   => 'Kas Koperasi',
            'account_type'   => 'kas',
            'category'       => 'asset',
            'balance'        => 30000000.00,
        ]);

        $member = Member::create([
            'member_number' => 'PELITA-FLAT-001',
            'nik'           => '1234567890999888',
            'name'          => 'Flat Member',
            'email'         => 'flat.member@koperasi.com',
            'phone'         => '081299988877',
            'password'      => bcrypt('123456'),
            'pin_code'      => '123456',
            'status'        => 'active',
            'has_buku_biru' => true,
        ]);

        $admin = User::create([
            'name'     => 'Admin Flat',
            'email'    => 'admin.flat@koperasi.com',
            'password' => bcrypt('123456'),
            'role'     => 'admin',
            'nik'      => '1111999988887777',
        ]);

        $manager = User::create([
            'name'     => 'Manager Flat',
            'email'    => 'manager.flat@koperasi.com',
            'password' => bcrypt('123456'),
            'role'     => 'manager',
            'nik'      => '2222999988887777',
        ]);

        // 1. Apply Flat Loan (Plafon: 12 Juta, Tenor: 12 Bulan, Method: flat -> Default Rate: 1.00%)
        Sanctum::actingAs($member);
        $applyRes = $this->postJson('/api/user/loans/apply', [
            'amount'          => 12000000,
            'tenor'           => 12,
            'interest_method' => 'flat',
        ]);
        $applyRes->assertStatus(201);
        $loanData = $applyRes->json('data');
        $this->assertEquals(1.00, $loanData['interest_rate']);
        $this->assertEquals('flat', $loanData['interest_method']);
        // Pokok = 1.000.000, Jasa Flat = 1% x 12.000.000 = 120.000 => Angsuran 1.120.000
        $this->assertEquals(1120000.00, $loanData['monthly_installment']);

        $loanId = $loanData['id'];

        // 2. Admin Verify
        Sanctum::actingAs($admin);
        $this->postJson("/api/admin/loans/{$loanId}/verify")->assertStatus(200);

        // 3. Manager Approve
        Sanctum::actingAs($manager);
        $this->postJson("/api/loans/{$loanId}/approve-manager")->assertStatus(200);

        // 4. Admin Disburse
        Sanctum::actingAs($admin);
        $this->postJson("/api/admin/loans/{$loanId}/disburse")->assertStatus(200);

        // 5. Verify Installments generated
        $installments = LoanInstallment::where('loan_id', $loanId)->orderBy('installment_number')->get();
        $this->assertCount(12, $installments);

        foreach ($installments as $inst) {
            $this->assertEquals(1000000.00, $inst->principal_amount);
            $this->assertEquals(120000.00, $inst->interest_amount);
            $this->assertEquals(1120000.00, $inst->total_amount);
        }

        // 6. Pay installment 1
        $firstInst = $installments[0];
        $payRes = $this->postJson("/api/admin/loans/installments/{$firstInst->id}/pay", [
            'receipt_number' => 'KM-FLAT-001',
        ]);
        $payRes->assertStatus(200);

        // Verify Journal detail description
        $journal = \App\Models\JournalEntry::where('voucher_number', 'KM-FLAT-001')->first();
        $this->assertNotNull($journal);
        $jasaCoaId = \App\Models\ChartOfAccount::where('account_code', '4180')->value('id');
        $jasaDetail = $journal->details()->where('account_id', $jasaCoaId)->first();
        $this->assertNotNull($jasaDetail);
        $this->assertStringContainsString('Flat', $jasaDetail->description);
        $this->assertStringContainsString('1.00%', $jasaDetail->description);
        $this->assertEquals(120000.00, $jasaDetail->credit);
    }

    public function test_loan_model_generate_installment_schedule_helper_flat_and_declining(): void
    {
        $member = Member::create([
            'member_number' => 'PELITA-MODEL-001',
            'nik'           => '1234567890777666',
            'name'          => 'Model Member',
            'email'         => 'model.member@koperasi.com',
            'phone'         => '081277766655',
            'password'      => bcrypt('123456'),
            'pin_code'      => '123456',
            'status'        => 'active',
            'has_buku_biru' => true,
        ]);

        // 1. Declining Balance (Plafon 6.000.000, Tenor 6 Bulan, Rate 2.50%)
        $decliningLoan = new Loan([
            'amount'          => 6000000,
            'duration_months' => 6,
            'interest_method' => 'declining_balance',
            'interest_rate'   => 2.50,
        ]);
        $scheduleDec = $decliningLoan->generateInstallmentSchedule();
        $this->assertCount(6, $scheduleDec);
        $this->assertEquals(150000.00, $scheduleDec[0]['interest_amount']); // 2.5% of 6M
        $this->assertEquals(125000.00, $scheduleDec[1]['interest_amount']); // 2.5% of 5M
        $this->assertEquals(25000.00, $scheduleDec[5]['interest_amount']);  // 2.5% of 1M

        // 2. Flat (Plafon 6.000.000, Tenor 6 Bulan, Rate 1.00%)
        $flatLoan = new Loan([
            'amount'          => 6000000,
            'duration_months' => 6,
            'interest_method' => 'flat',
            'interest_rate'   => 1.00,
        ]);
        $scheduleFlat = $flatLoan->generateInstallmentSchedule();
        $this->assertCount(6, $scheduleFlat);
        foreach ($scheduleFlat as $row) {
            $this->assertEquals(1000000.00, $row['principal_amount']);
            $this->assertEquals(60000.00, $row['interest_amount']); // 1% of 6M = 60.000
            $this->assertEquals(1060000.00, $row['total_amount']);
        }
    }

    public function test_loan_detail_and_installments_endpoint_returns_json_complete(): void
    {
        $this->setupCoa();
        $admin = User::create([
            'name' => 'Admin Detail',
            'email' => 'admin_detail@koperasi.com',
            'password' => bcrypt('123456'),
            'role' => 'admin',
            'nik' => '3201010101010001',
        ]);
        $member = Member::create([
            'member_number' => 'MBR-DETAIL-001',
            'nik' => '3201010101010011',
            'name' => 'Member Detail',
            'email' => 'member_detail@koperasi.com',
            'phone' => '081299990001',
            'password' => bcrypt('123456'),
            'has_buku_biru' => true,
        ]);
        Sanctum::actingAs($admin);

        $loan = Loan::create([
            'loan_code'           => 'LOAN-DETAIL-TEST-001',
            'member_id'           => $member->id,
            'amount'              => 5000000,
            'interest_rate'       => 2.50,
            'interest_method'     => 'declining_balance',
            'duration_months'     => 5,
            'tenor_months'        => 5,
            'monthly_installment' => 1125000,
            'remaining_amount'    => 5000000,
            'remaining_principal' => 5000000,
            'status'              => 'active',
            'application_date'    => '2026-03-01',
            'disbursement_date'   => '2026-03-05',
            'due_date'            => '2026-08-05',
        ]);

        LoanInstallment::create([
            'loan_id'            => $loan->id,
            'installment_number' => 1,
            'beginning_balance'  => 5000000,
            'principal_amount'   => 1000000,
            'interest_amount'    => 125000,
            'ending_balance'     => 4000000,
            'total_amount'       => 1125000,
            'due_date'           => '2026-04-05',
            'status'             => 'unpaid',
            'paid_by_member_id'  => $member->id,
        ]);

        // GET /api/loans/{id}/card
        $res1 = $this->getJson("/api/loans/{$loan->id}/card");
        $res1->assertStatus(200)
             ->assertJsonPath('success', true)
             ->assertJsonPath('data.header.loan_code', 'LOAN-DETAIL-TEST-001');
        $this->assertNotEmpty($res1->json('data.installments'));
    }

    public function test_migrate_existing_loan_without_cash_outflow_and_bypass_approval(): void
    {
        $this->setupCoa();
        $admin = User::create([
            'name' => 'Admin Migrate',
            'email' => 'admin_migrate@koperasi.com',
            'password' => bcrypt('123456'),
            'role' => 'admin',
            'nik' => '3201010101010002',
        ]);
        $member = Member::create([
            'member_number' => 'MBR-MIGRATE-001',
            'nik' => '3201010101010012',
            'name' => 'Member Migrate',
            'email' => 'member_migrate@koperasi.com',
            'phone' => '081299990002',
            'password' => bcrypt('123456'),
            'has_buku_biru' => true,
        ]);
        Sanctum::actingAs($admin);

        $initialKasBalance = \App\Models\Account::where('account_type', 'kas')->value('balance') ?? 0;
        $initialKKCount    = Transaction::where('type', 'withdrawal')->count();

        $payload = [
            'member_id'        => $member->id,
            'interest_method'  => 'flat',
            'interest_rate'    => 1.00,
            'original_amount'  => 12000000,
            'current_balance'  => 6000000,
            'remaining_tenor'  => 6,
            'due_date'         => '2026-09-20',
            'disbursement_date'=> '2026-03-20',
            'notes'            => 'Migrasi saldo pinjaman lama CUM Pelita',
        ];

        $res = $this->postJson('/api/loans/migrate-existing', $payload);
        $res->assertStatus(201)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.status', 'active')
            ->assertJsonPath('data.amount', 12000000)
            ->assertJsonPath('data.original_amount', 12000000)
            ->assertJsonPath('data.plafon_awal', 12000000)
            ->assertJsonPath('data.remaining_principal', 6000000)
            ->assertJsonPath('data.sisa_saldo_pokok', 6000000);

        $loanId = $res->json('data.id');
        $this->assertNotNull($loanId);

        // Verify GET /api/loans/{id} returns distinct plafon_awal and sisa_saldo_pokok
        $showRes = $this->getJson("/api/loans/{$loanId}");
        $showRes->assertStatus(200)
            ->assertJsonPath('data.original_amount', 12000000)
            ->assertJsonPath('data.plafon_awal', 12000000)
            ->assertJsonPath('data.amount', 12000000)
            ->assertJsonPath('data.remaining_principal', 6000000)
            ->assertJsonPath('data.sisa_saldo_pokok', 6000000)
            ->assertJsonPath('data.remaining_balance', 6000000);

        // Verify GET /api/loans/{id}/card returns distinct plafon_awal and sisa_saldo_pokok in header
        $cardRes = $this->getJson("/api/loans/{$loanId}/card");
        $cardRes->assertStatus(200)
            ->assertJsonPath('data.header.original_amount', 12000000)
            ->assertJsonPath('data.header.plafon_awal', 12000000)
            ->assertJsonPath('data.header.amount', 12000000)
            ->assertJsonPath('data.header.remaining_principal', 6000000)
            ->assertJsonPath('data.header.sisa_saldo_pokok', 6000000)
            ->assertJsonPath('data.header.remaining_balance', 6000000);

        // Verify no Kas Keluar (KK) was created
        $finalKasBalance = \App\Models\Account::where('account_type', 'kas')->value('balance') ?? 0;
        $finalKKCount    = Transaction::where('type', 'withdrawal')->count();
        $this->assertEquals($initialKasBalance, $finalKasBalance);
        $this->assertEquals($initialKKCount, $finalKKCount);

        // Verify remaining installments generated based on current_balance (6.000.000 / 6 = 1.000.000 pokok)
        $installments = LoanInstallment::where('loan_id', $loanId)->orderBy('installment_number')->get();
        $this->assertCount(6, $installments);
        $this->assertEquals(6000000.00, $installments[0]->beginning_balance);
        $this->assertEquals(1000000.00, $installments[0]->principal_amount);
        $this->assertEquals(120000.00, $installments[0]->interest_amount); // 1% of 12M = 120.000
        $this->assertEquals(5000000.00, $installments[0]->ending_balance);
        $this->assertEquals(0.00, $installments[5]->ending_balance);
    }

    public function test_flexible_repayment_calculation_deducts_only_principal(): void
    {
        $this->setupCoa();
        Account::forceCreate([
            'account_number' => 'KAS-999',
            'account_name'   => 'Kas Operasional',
            'account_type'   => 'kas',
            'category'       => 'asset',
            'balance'        => 50000000.00,
        ]);

        $admin = User::create([
            'name' => 'Admin Flex',
            'email' => 'admin_flex@koperasi.com',
            'password' => bcrypt('123456'),
            'role' => 'admin',
            'nik' => '3201010101010003',
        ]);
        $member = Member::create([
            'member_number' => 'MBR-FLEX-001',
            'nik' => '3201010101010013',
            'name' => 'Member Flex',
            'email' => 'member_flex@koperasi.com',
            'phone' => '081299990003',
            'password' => bcrypt('123456'),
            'has_buku_biru' => true,
        ]);
        Sanctum::actingAs($admin);

        $loan = Loan::create([
            'loan_code'           => 'LOAN-FLEX-001',
            'member_id'           => $member->id,
            'amount'              => 10000000,
            'interest_rate'       => 2.50,
            'interest_method'     => 'declining_balance',
            'duration_months'     => 10,
            'tenor_months'        => 10,
            'monthly_installment' => 1250000,
            'remaining_amount'    => 10000000,
            'remaining_principal' => 10000000,
            'status'              => 'active',
            'application_date'    => '2026-01-01',
            'disbursement_date'   => '2026-01-05',
            'due_date'            => '2026-11-05',
        ]);

        $inst = LoanInstallment::create([
            'loan_id'            => $loan->id,
            'installment_number' => 1,
            'beginning_balance'  => 10000000,
            'principal_amount'   => 1000000,
            'interest_amount'    => 250000,
            'ending_balance'     => 9000000,
            'total_amount'       => 1250000,
            'due_date'           => '2026-02-05',
            'status'             => 'unpaid',
            'paid_by_member_id'  => $member->id,
        ]);

        // Pay with customized flexible components:
        // Pokok = 1.500.000, Jasa = 250.000, Denda = 50.000 => Total = 1.800.000
        $payRes = $this->postJson("/api/loans/installments/{$inst->id}/pay", [
            'principal_amount' => 1500000,
            'interest_amount'  => 250000,
            'penalty_amount'   => 50000,
            'receipt_number'   => 'KM-FLEX-001',
        ]);

        $payRes->assertStatus(200)
               ->assertJsonPath('success', true)
               ->assertJsonPath('data.total_dibayar', 1800000)
               ->assertJsonPath('data.loan_sisa_pokok', 8500000); // 10.000.000 - 1.500.000 = 8.500.000 (ONLY principal deducted!)

        // Check Transaction KM
        $trx = Transaction::where('receipt_number', 'KM-FLEX-001')->first();
        $this->assertNotNull($trx);
        $this->assertEquals(1800000.00, $trx->amount);
        $this->assertEquals('approved', $trx->status);
        $this->assertEquals('deposit', $trx->type);

        // Check Journal split entries
        $journal = \App\Models\JournalEntry::where('voucher_number', 'KM-FLEX-001')->first();
        $this->assertNotNull($journal);
        $details = $journal->details;
        $this->assertEquals(1800000.00, $details->where('debit', '>', 0)->sum('debit')); // Kas debet 1.800.000
        $this->assertEquals(1800000.00, $details->where('credit', '>', 0)->sum('credit')); // Total kredit 1.800.000
    }

    public function test_active_members_dropdown_endpoint(): void
    {
        $admin = User::create([
            'name' => 'Admin Active Test',
            'email' => 'admin_act@koperasi.com',
            'password' => bcrypt('123456'),
            'role' => 'admin',
            'nik' => '3201010101010999',
        ]);
        Member::create([
            'member_number' => '1024-1909-4176',
            'nik' => '3201010101010888',
            'name' => 'Elistan Simarmata',
            'email' => 'elistan@koperasi.com',
            'phone' => '081299990888',
            'password' => bcrypt('123456'),
            'status' => 'active',
            'has_buku_biru' => true,
        ]);
        Member::create([
            'member_number' => '1024-1909-4177',
            'nik' => '3201010101010889',
            'name' => 'Non Active Member',
            'email' => 'nonactive@koperasi.com',
            'phone' => '081299990889',
            'password' => bcrypt('123456'),
            'status' => 'inactive',
            'has_buku_biru' => false,
        ]);

        Sanctum::actingAs($admin);

        // 1. Test GET /api/members/active-list
        $res = $this->getJson('/api/members/active-list');
        $res->assertStatus(200)
            ->assertJsonPath('status', 'success')
            ->assertJsonStructure([
                'status',
                'data' => [
                    '*' => ['id', 'name', 'member_no']
                ]
            ]);

        $items = $res->json('data');
        $elistan = collect($items)->firstWhere('name', 'Elistan Simarmata');
        $this->assertNotNull($elistan);
        $this->assertEquals('1024-1909-4176', $elistan['member_no']);

        // Non active should not be in active list
        $nonActive = collect($items)->firstWhere('name', 'Non Active Member');
        $this->assertNull($nonActive);

        // 2. Test GET /api/members?all=1
        $resAll = $this->getJson('/api/members?all=1');
        $resAll->assertStatus(200)
               ->assertJsonPath('status', 'success');
        $allData = $resAll->json('data');
        $this->assertIsArray($allData);
        $elistanAll = collect($allData)->firstWhere('name', 'Elistan Simarmata');
        $this->assertNotNull($elistanAll);
        $this->assertEquals('1024-1909-4176', $elistanAll['member_no']);
    }

    public function test_loan_card_and_detail_endpoint_specification(): void
    {
        $this->setupCoa();
        $teller = User::create([
            'name'     => 'Kasir Teller 1',
            'email'    => 'teller1@koperasi.com',
            'password' => bcrypt('123456'),
            'role'     => 'admin',
            'nik'      => '3201010101099999',
        ]);
        $member = Member::create([
            'member_number' => '1024-2026-0001',
            'nik'           => '3201010101088888',
            'name'          => 'Budi Santoso',
            'email'         => 'budi@koperasi.com',
            'phone'         => '081234567899',
            'address'       => 'Jl. Merdeka No. 10 Medan',
            'password'      => bcrypt('123456'),
            'has_buku_biru' => true,
        ]);
        Sanctum::actingAs($teller);

        $loan = Loan::create([
            'loan_code'           => 'SH-2026-0012',
            'member_id'           => $member->id,
            'amount'              => 10000000.0,
            'interest_rate'       => 2.50,
            'interest_method'     => 'declining_balance',
            'duration_months'     => 10,
            'tenor_months'        => 10,
            'monthly_installment' => 1250000.0,
            'remaining_amount'    => 10000000.0,
            'remaining_principal' => 10000000.0,
            'status'              => 'active',
            'application_date'    => '2026-01-01',
            'disbursement_date'   => '2026-01-05',
            'due_date'            => '2026-11-05',
            'collateral'          => 'BPKB Mobil Avanza',
        ]);

        // Installment 1 (Paid)
        LoanInstallment::create([
            'loan_id'            => $loan->id,
            'installment_number' => 1,
            'receipt_number'     => 'KM-20260205-001',
            'beginning_balance'  => 10000000.0,
            'principal_amount'   => 1000000.0,
            'interest_amount'    => 250000.0,
            'ending_balance'     => 9000000.0,
            'penalty_fee'        => 15000.0,
            'total_amount'       => 1265000.0,
            'due_date'           => '2026-02-05',
            'paid_at'            => '2026-02-06 10:30:00',
            'paid_by'            => $teller->id,
            'paid_by_member_id'  => $member->id,
            'status'             => 'paid',
        ]);

        // Installment 2 (Unpaid)
        LoanInstallment::create([
            'loan_id'            => $loan->id,
            'installment_number' => 2,
            'receipt_number'     => null,
            'beginning_balance'  => 9000000.0,
            'principal_amount'   => 1000000.0,
            'interest_amount'    => 225000.0,
            'ending_balance'     => 8000000.0,
            'penalty_fee'        => 0.0,
            'total_amount'       => 1225000.0,
            'due_date'           => '2026-03-05',
            'paid_by_member_id'  => $member->id,
            'status'             => 'unpaid',
        ]);

        // 1. Test GET /api/loans/{id}
        $showRes = $this->getJson("/api/loans/{$loan->id}");
        $showRes->assertStatus(200);
        $showData = $showRes->json('data');

        $this->assertEquals('SH-2026-0012', $showData['loan_number']);
        $this->assertEquals('Budi Santoso', $showData['member']['name']);
        $this->assertEquals('081234567899', $showData['member']['phone']);
        $this->assertEquals('Jl. Merdeka No. 10 Medan', $showData['member']['address']);
        $this->assertEquals(10000000.0, $showData['amount']);
        $this->assertEquals(2.50, $showData['interest_rate']);
        $this->assertEquals(10, $showData['duration_months']);
        $this->assertEquals('2026-11-05', $showData['due_date']);
        $this->assertEquals('BPKB Mobil Avanza', $showData['collateral_type']);
        $this->assertEquals(9000000.0, $showData['remaining_balance']); // 10M - 1M paid

        $insts = $showData['installments'];
        $this->assertCount(2, $insts);

        // First installment check
        $this->assertEquals('2026-02-06', $insts[0]['date']);
        $this->assertEquals('KM-20260205-001', $insts[0]['receipt_number']);
        $this->assertEquals(1, $insts[0]['installment_order']);
        $this->assertEquals(1000000.0, $insts[0]['principal_amount']);
        $this->assertEquals(250000.0, $insts[0]['interest_amount']);
        $this->assertEquals(15000.0, $insts[0]['late_fee']);
        $this->assertEquals('Kasir Teller 1', $insts[0]['teller_name']);

        // 2. Test GET /api/loans/{id}/card
        $cardRes = $this->getJson("/api/loans/{$loan->id}/card");
        $cardRes->assertStatus(200);
        $cardData = $cardRes->json('data');

        $this->assertEquals('SH-2026-0012', $cardData['loan_number']);
        $this->assertEquals('Budi Santoso', $cardData['member']['name']);
        $this->assertEquals('081234567899', $cardData['member']['phone']);
        $this->assertEquals('Jl. Merdeka No. 10 Medan', $cardData['member']['address']);
        $this->assertEquals(10000000.0, $cardData['amount']);
        $this->assertEquals(2.50, $cardData['interest_rate']);
        $this->assertEquals(10, $cardData['duration_months']);
        $this->assertEquals('2026-11-05', $cardData['due_date']);
        $this->assertEquals('BPKB Mobil Avanza', $cardData['collateral_type']);
        $this->assertEquals(9000000.0, $cardData['remaining_balance']);

        $cardInsts = $cardData['installments'];
        $this->assertEquals('2026-02-06', $cardInsts[0]['date']);
        $this->assertEquals('KM-20260205-001', $cardInsts[0]['receipt_number']);
        $this->assertEquals(1, $cardInsts[0]['installment_order']);
        $this->assertEquals(1000000.0, $cardInsts[0]['principal_amount']);
        $this->assertEquals(250000.0, $cardInsts[0]['interest_amount']);
        $this->assertEquals(15000.0, $cardInsts[0]['late_fee']);
        $this->assertEquals('Kasir Teller 1', $cardInsts[0]['teller_name']);

        // 3. Test GET /api/loans/dropdown-list
        $dropdownRes = $this->getJson('/api/loans/dropdown-list');
        $dropdownRes->assertStatus(200);
        $dropdownItems = $dropdownRes->json('data');
        $this->assertNotEmpty($dropdownItems);
        $this->assertStringContainsString('Budi Santoso', $dropdownItems[0]['label']);
        $this->assertStringContainsString('SH-2026-0012', $dropdownItems[0]['label']);

        // 4. Test GET /api/loans/{id}/card/pdf
        $pdfRes = $this->getJson("/api/loans/{$loan->id}/card/pdf");
        $pdfRes->assertStatus(200);
        $this->assertStringContainsString('application/pdf', $pdfRes->headers->get('content-type'));
    }

    public function test_flexible_installment_payment_business_rules()
    {
        $this->setupCoa();

        // 1. Setup Cash Account & User
        Account::forceCreate([
            'account_number' => 'KAS-101',
            'account_name'   => 'Kas Koperasi',
            'account_type'   => 'kas',
            'category'       => 'asset',
            'balance'        => 50000000.00,
        ]);

        $admin = User::create([
            'name'     => 'Admin Kasir',
            'email'    => 'admin.kasir@koperasi.com',
            'password' => bcrypt('password123'),
            'role'     => 'admin',
            'nik'      => '1234567890123457',
        ]);
        Sanctum::actingAs($admin);

        $member = Member::create([
            'member_number' => 'ANG-2026-0099',
            'nik'           => '1234567890987654',
            'name'          => 'Dewi Lestari',
            'email'         => 'dewi.lestari@koperasi.com',
            'phone'         => '081299887766',
            'address'       => 'Jl. Flamboyan No. 12',
            'status'        => 'active',
            'has_buku_biru' => true,
        ]);

        $loan = Loan::create([
            'loan_code'           => 'SH-2026-0099',
            'member_id'           => $member->id,
            'amount'              => 5000000.0,
            'remaining_principal' => 5000000.0,
            'remaining_amount'    => 5000000.0,
            'duration_months'     => 5,
            'interest_rate'       => 2.50,
            'interest_method'     => 'flat',
            'monthly_installment' => 1125000.0,
            'purpose'             => 'Modal Kerja',
            'status'              => 'active',
            'application_date'    => '2026-03-01',
        ]);

        $inst1 = LoanInstallment::create([
            'loan_id'            => $loan->id,
            'installment_number' => 1,
            'beginning_balance'  => 5000000.0,
            'principal_amount'   => 1000000.0,
            'interest_amount'    => 125000.0,
            'ending_balance'     => 4000000.0,
            'penalty_fee'        => 0.0,
            'total_amount'       => 1125000.0,
            'due_date'           => '2026-04-01',
            'paid_by_member_id'  => $member->id,
            'status'             => 'unpaid',
        ]);

        // Rule 1: Validation - Total payment <= 0 is rejected with 422
        $zeroPaymentRes = $this->postJson("/api/loans/installments/{$inst1->id}/pay", [
            'principal_amount' => 0,
            'interest_amount'  => 0,
            'penalty_amount'   => 0,
        ]);
        $zeroPaymentRes->assertStatus(422);
        $this->assertEquals('Total pembayaran harus lebih besar dari Rp 0.', $zeroPaymentRes->json('message'));

        // Rule 2: Interest-only payment (principal_amount = 0, interest_amount = 125000)
        // Must succeed with status PAID, loan remaining_principal unchanged, and journal without Piutang 1024
        $interestOnlyRes = $this->postJson("/api/loans/installments/{$inst1->id}/pay", [
            'principal_amount' => 0,
            'interest_amount'  => 125000,
            'penalty_amount'   => 10000,
            'receipt_number'   => 'KM-20260401-001',
        ]);
        $interestOnlyRes->assertStatus(200);
        $this->assertEquals('paid', $interestOnlyRes->json('data.installment.status'));
        $this->assertEquals(0, $interestOnlyRes->json('data.principal_paid'));
        $this->assertEquals(125000, $interestOnlyRes->json('data.interest_paid'));
        $this->assertEquals(10000, $interestOnlyRes->json('data.penalty_paid'));
        $this->assertEquals(5000000.0, $interestOnlyRes->json('data.remaining_balance')); // Principal not deducted

        // Check journal entries
        $journal = \App\Models\JournalEntry::where('voucher_number', 'KM-20260401-001')->with('details.account')->first();
        $this->assertNotNull($journal);
        $debitDetail = $journal->details->where('debit', '>', 0)->first();
        $this->assertEquals(135000.0, (float) $debitDetail->debit); // 125.000 + 10.000
        $this->assertEquals('1000', $debitDetail->account->account_code);

        // Piutang (1024) must NOT be present in details because principal = 0
        $piutangDetail = $journal->details->first(fn($d) => $d->account->account_code === '1024');
        $this->assertNull($piutangDetail);

        // Jasa Pinjaman (4180) must be present
        $jasaDetail = $journal->details->first(fn($d) => $d->account->account_code === '4180');
        $this->assertNotNull($jasaDetail);
        $this->assertEquals(125000.0, (float) $jasaDetail->credit);

        // Denda (4182) must be present
        $dendaDetail = $journal->details->first(fn($d) => $d->account->account_code === '4182');
        $this->assertNotNull($dendaDetail);
        $this->assertEquals(10000.0, (float) $dendaDetail->credit);

        // Rule 3: Sequential installment order on ad-hoc payment
        $adhocRes = $this->postJson("/api/loans/{$loan->id}/repayment", [
            'principal_amount' => 500000,
            'interest_amount'  => 125000,
        ]);
        $adhocRes->assertStatus(200);
        $this->assertEquals(2, $adhocRes->json('data.installment_order'));
        $this->assertEquals(2, $adhocRes->json('data.angsuran_ke'));
        $this->assertEquals(4500000.0, $adhocRes->json('data.remaining_balance'));
    }

    public function test_print_loan_card_with_query_token()
    {
        $this->setupCoa();

        $admin = User::create([
            'name'     => 'Admin Print',
            'email'    => 'admin.print@koperasi.com',
            'password' => bcrypt('password123'),
            'role'     => 'admin',
            'nik'      => '5544332211009988',
        ]);

        $member = Member::create([
            'member_number' => 'ANG-2026-0888',
            'nik'           => '8877665544332211',
            'name'          => 'Hendra Wijaya',
            'email'         => 'hendra.wijaya@koperasi.com',
            'phone'         => '081234567999',
            'address'       => 'Jl. Gatot Subroto No. 88',
            'status'        => 'active',
            'has_buku_biru' => true,
        ]);

        $loan = Loan::create([
            'loan_code'           => 'SH-2026-0888',
            'member_id'           => $member->id,
            'amount'              => 5000000.0,
            'remaining_principal' => 5000000.0,
            'remaining_amount'    => 5000000.0,
            'duration_months'     => 5,
            'interest_rate'       => 2.50,
            'interest_method'     => 'flat',
            'monthly_installment' => 1125000.0,
            'purpose'             => 'Modal Kerja',
            'status'              => 'active',
            'application_date'    => '2026-03-01',
        ]);

        // Buat personal access token untuk admin
        $plainToken = $admin->createToken('print-token')->plainTextToken;

        // Test Print with Query String Token (Tab browser baru tanpa header Authorization)
        $printRes = $this->get("/api/loans/{$loan->id}/print?token={$plainToken}&inline=1");
        $printRes->assertStatus(200);
        $this->assertStringContainsString('application/pdf', $printRes->headers->get('content-type'));

        // Test invalid token
        $invalidPrintRes = $this->get("/api/loans/{$loan->id}/print?token=invalid-token-12345&inline=1");
        $invalidPrintRes->assertStatus(401);
    }

    public function test_loan_transactions_history_endpoint()
    {
        $this->setupCoa();

        $account = Account::forceCreate([
            'account_number' => 'KAS-101',
            'account_name'   => 'Kas Koperasi',
            'account_type'   => 'kas',
            'category'       => 'asset',
            'balance'        => 50000000.00,
        ]);

        $admin = User::create([
            'name'     => 'Admin Loan History',
            'email'    => 'admin.history@koperasi.com',
            'password' => bcrypt('password123'),
            'role'     => 'admin',
            'nik'      => '9988776655443322',
        ]);
        Sanctum::actingAs($admin);

        $member = Member::create([
            'member_number' => 'ANG-2026-0777',
            'nik'           => '1122334455667788',
            'name'          => 'Ahmad Dahlan',
            'email'         => 'ahmad.dahlan@koperasi.com',
            'phone'         => '081234567800',
            'address'       => 'Jl. Sudirman No. 45',
            'status'        => 'active',
            'has_buku_biru' => true,
        ]);

        $loan = Loan::create([
            'loan_code'           => 'SH-2026-0777',
            'member_id'           => $member->id,
            'amount'              => 10000000.0,
            'remaining_principal' => 8000000.0,
            'remaining_amount'    => 8000000.0,
            'duration_months'     => 10,
            'interest_rate'       => 2.50,
            'interest_method'     => 'declining_balance',
            'monthly_installment' => 1250000.0,
            'purpose'             => 'Modal Usaha',
            'status'              => 'active',
            'application_date'    => '2026-01-10',
            'disbursement_date'   => '2026-01-15',
        ]);

        // 1. Transaction: Pencairan Pinjaman (KK)
        Transaction::withoutPeriodLock(function () use ($loan, $member, $account, $admin) {
            Transaction::create([
                'transaction_number' => 'KK-20260115-001',
                'receipt_number'     => 'KK-SH-2026-0777',
                'member_id'          => $member->id,
                'account_id'         => $account->id,
                'book_type'          => 'BUKU_BIRU',
                'category'           => 'pencairan_pinjaman',
                'operator_id'        => $admin->id,
                'type'               => 'withdrawal',
                'amount'             => 10000000.0,
                'transaction_date'   => '2026-01-15',
                'description'        => 'Pencairan Pinjaman (SH-2026-0777) - Ahmad Dahlan',
                'status'             => 'approved',
            ]);
        });

        // 2. Transaction: Angsuran Ke-1 (KM)
        $inst1 = LoanInstallment::create([
            'loan_id'            => $loan->id,
            'installment_number' => 1,
            'beginning_balance'  => 10000000.0,
            'principal_amount'   => 1000000.0,
            'interest_amount'    => 250000.0,
            'ending_balance'     => 9000000.0,
            'penalty_fee'        => 0.0,
            'total_amount'       => 1250000.0,
            'due_date'           => '2026-02-15',
            'paid_at'            => '2026-02-15',
            'paid_by_member_id'  => $member->id,
            'status'             => 'paid',
            'receipt_number'     => 'KM-20260215-001',
        ]);

        Transaction::withoutPeriodLock(function () use ($loan, $member, $account, $admin) {
            Transaction::create([
                'transaction_number' => 'KM-20260215-001',
                'receipt_number'     => 'KM-20260215-001',
                'member_id'          => $member->id,
                'account_id'         => $account->id,
                'book_type'          => 'BUKU_BIRU',
                'category'           => 'angsuran_pinjaman',
                'operator_id'        => $admin->id,
                'type'               => 'deposit',
                'amount'             => 1250000.0,
                'transaction_date'   => '2026-02-15',
                'description'        => 'Pembayaran Angsuran Pinjaman ke-1 (SH-2026-0777) - Ahmad Dahlan',
                'status'             => 'approved',
            ]);
        });

        // 3. Transaction: Simpanan Wajib (Harus DI-FILTER / TIDAK MUNCUL di riwayat pinjaman)
        Transaction::withoutPeriodLock(function () use ($member, $account, $admin) {
            Transaction::create([
                'transaction_number' => 'KM-20260215-002',
                'receipt_number'     => 'KM-20260215-002',
                'member_id'          => $member->id,
                'account_id'         => $account->id,
                'book_type'          => 'BUKU_BIRU',
                'category'           => 'simpanan_wajib',
                'operator_id'        => $admin->id,
                'type'               => 'deposit',
                'amount'             => 50000.0,
                'transaction_date'   => '2026-02-15',
                'description'        => 'Setor Simpanan Wajib - Ahmad Dahlan',
                'status'             => 'approved',
            ]);
        });

        // 4. Test GET /api/loans/transactions-history
        $res = $this->getJson('/api/loans/transactions-history');
        $res->assertStatus(200);

        // Verify summary
        $summary = $res->json('summary');
        $this->assertEquals(10000000.0, $summary['total_pinjaman_dicairkan']);
        $this->assertEquals(1000000.0, $summary['total_pokok_diterima']);
        $this->assertEquals(250000.0, $summary['total_jasa_diterima']);
        $this->assertEquals(9000000.0, $summary['total_sisa_pinjaman']);

        // Verify transaction items
        $items = $res->json('data.data');
        $this->assertCount(2, $items); // Only 2 loan transactions (Pencairan & Angsuran), Simpanan Wajib excluded

        // Most recent first: Angsuran (2026-02-15)
        $this->assertEquals('KM-20260215-001', $items[0]['receipt_number']);
        $this->assertEquals('REPAYMENT', $items[0]['transaction_type']);
        $this->assertEquals('Ahmad Dahlan', $items[0]['member_name']);
        $this->assertEquals('ANG-2026-0777', $items[0]['member_number']);
        $this->assertEquals('SH-2026-0777', $items[0]['loan_number']);
        $this->assertEquals(1, $items[0]['installment_order']);
        $this->assertEquals(1000000.0, $items[0]['principal_amount']);
        $this->assertEquals(250000.0, $items[0]['interest_amount']);
        $this->assertEquals(1250000.0, $items[0]['total_amount']);

        // Second: Pencairan (2026-01-15)
        $this->assertEquals('KK-SH-2026-0777', $items[1]['receipt_number']);
        $this->assertEquals('DISBURSEMENT', $items[1]['transaction_type']);
        $this->assertEquals('Ahmad Dahlan', $items[1]['member_name']);
        $this->assertEquals(10000000.0, $items[1]['principal_amount']);
        $this->assertEquals(10000000.0, $items[1]['total_amount']);
    }

    public function test_member_dashboard_loan_integration_and_apply_loan_protection(): void
    {
        $this->setupCoa();

        // 1. Setup Member
        $member = Member::create([
            'member_number' => 'PELITA-DASH-001',
            'nik'           => '1234567890123499',
            'name'          => 'Member Dashboard Test',
            'email'         => 'member.dash@koperasi.com',
            'phone'         => '081234567899',
            'password'      => bcrypt('123456'),
            'status'        => 'active',
            'has_buku_biru' => true,
        ]);

        Sanctum::actingAs($member);

        // 2. Dashboard saat belum ada pinjaman
        $dashRes1 = $this->getJson('/api/dashboard-summary');
        $dashRes1->assertStatus(200);
        $this->assertFalse($dashRes1->json('data.has_active_loan'));
        $this->assertNull($dashRes1->json('data.loan_status'));
        $this->assertEquals(0.0, (float) $dashRes1->json('data.total_pinjaman'));

        // 3. Ajukan pinjaman pertama
        $applyRes = $this->postJson('/api/user/loans/apply', [
            'amount'  => 5000000,
            'tenor'   => 10,
            'purpose' => 'Modal Usaha',
        ]);
        $applyRes->assertStatus(201);
        $loanId = $applyRes->json('data.id');

        // 4. Dashboard saat pinjaman pending_admin
        $dashRes2 = $this->getJson('/api/dashboard-summary');
        $dashRes2->assertStatus(200);
        $this->assertTrue($dashRes2->json('data.has_active_loan'));
        $this->assertEquals('pending_admin', $dashRes2->json('data.loan_status'));
        $this->assertEquals(0.0, (float) $dashRes2->json('data.total_pinjaman'));
        $this->assertEquals(5000000.0, (float) $dashRes2->json('data.current_loan.amount'));

        // 5. Coba ajukan pinjaman kedua saat masih pending -> DITOLAK (422)
        $duplicateApply = $this->postJson('/api/user/loans/apply', [
            'amount'  => 3000000,
            'tenor'   => 6,
            'purpose' => 'Pengajuan Ganda',
        ]);
        $duplicateApply->assertStatus(422);
        $this->assertStringContainsString('Anda masih memiliki pengajuan pinjaman yang sedang diproses atau belum lunas.', $duplicateApply->json('message'));

        // 6. Update status ke APPROVED_BY_MANAGER
        $loan = Loan::find($loanId);
        $loan->update(['status' => 'APPROVED_BY_MANAGER']);

        $dashRes3 = $this->getJson('/api/dashboard-summary');
        $dashRes3->assertStatus(200);
        $this->assertTrue($dashRes3->json('data.has_active_loan'));
        $this->assertEquals('APPROVED_BY_MANAGER', $dashRes3->json('data.loan_status'));
        $this->assertEquals(0.0, (float) $dashRes3->json('data.total_pinjaman')); // Belum cair -> total_pinjaman 0

        // 7. Update status ke DISBURSED (sudah dicairkan kasir)
        $loan->update([
            'status'              => 'DISBURSED',
            'remaining_principal' => 5000000,
        ]);

        $dashRes4 = $this->getJson('/api/dashboard-summary');
        $dashRes4->assertStatus(200);
        $this->assertTrue($dashRes4->json('data.has_active_loan'));
        $this->assertEquals('DISBURSED', $dashRes4->json('data.loan_status'));
        $this->assertEquals(5000000.0, (float) $dashRes4->json('data.total_pinjaman')); // Sudah cair -> total_pinjaman 5M
    }

    /**
     * Test Pembayaran Angsuran via Kasir (KM) meng-update jadwal unpaid terlama,
     * tidak membuat baris ke-13, dan menghitung accessor agregat dengan tepat.
     */
    public function test_cashier_installment_payment_updates_earliest_unpaid_installment_and_loan_aggregates(): void
    {
        $this->setupCoa();

        $account = Account::forceCreate([
            'account_number' => 'KAS-101',
            'account_name'   => 'Kas Koperasi',
            'account_type'   => 'kas',
            'category'       => 'asset',
            'balance'        => 50000000.00,
        ]);

        $member = Member::create([
            'member_number' => 'PELITA-0099',
            'nik'           => '1234567890999999',
            'name'          => 'Debitur Kasir',
            'email'         => 'debitur.kasir@koperasi.com',
            'phone'         => '081299999999',
            'status'        => 'active',
            'has_buku_biru' => true,
        ]);

        $admin = User::create([
            'name'     => 'Kasir Teller',
            'email'    => 'kasir.teller@koperasi.com',
            'password' => bcrypt('123456'),
            'role'     => 'admin',
            'nik'      => '9999888877776666',
        ]);

        // Buat pinjaman Rp 50.000.000 tenor 12 bulan (Pokok 4.166.667/bln)
        $loan = Loan::create([
            'loan_code'           => 'LOAN-20260924-0099',
            'member_id'           => $member->id,
            'amount'              => 50000000.00,
            'interest_rate'       => 2.50,
            'interest_method'     => 'declining_balance',
            'duration_months'     => 12,
            'tenor_months'        => 12,
            'monthly_installment' => 5416667.00,
            'remaining_amount'    => 50000000.00,
            'remaining_principal' => 50000000.00,
            'status'              => 'DISBURSED',
            'application_date'    => '2026-09-01',
            'disbursement_date'   => '2026-09-01',
            'due_date'            => '2027-09-01',
        ]);

        // Generate 12 baris jadwal cicilan unpaid
        $schedule = $loan->generateInstallmentSchedule(\Carbon\Carbon::parse('2026-09-01'));
        foreach ($schedule as $row) {
            LoanInstallment::create([
                'loan_id'            => $loan->id,
                'installment_number' => $row['installment_number'],
                'beginning_balance'  => $row['beginning_balance'],
                'principal_amount'   => $row['principal_amount'],
                'interest_amount'    => $row['interest_amount'],
                'ending_balance'     => $row['ending_balance'],
                'total_amount'       => $row['total_amount'],
                'due_date'           => $row['due_date'],
                'status'             => 'unpaid',
                'paid_by_member_id'  => $member->id,
            ]);
        }

        $this->assertEquals(12, $loan->installments()->count());
        $this->assertEquals(0, $loan->total_principal_paid);
        $this->assertEquals(0, $loan->total_interest_paid);
        $this->assertEquals(1, $loan->active_installment_number);

        Sanctum::actingAs($admin);

        // Kasir menginput pembayaran angsuran ke-1 lewat Kas Masuk (KM)
        $kmPayload = [
            'member_id'        => $member->id,
            'transaction_date' => '2026-09-24',
            'voucher_number'   => 'KM-20260924-001',
            'payment_method'   => 'cash',
            'items'            => [
                ['account_code' => '1024', 'amount' => 4166667, 'description' => 'Angsuran Pokok ke-1'],
                ['account_code' => '4180', 'amount' => 1250000, 'description' => 'Jasa Pinjaman ke-1'],
            ],
        ];

        $res = $this->postJson('/api/transactions', $kmPayload);
        $res->assertStatus(200);

        // Verifikasi TIDAK ADA baris ke-13 yang terbuat! Total baris tetap 12!
        $this->assertEquals(12, $loan->installments()->count());

        // Verifikasi Angsuran ke-1 ter-update menjadi 'paid'
        $inst1 = LoanInstallment::where('loan_id', $loan->id)->where('installment_number', 1)->first();
        $this->assertEquals('paid', $inst1->status);
        $this->assertEquals('KM-20260924-001', $inst1->receipt_number);
        $this->assertEquals(4166667.0, (float) $inst1->principal_amount);
        $this->assertEquals(1250000.0, (float) $inst1->interest_amount);

        // Verifikasi Angsuran ke-2 tetap 'unpaid'
        $inst2 = LoanInstallment::where('loan_id', $loan->id)->where('installment_number', 2)->first();
        $this->assertEquals('unpaid', $inst2->status);

        // Verifikasi Sisa Pokok Pinjaman berkurang
        $loan->refresh();
        $this->assertEquals(45833333.0, (float) $loan->remaining_principal);
        $this->assertEquals(45833333.0, (float) $loan->remaining_amount);

        // Verifikasi Accessor Agregasi pada Model Loan
        $this->assertEquals(4166667.0, $loan->total_principal_paid);
        $this->assertEquals(1250000.0, $loan->total_interest_paid);
        $this->assertEquals(0.0, $loan->total_penalty_paid);
        $this->assertEquals(2, $loan->active_installment_number);
    }
}




