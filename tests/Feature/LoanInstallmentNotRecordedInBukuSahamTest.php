<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Loan;
use App\Models\LoanInstallment;
use App\Models\Member;
use App\Models\Transaction;
use App\Models\User;
use App\Services\DividendService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class LoanInstallmentNotRecordedInBukuSahamTest extends TestCase
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
            'member_number'     => 'PELITA-0088',
            'name'              => 'Bpk. Tigor Nainggolan',
            'nik'               => '3201010101010088',
            'phone'             => '081234567888',
            'status'            => 'active',
            'principal_savings' => 200000.00,
            'mandatory_savings' => 100000.00,
            'voluntary_savings' => 500000.00,
            'has_buku_biru'     => true,
        ]);

        // Buat pinjaman Rp 10.000.000 tenor 10 bulan
        $this->loan = Loan::create([
            'loan_code'           => 'PJ-2026-0088',
            'member_id'           => $this->member->id,
            'amount'              => 10000000.00,
            'interest_rate'       => 2.50,
            'interest_method'     => 'flat',
            'duration_months'     => 10,
            'tenor_months'        => 10,
            'monthly_installment' => 1250000.00,
            'remaining_amount'    => 10000000.00,
            'remaining_principal' => 10000000.00,
            'status'              => 'DISBURSED',
            'application_date'    => '2026-06-01',
            'disbursement_date'   => '2026-06-01',
            'due_date'            => '2027-04-01',
        ]);

        // Buat 10 cicilan
        for ($i = 1; $i <= 10; $i++) {
            LoanInstallment::create([
                'loan_id'            => $this->loan->id,
                'installment_number' => $i,
                'beginning_balance'  => 10000000.00 - (($i - 1) * 1000000.00),
                'principal_amount'   => 1000000.00,
                'interest_amount'    => 250000.00,
                'ending_balance'     => 10000000.00 - ($i * 1000000.00),
                'total_amount'       => 1250000.00,
                'due_date'           => "2026-0" . min(9, $i) . "-15",
                'status'             => 'unpaid',
                'paid_by_member_id'  => $this->member->id,
            ]);
        }
    }

    /**
     * Test pembayaran angsuran via Kas Masuk tunggal (KM-20261014-1001)
     * TIDAK menambah Simpanan Sukarela (SS), TIDAK masuk Buku Biru,
     * mengurangi sisa pokok pinjaman, dan mengupdate jadwal menjadi 'paid'.
     */
    public function test_cashier_single_installment_payment_does_not_inflate_voluntary_savings_and_marks_paid(): void
    {
        Sanctum::actingAs($this->admin);

        $initialSs = (float) $this->member->voluntary_savings; // 500.000

        // Kasir menginput Kas Masuk tunggal bukti KM-20261014-1001
        $payload = [
            'member_id'        => $this->member->id,
            'transaction_date' => '2026-07-15',
            'receipt_number'   => 'KM-20261014-1001',
            'voucher_number'   => 'KM-20261014-1001',
            'payment_method'   => 'cash',
            'amount'           => 1250000.00,
            'category'         => 'angsuran_pinjaman',
            'description'      => 'Pembayaran Angsuran Pinjaman ke-1 (PJ-2026-0088)',
        ];

        $res = $this->postJson('/api/transactions', $payload);
        $res->assertStatus(200);

        // 1. Simpanan Sukarela (voluntary_savings) TIDAK BOLEH bertambah!
        $this->member->refresh();
        $this->assertEquals($initialSs, (float) $this->member->voluntary_savings, 'Simpanan sukarela anggota TIDAK boleh bertambah oleh angsuran pinjaman!');

        // 2. Transaksi yang terbentuk BUKAN BUKU_BIRU
        $trx = Transaction::where('receipt_number', 'KM-20261014-1001')->first();
        $this->assertNotNull($trx);
        $this->assertNotEquals('BUKU_BIRU', $trx->book_type, 'book_type transaksi angsuran tidak boleh BUKU_BIRU!');

        // 3. Status jadwal angsuran ke-1 terupdate menjadi 'paid'
        $inst1 = LoanInstallment::where('loan_id', $this->loan->id)->where('installment_number', 1)->first();
        $this->assertEquals('paid', $inst1->status, 'Status jadwal angsuran harus terupdate menjadi paid!');
        $this->assertEquals('KM-20261014-1001', $inst1->receipt_number);

        // 4. Sisa pokok pinjaman berkurang dari 10M menjadi 9M
        $this->loan->refresh();
        $this->assertEquals(9000000.00, (float) $this->loan->remaining_principal);

        // 5. Lembar Buku Saham (Dividend Statement) TIDAK mencatat KM-20261014-1001 sebagai Setoran SS
        $divService = app(DividendService::class);
        $statement = $divService->getMemberDividendStatement($this->member->id, 2027);

        // Periksa setiap baris bulanan: tidak boleh ada SS masuk dari angsuran
        foreach ($statement['monthly_records'] as $record) {
            if ($record['is_saldo_awal'] ?? false) continue;
            // ss_in pada siklus Juli tidak boleh memuat nominal angsuran 1.250.000 atau 1.000.000
            $this->assertNotEquals(1250000.00, (float) ($record['ss_in'] ?? 0));
            $this->assertNotEquals(1000000.00, (float) ($record['ss_in'] ?? 0));
        }

        // 6. getSavingsBalanceAt('SS') tidak bertambah
        $ssAtJuly = $this->member->getSavingsBalanceAt('SS', '2026-07-31');
        $this->assertEquals($initialSs, $ssAtJuly);
    }

    /**
     * Test pembayaran angsuran via payInstallment endpoint
     * TIDAK menambah Simpanan Sukarela (SS), book_type bukan BUKU_BIRU,
     * dan mengupdate status baris menjadi 'paid'.
     */
    public function test_pay_installment_endpoint_does_not_tag_buku_biru_and_marks_paid(): void
    {
        Sanctum::actingAs($this->admin);

        $initialSs = (float) $this->member->voluntary_savings;
        $inst1 = LoanInstallment::where('loan_id', $this->loan->id)->where('installment_number', 1)->first();

        $payRes = $this->postJson("/api/loans/installments/{$inst1->id}/pay", [
            'receipt_number' => 'KM-20261014-1002',
            'amount'         => 1250000.00,
            'payment_date'   => '2026-08-15',
        ]);
        $payRes->assertStatus(200);

        // 1. Status installment ke-1 terupdate menjadi 'paid'
        $inst1->refresh();
        $this->assertEquals('paid', $inst1->status);

        // 2. Transaksi yang terbentuk BUKAN BUKU_BIRU
        $trx = Transaction::where('receipt_number', 'KM-20261014-1002')->first();
        $this->assertNotNull($trx);
        $this->assertNotEquals('BUKU_BIRU', $trx->book_type);

        // 3. Simpanan sukarela anggota tidak berubah
        $this->member->refresh();
        $this->assertEquals($initialSs, (float) $this->member->voluntary_savings);

        // 4. Lembar Buku Saham tidak mencatat sebagai SS
        $divService = app(DividendService::class);
        $statement = $divService->getMemberDividendStatement($this->member->id, 2027);

        foreach ($statement['monthly_records'] as $record) {
            if ($record['is_saldo_awal'] ?? false) continue;
            $this->assertNotEquals(1250000.00, (float) ($record['ss_in'] ?? 0));
        }
    }

    /**
     * Test pembayaran angsuran via Kas Masuk bertingkat (items array dengan 1024 dan 4180)
     * TIDAK menambah voluntary_savings, book_type bukan BUKU_BIRU, dan mengupdate status menjadi 'paid'.
     */
    public function test_itemized_cashier_installment_payment_does_not_inflate_voluntary_savings(): void
    {
        Sanctum::actingAs($this->admin);

        $initialSs = (float) $this->member->voluntary_savings;

        $payload = [
            'member_id'        => $this->member->id,
            'transaction_date' => '2026-07-15',
            'receipt_number'   => 'KM-20261014-ITEM',
            'voucher_number'   => 'KM-20261014-ITEM',
            'payment_method'   => 'cash',
            'category'         => 'angsuran_pinjaman',
            'items'            => [
                ['account_code' => '1024', 'amount' => 1000000.00, 'description' => 'Angsuran Pokok Pinjaman ke-1'],
                ['account_code' => '4180', 'amount' => 250000.00,  'description' => 'Jasa Pinjaman ke-1'],
            ],
        ];

        $res = $this->postJson('/api/transactions', $payload);
        $res->assertStatus(200);

        // 1. Simpanan sukarela anggota TIDAK bertambah
        $this->member->refresh();
        $this->assertEquals($initialSs, (float) $this->member->voluntary_savings);

        // 2. Transaksi yang tersimpan book_type bukan BUKU_BIRU
        $trxs = Transaction::where('receipt_number', 'KM-20261014-ITEM')->get();
        $this->assertNotEmpty($trxs);
        foreach ($trxs as $trx) {
            $this->assertNotEquals('BUKU_BIRU', $trx->book_type);
        }

        // 3. Status jadwal angsuran ke-1 terupdate menjadi 'paid'
        $inst1 = LoanInstallment::where('loan_id', $this->loan->id)->where('installment_number', 1)->first();
        $this->assertEquals('paid', $inst1->status);

        // 4. Sisa pokok pinjaman berkurang
        $this->loan->refresh();
        $this->assertEquals(9000000.00, (float) $this->loan->remaining_principal);
    }

    /**
     * Test jika ada data historis yang tersimpan dengan book_type = 'BUKU_BIRU' dan category = 'angsuran_pinjaman',
     * DividendService dan Member::getSavingsBalanceAt('SS') tetap mengecualikannya dan tidak menghitungnya sebagai SS.
     */
    public function test_historical_buku_biru_loan_transaction_is_excluded_from_ss(): void
    {
        // Buat transaksi historis yang terlanjur berlabel BUKU_BIRU
        Transaction::create([
            'transaction_number' => 'KM-HIST-001',
            'receipt_number'     => 'KM-HIST-001',
            'member_id'          => $this->member->id,
            'account_id'         => $this->kasAccount->id,
            'book_type'          => 'BUKU_BIRU',
            'category'           => 'angsuran_pinjaman',
            'type'               => 'deposit',
            'amount'             => 1250000.00,
            'transaction_date'   => '2026-07-20',
            'description'        => 'Pembayaran Angsuran Pinjaman ke-1 (PJ-2026-0088)',
            'status'             => 'approved',
        ]);

        // 1. getSavingsBalanceAt('SS') tidak memasukkan KM-HIST-001
        $initialSs = (float) $this->member->voluntary_savings;
        $ssAtJuly = $this->member->getSavingsBalanceAt('SS', '2026-07-31');
        $this->assertEquals($initialSs, $ssAtJuly);

        // 2. Lembar Buku Saham tidak memasukkan KM-HIST-001 ke ss_in
        $divService = app(DividendService::class);
        $statement = $divService->getMemberDividendStatement($this->member->id, 2027);

        foreach ($statement['monthly_records'] as $record) {
            if ($record['is_saldo_awal'] ?? false) continue;
            $this->assertNotEquals(1250000.00, (float) ($record['ss_in'] ?? 0));
        }
    }
}
