<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Account;
use App\Models\ActivityLog;
use App\Models\Loan;
use App\Models\LoanInstallment;
use App\Models\Member;
use App\Models\Transaction;
use App\Models\ChartOfAccount;
use App\Services\JournalService;
use App\Services\PeriodLockService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Cache;
use Illuminate\Validation\ValidationException;
use Illuminate\Support\Facades\Schema;

class TransactionController extends Controller
{
    /**
     * Ekstrak dan normalisasi tanggal transaksi dari berbagai format payload input
     */
    protected function extractTransactionDate($input, ?string $fallback = null): string
    {
        $raw = null;
        if (is_array($input)) {
            $raw = $input['transaction_date']
                ?? $input['transactionDate']
                ?? $input['date']
                ?? $input['tanggal']
                ?? $input['tanggal_transaksi']
                ?? $input['tanggalTransaksi']
                ?? $input['entry_date']
                ?? $input['entryDate']
                ?? $input['tx_date']
                ?? $input['txDate']
                ?? $input['tgl']
                ?? $input['tgl_transaksi']
                ?? $input['tglTransaksi']
                ?? null;
        } elseif ($input instanceof Request) {
            $raw = $input->input('transaction_date')
                ?? $input->input('transactionDate')
                ?? $input->input('date')
                ?? $input->input('tanggal')
                ?? $input->input('tanggal_transaksi')
                ?? $input->input('tanggalTransaksi')
                ?? $input->input('entry_date')
                ?? $input->input('entryDate')
                ?? $input->input('tx_date')
                ?? $input->input('txDate')
                ?? $input->input('tgl')
                ?? $input->input('tgl_transaksi')
                ?? $input->input('tglTransaksi')
                ?? null;
        } elseif (is_string($input)) {
            $raw = $input;
        }

        if (empty($raw)) {
            return $fallback ?: now()->toDateString();
        }

        $rawStr = trim((string) $raw);

        // Format dd/mm/yyyy atau dd-mm-yyyy (misal 18/10/2026 atau 18-10-2026)
        if (preg_match('/^(\d{1,2})[\/\-](\d{1,2})[\/\-](\d{4})/', $rawStr, $matches)) {
            $day = (int) $matches[1];
            $month = (int) $matches[2];
            $year = (int) $matches[3];
            if ($month <= 12 && $day <= 31) {
                return \Carbon\Carbon::createFromDate($year, $month, $day)->toDateString();
            }
        }

        // Format yyyy-mm-dd atau yyyy/mm/dd (misal 2026-10-18 atau 2026/10/18)
        if (preg_match('/^(\d{4})[\/\-](\d{1,2})[\/\-](\d{1,2})/', $rawStr, $matches)) {
            $year = (int) $matches[1];
            $month = (int) $matches[2];
            $day = (int) $matches[3];
            return \Carbon\Carbon::createFromDate($year, $month, $day)->toDateString();
        }

        try {
            return \Carbon\Carbon::parse($rawStr)->toDateString();
        } catch (\Throwable $e) {
            return $fallback ?: now()->toDateString();
        }
    }

