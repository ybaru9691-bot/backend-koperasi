<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Cache;
use App\Models\Transaction;
use App\Models\JournalEntry;
use App\Models\JournalDetail;
use App\Models\ChartOfAccount;
use App\Models\Coa;
use App\Models\Account;
use Carbon\Carbon;

class ManagerCashTransactionController extends Controller
{
    /**
     * Catat Beban / Pengeluaran Kas (KK) dari Dashboard Manajer
     * Endpoint: POST /api/manager/transactions/expense
     */
    public function storeExpense(Request $request)
    {
        $rawVoucher = $request->input('voucher_no')
            ?? $request->input('transaction_number')
            ?? $request->input('voucher_number')
            ?? $request->input('receipt_number')
            ?? $request->input('no_bukti');

        if ($rawVoucher !== null) {
            $rawVoucher = trim((string) $rawVoucher);
            $request->merge([
                'voucher_no'         => $rawVoucher,
                'transaction_number' => $rawVoucher,
                'voucher_number'     => $rawVoucher,
                'receipt_number'     => $rawVoucher,
            ]);
        }

        $request->validate([
            'voucher_no'  => [
                'required',
                'string',
                'max:50',
                'unique:transactions,transaction_number',
                'unique:transactions,receipt_number',
                'unique:journal_entries,voucher_number',
            ],
            'coa_id'      => 'nullable',
            'account_id'  => 'nullable',
            'account_code'=> 'nullable|string',
            'amount'      => 'required|numeric|min:0.01',
            'date'        => 'nullable|date',
            'transaction_date' => 'nullable|date',
            'description' => 'required|string|max:255',
        ], [
            'voucher_no.unique' => 'Maaf, Transaksi KK ini sudah ada coba lagi dengan no berbeda',
        ]);

        $voucherNo = trim($request->voucher_no ?? $request->transaction_number ?? $request->voucher_number ?? $request->receipt_number);

        // Cek duplikasi nomor bukti jika sudah ada (defensive check)
        if (Transaction::where('transaction_number', $voucherNo)->orWhere('receipt_number', $voucherNo)->exists() || JournalEntry::where('voucher_number', $voucherNo)->exists()) {
            return response()->json([
                'success' => false,
                'message' => 'Maaf, Transaksi KK ini sudah ada coba lagi dengan no berbeda',
                'errors'  => [
                    'voucher_no'         => ['Maaf, Transaksi KK ini sudah ada coba lagi dengan no berbeda'],
                    'transaction_number' => ['Maaf, Transaksi KK ini sudah ada coba lagi dengan no berbeda'],
                ],
            ], 422);
        }

        $rawDate = $request->input('date')
            ?? $request->input('transaction_date')
            ?? $request->input('transactionDate')
            ?? $request->input('tanggal')
            ?? $request->input('tanggal_transaksi')
            ?? $request->input('entry_date')
            ?? $request->input('tx_date')
            ?? $request->input('tgl')
            ?? now()->toDateString();
        $date = Carbon::parse(str_replace('/', '-', (string)$rawDate))->format('Y-m-d');

        // Validasi Periode Terkunci (Global Lock Validation)
        if (class_exists(\App\Services\PeriodLockService::class)) {
            if ($lockRes = \App\Services\PeriodLockService::validateDate($date)) {
                return $lockRes;
            }
        }

        // Cari COA Beban Terpilih (Mendukung ID integer maupun string account_code)
        $selectedCoa = null;
        if ($request->filled('coa_id')) {
            $val = $request->coa_id;
            $selectedCoa = ChartOfAccount::find($val)
                ?? ChartOfAccount::where('account_code', (string) $val)->first();
        } elseif ($request->filled('account_id')) {
            $val = $request->account_id;
            $selectedCoa = ChartOfAccount::find($val)
                ?? ChartOfAccount::where('account_code', (string) $val)->first();
        } elseif ($request->filled('account_code')) {
            $val = $request->account_code;
            $selectedCoa = ChartOfAccount::where('account_code', (string) $val)->first()
                ?? ChartOfAccount::find($val);
        }

        if (!$selectedCoa) {
            return response()->json([
                'success' => false,
                'message' => 'Akun perkiraan (COA) beban tidak ditemukan.',
            ], 422);
        }

        // Cari Akun Kas (1000 Kas Tunai / 1001 Kas Kasir)
        $cashCoa = ChartOfAccount::where('account_code', '1000')
            ->orWhere('account_code', '1001')
            ->first();

        if (!$cashCoa) {
            $cashCoa = ChartOfAccount::firstOrCreate(
                ['account_code' => '1000'],
                [
                    'account_name'   => 'Kas Tunai',
                    'account_type'   => 'ASSET',
                    'normal_balance' => 'DEBIT',
                    'is_active'      => true,
                ]
            );
        }

        $amount = (float) $request->amount;
        $userId = auth()->id();
        $desc   = trim($request->description);

        try {
            DB::beginTransaction();

            // Update Master Akun Kas di tabel accounts
            $kasMaster = Account::where('account_type', 'kas')->first();
            if (!$kasMaster) {
                $kasMaster = Account::firstOrCreate(
                    ['account_number' => 'KAS-101'],
                    [
                        'account_name' => 'Kas Koperasi',
                        'account_type' => 'kas',
                        'category'     => 'asset',
                        'balance'      => 0.00,
                    ]
                );
            }

            $beginningBalance = (float) $kasMaster->balance;
            $kasMaster->decrement('balance', $amount);
            $endingBalance = (float) $kasMaster->fresh()->balance;

            // 1. Simpan Transaksi Kasir (Tabelaris & Dashboard)
            $transaction = Transaction::create([
                'transaction_number' => $voucherNo,
                'receipt_number'     => $voucherNo,
                'type'               => 'KK',
                'category'           => 'beban_operasional',
                'account_id'         => $kasMaster->id,
                'member_id'          => null,
                'operator_id'        => $userId,
                'approved_by'        => $userId,
                'amount'             => $amount,
                'beginning_balance'  => $beginningBalance,
                'ending_balance'     => $endingBalance,
                'payment_method'     => 'cash',
                'transaction_date'   => $date,
                'description'        => $desc,
                'status'             => 'approved',
                'approved_at'        => now(),
            ]);

            // Hapus jika ada jurnal otomatis yang sempat dibuat
            JournalEntry::where('transaction_id', $transaction->id)->delete();

            // 2. Double-Entry Jurnal Umum & Buku Besar
            $journal = JournalEntry::create([
                'transaction_id' => $transaction->id,
                'entry_date'     => $date,
                'voucher_number' => $voucherNo,
                'description'    => $desc,
                'created_by'     => $userId,
            ]);

            // DEBET: Akun Beban (Wajib pakai ID akun yang dipilih user dari dropdown)
            JournalDetail::create([
                'journal_entry_id' => $journal->id,
                'account_id'       => $selectedCoa->id,
                'debit'            => $amount,
                'credit'           => 0,
                'description'      => $desc,
            ]);

            // KREDIT: Akun Kas (1000 - Kas Berkurang)
            JournalDetail::create([
                'journal_entry_id' => $journal->id,
                'account_id'       => $cashCoa->id,
                'debit'            => 0,
                'credit'           => $amount,
                'description'      => $desc,
            ]);

            DB::commit();

            Cache::forget('manager_dashboard_summary_data');
            Cache::forget('dashboard_summary_data');

            return response()->json([
                'success' => true,
                'message' => 'Pengeluaran kas berhasil dibukukan dengan nomor ' . $voucherNo,
                'data'    => $transaction->fresh(['account', 'journalEntry.details.account']),
            ], 201);
        } catch (\Illuminate\Database\QueryException $e) {
            DB::rollBack();
            if ($e->getCode() === '23000' || ($e->errorInfo[1] ?? 0) === 1062 || str_contains($e->getMessage(), '1062')) {
                return response()->json([
                    'success' => false,
                    'message' => 'Maaf, Transaksi KK ini sudah ada coba lagi dengan no berbeda',
                    'errors'  => [
                        'voucher_no'         => ['Maaf, Transaksi KK ini sudah ada coba lagi dengan no berbeda'],
                        'transaction_number' => ['Maaf, Transaksi KK ini sudah ada coba lagi dengan no berbeda'],
                    ],
                ], 422);
            }

            return response()->json([
                'success' => false,
                'message' => 'Gagal membukukan pengeluaran kas: ' . $e->getMessage(),
            ], 500);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'success' => false,
                'message' => 'Gagal membukukan pengeluaran kas: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Catat Tambah Saldo / Pemasukan Kas (KM) dari Dashboard Manajer
     * Endpoint: POST /api/manager/transactions/income
     */
    public function storeIncome(Request $request)
    {
        $rawVoucher = $request->input('voucher_no')
            ?? $request->input('transaction_number')
            ?? $request->input('voucher_number')
            ?? $request->input('receipt_number')
            ?? $request->input('no_bukti');

        if ($rawVoucher !== null) {
            $rawVoucher = trim((string) $rawVoucher);
            $request->merge([
                'voucher_no'         => $rawVoucher,
                'transaction_number' => $rawVoucher,
                'voucher_number'     => $rawVoucher,
                'receipt_number'     => $rawVoucher,
            ]);
        }

        $request->validate([
            'voucher_no'  => [
                'required',
                'string',
                'max:50',
                'unique:transactions,transaction_number',
                'unique:transactions,receipt_number',
                'unique:journal_entries,voucher_number',
            ],
            'coa_id'      => 'nullable',
            'account_id'  => 'nullable',
            'account_code'=> 'nullable|string',
            'amount'      => 'required|numeric|min:0.01',
            'date'        => 'nullable|date',
            'transaction_date' => 'nullable|date',
            'description' => 'required|string|max:255',
        ], [
            'voucher_no.unique' => 'Maaf, Transaksi KM ini sudah ada coba lagi dengan no berbeda',
        ]);

        $voucherNo = trim($request->voucher_no ?? $request->transaction_number ?? $request->voucher_number ?? $request->receipt_number);

        // Cek duplikasi nomor bukti jika sudah ada (defensive check)
        if (Transaction::where('transaction_number', $voucherNo)->orWhere('receipt_number', $voucherNo)->exists() || JournalEntry::where('voucher_number', $voucherNo)->exists()) {
            return response()->json([
                'success' => false,
                'message' => 'Maaf, Transaksi KM ini sudah ada coba lagi dengan no berbeda',
                'errors'  => [
                    'voucher_no'         => ['Maaf, Transaksi KM ini sudah ada coba lagi dengan no berbeda'],
                    'transaction_number' => ['Maaf, Transaksi KM ini sudah ada coba lagi dengan no berbeda'],
                ],
            ], 422);
        }

        $rawDate = $request->input('date')
            ?? $request->input('transaction_date')
            ?? $request->input('transactionDate')
            ?? $request->input('tanggal')
            ?? $request->input('tanggal_transaksi')
            ?? $request->input('entry_date')
            ?? $request->input('tx_date')
            ?? $request->input('tgl')
            ?? now()->toDateString();
        $date = Carbon::parse(str_replace('/', '-', (string)$rawDate))->format('Y-m-d');

        // Validasi Periode Terkunci (Global Lock Validation)
        if (class_exists(\App\Services\PeriodLockService::class)) {
            if ($lockRes = \App\Services\PeriodLockService::validateDate($date)) {
                return $lockRes;
            }
        }

        // Cari COA Pendapatan / Sumber Terpilih (Mendukung ID integer maupun string account_code)
        $selectedCoa = null;
        if ($request->filled('coa_id')) {
            $val = $request->coa_id;
            $selectedCoa = ChartOfAccount::find($val)
                ?? ChartOfAccount::where('account_code', (string) $val)->first();
        } elseif ($request->filled('account_id')) {
            $val = $request->account_id;
            $selectedCoa = ChartOfAccount::find($val)
                ?? ChartOfAccount::where('account_code', (string) $val)->first();
        } elseif ($request->filled('account_code')) {
            $val = $request->account_code;
            $selectedCoa = ChartOfAccount::where('account_code', (string) $val)->first()
                ?? ChartOfAccount::find($val);
        }

        if (!$selectedCoa) {
            return response()->json([
                'success' => false,
                'message' => 'Akun perkiraan (COA) pemasukan tidak ditemukan.',
            ], 422);
        }

        // Cari Akun Kas (1000 Kas Tunai / 1001 Kas Kasir)
        $cashCoa = ChartOfAccount::where('account_code', '1000')
            ->orWhere('account_code', '1001')
            ->first();

        if (!$cashCoa) {
            $cashCoa = ChartOfAccount::firstOrCreate(
                ['account_code' => '1000'],
                [
                    'account_name'   => 'Kas Tunai',
                    'account_type'   => 'ASSET',
                    'normal_balance' => 'DEBIT',
                    'is_active'      => true,
                ]
            );
        }

        $amount = (float) $request->amount;
        $userId = auth()->id();
        $desc   = trim($request->description);

        try {
            DB::beginTransaction();

            // Update Master Akun Kas di tabel accounts
            $kasMaster = Account::where('account_type', 'kas')->first();
            if (!$kasMaster) {
                $kasMaster = Account::firstOrCreate(
                    ['account_number' => 'KAS-101'],
                    [
                        'account_name' => 'Kas Koperasi',
                        'account_type' => 'kas',
                        'category'     => 'asset',
                        'balance'      => 0.00,
                    ]
                );
            }

            $beginningBalance = (float) $kasMaster->balance;
            $kasMaster->increment('balance', $amount);
            $endingBalance = (float) $kasMaster->fresh()->balance;

            // 1. Simpan Transaksi Kasir (Tabelaris & Dashboard)
            $transaction = Transaction::create([
                'transaction_number' => $voucherNo,
                'receipt_number'     => $voucherNo,
                'type'               => 'KM',
                'category'           => 'pendapatan_lain',
                'account_id'         => $kasMaster->id,
                'member_id'          => null,
                'operator_id'        => $userId,
                'approved_by'        => $userId,
                'amount'             => $amount,
                'beginning_balance'  => $beginningBalance,
                'ending_balance'     => $endingBalance,
                'payment_method'     => 'cash',
                'transaction_date'   => $date,
                'description'        => $desc,
                'status'             => 'approved',
                'approved_at'        => now(),
            ]);

            // Hapus jika ada jurnal otomatis yang sempat dibuat
            JournalEntry::where('transaction_id', $transaction->id)->delete();

            // 2. Double-Entry Jurnal Umum & Buku Besar
            $journal = JournalEntry::create([
                'transaction_id' => $transaction->id,
                'entry_date'     => $date,
                'voucher_number' => $voucherNo,
                'description'    => $desc,
                'created_by'     => $userId,
            ]);

            // DEBET: Akun Kas (1000 - Kas Bertambah)
            JournalDetail::create([
                'journal_entry_id' => $journal->id,
                'account_id'       => $cashCoa->id,
                'debit'            => $amount,
                'credit'           => 0,
                'description'      => $desc,
            ]);

            // KREDIT: Akun Sumber / Pendapatan Terpilih (Wajib pakai ID akun yang dipilih user)
            JournalDetail::create([
                'journal_entry_id' => $journal->id,
                'account_id'       => $selectedCoa->id,
                'debit'            => 0,
                'credit'           => $amount,
                'description'      => $desc,
            ]);

            DB::commit();

            Cache::forget('manager_dashboard_summary_data');
            Cache::forget('dashboard_summary_data');

            return response()->json([
                'success' => true,
                'message' => 'Pemasukan kas berhasil dibukukan dengan nomor ' . $voucherNo,
                'data'    => $transaction->fresh(['account', 'journalEntry.details.account']),
            ], 201);
        } catch (\Illuminate\Database\QueryException $e) {
            DB::rollBack();
            if ($e->getCode() === '23000' || ($e->errorInfo[1] ?? 0) === 1062 || str_contains($e->getMessage(), '1062')) {
                return response()->json([
                    'success' => false,
                    'message' => 'Maaf, Transaksi KM ini sudah ada coba lagi dengan no berbeda',
                    'errors'  => [
                        'voucher_no'         => ['Maaf, Transaksi KM ini sudah ada coba lagi dengan no berbeda'],
                        'transaction_number' => ['Maaf, Transaksi KM ini sudah ada coba lagi dengan no berbeda'],
                    ],
                ], 422);
            }

            return response()->json([
                'success' => false,
                'message' => 'Gagal membukukan pemasukan kas: ' . $e->getMessage(),
            ], 500);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'success' => false,
                'message' => 'Gagal membukukan pemasukan kas: ' . $e->getMessage(),
            ], 500);
        }
    }
}
