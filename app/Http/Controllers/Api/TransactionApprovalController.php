<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Account;
use App\Models\Loan;
use App\Models\Member;
use App\Models\Transaction;
use App\Services\PeriodLockService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class TransactionApprovalController extends Controller
{
    /**
     * Memperbarui status transaksi (approved / rejected) oleh Admin atau Ketua.
     * Endpoint: PUT/PATCH /api/transactions/{id}/status
     *
     * @param Request $request
     * @param int|string $id
     * @return JsonResponse
     */
    public function updateTransactionStatus(Request $request, $id): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'status'      => 'required|string|in:approved,rejected,disetujui,ditolak',
            'description' => 'nullable|string|max:255',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validasi gagal',
                'data'    => $validator->errors(),
            ], 422);
        }

        try {
            $transaction = Transaction::with(['member', 'account'])->find($id);

            if (!$transaction) {
                return response()->json([
                    'success' => false,
                    'message' => 'Transaksi tidak ditemukan',
                    'data'    => null,
                ], 404);
            }
            // Normalisasi status ke format standar DB ('approved' / 'rejected')
            $statusInput = strtolower($request->status);
            $normalizedStatus = match ($statusInput) {
                'disetujui', 'approved' => 'approved',
                'ditolak', 'rejected'   => 'rejected',
                default                 => $statusInput,
            };

            // Update status transaksi
            $transaction->status = $normalizedStatus;
            
            if (auth()->check()) {
                $transaction->user_id = auth()->id();
            }

            if ($request->has('description') && !empty($request->description)) {
                $transaction->description = $request->description;
            }

            $transaction->save();

            $statusTextLabel = $normalizedStatus === 'approved' ? 'disetujui' : 'ditolak';

            return response()->json([
                'success' => true,
                'message' => "Status transaksi #{$transaction->transaction_number} berhasil diubah menjadi {$statusTextLabel}",
                'data'    => $transaction,
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Gagal memperbarui status transaksi: ' . $e->getMessage(),
                'data'    => null,
            ], 500);
        }
    }
    /**
     * Menyetujui pengajuan transaksi atau pinjaman oleh Manager/Pengurus.
     * Endpoint: POST /api/manager/approvals/{id}/approve
     *
     * @param int|string $id
     * @return JsonResponse
     */
    public function approveTransaction($id): JsonResponse
    {
        DB::beginTransaction();
        try {
            // 1. Cek di tabel Transactions
            $transaction = Transaction::find($id);
            if ($transaction) {
                if ($lockRes = PeriodLockService::validateDate($transaction->transaction_date)) {
                    DB::rollBack();
                    return $lockRes;
                }
                if ($transaction->status === 'approved') {
                    return response()->json([
                        'success' => false,
                        'message' => 'Transaksi sudah disetujui sebelumnya'
                    ], 400);
                }
                $transaction->status = 'approved';
                $transaction->approved_at = now();
                if (auth()->check()) {
                    $transaction->approved_by = auth()->id();
                }
                $transaction->save();
                // Ambil data member dan update saldo
                $member = Member::find($transaction->member_id);
                if ($member) {
                    $bookType = $transaction->book_type;
                    $desc     = strtolower($transaction->description ?? '');
                    $amount   = (float) $transaction->amount;
                    $type     = strtolower($transaction->type);

                    $isDeposit    = in_array($type, ['deposit', 'in', 'kas_masuk']);
                    $isWithdrawal = in_array($type, ['withdrawal', 'out', 'kas_keluar']);

                    if ($bookType === 'BUKU_PUTIH') {
                        $column = 'daily_savings';
                    } else {
                        if (str_contains($desc, 'pokok')) {
                            $column = 'principal_savings';
                        } elseif (str_contains($desc, 'wajib')) {
                            $column = 'mandatory_savings';
                        } else {
                            $column = 'voluntary_savings';
                        }
                    }
                    if ($isDeposit) {
                        $member->increment($column, $amount);
                    } elseif ($isWithdrawal) {
                        if ($column === 'daily_savings' || $bookType === 'BUKU_PUTIH') {
                            $currentDaily = (float) ($member->daily_savings ?? 0.00);
                            $hasBukuPutih = $member->has_buku_putih || ($currentDaily > 0);
                            if (!$hasBukuPutih) {
                                DB::rollBack();
                                return response()->json([
                                    'success' => false,
                                    'message' => 'Anggota belum memiliki rekening Buku Putih (Tabungan Harian).'
                                ], 400);
                            }
                            $maxWithdrawal = max(0.00, $currentDaily - 100000.00);
                            if ($amount > $maxWithdrawal || $currentDaily < 100000.00) {
                                DB::rollBack();
                                $formattedCurrent = number_format($currentDaily, 0, ',', '.');
                                return response()->json([
                                    'success' => false,
                                    'message' => "Gagal Penarikan: Saldo tidak mencukupi. Minimal saldo mengendap di Buku Putih adalah Rp 100.000. Saldo Anda saat ini: Rp {$formattedCurrent}."
                                ], 400);
                            }
                        } else {
                            $currentBal = (float) ($member->$column ?? 0.00);
                            if ($amount > $currentBal) {
                                DB::rollBack();
                                $formattedCurrent = number_format($currentBal, 0, ',', '.');
                                return response()->json([
                                    'success' => false,
                                    'message' => "Gagal Penarikan: Saldo tidak mencukupi. Saldo Anda saat ini: Rp {$formattedCurrent}."
                                ], 400);
                            }
                        }
                        $member->decrement($column, $amount);
                    }

                    // Auto-reactivate member if inactive
                    if ($member->status === 'inactive') {
                        $member->status = 'active';
                        $member->save();
                    }
                }
                DB::commit();

                return response()->json([
                    'success' => true,
                    'message' => 'Transaksi berhasil disetujui dan saldo anggota telah diperbarui',
                    'data'    => $transaction
                ], 200);
            }
            // 2. Cek di tabel Loans
            $loan = Loan::find($id);
            if ($loan) {
                if ($loan->status === 'approved') {
                    return response()->json([
                        'success' => false,
                        'message' => 'Pinjaman sudah disetujui sebelumnya'
                    ], 400);
                }
                $loan->status = 'approved';
                $loan->approved_at = now();
                if (auth()->check()) {
                    $loan->approved_by = auth()->id();
                }
                $loan->save();

                // Tambahkan transaksi pencairan pinjaman (Kas Keluar)
                $accountKas = Account::firstOrCreate(
                    ['account_number' => 'KAS-101'],
                    [
                        'account_name' => 'Kas Koperasi',
                        'account_type' => 'kas',
                        'category'     => 'asset',
                        'balance'      => 0.00,
                    ]
                );
                $trxNumber = 'TRX-' . date('Ymd') . '-' . str_pad((string) mt_rand(1, 9999), 4, '0', STR_PAD_LEFT);
                $disbTrx = Transaction::create([
                    'transaction_number' => $trxNumber,
                    'receipt_number'     => 'LNC-' . $loan->loan_code,
                    'member_id'          => $loan->member_id,
                    'account_id'         => $accountKas->id,
                    'book_type'          => 'BUKU_BIRU',
                    'operator_id'        => auth()->id(),
                    'approved_by'        => auth()->id(),
                    'type'               => 'withdrawal',
                    'amount'             => $loan->amount,
                    'beginning_balance'  => 0,
                    'ending_balance'     => 0,
                    'payment_method'     => 'cash',
                    'transaction_date'   => now()->toDateString(),
                    'description'        => 'Pencairan Pinjaman #' . $loan->loan_code,
                    'status'             => 'approved',
                    'approved_at'        => now(),
                ]);

                // Buat Jurnal Pencairan Pinjaman otomatis (DEBET: 1024, KREDIT: 1000)
                try {
                    app(\App\Services\JournalService::class)->generateJournal($disbTrx);
                } catch (\Throwable $jEx) {
                    \Illuminate\Support\Facades\Log::warning('[TransactionApprovalController] Gagal auto-jurnal pencairan: ' . $jEx->getMessage());
                }

                DB::commit();

                return response()->json([
                    'success' => true,
                    'message' => 'Pinjaman berhasil disetujui dan kas keluar pencairan dicatat',
                    'data'    => $loan
                ], 200);
            }

            return response()->json([
                'success' => false,
                'message' => 'Data pengajuan tidak ditemukan'
            ], 404);

        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'success' => false,
                'message' => 'Gagal menyetujui pengajuan: ' . $e->getMessage()
            ], 500);
        }
    }
    /**
     * Menolak pengajuan transaksi atau pinjaman oleh Manager/Pengurus.
     * Endpoint: POST /api/manager/approvals/{id}/reject
     *
     * @param int|string $id
     * @return JsonResponse
     */
    public function rejectTransaction($id): JsonResponse
    {
        try {
            // Cek di tabel Transactions
            $transaction = Transaction::find($id);
            if ($transaction) {
                if ($transaction->status === 'approved') {
                    return response()->json([
                        'success' => false,
                        'message' => 'Transaksi yang sudah disetujui tidak dapat ditolak'
                    ], 400);
                }

                $transaction->status = 'rejected';
                $transaction->save();

                return response()->json([
                    'success' => true,
                    'message' => 'Transaksi berhasil ditolak',
                    'data'    => $transaction
                ], 200);
            }

            // Cek di tabel Loans
            $loan = Loan::find($id);
            if ($loan) {
                if ($loan->status === 'approved') {
                    return response()->json([
                        'success' => false,
                        'message' => 'Pinjaman yang sudah disetujui tidak dapat ditolak'
                    ], 400);
                }

                $loan->status = 'rejected';
                $loan->save();

                return response()->json([
                    'success' => true,
                    'message' => 'Pinjaman berhasil ditolak',
                    'data'    => $loan
                ], 200);
            }

            return response()->json([
                'success' => false,
                'message' => 'Data pengajuan tidak ditemukan'
            ], 404);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Gagal menolak pengajuan: ' . $e->getMessage()
            ], 500);
        }
    }
}