    /**
     * Simpan Transaksi Baru dari Flutter Admin Web (Support Single & Bulk Daily Transactions)
     * Endpoints: POST /api/transactions & POST /api/daily-transactions
     */
    public function store(Request $request): JsonResponse
    {
        try {
            // Log request input untuk debugging
            Log::info('Daily Transaction Input:', $request->all());

            $transactionDate = $this->extractTransactionDate($request);
            $request->merge([
                'transaction_date' => $transactionDate,
            ]);

            // 1. Validasi Input Dasar
            $validated = $request->validate([
                'member_id'        => 'required|exists:members,id',
                'payment_method'   => 'required|in:cash,tunai,bank,transfer',
                'transaction_date' => 'nullable|date',
                'date'             => 'nullable',
                'tanggal'          => 'nullable',
                'tanggal_transaksi'=> 'nullable',
                'entry_date'       => 'nullable',
                'tx_date'          => 'nullable',
                'tgl'              => 'nullable',
                'items'            => 'nullable|array',
                'items.*.amount'   => 'nullable|numeric',
                'items.*.account_code' => 'nullable|string',
                'items.*.transaction_date' => 'nullable',
                'items.*.date'     => 'nullable',
                'items.*.tanggal'  => 'nullable',
                'items.*.tanggal_transaksi' => 'nullable',
            ]);

            $authUser = $request->user();
            $member = Member::find($request->member_id);
            if (!$member) {
                return response()->json([
                    'success' => false,
                    'status'  => 'error',
                    'message' => 'Data anggota tidak ditemukan.'
                ], 404);
            }

            // Guard Clause: Cek Status Anggota (Hanya Anggota AKTIF yang Boleh Menerima Transaksi Baru)
            $memberStatus = strtolower(trim((string) ($member->status ?? '')));
            $inactiveStatuses = ['resigned', 'resigned_total', 'inactive', 'pasif', 'keluar', 'non-aktif', 'non_aktif'];

            if (in_array($memberStatus, $inactiveStatuses) || $memberStatus !== 'active') {
                return response()->json([
                    'success' => false,
                    'status'  => 'error',
                    'message' => 'Anggota telah berstatus non-aktif/keluar, tidak dapat menerima transaksi baru.',
                    'errors'  => [
                        'member_id' => ['Anggota telah berstatus non-aktif/keluar, tidak dapat menerima transaksi baru.']
                    ]
                ], 422);
            }

            $paymentMethod = $request->input('payment_method', 'cash');

            // Validasi Periode Terkunci (Global Lock Validation)
            if ($lockRes = PeriodLockService::validateDate($transactionDate)) {
                return $lockRes;
            }

            DB::beginTransaction();

            $bookType = $request->input('book_type', 'BUKU_BIRU');

            $accountKas = Account::firstOrCreate(
                ['account_number' => 'KAS-101'],
                [
                    'account_name' => 'Kas Koperasi',
                    'account_type' => 'kas',
                    'category'     => 'asset',
                    'balance'      => 0.00,
                ]
            );

            $journalService = app(JournalService::class);
            $savedTransactions = [];

            // A. SKENARIO BULK ITEMS (DARI FORM INPUT TRANSAKSI HARIAN)
            if ($request->has('items') && is_array($request->items) && count($request->items) > 0) {
                $rawReceipt = $request->input('voucher_number') ?? $request->input('receipt_number') ?? $request->input('no_bukti') ?? $request->input('proof_number');
                $receiptNumber = $rawReceipt ? trim(preg_replace('/^(KM|KK)\s+/i', '', $rawReceipt)) : null;

                foreach ($request->items as $index => $item) {
                    $amount = (float) ($item['amount'] ?? 0);
                    if ($amount <= 0) {
                        continue;
                    }

                    $accountCode = $item['account_code'] ?? null;
                    $itemDesc = $item['description'] ?? $item['label'] ?? null;
                    $coa = null;

                    if ($accountCode) {
                        $coa = ChartOfAccount::where('account_code', $accountCode)->first();
                    }

                    // Tentukan jenis transaksi (deposit / withdrawal)
                    // 'kk' dan 'kas_keluar' => withdrawal; 'km' dan 'kas_masuk' => deposit
                    $reqType = strtolower($request->input('type', ''));
                    if (in_array($reqType, ['withdrawal', 'out', 'kas_keluar', 'kk'])) {
                        $type = 'withdrawal';
                    } elseif (in_array($reqType, ['deposit', 'in', 'kas_masuk', 'km'])) {
                        $type = 'deposit';
                    } else {
                        $isWithdrawal = $coa ? in_array(strtoupper($coa->account_type), ['EXPENSE', 'BEBAN']) : false;
                        $type = $isWithdrawal ? 'withdrawal' : 'deposit';
                    }

                    if (empty($itemDesc) || str_starts_with($itemDesc, 'Transaksi Akun')) {
                        $itemDesc = $type === 'deposit' ? 'Setoran Simpanan' : 'Penarikan Simpanan';
                    }

                    // Mapping kolom saldo member
                    $columnToUpdate = null;
                    $descLower = strtolower($itemDesc);
                    $accCode = trim((string) ($accountCode ?? $coa?->account_code ?? ''));

                    // Khusus Pos Bank / Bank BRI (Akun 1010) - JANGAN masuk ke 2020
                    $isBankPos = $accCode === '1010' || (str_contains($descLower, 'bank') && !str_contains($descLower, 'jasa bank') && !str_contains($descLower, 'bunga bank'));
                    if ($isBankPos) {
                        $accCode = '1010';
                    }

                    // Cek apakah transaksi merupakan pos pendapatan operasional, piutang/pinjaman, atau bank (bukan simpanan)
                    $isOperationalRevenueOrLoan = $isBankPos
                        || in_array($accCode, ['4170', '4180', '4181', '4182', '4183', '4184', '4191', '4192', '4193', '4194', '1024', '1010'])
                        || str_starts_with($accCode, '4') || str_starts_with($accCode, '7') || str_starts_with($accCode, '5') || str_starts_with($accCode, '1')
                        || str_contains($descLower, 'pinjaman') || str_contains($descLower, 'piutang') || str_contains($descLower, 'pencairan')
                        || str_contains($descLower, 'provisi') || str_contains($descLower, 'denda') || str_contains($descLower, 'jasa pinjaman') || str_contains($descLower, 'bunga pinjaman')
                        || str_contains($descLower, 'pendapatan') || str_contains($descLower, 'biaya') || str_contains($descLower, 'beban');

                    if ($isOperationalRevenueOrLoan) {
                        $columnToUpdate = null; // Bank, Pendapatan operasional, piutang, & pinjaman TIDAK menambah/mengurangi saldo simpanan anggota
                    } elseif ($accCode === '2021' || $bookType === 'BUKU_PUTIH' || str_contains($descLower, 'harian') || str_contains($descLower, 'buku putih') || str_contains($descLower, 'tabungan')) {
                        $columnToUpdate = 'daily_savings';
                        $bookType = 'BUKU_PUTIH';
                    } elseif ($accCode === '2020') {
                        if (str_contains($descLower, 'pokok')) {
                            $columnToUpdate = 'principal_savings';
                        } elseif (str_contains($descLower, 'wajib')) {
                            $columnToUpdate = 'mandatory_savings';
                        } else {
                            $columnToUpdate = 'voluntary_savings';
                        }
                        $bookType = 'BUKU_BIRU';
                    } elseif (str_contains($descLower, 'pokok')) {
                        $columnToUpdate = 'principal_savings';
                    } elseif (str_contains($descLower, 'wajib')) {
                        $columnToUpdate = 'mandatory_savings';
                    } elseif (str_contains($descLower, 'duka') || $accCode === '2038') {
                        $columnToUpdate = 'grief_fund';
                    } elseif (str_contains($descLower, 'sukarela') || str_contains($descLower, 'simpanan')) {
                        $columnToUpdate = 'voluntary_savings';
                    } else {
                        // Default jika setoran simpanan umum
                        $columnToUpdate = 'voluntary_savings';
                    }

                    // Validasi Penarikan / Withdrawal
                    if ($type === 'withdrawal' && $columnToUpdate) {
                        // Kunci penarikan SP dan SW untuk anggota aktif
                        if (in_array($columnToUpdate, ['principal_savings', 'mandatory_savings'])) {
                            DB::rollBack();
                            return response()->json([
                                'status'  => 'error',
                                'success' => false,
                                'message' => 'Gagal Penarikan: Simpanan Pokok dan Simpanan Wajib tidak dapat ditarik selama masih berstatus Anggota Aktif. Hanya Simpanan Sukarela yang dapat ditarik.'
                            ], 400);
                        }

                        if ($columnToUpdate === 'daily_savings' || $bookType === 'BUKU_PUTIH') {
                            $currentDaily = (float) ($member->daily_savings ?? 0.00);
                            $hasBukuPutih = $member->has_buku_putih || ($currentDaily > 0);
                            if (!$hasBukuPutih) {
                                DB::rollBack();
                                return response()->json([
                                    'status'  => 'error',
                                    'success' => false,
                                    'message' => 'Anggota belum memiliki rekening Buku Putih (Tabungan Harian).'
                                ], 400);
                            }
                            $maxWithdrawal = max(0.00, $currentDaily - 100000.00);
                            if ($amount > $maxWithdrawal || $currentDaily < 100000.00) {
                                DB::rollBack();
                                $formattedCurrent = number_format($currentDaily, 0, ',', '.');
                                return response()->json([
                                    'status'  => 'error',
                                    'success' => false,
                                    'message' => "Gagal Penarikan: Saldo tidak mencukupi. Minimal saldo mengendap di Buku Putih adalah Rp 100.000. Saldo Anda saat ini: Rp {$formattedCurrent}."
                                ], 400);
                            }
                        } elseif ($columnToUpdate === 'voluntary_savings') {
                            $currentSS = (float) ($member->voluntary_savings ?? 0.00);
                            $maxWithdrawal = max(0.00, $currentSS - 10000.00);
                            if ($amount > $maxWithdrawal) {
                                DB::rollBack();
                                $formattedCurrent = number_format($currentSS, 0, ',', '.');
                                return response()->json([
                                    'status'  => 'error',
                                    'success' => false,
                                    'message' => "Gagal Penarikan: Saldo Simpanan Sukarela tidak mencukupi. Minimal saldo mengendap adalah Rp 10.000. Saldo Sukarela Anda: Rp {$formattedCurrent}."
                                ], 400);
                            }
                        } else {
                            $currentBal = (float) ($member->$columnToUpdate ?? 0.00);
                            if ($amount > $currentBal) {
                                DB::rollBack();
                                $formattedCurrent = number_format($currentBal, 0, ',', '.');
                                return response()->json([
                                    'status'  => 'error',
                                    'success' => false,
                                    'message' => "Gagal Penarikan: Saldo tidak mencukupi. Saldo Anda saat ini: Rp {$formattedCurrent}."
                                ], 400);
                            }
                        }
                    }

                    $isWithdrawal = ($type === 'withdrawal');
                    $beginningBalance = $columnToUpdate ? (float) ($member->$columnToUpdate ?? 0.00) : 0.00;
                    $endingBalance = $columnToUpdate 
                        ? ($isWithdrawal ? ($beginningBalance - $amount) : ($beginningBalance + $amount))
                        : 0.00;

                    $trxNumber = 'TRX-' . date('Ymd') . '-' . str_pad((string) mt_rand(1, 9999), 4, '0', STR_PAD_LEFT);
                    $itemTransactionDate = $this->extractTransactionDate($item, $transactionDate);

                    $transaction = Transaction::create([
                        'transaction_number' => $trxNumber,
                        'receipt_number'     => $receiptNumber,
                        'member_id'          => $member->id,
                        'account_id'         => $accountKas->id,
                        'book_type'          => $bookType,
                        'operator_id'        => $authUser->id ?? null,
                        'approved_by'        => $authUser->id ?? null,
                        'type'               => $type,
                        'amount'             => $amount,
                        'beginning_balance'  => $beginningBalance,
                        'ending_balance'     => $endingBalance,
                        'payment_method'     => $paymentMethod,
                        'transaction_date'   => $itemTransactionDate,
                        'description'        => $itemDesc,
                        'status'             => 'approved',
                        'approved_at'        => now(),
                        'denda'              => 0.00,
                    ]);

                    // Update saldo member HANYA jika ini transaksi simpanan ($columnToUpdate !== null)
                    if ($columnToUpdate) {
                        if ($type === 'deposit') {
                            if ($columnToUpdate === 'daily_savings') {
                                $member->has_buku_putih = true;
                                $member->is_white_book_active = true;
                                $member->save();
                            }
                            $member->increment($columnToUpdate, $amount);
                        } else {
                            $member->decrement($columnToUpdate, $amount);
                        }
                    }

                    // Auto-jurnal via JournalService dengan explicit account_code
                    try {
                        app(\App\Services\JournalService::class)->generateJournal($transaction, $accCode ?: $accountCode);
                    } catch (\Throwable $jEx) {
                        Log::warning('Auto-journal warning: ' . $jEx->getMessage());
                    }

                    $savedTransactions[] = $transaction;
                }
            } 
            // B. SKENARIO SINGLE TRANSACTION
            else {
                $amount = (float) ($request->amount ?? $request->amount_simpanan_harian ?? $request->simpanan_harian ?? $request->daily_savings ?? 0);
                $type = strtolower($request->type ?? 'deposit');
                // Normalisasi: 'km' / 'kas_masuk' / 'in' => deposit; 'kk' / 'kas_keluar' / 'out' / 'fee' => withdrawal
                if (in_array($type, ['in', 'kas_masuk', 'km'])) $type = 'deposit';
                if (in_array($type, ['out', 'kas_keluar', 'fee', 'kk'])) $type = 'withdrawal';

                $rawReceipt = $request->input('receipt_number') ?? $request->input('no_bukti') ?? $request->input('proof_number');
                $receiptNumber = $rawReceipt ? trim(preg_replace('/^(KM|KK)\s+/i', '', $rawReceipt)) : null;
                $descriptionInput = $request->description ?? $request->title ?? ($type === 'deposit' ? 'Kas Masuk' : 'Kas Keluar');

                $columnToUpdate = null;
                $descLower = strtolower($descriptionInput);
                $accCode = trim((string) ($request->account_code ?? $request->coa_code ?? ''));

                // Khusus Pos Bank / Bank BRI (Akun 1010) - JANGAN masuk ke 2020
                $isBankPos = $accCode === '1010' || (str_contains($descLower, 'bank') && !str_contains($descLower, 'jasa bank') && !str_contains($descLower, 'bunga bank'));
                if ($isBankPos) {
                    $accCode = '1010';
                }

                $isOperationalRevenueOrLoan = $isBankPos
                    || in_array($accCode, ['4170', '4180', '4181', '4182', '4183', '4184', '4191', '4192', '4193', '4194', '1024', '1010'])
                    || str_starts_with($accCode, '4') || str_starts_with($accCode, '7') || str_starts_with($accCode, '5') || str_starts_with($accCode, '1')
                    || str_contains($descLower, 'pinjaman') || str_contains($descLower, 'piutang') || str_contains($descLower, 'pencairan')
                    || str_contains($descLower, 'provisi') || str_contains($descLower, 'denda') || str_contains($descLower, 'jasa pinjaman') || str_contains($descLower, 'bunga pinjaman')
                    || str_contains($descLower, 'pendapatan') || str_contains($descLower, 'biaya') || str_contains($descLower, 'beban');

                if ($isOperationalRevenueOrLoan) {
                    $columnToUpdate = null; // Pendapatan operasional / piutang / pinjaman TIDAK menambah/mengurangi saldo simpanan anggota
                } elseif ($accCode === '2021' || $bookType === 'BUKU_PUTIH' || str_contains($descLower, 'harian') || str_contains($descLower, 'buku putih') || str_contains($descLower, 'tabungan') || $request->has('amount_simpanan_harian') || $request->has('simpanan_harian')) {
                    $columnToUpdate = 'daily_savings';
                    $bookType = 'BUKU_PUTIH';
                } elseif ($accCode === '2020') {
                    if (str_contains($descLower, 'pokok')) {
                        $columnToUpdate = 'principal_savings';
                    } elseif (str_contains($descLower, 'wajib')) {
                        $columnToUpdate = 'mandatory_savings';
                    } else {
                        $columnToUpdate = 'voluntary_savings';
                    }
                    $bookType = 'BUKU_BIRU';
                } elseif (str_contains($descLower, 'pokok')) {
                    $columnToUpdate = 'principal_savings';
                } elseif (str_contains($descLower, 'wajib')) {
                    $columnToUpdate = 'mandatory_savings';
                } elseif (str_contains($descLower, 'duka') || $accCode === '2038') {
                    $columnToUpdate = 'grief_fund';
                } elseif (str_contains($descLower, 'sukarela') || str_contains($descLower, 'simpanan')) {
                    $columnToUpdate = 'voluntary_savings';
                } else {
                    $columnToUpdate = 'voluntary_savings';
                }

                // Validasi Penarikan / Withdrawal
                if ($type === 'withdrawal' && $columnToUpdate) {
                    // Kunci penarikan SP dan SW untuk anggota aktif
                    if (in_array($columnToUpdate, ['principal_savings', 'mandatory_savings'])) {
                        DB::rollBack();
                        return response()->json([
                            'status'  => 'error',
                            'success' => false,
                            'message' => 'Gagal Penarikan: Simpanan Pokok dan Simpanan Wajib tidak dapat ditarik selama masih berstatus Anggota Aktif. Hanya Simpanan Sukarela yang dapat ditarik.'
                        ], 400);
                    }

                    if ($columnToUpdate === 'daily_savings' || $bookType === 'BUKU_PUTIH') {
                        $currentDaily = (float) ($member->daily_savings ?? 0.00);
                        $hasBukuPutih = $member->has_buku_putih || ($currentDaily > 0);
                        if (!$hasBukuPutih) {
                            DB::rollBack();
                            return response()->json([
                                'status'  => 'error',
                                'success' => false,
                                'message' => 'Anggota belum memiliki rekening Buku Putih (Tabungan Harian).'
                            ], 400);
                        }
                        $maxWithdrawal = max(0.00, $currentDaily - 100000.00);
                        if ($amount > $maxWithdrawal || $currentDaily < 100000.00) {
                            DB::rollBack();
                            $formattedCurrent = number_format($currentDaily, 0, ',', '.');
                            return response()->json([
                                'status'  => 'error',
                                'success' => false,
                                'message' => "Gagal Penarikan: Saldo tidak mencukupi. Minimal saldo mengendap di Buku Putih adalah Rp 100.000. Saldo Anda saat ini: Rp {$formattedCurrent}."
                            ], 400);
                        }
                    } elseif ($columnToUpdate === 'voluntary_savings') {
                        $currentSS = (float) ($member->voluntary_savings ?? 0.00);
                        $maxWithdrawal = max(0.00, $currentSS - 10000.00);
                        if ($amount > $maxWithdrawal) {
                            DB::rollBack();
                            $formattedCurrent = number_format($currentSS, 0, ',', '.');
                            return response()->json([
                                'status'  => 'error',
                                'success' => false,
                                'message' => "Gagal Penarikan: Saldo Simpanan Sukarela tidak mencukupi. Minimal saldo mengendap adalah Rp 10.000. Saldo Sukarela Anda: Rp {$formattedCurrent}."
                            ], 400);
                        }
                    } else {
                        $currentBal = (float) ($member->$columnToUpdate ?? 0.00);
                        if ($amount > $currentBal) {
                            DB::rollBack();
                            $formattedCurrent = number_format($currentBal, 0, ',', '.');
                            return response()->json([
                                'status'  => 'error',
                                'success' => false,
                                'message' => "Gagal Penarikan: Saldo tidak mencukupi. Saldo Anda saat ini: Rp {$formattedCurrent}."
                            ], 400);
                        }
                    }
                }

                $beginningBalance = $columnToUpdate ? (float) ($member->$columnToUpdate ?? 0.00) : 0.00;
                $endingBalance = $columnToUpdate 
                    ? (($type === 'deposit') ? ($beginningBalance + $amount) : ($beginningBalance - $amount))
                    : 0.00;

                $trxNumber = 'TRX-' . date('Ymd') . '-' . str_pad((string) mt_rand(1, 9999), 4, '0', STR_PAD_LEFT);

                $singleCoa = null;
                if ($accCode) {
                    $singleCoa = ChartOfAccount::where('account_code', $accCode)->first();
                }

                $transaction = Transaction::create([
                    'transaction_number' => $trxNumber,
                    'receipt_number'     => $receiptNumber,
                    'member_id'          => $member->id,
                    'account_id'         => $accountKas->id,
                    'book_type'          => $bookType,
                    'operator_id'        => $authUser->id ?? null,
                    'approved_by'        => $authUser->id ?? null,
                    'type'               => $type,
                    'amount'             => $amount,
                    'beginning_balance'  => $beginningBalance,
                    'ending_balance'     => $endingBalance,
                    'payment_method'     => $paymentMethod,
                    'transaction_date'   => $transactionDate,
                    'description'        => $descriptionInput,
                    'status'             => $request->input('status', 'approved'),
                    'approved_at'        => now(),
                    'denda'              => (float) $request->input('denda', 0.00),
                    'denda_reason'       => $request->input('denda_reason'),
                ]);

                if ($columnToUpdate) {
                    if ($type === 'deposit') {
                        if ($columnToUpdate === 'daily_savings') {
                            $member->has_buku_putih = true;
                            $member->is_white_book_active = true;
                            $member->save();
                        }
                        $member->increment($columnToUpdate, $amount);
                    } elseif ($type === 'withdrawal') {
                        $member->decrement($columnToUpdate, $amount);
                    }
                }

                // Auto-jurnal via JournalService dengan explicit account_code
                try {
                    app(\App\Services\JournalService::class)->generateJournal($transaction, $accCode);
                } catch (\Throwable $jEx) {
                    Log::warning('Auto-journal warning: ' . $jEx->getMessage());
                }

                $savedTransactions[] = $transaction;
            }

            // Deteksi & Otomatisasi Sinkronisasi Pembayaran Angsuran ke Kartu Pinjaman Anggota (1024 = Pokok, 4180 = Jasa, 4182 = Denda)
            $loanPrincipalAmount = 0.0;
            $loanInterestAmount  = 0.0;
            $loanPenaltyAmount   = 0.0;

            if ($request->has('items') && is_array($request->items) && count($request->items) > 0) {
                foreach ($request->items as $item) {
                    $accCode = trim((string) ($item['account_code'] ?? ''));
                    $itemAmount = (float) ($item['amount'] ?? 0);
                    if ($itemAmount <= 0) continue;

                    if ($accCode === '1024') {
                        $loanPrincipalAmount += $itemAmount;
                    } elseif ($accCode === '4180') {
                        $loanInterestAmount += $itemAmount;
                    } elseif ($accCode === '4182') {
                        $loanPenaltyAmount += $itemAmount;
                    }
                }
            } else {
                $accCode = trim((string) ($request->input('account_code') ?? $request->input('coa_code') ?? ''));
                $itemAmount = (float) ($request->input('amount') ?? $request->input('amount_simpanan_harian') ?? 0);
                $dendaInput = (float) ($request->input('denda', 0));

                if ($accCode === '1024') {
                    $loanPrincipalAmount += $itemAmount;
                } elseif ($accCode === '4180') {
                    $loanInterestAmount += $itemAmount;
                } elseif ($accCode === '4182') {
                    $loanPenaltyAmount += $itemAmount;
                }
                if ($dendaInput > 0) {
                    $loanPenaltyAmount += $dendaInput;
                }
            }

            $totalLoanPayment = $loanPrincipalAmount + $loanInterestAmount + $loanPenaltyAmount;
            if ($totalLoanPayment > 0 && $member) {
                $activeLoan = Loan::where('member_id', $member->id)
                    ->where(function ($q) {
                        $q->whereIn('status', ['APPROVED', 'APPROVED_BY_MANAGER', 'DISBURSED', 'ACTIVE', 'approved', 'approved_by_manager', 'disbursed', 'active'])
                          ->orWhereIn(DB::raw('UPPER(status)'), ['APPROVED', 'APPROVED_BY_MANAGER', 'DISBURSED', 'ACTIVE']);
                    })
                    ->where(function ($q) {
                        $q->where('remaining_principal', '>', 0)
                          ->orWhereNull('remaining_principal');
                    })
                    ->orderBy('id', 'asc')
                    ->first();

                if ($activeLoan) {
                    $rawVoucher = $request->input('voucher_number') ?? $request->input('receipt_number') ?? $request->input('no_bukti') ?? $request->input('proof_number');
                    $voucherNumber = $rawVoucher ?: ($savedTransactions[0]->receipt_number ?? null);

                    $currentRemaining = (float) ($activeLoan->remaining_principal ?? $activeLoan->remaining_amount ?? $activeLoan->amount);
                    $newRemaining = max(0.0, $currentRemaining - $loanPrincipalAmount);

                    // 1. Cari jadwal angsuran terlama yang masih berstatus 'unpaid'
                    $targetInstallment = LoanInstallment::where('loan_id', $activeLoan->id)
                        ->where('status', '!=', 'paid')
                        ->orderBy('installment_number', 'asc')
                        ->first();

                    if ($targetInstallment) {
                        // UPDATE record jadwal yang sudah ada
                        $targetInstallment->update([
                            'status'           => 'paid',
                            'paid_at'          => $transactionDate . ' ' . date('H:i:s'),
                            'receipt_number'   => $voucherNumber,
                            'principal_amount' => $loanPrincipalAmount,
                            'interest_amount'  => $loanInterestAmount,
                            'penalty_fee'      => $loanPenaltyAmount,
                            'penalty_amount'   => $loanPenaltyAmount,
                            'total_amount'     => $totalLoanPayment,
                            'ending_balance'   => $newRemaining,
                            'paid_by'          => $authUser->id ?? null,
                            'paid_by_member_id'=> $member->id,
                            'notes'            => 'Pembayaran via Kas Masuk (KM)',
                        ]);
                    } else {
                        // Jika jadwal belum ada atau semua sudah paid, buat baris baru
                        $nextInstallmentNumber = ($activeLoan->installments()->max('installment_number') ?? 0) + 1;
                        LoanInstallment::create([
                            'loan_id'            => $activeLoan->id,
                            'paid_by_member_id'  => $member->id,
                            'received_by_user_id'=> $authUser->id ?? null,
                            'installment_number' => $nextInstallmentNumber,
                            'receipt_number'     => $voucherNumber,
                            'beginning_balance'  => $currentRemaining,
                            'principal_amount'   => $loanPrincipalAmount,
                            'interest_amount'    => $loanInterestAmount,
                            'penalty_fee'        => $loanPenaltyAmount,
                            'penalty_amount'     => $loanPenaltyAmount,
                            'total_amount'       => $totalLoanPayment,
                            'ending_balance'     => $newRemaining,
                            'due_date'           => $transactionDate,
                            'paid_at'            => $transactionDate . ' ' . date('H:i:s'),
                            'paid_by'            => $authUser->id ?? null,
                            'status'             => 'paid',
                            'notes'              => 'Pembayaran via Kas Masuk (KM)',
                        ]);
                    }

                    $activeLoan->remaining_principal = $newRemaining;
                    if (Schema::hasColumn('loans', 'remaining_amount')) {
                        $activeLoan->remaining_amount = $newRemaining;
                    }
                    if ($newRemaining == 0) {
                        $activeLoan->status = 'PAID_OFF';
                    }
                    $activeLoan->save();
                    $activeLoan->recalculateSchedule();
                }
            }

            if ($member->status === 'inactive') {
                $member->status = 'active';
                $member->save();
            }

            DB::commit();

            return response()->json([
                'status'  => 'success',
                'success' => true,
                'message' => 'Transaksi berhasil disimpan dan dijurnal otomatis!',
                'data'    => $savedTransactions,
            ], 200);

        } catch (ValidationException $e) {
            DB::rollBack();
            return response()->json([
                'status'  => 'error',
                'success' => false,
                'message' => 'Validasi gagal',
                'errors'  => $e->errors()
            ], 422);
        } catch (\Illuminate\Database\QueryException $e) {
            DB::rollBack();
            Log::error('Transaction Store QueryException: ' . $e->getMessage() . ' | Line: ' . $e->getLine());
            if ($e->getCode() === '23000' || ($e->errorInfo[1] ?? 0) === 1062 || str_contains($e->getMessage(), '1062')) {
                return response()->json([
                    'status'  => 'error',
                    'success' => false,
                    'message' => 'Maaf, nomor transaksi/bukti ini sudah terdaftar. Silakan coba lagi dengan nomor yang berbeda.',
                    'errors'  => [
                        'voucher_no'         => ['Maaf, nomor transaksi/bukti ini sudah terdaftar. Silakan coba lagi dengan nomor yang berbeda.'],
                        'transaction_number' => ['Maaf, nomor transaksi/bukti ini sudah terdaftar. Silakan coba lagi dengan nomor yang berbeda.'],
                    ],
                ], 422);
            }
            return response()->json([
                'status'  => 'error',
                'success' => false,
                'message' => 'Gagal menyimpan transaksi: ' . $e->getMessage(),
                'line'    => $e->getLine()
            ], 500);
        } catch (\Throwable $e) {
            DB::rollBack();
            Log::error('Transaction Store Error: ' . $e->getMessage() . ' | Line: ' . $e->getLine());
            return response()->json([
                'status'  => 'error',
                'success' => false,
                'message' => 'Gagal menyimpan transaksi: ' . $e->getMessage(),
                'line'    => $e->getLine()
            ], 500);
        }
    }

