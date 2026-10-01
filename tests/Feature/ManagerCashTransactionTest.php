<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\ChartOfAccount;
use App\Models\Coa;
use App\Models\JournalDetail;
use App\Models\JournalEntry;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ManagerCashTransactionTest extends TestCase
{
    use RefreshDatabase;

    protected User $manager;
    protected ChartOfAccount $cashCoa;
    protected ChartOfAccount $expenseCoa;
    protected ChartOfAccount $incomeCoa;
    protected Account $kasAccount;

    protected function setUp(): void
    {
        parent::setUp();

        $this->manager = User::create([
            'name'     => 'Manager Keuangan',
            'email'    => 'manager.cash@koperasi.com',
            'password' => bcrypt('password123'),
            'role'     => 'manager',
            'nik'      => '1122334455667700',
        ]);

        $this->kasAccount = Account::create([
            'account_number' => 'KAS-101',
            'account_name'   => 'Kas Koperasi',
            'account_type'   => 'kas',
            'category'       => 'asset',
            'balance'        => 5000000.00,
        ]);

        $this->cashCoa = ChartOfAccount::create([
            'account_code'   => '1000',
            'account_name'   => 'Kas Tunai',
            'account_type'   => 'ASSET',
            'normal_balance' => 'DEBIT',
            'is_active'      => true,
        ]);

        $this->expenseCoa = ChartOfAccount::create([
            'account_code'   => '7100',
            'account_name'   => 'Beban ATK & Cetak',
            'account_type'   => 'EXPENSE',
            'normal_balance' => 'DEBIT',
            'is_active'      => true,
        ]);

        $this->incomeCoa = ChartOfAccount::create([
            'account_code'   => '4195',
            'account_name'   => 'Pendapatan Lain-lain',
            'account_type'   => 'REVENUE',
            'normal_balance' => 'CREDIT',
            'is_active'      => true,
        ]);
    }

    public function test_manager_can_store_expense_with_manual_voucher_no_and_appears_in_reports(): void
    {
        Sanctum::actingAs($this->manager);

        $dateStr = '2026-09-11';
        $manualVoucher = 'KK 0120';

        // 1. Validasi gagal jika voucher_no tidak diisi
        $failRes = $this->postJson('/api/manager/transactions/expense', [
            'coa_id'      => $this->expenseCoa->id,
            'amount'      => 350000.00,
            'date'        => $dateStr,
            'description' => 'Pembelian Kertas HVS & Tinta Printer',
        ]);
        $failRes->assertStatus(422);
        $failRes->assertJsonValidationErrors(['voucher_no']);

        // 2. Berhasil menyimpan saat voucher_no diisi manual
        $payload = [
            'voucher_no'  => $manualVoucher,
            'coa_id'      => $this->expenseCoa->id,
            'amount'      => 350000.00,
            'date'        => $dateStr,
            'description' => 'Pembelian Kertas HVS & Tinta Printer',
        ];

        $response = $this->postJson('/api/manager/transactions/expense', $payload);

        $response->assertStatus(201);
        $response->assertJson([
            'success' => true,
        ]);
        $this->assertStringContainsString($manualVoucher, $response->json('message'));

        // 3. Verifikasi Transaksi Kasir
        $trx = Transaction::where('type', 'KK')->first();
        $this->assertNotNull($trx);
        $this->assertEquals($manualVoucher, $trx->transaction_number);
        $this->assertEquals($manualVoucher, $trx->receipt_number);
        $this->assertEquals(350000.00, (float) $trx->amount);
        $this->assertEquals('approved', $trx->status);
        $this->assertEquals('2026-09-11', $trx->transaction_date->toDateString());
        $this->assertEquals('Pembelian Kertas HVS & Tinta Printer', $trx->description);

        // 4. Verifikasi Saldo Kas Fisik Berkurang (5.000.000 - 350.000 = 4.650.000)
        $this->kasAccount->refresh();
        $this->assertEquals(4650000.00, (float) $this->kasAccount->balance);

        // 5. Verifikasi Double-Entry Jurnal Umum & Buku Besar
        $journal = JournalEntry::where('voucher_number', $manualVoucher)->first();
        $this->assertNotNull($journal);
        $this->assertEquals($trx->id, $journal->transaction_id);

        $details = $journal->details;
        $this->assertCount(2, $details);

        // DEBET Beban ATK (7100) = 350.000
        $debitRow = $details->where('account_id', $this->expenseCoa->id)->first();
        $this->assertNotNull($debitRow);
        $this->assertEquals(350000.00, (float) $debitRow->debit);
        $this->assertEquals(0.00, (float) $debitRow->credit);

        // KREDIT Kas Tunai (1000) = 350.000
        $creditRow = $details->where('account_id', $this->cashCoa->id)->first();
        $this->assertNotNull($creditRow);
        $this->assertEquals(0.00, (float) $creditRow->debit);
        $this->assertEquals(350000.00, (float) $creditRow->credit);

        // 6. Verifikasi Buku Besar (Ledger) menampilkan persis 'KK 0120'
        $ledgerRes = $this->getJson('/api/ledger?account_code=7100&start_date=2026-09-01&end_date=2026-09-30');
        $ledgerRes->assertStatus(200);
        $ledgerEntries = $ledgerRes->json('data.entries');
        $this->assertNotEmpty($ledgerEntries);
        $ledgerRow = collect($ledgerEntries)->firstWhere('voucher_number', $manualVoucher);
        $this->assertNotNull($ledgerRow, 'Buku Besar wajib menampilkan nomor bukti persis KK 0120');

        // 7. Verifikasi Jurnal Tabelaris 29 Kolom menampilkan persis 'KK 0120'
        $tabRes = $this->getJson('/api/tabelaris?start_date=2026-09-11&end_date=2026-09-11');
        $tabRes->assertStatus(200);
        $rows = $tabRes->json('data.rows');
        $this->assertNotEmpty($rows);
        $row = collect($rows)->firstWhere('no_bukti', $manualVoucher);
        $this->assertNotNull($row, 'Jurnal Tabelaris wajib menampilkan no_bukti persis KK 0120');
        $this->assertEquals(350000.00, (float) $row['kas_kredit']);
    }

    public function test_manager_can_store_income_with_manual_voucher_no_and_appears_in_reports(): void
    {
        Sanctum::actingAs($this->manager);

        $dateStr = '2026-09-11';
        $manualVoucher = 'KM 3150';

        // 1. Validasi gagal jika voucher_no tidak diisi
        $failRes = $this->postJson('/api/manager/transactions/income', [
            'coa_id'      => $this->incomeCoa->id,
            'amount'      => 800000.00,
            'date'        => $dateStr,
            'description' => 'Pendapatan Penjualan Buku & Merchandise',
        ]);
        $failRes->assertStatus(422);
        $failRes->assertJsonValidationErrors(['voucher_no']);

        // 2. Berhasil menyimpan saat voucher_no diisi manual
        $payload = [
            'voucher_no'  => $manualVoucher,
            'coa_id'      => $this->incomeCoa->id,
            'amount'      => 800000.00,
            'date'        => $dateStr,
            'description' => 'Pendapatan Penjualan Buku & Merchandise',
        ];

        $response = $this->postJson('/api/manager/transactions/income', $payload);

        $response->assertStatus(201);
        $response->assertJson([
            'success' => true,
        ]);
        $this->assertStringContainsString($manualVoucher, $response->json('message'));

        // 3. Verifikasi Transaksi Kasir
        $trx = Transaction::where('type', 'KM')->first();
        $this->assertNotNull($trx);
        $this->assertEquals($manualVoucher, $trx->transaction_number);
        $this->assertEquals($manualVoucher, $trx->receipt_number);
        $this->assertEquals(800000.00, (float) $trx->amount);
        $this->assertEquals('approved', $trx->status);
        $this->assertEquals('Pendapatan Penjualan Buku & Merchandise', $trx->description);

        // 4. Verifikasi Saldo Kas Fisik Bertambah (5.000.000 + 800.000 = 5.800.000)
        $this->kasAccount->refresh();
        $this->assertEquals(5800000.00, (float) $this->kasAccount->balance);

        // 5. Verifikasi Double-Entry Jurnal Umum & Buku Besar
        $journal = JournalEntry::where('voucher_number', $manualVoucher)->first();
        $this->assertNotNull($journal);
        $this->assertEquals($trx->id, $journal->transaction_id);

        $details = $journal->details;
        $this->assertCount(2, $details);

        // DEBET Kas Tunai (1000) = 800.000
        $debitRow = $details->where('account_id', $this->cashCoa->id)->first();
        $this->assertNotNull($debitRow);
        $this->assertEquals(800000.00, (float) $debitRow->debit);
        $this->assertEquals(0.00, (float) $debitRow->credit);

        // KREDIT Pendapatan Lain-lain (4195) = 800.000
        $creditRow = $details->where('account_id', $this->incomeCoa->id)->first();
        $this->assertNotNull($creditRow);
        $this->assertEquals(0.00, (float) $creditRow->debit);
        $this->assertEquals(800000.00, (float) $creditRow->credit);

        // 6. Verifikasi Buku Besar (Ledger) menampilkan persis 'KM 3150'
        $ledgerRes = $this->getJson('/api/ledger?account_code=4195&start_date=2026-09-01&end_date=2026-09-30');
        $ledgerRes->assertStatus(200);
        $ledgerEntries = $ledgerRes->json('data.entries');
        $this->assertNotEmpty($ledgerEntries);
        $ledgerRow = collect($ledgerEntries)->firstWhere('voucher_number', $manualVoucher);
        $this->assertNotNull($ledgerRow, 'Buku Besar wajib menampilkan nomor bukti persis KM 3150');

        // 7. Verifikasi Jurnal Tabelaris 29 Kolom menampilkan persis 'KM 3150'
        $tabRes = $this->getJson('/api/tabelaris?start_date=2026-09-11&end_date=2026-09-11');
        $tabRes->assertStatus(200);
        $rows = $tabRes->json('data.rows');
        $this->assertNotEmpty($rows);
        $row = collect($rows)->firstWhere('no_bukti', $manualVoucher);
        $this->assertNotNull($row, 'Jurnal Tabelaris wajib menampilkan no_bukti persis KM 3150');
        $this->assertEquals(800000.00, (float) $row['kas_debet']);
    }

    public function test_expense_rejects_duplicate_voucher_no_with_custom_message(): void
    {
        Sanctum::actingAs($this->manager);

        $voucher = 'KK 9999';

        // Simpan transaksi pertama
        $res1 = $this->postJson('/api/manager/transactions/expense', [
            'voucher_no'  => $voucher,
            'coa_id'      => $this->expenseCoa->id,
            'amount'      => 100000.00,
            'date'        => '2026-09-11',
            'description' => 'Biaya Pertama',
        ]);
        $res1->assertStatus(201);

        // Percobaan kedua dengan nomor bukti yang sama persis
        $res2 = $this->postJson('/api/manager/transactions/expense', [
            'voucher_no'  => $voucher,
            'coa_id'      => $this->expenseCoa->id,
            'amount'      => 200000.00,
            'date'        => '2026-09-11',
            'description' => 'Biaya Kedua Duplikat',
        ]);

        $res2->assertStatus(422);
        $res2->assertJsonValidationErrors(['voucher_no']);
        $this->assertEquals(
            'Maaf, Transaksi KK ini sudah ada coba lagi dengan no berbeda',
            $res2->json('errors.voucher_no.0')
        );
    }

    public function test_income_rejects_duplicate_voucher_no_with_custom_message(): void
    {
        Sanctum::actingAs($this->manager);

        $voucher = 'KM 8888';

        // Simpan transaksi pertama
        $res1 = $this->postJson('/api/manager/transactions/income', [
            'voucher_no'  => $voucher,
            'coa_id'      => $this->incomeCoa->id,
            'amount'      => 150000.00,
            'date'        => '2026-09-11',
            'description' => 'Pemasukan Pertama',
        ]);
        $res1->assertStatus(201);

        // Percobaan kedua dengan nomor bukti yang sama persis
        $res2 = $this->postJson('/api/manager/transactions/income', [
            'voucher_no'  => $voucher,
            'coa_id'      => $this->incomeCoa->id,
            'amount'      => 250000.00,
            'date'        => '2026-09-11',
            'description' => 'Pemasukan Kedua Duplikat',
        ]);

        $res2->assertStatus(422);
        $res2->assertJsonValidationErrors(['voucher_no']);
        $this->assertEquals(
            'Maaf, Transaksi KM ini sudah ada coba lagi dengan no berbeda',
            $res2->json('errors.voucher_no.0')
        );
    }

    public function test_manager_transactions_handles_field_aliases_and_rejects_duplicate(): void
    {
        Sanctum::actingAs($this->manager);

        $voucher = 'KM 4789';

        // Simpan transaksi pertama menggunakan field transaction_number
        $res1 = $this->postJson('/api/manager/transactions/income', [
            'transaction_number' => $voucher,
            'coa_id'             => $this->incomeCoa->id,
            'amount'             => 175000.00,
            'date'               => '2026-09-11',
            'description'        => 'Pemasukan Awal',
        ]);
        $res1->assertStatus(201);

        // Percobaan kedua mengirim field transaction_number yang sama
        $res2 = $this->postJson('/api/manager/transactions/income', [
            'transaction_number' => $voucher,
            'coa_id'             => $this->incomeCoa->id,
            'amount'             => 275000.00,
            'date'               => '2026-09-11',
            'description'        => 'Pemasukan Duplikat',
        ]);

        $res2->assertStatus(422);
        $this->assertEquals(
            'Maaf, Transaksi KM ini sudah ada coba lagi dengan no berbeda',
            $res2->json('message')
        );
    }

    public function test_adjust_balance_endpoint_rejects_duplicate_voucher(): void
    {
        Sanctum::actingAs($this->manager);

        $voucherKK = 'KK 7777';

        // 1. Simpan pengeluaran via adjust-balance
        $res1 = $this->postJson('/api/manager/transactions/adjust-balance', [
            'voucher_no'   => $voucherKK,
            'type'         => 'expense',
            'coa_id'       => $this->expenseCoa->id,
            'amount'       => 120000.00,
            'date'         => '2026-09-11',
            'description'  => 'Biaya Listrik',
        ]);
        $res1->assertStatus(201);

        // 2. Percobaan kedua dengan nomor bukti sama
        $res2 = $this->postJson('/api/manager/transactions/adjust-balance', [
            'transaction_number' => $voucherKK,
            'type'               => 'expense',
            'coa_id'             => $this->expenseCoa->id,
            'amount'             => 120000.00,
            'date'               => '2026-09-11',
            'description'        => 'Biaya Listrik Duplikat',
        ]);

        $res2->assertStatus(422);
        $this->assertEquals(
            'Maaf, Transaksi KK ini sudah ada coba lagi dengan no berbeda',
            $res2->json('message')
        );
    }
}


