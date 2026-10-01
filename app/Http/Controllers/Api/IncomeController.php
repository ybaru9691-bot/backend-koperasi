<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Account;
use App\Models\ChartOfAccount;
use App\Models\JournalDetail;
use App\Models\JournalEntry;
use App\Models\Transaction;
use App\Services\PeriodLockService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class IncomeController extends Controller
{
    /**
     * Mengambil daftar transaksi Pendapatan Lain-lain
     * Endpoint: GET /api/incomes & GET /api/transactions/income
     */
    public function index(Request $request): JsonResponse
    {
        try {
            $query = Transaction::where('status', 'approved')
                ->where(function ($q) {
                    $q->where('category', 'pendapatan_lain')
                      ->orWhere('description', 'like', '%Pendapatan Lain%')
                      ->orWhere('description', 'like', '%pendapatan lain%');
                })
                ->with(['account', 'operator', 'journalEntry.details.account']);

            if ($request->filled('start_date') && $request->filled('end_date')) {
                $query->whereBetween('transaction_date', [$request->start_date, $request->end_date]);
            } elseif ($request->filled('month') && $request->filled('year')) {
                $query->whereMonth('transaction_date', $request->month)->whereYear('transaction_date', $request->year);
            }

            $incomes = $query->orderBy('transaction_date', 'desc')
                ->orderBy('id', 'desc')
                ->paginate($request->input('per_page', 20));

            $totalAmount = (float) (clone $query)->sum('amount');

            return response()->json([
                'success' => true,
                'status'  => 'success',
                'data'    => $incomes,
                'summary' => [
                    'total_amount' => $totalAmount,
                ]
            ], 200);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Gagal mengambil data pendapatan lain-lain: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Input Pendapatan Lain-lain / Tambah Kas Masuk (COA 419x)
     * Endpoint: POST /api/incomes, POST /api/transactions/income, POST /api/transactions/other-income
     */
    public function store(Request $request): JsonResponse
    {
        $rawDate = $request->input('transaction_date')
            ?? $request->input('transactionDate')
            ?? $request->input('date')
            ?? $request->input('tanggal')
            ?? $request->input('tanggal_transaksi')
            ?? $request->input('entry_date')
            ?? $request->input('tx_date')
            ?? $request->input('tgl')
            ?? now()->toDateString();
        
        $transactionDate = Carbon::parse(str_replace('/', '-', $rawDate))->toDateString();
        $request->merge(['transaction_date' => $transactionDate]);

        $request->validate([
            'amount'           => 'required|numeric|min:0.01',
            'description'      => 'required|string|max:255',
            'account_code'     => 'nullable|string|max:20',
            'category'         => 'nullable|string|max:100',
            'transaction_date' => 'nullable|date',
            'member_id'        => 'nullable|exists:members,id',
            'payment_method'   => 'nullable|string|in:cash,tunai,transfer,bank',
        ]);

        // Validasi Periode Terkunci
        if ($lockRes = PeriodLockService::validateDate($transactionDate)) {
            return $lockRes;
        }

        try {
            DB::beginTransaction();

            $amount = (float) $request->input('amount');
            $rawDesc = trim($request->input('description'));
            $fullDesc = str_starts_with($rawDesc, 'Penerimaan Pendapatan Lain-lain')
                ? $rawDesc
                : 'Penerimaan Pendapatan Lain-lain: ' . $rawDesc;

            $accountCode = $request->input('account_code', '4195'); // Default COA 4195 (Pendapatan Lain-lain) / 4191
            $category = $request->input('category', 'pendapatan_lain');
            $paymentMethod = $request->input('payment_method', 'cash');
            if (in_array($paymentMethod, ['tunai'])) $paymentMethod = 'cash';
            if (in_array($paymentMethod, ['bank']))  $paymentMethod = 'transfer';

            $authUser = $request->user();
            $userId = $authUser ? $authUser->id : null;

            // 1. Ambil Master Akun Kas & Tambah Saldo
            $kasAccount = Account::where('account_type', 'kas')->first();
            if (!$kasAccount) {
                $kasAccount = Account::firstOrCreate(
                    ['account_number' => 'KAS-101'],
                    [
                        'account_name' => 'Kas Koperasi',
                        'account_type' => 'kas',
                        'category'     => 'asset',
                        'balance'      => 0.00,
                    ]
                );
            }

            $kasAccount->increment('balance', $amount);

            // 2. Buat Nomor Transaksi KM
            $dateStr = Carbon::parse($transactionDate)->format('Ymd');
            $trxNumber = 'KM-' . $dateStr . '-' . rand(1000, 9999);
            while (Transaction::where('transaction_number', $trxNumber)->exists()) {
                $trxNumber = 'KM-' . $dateStr . '-' . rand(1000, 9999);
            }

            // 3. Simpan Baris Transaksi
            $transaction = Transaction::create([
                'transaction_number' => $trxNumber,
                'receipt_number'     => $request->input('receipt_number') ?? $trxNumber,
                'account_id'         => $kasAccount->id,
                'member_id'          => $request->input('member_id') ?? null,
                'operator_id'        => $userId,
                'approved_by'        => $userId,
                'type'               => 'deposit',
                'category'           => $category,
                'amount'             => $amount,
                'beginning_balance'  => (float) $kasAccount->balance - $amount,
                'ending_balance'     => (float) $kasAccount->balance,
                'payment_method'     => $paymentMethod,
                'transaction_date'   => $transactionDate,
                'description'        => $fullDesc,
                'status'             => 'approved',
                'approved_at'        => now(),
            ]);

            // 4. Sinkronisasi ke Buku Besar & Jurnal Double Entry (Debit: Kas 1000, Kredit: Pendapatan Lain 419x)
            $coaKas = ChartOfAccount::where('account_code', '1000')->first();
            $coaIncome = ChartOfAccount::where('account_code', $accountCode)->first();
            if (!$coaIncome) {
                $coaIncome = ChartOfAccount::where('account_type', 'REVENUE')
                    ->where('account_code', 'like', '419%')
                    ->first()
                    ?? ChartOfAccount::where('account_type', 'REVENUE')->first();
            }

            $journal = null;
            if ($coaKas && $coaIncome) {
                $journal = JournalEntry::create([
                    'transaction_id' => $transaction->id,
                    'entry_date'     => $transactionDate,
                    'voucher_number' => $trxNumber,
                    'description'    => $fullDesc,
                    'created_by'     => $userId,
                ]);

                // DEBIT Kas (1000)
                JournalDetail::create([
                    'journal_entry_id' => $journal->id,
                    'account_id'       => $coaKas->id,
                    'debit'            => $amount,
                    'credit'           => 0,
                    'description'      => $fullDesc,
                ]);

                // KREDIT Akun Pendapatan Lain-lain (419x)
                JournalDetail::create([
                    'journal_entry_id' => $journal->id,
                    'account_id'       => $coaIncome->id,
                    'debit'            => 0,
                    'credit'           => $amount,
                    'description'      => $fullDesc,
                ]);
            }

            DB::commit();

            return response()->json([
                'success' => true,
                'status'  => 'success',
                'message' => 'Pendapatan Lain-lain berhasil dicatat, saldo kas bertambah, dan transaksi Kas Masuk telah disinkronkan.',
                'data'    => [
                    'transaction' => $transaction,
                    'kas_account' => [
                        'id'          => $kasAccount->id,
                        'name'        => $kasAccount->account_name,
                        'new_balance' => (float) $kasAccount->balance,
                    ],
                    'coa_account' => $coaIncome ? [
                        'account_code' => $coaIncome->account_code,
                        'account_name' => $coaIncome->account_name,
                    ] : null,
                    'journal_id'  => $journal ? $journal->id : null,
                ]
            ], 201);

        } catch (\Illuminate\Database\QueryException $e) {
            DB::rollBack();
            Log::error('[IncomeController] Database error: ' . $e->getMessage());
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
                'message' => 'Gagal mencatat pendapatan lain-lain: ' . $e->getMessage()
            ], 500);
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('[IncomeController] Gagal mencatat pendapatan: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Gagal mencatat pendapatan lain-lain: ' . $e->getMessage()
            ], 500);
        }
    }
}