    /**
     * Ambil Data Transaksi Terbaru dengan payment_method
     */
    public function latest(Request $request): JsonResponse
    {
        try {
            $limit = $request->query('limit', 10);
            
            $transactions = Transaction::with(['member:id,name,member_number'])
                ->latest()
                ->take($limit)
                ->get();
                
            $data = $transactions->map(function ($trx) {
                $typeIn = in_array(strtolower($trx->type ?? ''), ['deposit', 'in', 'kas_masuk', 'km']);
                return [
                    'id' => (string) $trx->id,
                    'transaction_number' => $trx->transaction_number ?? '',
                    'receipt_number' => $trx->receipt_number ?? '',
                    'formatted_receipt_no' => $trx->formatted_receipt_no ?? '',
                    'member' => [
                        'full_name' => ($trx->member && $trx->member->name) ? $trx->member->name : 'Anggota Umum',
                        'member_number' => ($trx->member && $trx->member->member_number) ? $trx->member->member_number : '-',
                    ],
                    'type' => $trx->type ?? 'deposit',
                    'isIncome' => $typeIn,
                    'amount' => (float) ($trx->amount ?? 0),
                    'payment_method' => $trx->payment_method ?? 'cash',
                    'status' => $trx->status ?? 'pending',
                    'description' => $trx->description ?? '',
                    'date' => $trx->created_at ? $trx->created_at->setTimezone('Asia/Jakarta')->translatedFormat('d M Y, H:i') : '',
                ];
            });
            return response()->json([
                'success' => true,
                'data' => $data,
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Gagal mengambil data transaksi terbaru: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Ambil list transaksi harian ter-rekap per Kuitansi/Nota dengan breakdown item COA (details)
     * Endpoint: GET /api/transactions/daily & GET /api/daily-transactions
     */
    public function dailyTransactions(Request $request): JsonResponse
    {
        try {
            $query = Transaction::with([
                'member:id,name,member_number,phone',
                'journalEntry.details.account',
                'operator:id,name',
            ])
            ->where(function ($q) {
                $q->whereNull('category')->orWhere('category', '!=', 'bunga_simpanan');
            })
            ->where(function ($q) {
                $q->whereNull('payment_method')->orWhere('payment_method', '!=', 'memorial');
            });

            // Filter Tanggal
            if ($request->filled('date')) {
                $query->whereDate('transaction_date', $request->date);
            } elseif ($request->filled('start_date') && $request->filled('end_date')) {
                $query->whereBetween('transaction_date', [$request->start_date, $request->end_date]);
            } elseif ($request->filled('start_date')) {
                $query->whereDate('transaction_date', '>=', $request->start_date);
            } elseif ($request->filled('end_date')) {
                $query->whereDate('transaction_date', '<=', $request->end_date);
            }

            // Filter Tipe (deposit / withdrawal)
            if ($request->filled('type') && !in_array($request->type, ['Semua', 'All', 'all'])) {
                $typeFilter = strtolower($request->type);
                if (in_array($typeFilter, ['in', 'kas_masuk', 'deposit'])) {
                    $query->whereIn('type', ['deposit', 'in', 'kas_masuk']);
                } elseif (in_array($typeFilter, ['out', 'kas_keluar', 'withdrawal'])) {
                    $query->whereIn('type', ['withdrawal', 'out', 'kas_keluar']);
                } else {
                    $query->where('type', $request->type);
                }
            }

            // Filter Payment Method
            if ($request->filled('payment_method') && !in_array($request->payment_method, ['Semua', 'All', 'all'])) {
                $query->where('payment_method', $request->payment_method);
            }

            // Filter Search (Member name / no, receipt number, description)
            $search = trim((string) ($request->input('search') ?? $request->input('q') ?? $request->input('keyword') ?? ''));
            $query->when($request->filled('search') || $search !== '', function ($q) use ($search) {
                $q->where(function ($sub) use ($search) {
                    $sub->where('receipt_number', 'LIKE', "%{$search}%")
                        ->orWhere('transaction_number', 'LIKE', "%{$search}%")
                        ->orWhere('description', 'LIKE', "%{$search}%")
                        ->orWhereHas('member', function ($m) use ($search) {
                            $m->where('name', 'LIKE', "%{$search}%")
                              ->orWhere('member_number', 'LIKE', "%{$search}%")
                              ->orWhere('nik', 'LIKE', "%{$search}%");
                        });
                });
            });

            $rawTransactions = $query->orderBy('transaction_date', 'desc')
                                     ->orderBy('id', 'desc')
                                     ->get();

            // Grouping per Kuitansi / Bukti Kas
            $grouped = $rawTransactions->groupBy(function ($trx) {
                $receipt = $trx->receipt_number;
                if (!empty($receipt)) {
                    $date = $trx->transaction_date ? $trx->transaction_date->format('Ymd') : '00000000';
                    return "RCP_{$receipt}_{$trx->member_id}_{$date}_{$trx->type}";
                }
                return "TRX_{$trx->id}";
            });

            $results = [];
            foreach ($grouped as $groupKey => $trxGroup) {
                $firstTrx = $trxGroup->first();
                $member = $firstTrx->member;
                $memberName = $member ? $member->name : 'Anggota Umum';
                $memberNo = $member ? $member->member_number : '-';

                $transactionCode = $firstTrx->formatted_receipt_no;

                $paymentMethodRaw = strtolower($firstTrx->payment_method ?? 'cash');
                $paymentMethodFormatted = (str_contains($paymentMethodRaw, 'bank') || str_contains($paymentMethodRaw, 'transfer'))
                    ? 'Bank / Transfer'
                    : 'Tunai / Cash';

                $details = [];
                $totalAmount = 0;

                foreach ($trxGroup as $trx) {
                    $amount = (float) ($trx->amount ?? 0);
                    $totalAmount += $amount;

                    $accountCode = null;
                    $accountName = null;

                    // 1. Cek dari Journal Detail yang berelasi jika ada
                    if ($trx->journalEntry && $trx->journalEntry->details->count() > 0) {
                        $counterDetail = $trx->journalEntry->details->first(function ($det) {
                            $code = $det->account ? ($det->account->account_code ?? $det->account->account_number ?? '') : '';
                            return !empty($code) && !in_array($code, ['1000', '1010']);
                        });

                        if ($counterDetail && $counterDetail->account) {
                            $accountCode = $counterDetail->account->account_code ?? $counterDetail->account->account_number ?? '';
                            $accountName = $counterDetail->account->account_name;
                        }
                    }

                    // 2. Fallback berdasarkan deskripsi transaksi & COA
                    if (!$accountCode || !$accountName) {
                        $descLower = strtolower($trx->description ?? '');
                        if (str_contains($descLower, 'wajib')) {
                            $accountCode = '2020'; $accountName = 'Simpanan Wajib';
                        } elseif (str_contains($descLower, 'pokok')) {
                            $accountCode = '2020'; $accountName = 'Simpanan Pokok';
                        } elseif (str_contains($descLower, 'sukarela') || str_contains($descLower, 'harian') || $trx->book_type === 'BUKU_PUTIH') {
                            $accountCode = '2021'; $accountName = 'Simpanan Harian';
                        } elseif (str_contains($descLower, 'angsuran') || str_contains($descLower, 'cicilan') || str_contains($descLower, 'pokok pinjaman')) {
                            $accountCode = '1024'; $accountName = 'Angsuran Piutang';
                        } elseif (str_contains($descLower, 'jasa') || str_contains($descLower, 'bunga')) {
                            $accountCode = '4180'; $accountName = 'Jasa Pinjaman';
                        } elseif (str_contains($descLower, 'denda')) {
                            $accountCode = '4182'; $accountName = 'Denda Keterlambatan';
                        } elseif (str_contains($descLower, 'duka')) {
                            $accountCode = '2038'; $accountName = 'Dana Duka';
                        } elseif (str_contains($descLower, 'sosial')) {
                            $accountCode = '2034'; $accountName = 'Dana Sosial';
                        } elseif (str_contains($descLower, 'provisi')) {
                            $accountCode = '4170'; $accountName = 'Provisi Pinjaman';
                        } elseif (str_contains($descLower, 'pangkal')) {
                            $accountCode = '4191'; $accountName = 'Uang Pangkal';
                        } else {
                            $accountCode = $typeIn ? '2020' : '7110';
                            $accountName = $trx->description ?: ($typeIn ? 'Simpanan Anggota' : 'Beban Operasional');
                        }
                    }

                    $details[] = [
                        'account_code' => $accountCode,
                        'account_name' => $accountName,
                        'amount'       => $amount,
                        'description'  => $trx->description ?? $accountName,
                    ];
                }

                $isMultiple = count($details) > 1;

                $results[] = [
                    'id'               => (string) $firstTrx->id,
                    'transaction_code' => $transactionCode,
                    'receipt_number'   => $firstTrx->receipt_number,
                    'transaction_number' => $firstTrx->transaction_number,
                    'member_id'        => $firstTrx->member_id,
                    'member_name'      => $memberName,
                    'member_no'        => $memberNo,
                    'total_amount'     => (float) $totalAmount,
                    'payment_method'   => $paymentMethodFormatted,
                    'payment_method_raw' => $paymentMethodRaw,
                    'type'             => $firstTrx->type,
                    'status'           => $firstTrx->status,
                    'is_multiple'      => $isMultiple,
                    'transaction_date' => $firstTrx->transaction_date ? $firstTrx->transaction_date->format('Y-m-d') : '',
                    'created_at'       => $firstTrx->created_at ? $firstTrx->created_at->format('Y-m-d H:i:s') : '',
                    'details'          => $details,
                    'items'            => $details,
                ];
            }

            return response()->json([
                'success' => true,
                'total'   => count($results),
                'data'    => $results,
            ], 200);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Gagal mengambil data transaksi harian: ' . $e->getMessage(),
            ], 500);
        }
    }

    public function index(Request $request): JsonResponse
    {
        try {
            $perPage = (int) $request->input('per_page', 25);
            if ($perPage <= 0 || $perPage > 250) {
                $perPage = 25;
            }

            $query = Transaction::query()
                ->select([
                    'transactions.id',
                    'transactions.transaction_number',
                    'transactions.receipt_number',
                    'transactions.member_id',
                    'transactions.account_id',
                    'transactions.operator_id',
                    'transactions.approved_by',
                    'transactions.type',
                    'transactions.amount',
                    'transactions.beginning_balance',
                    'transactions.ending_balance',
                    'transactions.payment_method',
                    'transactions.transaction_date',
                    'transactions.description',
                    'transactions.status',
                    'transactions.approved_at',
                    'transactions.denda',
                    'transactions.book_type',
                    'transactions.created_at',
                    'transactions.updated_at',
                ])
                ->with([
                    'member:id,name,member_number',
                    'account:id,account_name,account_number',
                    'operator:id,name',
                    'journalEntry.details.account:id,account_code,account_name',
                ]);

            // Filters
            $isSqlite = DB::connection()->getDriverName() === 'sqlite';
            $rawDateSql = $isSqlite
                ? "DATE(COALESCE(transactions.transaction_date, transactions.created_at))"
                : "DATE(COALESCE(transactions.transaction_date, CONVERT_TZ(transactions.created_at, '+00:00', '+07:00'), transactions.created_at))";
            $dateExpr = DB::raw($rawDateSql);
            $startDate = $request->filled('start_date') ? $this->extractTransactionDate($request->start_date) : null;
            $endDate   = $request->filled('end_date') ? $this->extractTransactionDate($request->end_date) : null;

            if ($startDate && $endDate) {
                $query->where(function ($q) use ($startDate, $endDate, $rawDateSql) {
                    $q->whereBetween('transactions.transaction_date', [$startDate, $endDate])
                      ->orWhere(function ($sub) use ($startDate, $endDate, $rawDateSql) {
                          $sub->whereNull('transactions.transaction_date')
                              ->whereRaw("{$rawDateSql} BETWEEN ? AND ?", [$startDate, $endDate]);
                      });
                });
            } elseif ($startDate) {
                $query->where(function ($q) use ($startDate, $rawDateSql) {
                    $q->where('transactions.transaction_date', '>=', $startDate)
                      ->orWhere(function ($sub) use ($startDate, $rawDateSql) {
                          $sub->whereNull('transactions.transaction_date')
                              ->whereRaw("{$rawDateSql} >= ?", [$startDate]);
                      });
                });
            } elseif ($endDate) {
                $query->where(function ($q) use ($endDate, $rawDateSql) {
                    $q->where('transactions.transaction_date', '<=', $endDate)
                      ->orWhere(function ($sub) use ($endDate, $rawDateSql) {
                          $sub->whereNull('transactions.transaction_date')
                              ->whereRaw("{$rawDateSql} <= ?", [$endDate]);
                      });
                });
            }

            if ($request->filled('status') && !in_array($request->status, ['Semua', 'All', 'all'])) {
                $statusVal = strtolower($request->status);
                $query->where('transactions.status', $statusVal);
                if ($statusVal === 'approved' && ($request->boolean('today_only') || $request->has('today'))) {
                    $query->whereDate('transactions.updated_at', today());
                }
            }

            if ($request->filled('type') && !in_array($request->type, ['Semua', 'All', 'all'])) {
                $typeFilter = strtolower($request->type);
                if (in_array($typeFilter, ['in', 'kas_masuk', 'deposit'])) {
                    $query->whereIn('type', ['deposit', 'in', 'kas_masuk']);
                } elseif (in_array($typeFilter, ['out', 'kas_keluar', 'withdrawal'])) {
                    $query->whereIn('type', ['withdrawal', 'out', 'kas_keluar']);
                } else {
                    $query->where('type', $request->type);
                }
            }

            if ($request->filled('book_type') && !in_array($request->book_type, ['Semua', 'All', 'all', ''])) {
                $bookTypeInput = strtoupper(trim(str_replace(' ', '_', (string) $request->book_type)));
                if (in_array($bookTypeInput, ['BUKU_BIRU', 'BIRU'])) {
                    $query->where('transactions.book_type', 'BUKU_BIRU');
                } elseif (in_array($bookTypeInput, ['BUKU_PUTIH', 'PUTIH'])) {
                    $query->where('transactions.book_type', 'BUKU_PUTIH');
                } else {
                    $query->where('transactions.book_type', $request->book_type);
                }
            }

            $search = trim((string) ($request->input('search') ?? $request->input('q') ?? $request->input('keyword') ?? ''));
            $query->when($request->filled('search') || $search !== '', function ($q) use ($search) {
                $q->where(function ($sub) use ($search) {
                    $sub->where('receipt_number', 'LIKE', "%{$search}%")
                        ->orWhere('transaction_number', 'LIKE', "%{$search}%")
                        ->orWhere('description', 'LIKE', "%{$search}%")
                        ->orWhereHas('member', function ($m) use ($search) {
                            $m->where('name', 'LIKE', "%{$search}%")
                              ->orWhere('member_number', 'LIKE', "%{$search}%")
                              ->orWhere('nik', 'LIKE', "%{$search}%");
                        });
                });
            });

            $transactions = $query->orderBy('transactions.transaction_date', 'desc')
                                  ->orderBy('transactions.id', 'desc')
                                  ->paginate($perPage);

            $transactions->appends($request->all());

            // Tambahkan breakdown details pada tiap transaksi
            $transactions->getCollection()->transform(function ($trx) {
                $effectiveDate = null;
                if (!empty($trx->transaction_date)) {
                    $effectiveDate = \Carbon\Carbon::parse($trx->transaction_date)->toDateString();
                } elseif (!empty($trx->created_at)) {
                    $effectiveDate = \Carbon\Carbon::parse($trx->created_at)->timezone('Asia/Jakarta')->toDateString();
                } else {
                    $effectiveDate = now('Asia/Jakarta')->toDateString();
                }

                $accountCode = '2020';
                $accountName = 'Simpanan Anggota';

                if ($trx->relationLoaded('journalEntry') && $trx->journalEntry && $trx->journalEntry->details && $trx->journalEntry->details->count() > 0) {
                    $counterDetail = $trx->journalEntry->details->first(function ($det) {
                        $code = $det->account ? ($det->account->account_code ?? $det->account->account_number ?? '') : '';
                        return !empty($code) && !in_array($code, ['1000', '1010']);
                    });
                    if ($counterDetail && $counterDetail->account) {
                        $accountCode = $counterDetail->account->account_code ?? $counterDetail->account->account_number ?? '';
                        $accountName = $counterDetail->account->account_name;
                    }
                } else {
                    $descLower = strtolower($trx->description ?? '');
                    if (str_contains($descLower, 'wajib')) {
                        $accountCode = '2020'; $accountName = 'Simpanan Wajib';
                    } elseif (str_contains($descLower, 'pokok')) {
                        $accountCode = '2020'; $accountName = 'Simpanan Pokok';
                    } elseif (str_contains($descLower, 'sukarela') || str_contains($descLower, 'harian') || $trx->book_type === 'BUKU_PUTIH') {
                        $accountCode = '2021'; $accountName = 'Simpanan Harian';
                    } elseif (str_contains($descLower, 'angsuran') || str_contains($descLower, 'cicilan') || str_contains($descLower, 'pokok pinjaman')) {
                        $accountCode = '1024'; $accountName = 'Angsuran Piutang';
                    } elseif (str_contains($descLower, 'jasa') || str_contains($descLower, 'bunga')) {
                        $accountCode = '4180'; $accountName = 'Jasa Pinjaman';
                    } elseif (str_contains($descLower, 'denda')) {
                        $accountCode = '4182'; $accountName = 'Denda Keterlambatan';
                    } elseif (str_contains($descLower, 'duka')) {
                        $accountCode = '2038'; $accountName = 'Dana Duka';
                    } elseif (str_contains($descLower, 'sosial')) {
                        $accountCode = '2034'; $accountName = 'Dana Sosial';
                    }
                }

                $detailItem = [
                    'account_code' => $accountCode,
                    'account_name' => $accountName,
                    'amount'       => (float) $trx->amount,
                    'description'  => $trx->description ?? $accountName,
                ];

                $trx->details = [$detailItem];
                $trx->items   = [$detailItem];
                $trx->is_multiple = false;
                $trx->total_amount = (float) $trx->amount;
                $trx->unsetRelation('journalEntry');
                $trx->transaction_code = $trx->formatted_receipt_no;
                $trx->member_name = $trx->member ? $trx->member->name : 'Anggota Umum';
                $trx->transaction_date = $effectiveDate;
                $trx->date = $effectiveDate;

                return $trx;
            });

            return response()->json([
                'success' => true,
                'data' => $transactions
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Gagal mengambil data transaksi: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * API LIST TRANSAKSI HARIAN
     * Endpoint: GET /api/transactions/today
     */
    public function today(Request $request): JsonResponse
    {
        $request->merge(['date' => now()->toDateString()]);
        return $this->dailyTransactions($request);
    }

    /**
     * Koreksi / Update Transaksi Kas (Atomik DB Transaction)
     * Endpoint: PUT /api/v1/transactions/{id} & PUT /api/transactions/{id}
     */
    public function update(Request $request, $id): JsonResponse
    {
        $transaction = Transaction::with(['member', 'journalEntry.details.account', 'account'])->find($id);

        if (!$transaction) {
            return response()->json([
                'success' => false,
                'status'  => 'error',
                'message' => "Transaksi dengan ID #{$id} tidak ditemukan.",
            ], 404);
        }

        // 1. Validasi Input Form
        $validated = $request->validate([
            'amount'             => 'nullable|numeric|gt:0',
            'nominal'            => 'nullable|numeric|gt:0',
            'transaction_date'   => 'nullable|date',
            'description'        => 'nullable|string|max:500',
            'receipt_number'     => 'nullable|string|max:100',
            'voucher_number'     => 'nullable|string|max:100',
            'payment_method'     => 'nullable|in:cash,tunai,bank,transfer',
            'book_type'          => 'nullable|string',
            'account_code'       => 'nullable|string',
            'principal_amount'   => 'nullable|numeric|min:0',
            'interest_amount'    => 'nullable|numeric|min:0',
            'penalty_fee'        => 'nullable|numeric|min:0',
            'audit_note'         => 'nullable|string|max:255',
        ]);

        // Validasi Periode Terkunci (Global Lock Validation)
        if ($lockRes = PeriodLockService::validateDate($transaction->transaction_date)) {
            return $lockRes;
        }
        if ($request->filled('transaction_date') && ($lockRes = PeriodLockService::validateDate($request->input('transaction_date')))) {
            return $lockRes;
        }

        $oldAmount = (float) $transaction->amount;
        $newAmount = $request->has('amount') || $request->has('nominal')
            ? (float) ($request->input('amount') ?? $request->input('nominal'))
            : $oldAmount;

        if ($newAmount <= 0) {
            return response()->json([
                'success' => false,
                'status'  => 'error',
                'message' => 'Nominal transaksi harus lebih besar dari 0.',
            ], 422);
        }

        $delta = round($newAmount - $oldAmount, 2);
        $oldDate = $transaction->transaction_date;
        $transactionDate = $this->extractTransactionDate($request, $oldDate ? \Carbon\Carbon::parse($oldDate)->toDateString() : now()->toDateString());
        $newDescription = $request->input('description', $transaction->description);
        $rawReceipt = $request->input('receipt_number') ?? $request->input('voucher_number') ?? $request->input('no_bukti');
        $receiptNumber = $rawReceipt ? trim(preg_replace('/^(KM|KK)\s+/i', '', $rawReceipt)) : $transaction->receipt_number;
        $paymentMethod = $request->input('payment_method', $transaction->payment_method ?? 'cash');
        $bookType = $request->input('book_type', $transaction->book_type ?? 'BUKU_BIRU');
        $authUser = $request->user();

        return DB::transaction(function () use (
            $transaction, $oldAmount, $newAmount, $delta, $oldDate, $transactionDate,
            $newDescription, $receiptNumber, $paymentMethod, $bookType, $authUser, $request
        ) {
            $type = strtolower($transaction->type ?? 'deposit');
            $descLower = strtolower($newDescription ?: $transaction->description ?: '');
            $accCode = trim((string) ($request->input('account_code') ?? ''));

            // 3. Klasifikasi Transaksi & Koreksi Saldo Terkait
            $isOperationalRevenue = in_array($accCode, ['4170', '4181', '4182', '4183', '4184', '4191', '4192', '4193', '4194'])
                || (str_starts_with($accCode, '4') && $accCode !== '4180')
                || str_starts_with($accCode, '7') || str_starts_with($accCode, '5')
                || str_contains($descLower, 'provisi') || str_contains($descLower, 'denda')
                || str_contains($descLower, 'uang pangkal') || str_contains($descLower, 'pendapatan')
                || str_contains($descLower, 'biaya') || str_contains($descLower, 'beban')
                || str_contains($descLower, 'atk') || str_contains($descLower, 'gaji');

            $isLoanInstallment = $accCode === '1024' || $accCode === '4180'
                || str_contains($descLower, 'angsuran') || str_contains($descLower, 'cicilan')
                || str_contains($descLower, 'jasa pinjaman') || str_contains($descLower, 'bunga pinjaman');

            // A. KOREKSI ANGSURAN PINJAMAN (Jika Transaksi Merupakan Pembayaran Angsuran)
            if ($isLoanInstallment && $transaction->member_id) {
                $installment = null;
                if ($transaction->receipt_number) {
                    $installment = LoanInstallment::where('receipt_number', $transaction->receipt_number)->first();
                }
                if (!$installment) {
                    $installment = LoanInstallment::whereHas('loan', function ($q) use ($transaction) {
                        $q->where('member_id', $transaction->member_id);
                    })
                    ->where('status', 'paid')
                    ->whereDate('paid_at', $transaction->transaction_date)
                    ->first();
                }

                if ($installment) {
                    $oldPrincipalPaid = (float) $installment->principal_amount;
                    $newPrincipalPaid = $request->has('principal_amount')
                        ? (float) $request->input('principal_amount')
                        : ($delta != 0 ? max(0.0, $oldPrincipalPaid + $delta) : $oldPrincipalPaid);

                    $newInterestPaid = $request->has('interest_amount')
                        ? (float) $request->input('interest_amount')
                        : (float) $installment->interest_amount;

                    $newPenaltyFee = $request->has('penalty_fee')
                        ? (float) $request->input('penalty_fee')
                        : (float) $installment->penalty_fee;

                    $deltaPrincipalPaid = round($newPrincipalPaid - $oldPrincipalPaid, 2);

                    $installment->update([
                        'principal_amount' => $newPrincipalPaid,
                        'interest_amount'  => $newInterestPaid,
                        'penalty_fee'      => $newPenaltyFee,
                        'penalty_amount'   => $newPenaltyFee,
                        'total_amount'     => $newAmount,
                        'paid_at'          => $transactionDate,
                    ]);

                    if ($loan = $installment->loan) {
                        $currentRemaining = (float) ($loan->remaining_principal ?? $loan->remaining_amount ?? 0);
                        $newRemaining = max(0.0, $currentRemaining - $deltaPrincipalPaid);
                        $loan->update([
                            'remaining_principal' => $newRemaining,
                            'remaining_amount'    => $newRemaining,
                            'status'              => $newRemaining <= 0 ? 'completed' : 'approved',
                        ]);
                        $loan->recalculateSchedule();
                    }
                }
            }
            // B. KOREKSI SALDO SIMPANAN ANGGOTA (Jika Bukan Pendapatan Operasional)
            elseif (!$isOperationalRevenue && $transaction->member_id) {
                $member = $transaction->member ?: Member::find($transaction->member_id);
                if ($member && $delta != 0) {
                    $columnToUpdate = 'voluntary_savings';

                    if ($accCode === '2021' || $bookType === 'BUKU_PUTIH' || str_contains($descLower, 'harian') || str_contains($descLower, 'buku putih') || str_contains($descLower, 'tabungan')) {
                        $columnToUpdate = 'daily_savings';
                    } elseif ($accCode === '2020') {
                        if (str_contains($descLower, 'pokok')) {
                            $columnToUpdate = 'principal_savings';
                        } elseif (str_contains($descLower, 'wajib')) {
                            $columnToUpdate = 'mandatory_savings';
                        } else {
                            $columnToUpdate = 'voluntary_savings';
                        }
                    } elseif (str_contains($descLower, 'pokok')) {
                        $columnToUpdate = 'principal_savings';
                    } elseif (str_contains($descLower, 'wajib')) {
                        $columnToUpdate = 'mandatory_savings';
                    } elseif (str_contains($descLower, 'duka') || $accCode === '2038') {
                        $columnToUpdate = 'grief_fund';
                    }

                    if ($type === 'deposit') {
                        if ($delta > 0) {
                            $member->increment($columnToUpdate, $delta);
                        } else {
                            $member->decrement($columnToUpdate, abs($delta));
                        }
                    } else {
                        if ($delta > 0) {
                            $member->decrement($columnToUpdate, $delta);
                        } else {
                            $member->increment($columnToUpdate, abs($delta));
                        }
                    }
                }
            }

            // 4. Update Field Record Transaksi
            $transaction->update([
                'amount'           => $newAmount,
                'transaction_date' => $transactionDate,
                'description'      => $newDescription,
                'receipt_number'   => $receiptNumber,
                'payment_method'   => $paymentMethod,
                'book_type'        => $bookType,
                'operator_id'      => $authUser?->id ?? $transaction->operator_id,
            ]);

            // 5. Koreksi Saldo Kas pada Akun Kas (Jika Ada)
            $accountKas = $transaction->account ?: Account::where('account_number', 'KAS-101')->first();
            if ($accountKas && $delta != 0) {
                if ($type === 'deposit') {
                    if ($delta > 0) {
                        $accountKas->increment('balance', $delta);
                    } else {
                        $accountKas->decrement('balance', abs($delta));
                    }
                } else {
                    if ($delta > 0) {
                        $accountKas->decrement('balance', $delta);
                    } else {
                        $accountKas->increment('balance', abs($delta));
                    }
                }
            }

            // 6. Koreksi Jurnal Akuntansi (Buku Besar / General Ledger)
            try {
                $journalService = app(JournalService::class);
                $journal = JournalEntry::where('transaction_id', $transaction->id)->first();
                if ($journal) {
                    $journalService->updateJournal($transaction);
                } else {
                    $journalService->generateJournal($transaction);
                }
            } catch (\Throwable $jEx) {
                Log::warning("[TransactionController] Update journal warning: " . $jEx->getMessage());
            }

            // 7. Catat Audit Trail / ActivityLog
            try {
                ActivityLog::create([
                    'user_id'      => $authUser?->id,
                    'action'       => 'update_transaction',
                    'description'  => "Koreksi transaksi #{$transaction->id} ({$transaction->transaction_number}): Nominal diubah dari Rp " . number_format($oldAmount, 0, ',', '.') . " menjadi Rp " . number_format($newAmount, 0, ',', '.') . " (Delta: " . ($delta >= 0 ? '+' : '') . number_format($delta, 0, ',', '.') . ")",
                    'subject_type' => Transaction::class,
                    'subject_id'   => $transaction->id,
                    'properties'   => [
                        'old_amount' => $oldAmount,
                        'new_amount' => $newAmount,
                        'delta'      => $delta,
                        'old_date'   => $oldDate ? $oldDate->toDateString() : null,
                        'new_date'   => $transactionDate,
                        'audit_note' => $request->input('audit_note'),
                    ],
                    'ip_address'   => $request->ip(),
                    'user_agent'   => $request->userAgent(),
                    'created_at'   => now(),
                ]);
            } catch (\Throwable $logEx) {
                Log::warning("Gagal mencatat ActivityLog koreksi transaksi: " . $logEx->getMessage());
            }

            Log::info("[TransactionController] Transaksi #{$transaction->id} berhasil diupdate oleh User #{$authUser?->id}. Old: {$oldAmount}, New: {$newAmount}, Delta: {$delta}");

            return response()->json([
                'success' => true,
                'status'  => 'success',
                'message' => 'Transaksi berhasil diperbarui dan saldo serta jurnal akuntansi telah dikoreksi otomatis.',
                'data'    => [
                    'transaction' => $transaction->fresh(['member', 'account', 'journalEntry.details.account']),
                    'delta'       => $delta,
                    'old_amount'  => $oldAmount,
                    'new_amount'  => $newAmount,
                ],
            ], 200);
        });
    }

    public function destroy($id): JsonResponse
    {
        try {
            $transaction = Transaction::findOrFail((int) $id);

            // Validasi Periode Terkunci (Global Lock Validation)
            if ($lockRes = PeriodLockService::validateDate($transaction->transaction_date)) {
                return $lockRes;
            }

            DB::beginTransaction();
            $this->revertTransaction($transaction);
            Transaction::withoutPeriodLock(function () use ($transaction) {
                $transaction->delete();
            });
            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Transaksi berhasil dihapus dan saldo Buku Besar telah otomatis dikalkulasi ulang.',
                'deleted_id' => $id
            ], 200);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json(['success' => false, 'message' => 'Gagal menghapus transaksi: ' . $e->getMessage()], 500);
        }
    }

    public function destroyBatch(Request $request): JsonResponse
    {
        try {
            $date = $request->input('before_date');
            if (!$date) {
                return response()->json(['success' => false, 'message' => 'Parameter before_date diperlukan'], 400);
            }

            $transactions = Transaction::where('transaction_date', '<', $date)->get();

            // Validasi Periode Terkunci untuk seluruh transaksi yang akan dihapus
            foreach ($transactions as $t) {
                if ($lockRes = PeriodLockService::validateDate($t->transaction_date)) {
                    return $lockRes;
                }
            }

            DB::beginTransaction();
            $count = 0;
            Transaction::withoutPeriodLock(function () use ($transactions, &$count) {
                foreach ($transactions as $transaction) {
                    $this->revertTransaction($transaction);
                    $transaction->delete();
                    $count++;
                }
            });
            DB::commit();

            return response()->json(['success' => true, 'message' => "$count transaksi berhasil dihapus"], 200);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json(['success' => false, 'message' => 'Gagal menghapus batch transaksi: ' . $e->getMessage()], 500);
        }
    }

    /**
     * Ambil daftar transaksi yang masih berstatus pending (antrean approval).
     * Endpoint: GET /api/transactions/pending
     */
    public function pending(): JsonResponse
    {
        $transactions = Transaction::with('member')
            ->where('status', 'pending')
            ->orderBy('created_at', 'desc')
            ->get();

        return response()->json([
            'success' => true,
            'data'    => $transactions,
        ], 200);
    }

    /**
     * Input Pendapatan Lain-lain / Tambah Kas Masuk
     * Endpoint: POST /api/transactions/income & POST /api/transactions/other-income
     */
    public function storeIncome(Request $request): JsonResponse
    {
        return app(\App\Http\Controllers\Api\IncomeController::class)->store($request);
    }

    public function importExcel(Request $request): JsonResponse
    {
        $rows = [];

        // 1. Ambil data dari JSON payload atau unggahan file CSV
        if ($request->has('transactions')) {
            $rows = $request->input('transactions');
        } elseif ($request->hasFile('file') || $request->hasFile('excel')) {
            $file = $request->file('file') ?? $request->file('excel');
            $path = $file->getRealPath();
            
            if (($handle = fopen($path, 'r')) !== false) {
                // Read header row
                $headers = fgetcsv($handle, 1000, ',');
                if ($headers !== false) {
                    // Trim headers
                    $headers = array_map(function($h) {
                        return trim(strtolower($h));
                    }, $headers);
                    
                    while (($data = fgetcsv($handle, 1000, ',')) !== false) {
                        $row = [];
                        foreach ($headers as $index => $header) {
                            $row[$header] = $data[$index] ?? null;
                        }
                        $rows[] = $row;
                    }
                }
                fclose($handle);
            }
        }

        if (empty($rows)) {
            return response()->json([
                'success' => false,
                'status'  => 'error',
                'message' => 'Tidak ada data transaksi yang ditemukan untuk diimpor.'
            ], 400);
        }

        $authUser = $request->user();

        // 2. Ambil Periode Akuntansi yang OPEN
        $activePeriod = DB::table('periods')
            ->whereIn('status', ['open', 'OPEN', 'terbuka'])
            ->where('is_locked', false)
            ->latest('id')
            ->first() ?? DB::table('accounting_periods')
            ->whereIn('status', ['open', 'OPEN', 'terbuka'])
            ->where('is_locked', false)
            ->latest('id')
            ->first();

        if (!$activePeriod) {
            return response()->json([
                'success' => false,
                'status'  => 'error',
                'message' => 'Gagal impor: Periode akuntansi aktif (OPEN) tidak ditemukan.'
            ], 400);
        }

        $accountKas = Account::firstOrCreate(
            ['account_number' => 'KAS-101'],
            [
                'account_name' => 'Kas Koperasi',
                'account_type' => 'kas',
                'category'     => 'asset',
                'balance'      => 0.00,
            ]
        );

        $journalService = app(JournalService::class);
        $importedCount = 0;
        $totalAmount = 0.00;
        $savedTransactions = [];

        try {
            DB::beginTransaction();

            foreach ($rows as $index => $row) {
                // Cari Anggota berdasarkan member_id, nik, atau member_number
                $member = null;
                $memberId = $row['member_id'] ?? $row['id_anggota'] ?? null;
                $nik = $row['nik'] ?? null;
                $memberNumber = $row['member_number'] ?? $row['nomor_anggota'] ?? null;

                if ($memberId) {
                    $member = Member::find($memberId);
                }
                if (!$member && $nik) {
                    $member = Member::where('nik', $nik)->first();
                }
                if (!$member && $memberNumber) {
                    $member = Member::where('member_number', $memberNumber)->first();
                }

                if (!$member) {
                    throw new \Exception("Anggota tidak ditemukan untuk baris ke-" . ($index + 1));
                }

                $amount = (float) ($row['amount'] ?? $row['nominal'] ?? $row['jumlah'] ?? 0);
                if ($amount <= 0) {
                    throw new \Exception("Nominal transaksi harus lebih besar dari 0 pada baris ke-" . ($index + 1));
                }

                $transactionDate = $row['transaction_date'] ?? $row['tanggal'] ?? now()->toDateString();
                


                $paymentMethod = $row['payment_method'] ?? $row['metode_pembayaran'] ?? 'cash';
                $description = $row['description'] ?? $row['keterangan'] ?? 'Setoran Simpanan (Import)';
                $accountCode = trim((string) ($row['account_code'] ?? $row['kode_akun'] ?? ''));

                $descLower = strtolower($description);

                // Klasifikasikan Simpanan (Pokok, Wajib, Sukarela)
                $columnToUpdate = 'voluntary_savings';
                $bookType = 'BUKU_BIRU';

                if (str_contains($descLower, 'pokok') || $accountCode === '2020_pokok') {
                    $columnToUpdate = 'principal_savings';
                } elseif (str_contains($descLower, 'wajib') || $accountCode === '2020_wajib') {
                    $columnToUpdate = 'mandatory_savings';
                } else {
                    $columnToUpdate = 'voluntary_savings';
                }

                // Untuk log/book_type: jika tabungan harian, set ke BUKU_PUTIH jika ada indikasi
                if ($accountCode === '2021' || str_contains($descLower, 'harian') || str_contains($descLower, 'buku putih')) {
                    $bookType = 'BUKU_PUTIH';
                }

                $beginningBalance = (float) ($member->$columnToUpdate ?? 0.00);
                $endingBalance = $beginningBalance + $amount;

                $trxNumber = sprintf(
                    'TRX-IMP-%s-%s-%s-%s',
                    now()->format('YmdHis'),
                    $member->id ?? ($index + 1),
                    strtoupper(substr($bookType ?? 'DEP', 0, 3)),
                    \Illuminate\Support\Str::upper(\Illuminate\Support\Str::random(5))
                );

                $receiptNo = $row['receipt_number'] ?? $row['no_bukti'] ?? sprintf(
                    'KM-IMP-%s-%s-%s',
                    now()->format('YmdHis'),
                    $member->id ?? ($index + 1),
                    \Illuminate\Support\Str::upper(\Illuminate\Support\Str::random(4))
                );

                // Buat data transaksi
                $transactionData = [
                    'transaction_number' => $trxNumber,
                    'receipt_number'     => $receiptNo,
                    'member_id'          => $member->id,
                    'account_id'         => $accountKas->id,
                    'book_type'          => $bookType,
                    'operator_id'        => $authUser->id ?? null,
                    'approved_by'        => $authUser->id ?? null,
                    'type'               => 'deposit',
                    'amount'             => $amount,
                    'beginning_balance'  => $beginningBalance,
                    'ending_balance'     => $endingBalance,
                    'payment_method'     => $paymentMethod,
                    'transaction_date'   => $transactionDate,
                    'description'        => $description,
                    'status'             => 'approved',
                    'approved_at'        => now(),
                ];

                // Tambahkan period_id secara dinamis jika kolomnya ada di skema database
                if (\Illuminate\Support\Facades\Schema::hasColumn('transactions', 'period_id')) {
                    $transactionData['period_id'] = $activePeriod->id;
                }

                $transaction = Transaction::create($transactionData);

                // Auto-update saldo anggota di tabel members
                $member->increment($columnToUpdate, $amount);

                if ($columnToUpdate === 'voluntary_savings' && $bookType === 'BUKU_PUTIH') {
                    // Update has_buku_putih jika ini transaksi harian
                    $member->has_buku_putih = true;
                    $member->save();
                }

                // Buat jurnal akuntansi
                try {
                    $journalService->generateJournal($transaction);
                } catch (\Throwable $jEx) {
                    Log::warning("Jurnal gagal untuk transaksi impor ID {$transaction->id}: " . $jEx->getMessage());
                }

                // Logging terikat ke period_id active secara eksplisit ke Log laravel
                Log::info("Imported transaction ID {$transaction->id} bound to period ID {$activePeriod->id}. Member: {$member->name}, Column Updated: {$columnToUpdate}");

                $savedTransactions[] = $transaction;
                $importedCount++;
                $totalAmount += $amount;
            }

            DB::commit();

            return response()->json([
                'success' => true,
                'status'  => 'success',
                'message' => "Berhasil mengimpor {$importedCount} data transaksi kas!",
                'data'    => [
                    'imported_count' => $importedCount,
                    'total_imported' => $importedCount,
                    'total_amount'   => $totalAmount,
                    'period_id'      => $activePeriod->id,
                    'period_name'    => $activePeriod->period_name,
                ]
            ], 200);

        } catch (\Throwable $e) {
            DB::rollBack();
            Log::error("Import Excel Error: " . $e->getMessage());
            return response()->json([
                'success' => false,
                'status'  => 'error',
                'message' => "Gagal mengimpor data pada baris ke-" . ($index + 1) . ": " . $e->getMessage(),
                'failed_row_index' => $index + 1
            ], 400);
        }
    }

    private function revertTransaction(Transaction $transaction)
    {
        // 1. Revert member balance (jika bukan transaksi pendapatan operasional)
        $member = \App\Models\Member::find($transaction->member_id);
        if ($member) {
            $columnToUpdate = 'voluntary_savings';
            $descLower = strtolower($transaction->description ?? '');
            $accCode = $transaction->account ? ($transaction->account->account_number ?? $transaction->account->account_code ?? '') : '';

            $isOperationalRevenue = in_array($accCode, ['4170', '4181', '4182', '4183', '4184', '4191', '4192', '4193', '4194'])
                || (str_starts_with($accCode, '4') && $accCode !== '4180')
                || str_starts_with($accCode, '7') || str_starts_with($accCode, '5')
                || str_contains($descLower, 'provisi') || str_contains($descLower, 'denda')
                || str_contains($descLower, 'uang pangkal') || str_contains($descLower, 'pendapatan')
                || str_contains($descLower, 'biaya') || str_contains($descLower, 'beban');

            if (!$isOperationalRevenue) {
                if ($transaction->book_type === 'BUKU_PUTIH' || str_contains($descLower, 'harian')) {
                    $columnToUpdate = 'daily_savings';
                } elseif (str_contains($descLower, 'pokok')) {
                    $columnToUpdate = 'principal_savings';
                } elseif (str_contains($descLower, 'wajib')) {
                    $columnToUpdate = 'mandatory_savings';
                } elseif (str_contains($descLower, 'duka')) {
                    $columnToUpdate = 'grief_fund';
                }

                if ($transaction->type === 'deposit') {
                    $member->decrement($columnToUpdate, $transaction->amount);
                } else {
                    $member->increment($columnToUpdate, $transaction->amount);
                }
            }
        }

        // 2. Revert status Loan Installment jika transaksi pembayaran cicilan pinjaman
        $descLower = strtolower($transaction->description ?? '');
        $accCode = $transaction->account ? ($transaction->account->account_number ?? $transaction->account->account_code ?? '') : '';
        $isLoanPayment = ($accCode === '1024')
            || str_contains($descLower, 'angsuran')
            || str_contains($descLower, 'cicilan')
            || str_contains($descLower, 'pokok pinjaman')
            || ($transaction->category === 'angsuran_pinjaman');

        if ($transaction->receipt_number && $isLoanPayment) {
            $installment = \App\Models\LoanInstallment::where('receipt_number', $transaction->receipt_number)->first();
            if ($installment) {
                $principalPaid = (float) $installment->principal_amount;
                $installment->update([
                    'status'         => 'unpaid',
                    'paid_at'        => null,
                    'receipt_number' => null,
                ]);
                if ($loan = $installment->loan) {
                    $loan->increment('remaining_principal', $principalPaid);
                    $loan->increment('remaining_amount', $principalPaid);
                    $loan->update(['status' => 'approved']);
                    $loan->recalculateSchedule();
                }
            }
        }

        // 3. Revert Account Kas balance pada tabel accounts
        $accountKas = $transaction->account ?: \App\Models\Account::where('account_type', 'kas')->first();
        if ($accountKas) {
            if ($transaction->type === 'deposit') {
                $accountKas->decrement('balance', $transaction->amount);
            } else {
                $accountKas->increment('balance', $transaction->amount);
            }
        }

        // 4. Delete Journal Entry & Details
        $journal = \App\Models\JournalEntry::where('transaction_id', $transaction->id)->first();
        if ($journal) {
            $journal->details()->delete();
            $journal->delete();
        }
    }

    /**
     * Endpoint khusus transaksi Tabungan Harian (Buku Putih)
     * POST /api/daily-savings/transaction
     */
    public function dailySavingsTransaction(Request $request): JsonResponse
    {
        $transactionDate = $this->extractTransactionDate($request);
        $request->merge([
            'transaction_date' => $transactionDate,
        ]);

        $validated = $request->validate([
            'member_id'        => 'required|exists:members,id',
            'type'             => 'required|in:deposit,withdrawal',
            'amount'           => 'required|numeric|min:1',
            'payment_method'   => 'nullable|string',
            'notes'            => 'nullable|string',
            'description'      => 'nullable|string',
            'transaction_date' => 'nullable|date',
            'date'             => 'nullable',
            'tanggal'          => 'nullable',
            'tanggal_transaksi'=> 'nullable',
            'entry_date'       => 'nullable',
            'tx_date'          => 'nullable',
            'tgl'              => 'nullable',
        ]);

        $member = Member::find($request->member_id);
        if (!$member) {
            return response()->json([
                'success' => false,
                'status'  => 'error',
                'message' => 'Data anggota tidak ditemukan.'
            ], 404);
        }

        // Guard Clause: Cek Status Anggota (Hanya Anggota AKTIF yang Boleh Menerima Transaksi Baru)
        $memberStatus = strtolower(trim((string) ($member->status ?? '')));
        $inactiveStatuses = ['resigned', 'resigned_total', 'inactive', 'pasif', 'keluar', 'non-aktif', 'non_aktif'];

        if (in_array($memberStatus, $inactiveStatuses) || $memberStatus !== 'active') {
            return response()->json([
                'success' => false,
                'status'  => 'error',
                'message' => 'Anggota telah berstatus non-aktif/keluar, tidak dapat menerima transaksi baru.',
                'errors'  => [
                    'member_id' => ['Anggota telah berstatus non-aktif/keluar, tidak dapat menerima transaksi baru.']
                ]
            ], 422);
        }

        $type = strtolower($request->type);
        $amount = (float) $request->amount;
        $paymentMethod = $request->input('payment_method', 'cash');
        $authUser = $request->user();

        // Validasi Periode Terkunci (Global Lock Validation)
        if ($lockRes = PeriodLockService::validateDate($transactionDate)) {
            return $lockRes;
        }

        $beginningBalance = (float) ($member->daily_savings ?? 0.00);

        if ($type === 'withdrawal' && $amount > $beginningBalance) {
            return response()->json([
                'success' => false,
                'status'  => 'error',
                'message' => 'Saldo tabungan harian tidak mencukupi.',
            ], 422);
        }

        DB::beginTransaction();
        try {
            $accountKas = Account::firstOrCreate(
                ['account_number' => 'KAS-101'],
                [
                    'account_name' => 'Kas Koperasi',
                    'account_type' => 'kas',
                    'category'     => 'asset',
                    'balance'      => 0.00,
                ]
            );

            $endingBalance = ($type === 'deposit') ? ($beginningBalance + $amount) : ($beginningBalance - $amount);

            $member->daily_savings = $endingBalance;
            if ($type === 'deposit') {
                $member->has_buku_putih = true;
                if (empty($member->buku_putih)) {
                    $member->buku_putih = 'BP-' . str_pad((string)$member->id, 4, '0', STR_PAD_LEFT);
                }
            }
            $member->save();

            $category = ($type === 'deposit') ? 'simpanan_harian' : 'tarik_buku_putih';
            $desc = $request->input('notes') ?? $request->input('description') ?? (($type === 'deposit') ? 'Setoran Awal Tabungan Harian Kasir' : 'Penarikan Buku Putih Kasir');
            $trxNumber = ($type === 'deposit' ? 'KM-BP-' : 'KK-BP-') . date('Ymd') . '-' . str_pad((string) mt_rand(1, 9999), 4, '0', STR_PAD_LEFT);

            $transaction = Transaction::create([
                'transaction_number' => $trxNumber,
                'member_id'          => $member->id,
                'account_id'         => $accountKas->id,
                'book_type'          => 'BUKU_PUTIH',
                'category'           => $category,
                'operator_id'        => $authUser->id ?? null,
                'approved_by'        => $authUser->id ?? null,
                'type'               => $type,
                'amount'             => $amount,
                'beginning_balance'  => $beginningBalance,
                'ending_balance'     => $endingBalance,
                'payment_method'     => $paymentMethod,
                'transaction_date'   => $transactionDate,
                'description'        => $desc,
                'status'             => 'approved',
                'approved_at'        => now(),
                'denda'              => 0.00,
            ]);

            if ($type === 'deposit') {
                $accountKas->increment('balance', $amount);
            } else {
                $accountKas->decrement('balance', $amount);
            }

            // Auto Journal Entry
            try {
                app(\App\Services\JournalService::class)->generateJournal($transaction, '2021');
            } catch (\Throwable $jEx) {
                Log::warning('Daily savings auto-journal warning: ' . $jEx->getMessage());
            }

            Cache::forget('dashboard_summary_data');
            Cache::forget('manager_dashboard_summary_data');

            DB::commit();

            return response()->json([
                'success' => true,
                'status'  => 'success',
                'message' => 'Transaksi tabungan harian berhasil disimpan.',
                'data'    => [
                    'member' => [
                        'id'             => $member->id,
                        'daily_savings'  => (float) $member->daily_savings,
                        'has_buku_putih' => (bool) $member->has_buku_putih,
                        'buku_putih'     => $member->buku_putih,
                    ],
                    'transaction' => $transaction,
                ]
            ], 200);

        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'success' => false,
                'status'  => 'error',
                'message' => 'Gagal memproses transaksi: ' . $e->getMessage(),
            ], 500);
        }
    }
}