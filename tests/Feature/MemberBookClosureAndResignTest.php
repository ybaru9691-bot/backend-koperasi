<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\ChartOfAccount;
use App\Models\JournalEntry;
use App\Models\Loan;
use App\Models\Member;
use App\Models\Transaction;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class MemberBookClosureAndResignTest extends TestCase
{
    use RefreshDatabase;

    protected User $manager;
    protected Account $kasAccount;
    protected ChartOfAccount $cashCoa;
    protected ChartOfAccount $sahamCoa;
    protected ChartOfAccount $harianCoa;
    protected ChartOfAccount $penaltiCoa;

    protected function setUp(): void
    {
        parent::setUp();

        $this->manager = User::create([
            'name'     => 'Manager Koperasi',
            'email'    => 'manager.close@koperasi.com',
            'password' => bcrypt('password123'),
            'role'     => 'manager',
            'nik'      => '1122334455667799',
        ]);

        $this->kasAccount = Account::create([
            'account_number' => 'KAS-101',
            'account_name'   => 'Kas Koperasi',
            'account_type'   => 'kas',
            'category'       => 'asset',
            'balance'        => 50000000.00,
        ]);

        $this->cashCoa = ChartOfAccount::create([
            'account_code'   => '1000',
            'account_name'   => 'Kas Tunai',
            'account_type'   => 'ASSET',
            'normal_balance' => 'DEBIT',
            'is_active'      => true,
        ]);

        $this->sahamCoa = ChartOfAccount::create([
            'account_code'   => '2020',
            'account_name'   => 'Simpanan Pokok & Wajib',
            'account_type'   => 'LIABILITY',
            'normal_balance' => 'CREDIT',
            'is_active'      => true,
        ]);

        $this->harianCoa = ChartOfAccount::create([
            'account_code'   => '2021',
            'account_name'   => 'Simpanan Harian - Buku Putih',
            'account_type'   => 'LIABILITY',
            'normal_balance' => 'CREDIT',
            'is_active'      => true,
        ]);

        $this->penaltiCoa = ChartOfAccount::create([
            'account_code'   => '4183',
            'account_name'   => 'Pendapatan Administrasi & Denda',
            'account_type'   => 'REVENUE',
            'normal_balance' => 'CREDIT',
            'is_active'      => true,
        ]);
    }

    /**
     * 1. Test Tutup Rekening Buku Putih Saja:
     *    - Saldo Harian 2.500.000 (Tier 1M - 10M -> Potongan Rp 150.000)
     *    - Kas Keluar Bersih = 2.350.000
     *    - Status Member TETAP 'active'
     *    - Saldo Buku Biru tetap utuh
     */
    public function test_close_white_book_only_deducts_tier_fee_and_keeps_member_active(): void
    {
        Sanctum::actingAs($this->manager);

        $member = Member::create([
            'nik'               => '3201010101010091',
            'member_number'     => 'ANG-CLOSE-01',
            'name'              => 'Budi Santoso',
            'status'            => 'active',
            'has_buku_biru'     => true,
            'has_buku_putih'    => true,
            'principal_savings' => 1000000.00,
            'mandatory_savings' => 2000000.00,
            'voluntary_savings' => 500000.00,
            'daily_savings'     => 2500000.00, // Saldo Buku Putih = 2.500.000
        ]);

        // 1. Cek Endpoint Preview
        $previewRes = $this->getJson("/api/members/{$member->id}/close-white-book/preview");
        $previewRes->assertStatus(200);
        $previewRes->assertJson([
            'success' => true,
            'data'    => [
                'daily_savings' => 2500000.00,
                'fee'           => 150000.00,
                'net_refund'    => 2350000.00,
                'can_close'     => true,
            ]
        ]);

        // 2. Eksekusi Penutupan Buku Putih
        $manualKK = 'KK 0881';
        $res = $this->postJson("/api/members/{$member->id}/close-white-book", [
            'voucher_no' => $manualKK,
        ]);

        $res->assertStatus(200);
        $res->assertJson([
            'success' => true,
            'data'    => [
                'daily_savings' => 2500000.00,
                'fee'           => 150000.00,
                'net_refund'    => 2350000.00,
                'voucher_no'    => $manualKK,
                'member_status' => 'active',
                'has_buku_putih'=> false,
                'has_buku_biru' => true,
            ]
        ]);

        // 3. Verifikasi Data Member di Database
        $member->refresh();
        $this->assertEquals('active', $member->status, 'Status anggota wajib tetap ACTIVE');
        $this->assertFalse((bool) $member->has_buku_putih);
        $this->assertTrue((bool) $member->has_buku_biru);
        $this->assertEquals(0.00, (float) $member->daily_savings);
        $this->assertEquals(1000000.00, (float) $member->principal_savings, 'Buku Biru harus tetap utuh');
        $this->assertEquals(2000000.00, (float) $member->mandatory_savings, 'Buku Biru harus tetap utuh');

        // 4. Verifikasi Transaksi Kas Keluar (KK)
        $trx = Transaction::where('transaction_number', $manualKK)->first();
        $this->assertNotNull($trx);
        $this->assertEquals(2350000.00, (float) $trx->amount);
        $this->assertEquals('BUKU_PUTIH', $trx->book_type);

        // 5. Verifikasi Double Entry Jurnal
        $journal = JournalEntry::where('voucher_number', $manualKK)->with('details.account')->first();
        $this->assertNotNull($journal);

        // DEBET 2021 (Simpanan Harian) = 2.500.000
        $debitHarian = $journal->details->firstWhere('account.account_code', '2021');
        $this->assertNotNull($debitHarian);
        $this->assertEquals(2500000.00, (float) $debitHarian->debit);

        // KREDIT 4183 (Pendapatan Administrasi) = 150.000
        $creditFee = $journal->details->firstWhere('account.account_code', '4183');
        $this->assertNotNull($creditFee);
        $this->assertEquals(150000.00, (float) $creditFee->credit);

        // KREDIT 1000 (Kas) = 2.350.000
        $creditKas = $journal->details->firstWhere('account.account_code', '1000');
        $this->assertNotNull($creditKas);
        $this->assertEquals(2350000.00, (float) $creditKas->credit);
    }

    /**
     * 2. Test Tutup Buku Putih ditolak jika saldo simpanan harian < biaya potongan
     */
    public function test_close_white_book_fails_if_balance_less_than_fee(): void
    {
        Sanctum::actingAs($this->manager);

        $member = Member::create([
            'nik'               => '3201010101010092',
            'member_number'     => 'ANG-CLOSE-02',
            'name'              => 'Siti Harian',
            'status'            => 'active',
            'has_buku_biru'     => true,
            'has_buku_putih'    => true,
            'daily_savings'     => 50000.00, // Saldo 50.000 < Fee 100.000
        ]);

        $res = $this->postJson("/api/members/{$member->id}/close-white-book", [
            'voucher_no' => 'KK 0882',
        ]);

        $res->assertStatus(400);
        $this->assertStringContainsString('tidak mencukupi untuk biaya', $res->json('message'));
    }

    /**
     * 3. Test Resign Total / Tutup Buku Biru Ditolak jika ada pinjaman aktif belum lunas
     */
    public function test_resign_total_rejected_if_active_loan_exists(): void
    {
        Sanctum::actingAs($this->manager);

        $member = Member::create([
            'nik'               => '3201010101010093',
            'member_number'     => 'ANG-RESIGN-02',
            'name'              => 'Ahmad Pinjam',
            'status'            => 'active',
            'has_buku_biru'     => true,
            'principal_savings' => 1000000.00,
            'mandatory_savings' => 2000000.00,
            'voluntary_savings' => 0.00,
            'daily_savings'     => 0.00,
        ]);

        Loan::create([
            'loan_code'           => 'LOAN-ACTIVE-01',
            'member_id'           => $member->id,
            'amount'              => 5000000.00,
            'interest_rate'       => 1.5,
            'duration_months'     => 12,
            'monthly_installment' => 450000.00,
            'remaining_amount'    => 3500000.00,
            'application_date'    => now()->toDateString(),
            'status'              => 'active',
        ]);

        // Cek Preview
        $previewRes = $this->getJson("/api/members/{$member->id}/resign-total/preview");
        $previewRes->assertStatus(200);
        $this->assertTrue($previewRes->json('data.has_active_loan'));
        $this->assertFalse($previewRes->json('data.can_resign'));

        // Cek Eksekusi Resign Total
        $res = $this->postJson("/api/members/{$member->id}/resign-total", [
            'voucher_no' => 'KK 0883',
        ]);

        $res->assertStatus(400);
        $this->assertStringContainsString('masih memiliki pinjaman aktif', $res->json('message'));
    }

    /**
     * 4. Test Resign Total Berhasil:
     *    - Total Saham = 6.000.000 (SP 1M + SW 4M + SS 1M) -> Tier 1M - 10M = Fee Rp 150.000
     *    - Sisa Buku Putih = 500.000
     *    - Total Simpanan = 6.500.000
     *    - Kas Keluar Bersih = 6.350.000
     *    - Status Member menjadi 'inactive'
     */
    public function test_resign_total_success_with_shares_and_white_book_cleared(): void
    {
        Sanctum::actingAs($this->manager);

        $member = Member::create([
            'nik'               => '3201010101010094',
            'member_number'     => 'ANG-RESIGN-03',
            'name'              => 'Hendro Resign',
            'status'            => 'active',
            'has_buku_biru'     => true,
            'has_buku_putih'    => true,
            'principal_savings' => 1000000.00,
            'mandatory_savings' => 4000000.00,
            'voluntary_savings' => 1000000.00,
            'daily_savings'     => 500000.00,
        ]);

        $manualKK = 'KK 0884';
        $res = $this->postJson("/api/members/{$member->id}/resign-total", [
            'voucher_no' => $manualKK,
        ]);

        $res->assertStatus(200);
        $res->assertJson([
            'success' => true,
            'data'    => [
                'total_saham'    => 6000000.00,
                'daily_savings'  => 500000.00,
                'total_simpanan' => 6500000.00,
                'penalty'        => 150000.00,
                'net_refund'     => 6350000.00,
                'voucher_no'     => $manualKK,
                'status'         => 'inactive',
            ]
        ]);

        $member->refresh();
        $this->assertEquals('inactive', $member->status);
        $this->assertFalse((bool) $member->has_buku_biru);
        $this->assertFalse((bool) $member->has_buku_putih);
        $this->assertEquals(0, $member->principal_savings);
        $this->assertEquals(0, $member->mandatory_savings);
        $this->assertEquals(0, $member->voluntary_savings);
        $this->assertEquals(0, $member->daily_savings);

        // Verifikasi Jurnal Double Entry
        $journal = JournalEntry::where('voucher_number', $manualKK)->with('details.account')->first();
        $this->assertNotNull($journal);

        // DEBET 2020 (Saham) = 6.000.000
        $debitSaham = $journal->details->firstWhere('account.account_code', '2020');
        $this->assertNotNull($debitSaham);
        $this->assertEquals(6000000.00, (float) $debitSaham->debit);

        // DEBET 2021 (Harian) = 500.000
        $debitHarian = $journal->details->firstWhere('account.account_code', '2021');
        $this->assertNotNull($debitHarian);
        $this->assertEquals(500000.00, (float) $debitHarian->debit);

        // KREDIT 4183 (Penalti) = 150.000
        $creditPenalti = $journal->details->firstWhere('account.account_code', '4183');
        $this->assertNotNull($creditPenalti);
        $this->assertEquals(150000.00, (float) $creditPenalti->credit);

        // KREDIT 1000 (Kas) = 6.350.000
        $creditKas = $journal->details->firstWhere('account.account_code', '1000');
        $this->assertNotNull($creditKas);
        $this->assertEquals(6350000.00, (float) $creditKas->credit);
    }

    /**
     * 5. Test Validasi Duplikasi Nomor Bukti KK pada Tutup Buku Putih & Resign Total
     */
    public function test_rejects_duplicate_voucher_on_close_white_book_and_resign(): void
    {
        Sanctum::actingAs($this->manager);

        $member1 = Member::create([
            'nik'               => '3201010101010095',
            'member_number'     => 'ANG-DUP-01',
            'name'              => 'Member 1',
            'status'            => 'active',
            'daily_savings'     => 1000000.00,
        ]);

        $member2 = Member::create([
            'nik'               => '3201010101010096',
            'member_number'     => 'ANG-DUP-02',
            'name'              => 'Member 2',
            'status'            => 'active',
            'daily_savings'     => 1000000.00,
        ]);

        $voucher = 'KK 9911';

        // Penutupan pertama sukses
        $res1 = $this->postJson("/api/members/{$member1->id}/close-white-book", [
            'voucher_no' => $voucher,
        ]);
        $res1->assertStatus(200);

        // Penutupan kedua dengan nomor bukti sama ditolak (422)
        $res2 = $this->postJson("/api/members/{$member2->id}/close-white-book", [
            'voucher_no' => $voucher,
        ]);
        $res2->assertStatus(422);
        $this->assertEquals(
            'Maaf, Transaksi KK ini sudah ada coba lagi dengan no berbeda',
            $res2->json('message')
        );
    }

    /**
     * 6. Test Deteksi Tunggakan SW >= 6 Bulan pada Resign Total
     */
    public function test_resign_total_detects_sw_arrears_ge_6_months(): void
    {
        Sanctum::actingAs($this->manager);

        $member = Member::create([
            'nik'               => '3201010101010097',
            'member_number'     => 'ANG-ARREAR-01',
            'name'              => 'Anggota Nunggak SW',
            'status'            => 'active',
            'has_buku_biru'     => true,
            'principal_savings' => 500000.00,
            'mandatory_savings' => 20000.00,
            'voluntary_savings' => 0.00,
            'daily_savings'     => 0.00,
            'created_at'        => Carbon::now()->subMonths(8),
        ]);

        $previewRes = $this->getJson("/api/members/{$member->id}/resign-total/preview");
        $previewRes->assertStatus(200);
        $this->assertTrue($previewRes->json('data.is_sw_arrears_6_months'));
        $this->assertFalse($previewRes->json('data.eligible_for_shu'));
        $this->assertStringContainsString('Tunggakan SW ≥ 6 bulan', $previewRes->json('data.sw_arrears_warning'));
    }
}

