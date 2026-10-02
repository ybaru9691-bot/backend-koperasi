<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Account;
use App\Models\ChartOfAccount;
use App\Models\JournalDetail;
use App\Models\JournalEntry;
use App\Models\Loan;
use App\Models\LoanInstallment;
use App\Models\Member;
use App\Models\Transaction;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Cache;

class LoanController extends Controller
{
   

    private function cleanAmount(mixed $val): float
    {
        if (is_null($val) || $val === '') {
            return 0.0;
        }
        if (is_int($val) || is_float($val)) {
            return (float) $val;
        }

        $str = (string) $val;

        if (preg_match('/^\d+(\.\d+)?$/', $str)) {
            return (float) $str;
        }

        $str = str_ireplace(['rp', ' '], '', $str);

        if (str_contains($str, ',') && str_contains($str, '.')) {
            $str = str_replace('.', '', $str);
            $str = str_replace(',', '.', $str);
        } elseif (str_contains($str, ',')) {
            $str = preg_match('/,\d{2}$/', $str)
                ? str_replace(',', '.', $str)
                : str_replace(',', '', $str);
        } elseif (str_contains($str, '.')) {
            if (preg_match('/\.\d{3}$/', $str) || substr_count($str, '.') > 1) {
                $str = str_replace('.', '', $str);
            }
        }

        $clean = preg_replace('/[^\d.]/', '', $str);
        return is_numeric($clean) ? (float) $clean : 0.0;
    }

    /**
     * Ambil atau buat akun Kas default.
     */
    private function getKasAccount(): Account
    {
        return Account::firstOrCreate(
            ['account_type' => 'kas'],
            [
                'account_number' => 'KAS-101',
                'account_name'   => 'Kas Koperasi',
                'account_type'   => 'kas',
                'category'       => 'asset',
                'balance'        => 0.00,
            ]
        );
    }

    /**
     * Generate kode pinjaman unik: LOAN-YYYYMMDD-XXXX
     */
    private function generateUniqueLoanCode(): string
    {
        do {
            $code = 'LOAN-' . date('Ymd') . '-' . str_pad((string) mt_rand(1000, 9999), 4, '0', STR_PAD_LEFT);
        } while (Loan::where('loan_code', $code)->exists());

        return $code;
    }

    // PENGAJUAN PINJAMAN

    /**
     * Anggota mengajukan pinjaman baru.
     * Endpoint: POST /api/user/loans/apply
     *
     * - Hanya Anggota Buku Biru yang berhak.
     * - Suku Bunga default: 2.50% per bulan (Saldo Menurun / Declining Balance).
     * - Belum generate jadwal cicilan — dibuat saat Manajer menyetujui.
     */
    public function applyLoan(Request $request): JsonResponse
    {
        $appDate = $request->input('application_date', now()->toDateString());
        if ($lockRes = \App\Services\PeriodLockService::validateDate($appDate)) {
            return $lockRes;
        }

        $request->validate([
            'amount'           => 'required',
            'tenor'            => 'nullable|integer|min:1|max:360',
            'duration_months'  => 'nullable|integer|min:1|max:360',
            'notes'            => 'nullable|string',
            'purpose'          => 'nullable|string|max:255',
            'collateral'       => 'nullable|string|max:255',
            'interest_method'  => 'nullable|string|in:declining_balance,flat',
            'interest_rate'    => 'nullable|numeric|min:0|max:100',
        ]);

        $authUser = $request->user();

        // Tentukan member_id
        $memberId = null;
        if ($authUser instanceof Member) {
            $memberId = $authUser->id;
        } else {
            $memberId = $request->input('member_id')
                ?? Member::where('user_id', $authUser->id)->value('id');
        }

        if (!$memberId) {
            return response()->json([
                'success' => false,
                'message' => 'Member ID tidak valid atau Anda bukan anggota.',
            ], 403);
        }

        $member = Member::find($memberId);
        if (!$member) {
            return response()->json([
                'success' => false,
                'message' => 'Anggota tidak ditemukan.',
            ], 404);
        }

        // Validasi: hanya Buku Biru yang bisa pinjam
        if (!$member->has_buku_biru) {
            return response()->json([
                'success' => false,
                'message' => 'Aksi ditolak: Fasilitas pinjaman hanya diperuntukkan bagi Anggota Penuh (Buku Biru).',
            ], 403);
        }

        // Validasi: Cegah pengajuan ganda jika masih ada pinjaman yang sedang diproses atau belum lunas
        $activeOrPendingLoan = Loan::where('member_id', $member->id)
            ->whereIn('status', [
                'pending_admin', 'pending', 'WAITING_ADMIN_VERIFICATION',
                'pending_manager', 'WAITING_MANAGER_APPROVAL', 'menunggu_ketua',
                'APPROVED_BY_MANAGER', 'approved', 'APPROVED',
                'DISBURSED', 'disbursed', 'active', 'ACTIVE'
            ])
            ->where(function ($q) {
                $q->whereNull('remaining_principal')
                  ->orWhere('remaining_principal', '>', 0)
                  ->orWhereIn('status', [
                      'pending_admin', 'pending', 'WAITING_ADMIN_VERIFICATION',
                      'pending_manager', 'WAITING_MANAGER_APPROVAL', 'menunggu_ketua',
                      'APPROVED_BY_MANAGER', 'approved', 'APPROVED'
                  ]);
            })
            ->first();

        if ($activeOrPendingLoan) {
            return response()->json([
                'success'      => false,
                'message'      => 'Anda masih memiliki pengajuan pinjaman yang sedang diproses atau belum lunas.',
                'current_loan' => [
                    'id'                  => $activeOrPendingLoan->id,
                    'loan_code'           => $activeOrPendingLoan->loan_code,
                    'status'              => $activeOrPendingLoan->status,
                    'amount'              => (float) $activeOrPendingLoan->amount,
                    'remaining_principal' => (float) ($activeOrPendingLoan->remaining_principal ?? $activeOrPendingLoan->amount),
                ],
            ], 422);
        }

        $amount = $this->cleanAmount($request->input('amount'));
        if ($amount <= 0) {
            return response()->json([
                'success' => false,
                'message' => 'Nominal pinjaman harus lebih besar dari 0.',
            ], 422);
        }

        $tenor          = (int) ($request->input('tenor') ?? $request->input('duration_months') ?? 12);
        $interestMethod = $request->input('interest_method', 'declining_balance');
        $defaultRate    = ($interestMethod === 'flat') ? 1.00 : 2.50;
        $interestRate   = $request->has('interest_rate')
            ? (float) $request->input('interest_rate')
            : $defaultRate;

        // Estimasi angsuran bulan pertama
        $principalChunk      = (float) ceil($amount / $tenor);
        $firstMonthInterest  = round($amount * ($interestRate / 100), 0);
        $monthlyInstallment  = $principalChunk + $firstMonthInterest;

        $loan = Loan::create([
            'loan_code'           => $this->generateUniqueLoanCode(),
            'member_id'           => $memberId,
            'amount'              => $amount,
            'interest_rate'       => $interestRate,
            'interest_method'     => $interestMethod,
            'duration_months'     => $tenor,
            'tenor_months'        => $tenor,
            'monthly_installment' => $monthlyInstallment,
            'remaining_amount'    => $amount,
            'remaining_principal' => $amount,
            'status'              => 'pending_admin',
            'application_date'    => $appDate,
            'notes'               => $request->input('notes'),
            'purpose'             => $request->input('purpose'),
            'collateral'          => $request->input('collateral'),
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Pengajuan pinjaman berhasil dibuat. Menunggu persetujuan Admin.',
            'data'    => $loan,
        ], 201);
    }

    // DAFTAR PENGAJUAN PENDING

    /**
     * Daftar pengajuan yang menunggu persetujuan (pending_admin / pending_manager / WAITING_MANAGER_APPROVAL).
     * Endpoint: GET /api/admin/loans/pending
     */
    public function pendingLoans(Request $request): JsonResponse
    {
        $loans = Loan::with('member')
            ->whereIn('status', ['pending_admin', 'pending_manager', 'WAITING_ADMIN_VERIFICATION', 'WAITING_MANAGER_APPROVAL', 'pending', 'menunggu_ketua'])
            ->latest()
            ->get()
            ->map(function (Loan $loan) {
                $amount = (float) $loan->amount;
                $tenor = (int) ($loan->tenor_months ?? $loan->duration_months ?? $loan->tenor ?? 12);
                $member = $loan->member;
                $sisaPokok = (float) ($loan->remaining_principal ?? $amount);

                return [
                    'id'                  => $loan->id,
                    'loan_id'             => $loan->id,
                    'loan_code'           => $loan->loan_code,
                    'loan_number'         => $loan->loan_code,
                    'no_sh'               => $loan->loan_code,
                    'member_id'           => $loan->member_id,
                    'member_name'         => $member?->name ?? '',
                    'nama_anggota'        => $member?->name ?? '',
                    'member_no'           => $member?->member_number ?? '',
                    'no_anggota'          => $member?->member_number ?? '',
                    'original_amount'     => $amount,
                    'plafon_awal'         => $amount,
                    'amount'              => $amount,
                    'plafon'              => $amount,
                    'nominal'             => $amount,
                    'total_amount'        => $amount,
                    'loan_amount'         => $amount,
                    'remaining_principal' => $sisaPokok,
                    'sisa_saldo_pokok'    => $sisaPokok,
                    'sisa_pokok'          => $sisaPokok,
                    'remaining_balance'   => $sisaPokok,
                    'outstanding_principal' => $sisaPokok,
                    'monthly_installment' => (float) ($loan->monthly_installment ?? 0),
                    'angsuran_bulanan'    => (float) ($loan->monthly_installment ?? 0),
                    'tenor_months'        => $tenor,
                    'duration_months'     => $tenor,
                    'tenor'               => $tenor,
                    'interest_rate'       => (float) $loan->interest_rate,
                    'interest_method'     => $loan->interest_method ?? 'declining_balance',
                    'purpose'             => $loan->purpose ?? $loan->notes ?? '',
                    'notes'               => $loan->notes ?? $loan->purpose ?? '',
                    'collateral'          => $loan->collateral ?? '',
                    'status'              => $loan->status,
                    'application_date'    => $loan->application_date,
                    'created_at'          => $loan->created_at?->toIso8601String(),
                    'member'              => $member ? [
                        'id'            => $member->id,
                        'name'          => $member->name,
                        'nama'          => $member->name,
                        'member_number' => $member->member_number,
                        'no_anggota'    => $member->member_number,
                        'phone'         => $member->phone,
                        'address'       => $member->address,
                    ] : null,
                ];
            });

        return response()->json([
            'success' => true,
            'message' => 'Data pengajuan pinjaman berhasil diambil.',
            'data'    => $loans,
        ], 200);
    }

    // PERSETUJUAN TINGKAT 1 (ADMIN)

    /**
     * Admin Level-1 menyetujui / memverifikasi pengajuan (status: WAITING_ADMIN_VERIFICATION → WAITING_MANAGER_APPROVAL).
     * Endpoint: POST /api/admin/loans/{id}/approve-admin & POST /api/admin/loans/{id}/verify
     */
    public function approveByAdmin(Request $request, int $id): JsonResponse
    {
        return $this->verify($request, $id);
    }

    // PERSETUJUAN TINGKAT 2 (MANAJER)
    /**
     * Manajer/Ketua menyetujui pinjaman (Level 2 - ACC).
     * Endpoint: POST /api/loans/{id}/approve-manager & POST /api/manager/loans/{id}/approve
     */
    public function approveByManager(Request $request, int $id): JsonResponse
    {
        $authUser = $request->user();
        if (!$authUser || !in_array(strtolower($authUser->role ?? ''), ['manager', 'ketua', 'pengurus'])) {
            return response()->json([
                'success' => false,
                'status'  => 'error',
                'message' => 'Hanya Manajer/Ketua yang berhak memberikan persetujuan pinjaman.',
            ], 403);
        }

        $loan = Loan::find($id);

        if (!$loan) {
            return response()->json([
                'success' => false,
                'message' => 'Data pengajuan pinjaman tidak ditemukan.',
            ], 404);
        }

        if (in_array(strtoupper($loan->status), ['APPROVED_BY_MANAGER', 'APPROVED', 'DISBURSED', 'ACTIVE'])) {
            return response()->json([
                'success' => false,
                'message' => 'Pinjaman sudah disetujui sebelumnya.',
            ], 400);
        }

        $allowedStatuses = ['WAITING_MANAGER_APPROVAL', 'pending_manager', 'pending_admin', 'verifikasi_admin', 'WAITING_ADMIN_VERIFICATION', 'pending'];
        if (!in_array($loan->status, $allowedStatuses) && !in_array(strtoupper($loan->status), $allowedStatuses)) {
            return response()->json([
                'success' => false,
                'message' => "Status pinjaman tidak dapat disetujui manajer. Status saat ini: {$loan->status}.",
            ], 400);
        }

        // Validasi Kepemilikan & Kelayakan Buku Biru
        $member = $loan->member;
        if (!$member || !$member->has_buku_biru) {
            return response()->json([
                'success' => false,
                'message' => 'Aksi ditolak: Peminjam harus merupakan Anggota Penuh (Buku Biru).',
            ], 422);
        }

        if ($lockRes = \App\Services\PeriodLockService::validateDate(now()->toDateString())) {
            return $lockRes;
        }

        DB::beginTransaction();
        try {
            $now = Carbon::now();
            $tenor = (int) ($loan->tenor_months ?? $loan->duration_months ?? 12);

            // 1. Update status pinjaman menjadi APPROVED_BY_MANAGER
            $loan->update([
                'status'              => 'APPROVED_BY_MANAGER',
                'approved_at'         => $now,
                'approved_by'         => $authUser->id,
                'manager_approved_at' => $now,
                'manager_approved_by' => $authUser->id,
                'tenor_months'        => $tenor,
                'notes'               => $request->input('notes') ?? $loan->notes,
            ]);

            // 2. Generate jadwal cicilan saldo menurun agar siap ditinjau/dicairkan
            $this->generateInstallmentRows($loan, $now);

            // 3. Jika request meminta auto_disburse (atau kompatibilitas eksekusi langsung)
            if ($request->boolean('auto_disburse')) {
                $this->executeDisbursement($loan, $authUser, $now);
            }

            DB::commit();

            return response()->json([
                'success' => true,
                'status'  => 'success',
                'message' => 'Persetujuan pinjaman oleh Manajer berhasil.',
                'data'    => $loan->fresh(['installments', 'member']),
            ], 200);

        } catch (\Exception $e) {
            DB::rollBack();
            Log::error("[LoanController] Gagal approve manajer loan #{$id}: " . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Gagal memproses persetujuan manajer: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Manajer menolak pengajuan pinjaman.
     * Endpoint: POST /api/loans/{id}/reject-manager
     */
    public function rejectByManager(Request $request, int $id): JsonResponse
    {
        $authUser = $request->user();
        if (!$authUser || !in_array(strtolower($authUser->role ?? ''), ['manager', 'ketua', 'pengurus'])) {
            return response()->json([
                'success' => false,
                'status'  => 'error',
                'message' => 'Hanya Manajer/Ketua yang berhak memberikan persetujuan pinjaman.',
            ], 403);
        }

        return $this->rejectLoan($request, $id, 'REJECTED_BY_MANAGER');
    }

    /**
     * Tolak pengajuan pinjaman (Umum / Admin / Manajer).
     * Endpoint: POST /api/admin/loans/{id}/reject & POST /api/loans/{id}/reject
     */
    public function rejectLoan(Request $request, int $id, string $targetStatus = 'rejected'): JsonResponse
    {
        $request->validate([
            'rejection_reason' => 'nullable|string|max:255',
            'reason'           => 'nullable|string|max:255',
            'notes'            => 'nullable|string|max:255',
        ]);

        $loan = Loan::find($id);

        if (!$loan) {
            return response()->json([
                'success' => false,
                'message' => 'Data pengajuan pinjaman tidak ditemukan.',
            ], 404);
        }

        if (in_array(strtoupper($loan->status), ['DISBURSED', 'ACTIVE', 'PAID_OFF', 'COMPLETED'])) {
            return response()->json([
                'success' => false,
                'message' => "Pinjaman dengan status {$loan->status} tidak dapat ditolak.",
            ], 400);
        }

        $reason = $request->input('rejection_reason') ?? $request->input('reason') ?? $request->input('notes') ?? 'Pengajuan ditolak.';
        $loan->update([
            'status' => $targetStatus,
            'notes'  => $reason,
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Pengajuan pinjaman berhasil ditolak.',
            'data'    => $loan,
        ], 200);
    }

    /**
     * Eksekusi Pencairan Dana Pinjaman oleh Admin (Disburse).
     * Endpoint: POST /api/loans/{id}/disburse & POST /api/admin/loans/{id}/disburse
     */
    public function disburse(Request $request, int $id): JsonResponse
    {
        $loan = Loan::with('member')->find($id);

        if (!$loan) {
            return response()->json([
                'success' => false,
                'message' => 'Data pengajuan pinjaman tidak ditemukan.',
            ], 404);
        }

        // Validasi: Status pinjaman WAJIB APPROVED_BY_MANAGER / approved
        $allowedStatuses = ['APPROVED_BY_MANAGER', 'APPROVED', 'approved_by_manager', 'approved'];
        if (!in_array($loan->status, $allowedStatuses) && !in_array(strtoupper($loan->status), $allowedStatuses)) {
            return response()->json([
                'success' => false,
                'status'  => 'error',
                'message' => 'Pencairan dana ditolak: Pinjaman belum disetujui oleh Manajer.',
            ], 400);
        }

        if (in_array(strtoupper($loan->status), ['DISBURSED', 'ACTIVE', 'PAID_OFF', 'COMPLETED'])) {
            return response()->json([
                'success' => false,
                'message' => 'Dana pinjaman sudah dicairkan sebelumnya.',
            ], 400);
        }

        $rawDisbDate = $request->input('disbursement_date') ?? now()->toDateString();
        $disbDate = Carbon::parse($rawDisbDate);

        if ($lockRes = \App\Services\PeriodLockService::validateDate($disbDate)) {
            return $lockRes;
        }

        DB::beginTransaction();
        try {
            $authUser = $request->user();
            $this->executeDisbursement($loan, $authUser, $disbDate);
            DB::commit();

            return response()->json([
                'success' => true,
                'status'  => 'success',
                'message' => 'Pencairan pinjaman berhasil diproses dan dana telah diserahkan.',
                'data'    => $loan->fresh(['installments', 'member']),
            ], 200);

        } catch (\Exception $e) {
            DB::rollBack();
            Log::error("[LoanController] Gagal disburse loan #{$id}: " . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Gagal memproses pencairan pinjaman: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Helper eksekusi pencairan kas & pembukuan jurnal
     */
    private function executeDisbursement(Loan $loan, mixed $authUser, Carbon $disbDate): void
    {
        $tenor = (int) ($loan->tenor_months ?? $loan->duration_months ?? 12);

        $loan->update([
            'status'            => 'DISBURSED',
            'disbursement_date' => $disbDate->toDateString(),
            'disbursed_at'      => $disbDate,
            'disbursed_by'      => $authUser?->id,
            'due_date'          => $disbDate->copy()->addMonths($tenor)->toDateString(),
        ]);

        // Catat Kas Keluar (Pencairan Pinjaman)
        $kasAccount = $this->getKasAccount();
        $memberName = $loan->member?->name ?? 'Anggota';
        $trxNumber  = 'KK-' . date('Ymd') . '-' . rand(1000, 9999);
        $receiptNo  = 'KK-' . $loan->loan_code;

        $disbTrx = Transaction::withoutPeriodLock(function () use ($loan, $kasAccount, $authUser, $disbDate, $trxNumber, $receiptNo, $memberName) {
            return Transaction::create([
                'transaction_number' => $trxNumber,
                'receipt_number'     => $receiptNo,
                'member_id'          => $loan->member_id,
                'account_id'         => $kasAccount->id,
                'book_type'          => 'BUKU_BIRU',
                'category'           => 'pencairan_pinjaman',
                'operator_id'        => $authUser?->id,
                'approved_by'        => $authUser?->id,
                'type'               => 'withdrawal',
                'amount'             => $loan->amount,
                'beginning_balance'  => (float) $kasAccount->balance,
                'ending_balance'     => max(0.0, (float) $kasAccount->balance - $loan->amount),
                'payment_method'     => 'cash',
                'transaction_date'   => $disbDate->toDateString(),
                'description'        => "Pencairan Pinjaman ({$loan->loan_code}) - {$memberName}",
                'status'             => 'approved',
                'approved_at'        => $disbDate,
            ]);
        });

        $kasAccount->decrement('balance', $loan->amount);

        // Jurnal Pencairan: Debet Piutang Pinjaman 1024, Kredit Kas 1000
        $this->recordDisbursementJournal($disbTrx, $loan, $authUser?->id);

        // Pastikan jadwal angsuran sudah digenerate
        if ($loan->installments()->count() === 0) {
            $this->generateInstallmentRows($loan, $disbDate);
        }
    }

    // KARTU PINJAMAN KUNING (GET /api/loans/{id}/card)

    /**
     * Kembalikan data Kartu Pinjaman Kuning CUM PELITA secara lengkap.
     * Endpoint: GET /api/loans/{id}/card
     *
     * Respons mencakup:
     *   - Header: No. Anggota, Nama, Alamat/HP, Plafon, Tenor, Suku Bunga, Agunan
     *   - Tabel: No. Bukti | Angsuran Ke- | Sisa Pokok Awal | Angsuran Pokok |
    // DETAIL & KARTU PINJAMAN KUNING (GET /api/loans/{id}, GET /api/loans/{id}/card, GET /api/loans/{id}/installments)

    /**
     * Tampilkan detail satu pinjaman beserta anggota dan jadwal angsuran.
     * Endpoint: GET /api/loans/{id} & GET /api/admin/loans/{id}
     */
    public function show(int $id): JsonResponse
    {
        $loan = Loan::with(['member', 'installments.teller', 'approver'])->find($id);

        if (!$loan) {
            return response()->json([
                'success' => false,
                'message' => 'Data pinjaman tidak ditemukan.',
            ], 404);
        }

        $loan->recalculateSchedule();
        $loan->load(['member', 'installments.teller', 'approver']);

        $originalAmount = (float) $loan->amount;
        $totalPaidPrincipal = (float) $loan->installments->where('status', 'paid')->sum('principal_amount');
        $remainingBalance = (float) ($loan->remaining_principal ?? 0);

        $installments = $loan->installments->sortBy(function ($inst) {
            return $inst->paid_at ? $inst->paid_at->timestamp : ($inst->due_date ? $inst->due_date->timestamp : $inst->installment_number);
        })->values()->map(function ($inst) {
            $dateStr = $inst->paid_at?->format('Y-m-d') ?? $inst->due_date?->format('Y-m-d') ?? null;
            $tellerName = $inst->teller?->name ?? $inst->paidByUser?->name ?? 'Kasir';
            return [
                'id'                 => $inst->id,
                'loan_id'            => $inst->loan_id,
                'date'               => $dateStr,
                'transaction_date'   => $dateStr,
                'receipt_number'     => $inst->receipt_number ?? '-',
                'no_bukti'           => $inst->receipt_number ?? '-',
                'installment_order'  => (int) $inst->installment_number,
                'installment_number' => (int) $inst->installment_number,
                'angsuran_ke'        => (int) $inst->installment_number,
                'principal_amount'   => (float) $inst->principal_amount,
                'angsuran_pokok'     => (float) $inst->principal_amount,
                'interest_amount'    => (float) $inst->interest_amount,
                'jasa_pinjaman'      => (float) $inst->interest_amount,
                'late_fee'           => (float) ($inst->penalty_fee ?? $inst->penalty_amount ?? 0),
                'penalty_amount'     => (float) ($inst->penalty_fee ?? $inst->penalty_amount ?? 0),
                'denda'              => (float) ($inst->penalty_fee ?? $inst->penalty_amount ?? 0),
                'teller_name'        => $tellerName,
                'paraf'              => $tellerName,
                'beginning_balance'  => (float) ($inst->beginning_balance ?? 0),
                'sisa_pokok_awal'    => (float) ($inst->beginning_balance ?? 0),
                'ending_balance'     => (float) ($inst->ending_balance ?? 0),
                'sisa_pokok_akhir'   => (float) ($inst->ending_balance ?? 0),
                'total_amount'       => (float) $inst->total_amount,
                'total_bayar'        => (float) $inst->total_amount,
                'due_date'           => $inst->due_date?->format('Y-m-d'),
                'paid_at'            => $inst->paid_at?->format('Y-m-d H:i'),
                'status'             => $inst->status,
                'notes'              => $inst->notes,
            ];
        });

        $member = $loan->member;
        $memberData = [
            'id'            => $member?->id,
            'name'          => $member?->name ?? '-',
            'nama'          => $member?->name ?? '-',
            'member_number' => $member?->member_number ?? '-',
            'no_anggota'    => $member?->member_number ?? '-',
            'phone'         => $member?->phone ?? '-',
            'telepon'       => $member?->phone ?? '-',
            'no_hp'         => $member?->phone ?? '-',
            'address'       => $member?->address ?? '-',
            'alamat'        => $member?->address ?? '-',
        ];

        $dueDateStr = $loan->due_date ? (is_string($loan->due_date) ? substr($loan->due_date, 0, 10) : $loan->due_date->format('Y-m-d')) : null;

        $responseData = array_merge($loan->toArray(), [
            'loan_id'               => $loan->id,
            'loan_number'           => $loan->loan_code,
            'no_sh'                 => $loan->loan_code,
            'member'                => $memberData,
            'member_name'           => $member?->name ?? '-',
            'nama_anggota'          => $member?->name ?? '-',
            'member_no'             => $member?->member_number ?? '-',
            'no_anggota'            => $member?->member_number ?? '-',
            'original_amount'       => $originalAmount,
            'plafon_awal'           => $originalAmount,
            'amount'                => $originalAmount,
            'plafon'                => $originalAmount,
            'nominal'               => $originalAmount,
            'total_amount'          => $originalAmount,
            'loan_amount'           => $originalAmount,
            'monthly_installment'   => (float) ($loan->monthly_installment ?? 0),
            'angsuran_bulanan'      => (float) ($loan->monthly_installment ?? 0),
            'interest_rate'         => (float) $loan->interest_rate,
            'duration_months'       => (int) ($loan->duration_months ?? $loan->tenor_months ?? 12),
            'tenor_months'          => (int) ($loan->tenor_months ?? $loan->duration_months ?? 12),
            'tenor'                 => (int) ($loan->tenor_months ?? $loan->duration_months ?? 12),
            'due_date'              => $dueDateStr,
            'collateral_type'       => $loan->collateral ?? '-',
            'collateral'            => $loan->collateral ?? '-',
            'remaining_balance'     => $remainingBalance,
            'remaining_principal'   => $remainingBalance,
            'sisa_saldo_pokok'      => $remainingBalance,
            'sisa_pokok'            => $remainingBalance,
            'remaining_amount'      => $remainingBalance,
            'outstanding_principal' => $remainingBalance,
            'total_principal_paid'  => $totalPaidPrincipal,
            'total_pokok_terbayar'  => $totalPaidPrincipal,
            'installments'          => $installments,
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Detail pinjaman berhasil diambil.',
            'data'    => $responseData,
        ], 200);
    }

    /**
     * Tampilkan riwayat jadwal angsuran pinjaman.
     * Endpoint: GET /api/loans/{id}/installments & GET /api/admin/loans/{id}/installments
     */
    public function getInstallments(int $id): JsonResponse
    {
        $loan = Loan::with('installments.teller')->find($id);

        if (!$loan) {
            return response()->json([
                'success' => false,
                'message' => 'Data pinjaman tidak ditemukan.',
            ], 404);
        }

        $loan->recalculateSchedule();
        $loan->load('installments.teller');

        $installments = $loan->installments->sortBy(function ($inst) {
            return $inst->paid_at ? $inst->paid_at->timestamp : ($inst->due_date ? $inst->due_date->timestamp : $inst->installment_number);
        })->values()->map(function (LoanInstallment $inst) {
            $dateStr = $inst->paid_at?->format('Y-m-d') ?? $inst->due_date?->format('Y-m-d') ?? null;
            $tellerName = $inst->teller?->name ?? $inst->paidByUser?->name ?? 'Kasir';
            return [
                'id'                 => $inst->id,
                'loan_id'            => $inst->loan_id,
                'date'               => $dateStr,
                'transaction_date'   => $dateStr,
                'receipt_number'     => $inst->receipt_number ?? '-',
                'no_bukti'           => $inst->receipt_number ?? '-',
                'installment_order'  => (int) $inst->installment_number,
                'installment_number' => (int) $inst->installment_number,
                'angsuran_ke'        => (int) $inst->installment_number,
                'principal_amount'   => (float) $inst->principal_amount,
                'angsuran_pokok'     => (float) $inst->principal_amount,
                'interest_amount'    => (float) $inst->interest_amount,
                'jasa_pinjaman'      => (float) $inst->interest_amount,
                'late_fee'           => (float) ($inst->penalty_fee ?? $inst->penalty_amount ?? 0),
                'denda'              => (float) ($inst->penalty_fee ?? $inst->penalty_amount ?? 0),
                'penalty_amount'     => (float) ($inst->penalty_fee ?? $inst->penalty_amount ?? 0),
                'teller_name'        => $tellerName,
                'paraf'              => $tellerName,
                'beginning_balance'  => (float) ($inst->beginning_balance ?? 0),
                'sisa_pokok_awal'    => (float) ($inst->beginning_balance ?? 0),
                'ending_balance'     => (float) ($inst->ending_balance ?? 0),
                'sisa_pokok_akhir'   => (float) ($inst->ending_balance ?? 0),
                'total_amount'       => (float) $inst->total_amount,
                'total_bayar'        => (float) $inst->total_amount,
                'due_date'           => $inst->due_date?->format('Y-m-d'),
                'paid_at'            => $inst->paid_at?->format('Y-m-d H:i'),
                'status'             => $inst->status,
                'notes'              => $inst->notes,
            ];
        });

        return response()->json([
            'success' => true,
            'message' => 'Daftar angsuran berhasil diambil.',
            'data'    => $installments,
        ], 200);
    }

    /**
     * Kembalikan data Kartu Pinjaman Kuning CUM PELITA secara lengkap.
     * Endpoint: GET /api/loans/{id}/card & GET /api/admin/loans/{id}/card
     *
     * Respons mencakup:
     *   - Header: No. Anggota, Nama, Alamat/HP, Plafon, Tenor, Suku Bunga, Agunan
     *   - Tabel: No. Bukti | Angsuran Ke- | Sisa Pokok Awal | Angsuran Pokok |
     *            Sisa Pokok Akhir | Jasa 2.5% | Denda | Total Bayar | Status | Paraf
     */
    /**
     * Helper penyedia data kartu pinjaman terpadu (Single Source of Truth untuk Web API dan Cetak PDF).
     */
    private function buildLoanCardData(Loan $loan): array
    {
        $loan->recalculateSchedule();
        $loan->load(['member', 'installments.teller', 'installments.paidByUser']);

        $member = $loan->member;
        $tenor  = (int) ($loan->tenor_months ?? $loan->duration_months ?? 12);
        $originalAmount = (float) $loan->amount;
        $totalPaidPrincipal = (float) $loan->installments->where('status', 'paid')->sum('principal_amount');
        $remainingBalance = (float) ($loan->remaining_principal ?? 0);

        $dueDateStr = $loan->due_date ? (is_string($loan->due_date) ? substr($loan->due_date, 0, 10) : $loan->due_date->format('Y-m-d')) : null;

        // Header Kartu Kuning
        $header = [
            'loan_id'                   => $loan->id,
            'loan_code'                 => $loan->loan_code,
            'loan_number'               => $loan->loan_code,
            'no_sh'                     => $loan->loan_code,
            'member'                    => [
                'id'            => $member?->id,
                'name'          => $member?->name ?? '-',
                'nama'          => $member?->name ?? '-',
                'member_number' => $member?->member_number ?? '-',
                'no_anggota'    => $member?->member_number ?? '-',
                'phone'         => $member?->phone ?? '-',
                'telepon'       => $member?->phone ?? '-',
                'no_hp'         => $member?->phone ?? '-',
                'address'       => $member?->address ?? '-',
                'alamat'        => $member?->address ?? '-',
            ],
            'no_anggota'                => $member?->member_number ?? '-',
            'nama_anggota'              => $member?->name ?? '-',
            'alamat'                    => $member?->address ?? '-',
            'no_hp'                     => $member?->phone ?? '-',
            'telepon'                   => $member?->phone ?? '-',
            'original_amount'           => $originalAmount,
            'plafon_awal'               => $originalAmount,
            'amount'                    => $originalAmount,
            'plafon'                    => $originalAmount,
            'plafon_pinjaman'           => $originalAmount,
            'duration_months'           => $tenor,
            'tenor_months'              => $tenor,
            'tenor_bulan'               => $tenor,
            'interest_rate'             => (float) $loan->interest_rate,
            'suku_bunga'                => (float) $loan->interest_rate,
            'interest_method'           => $loan->interest_method ?? 'declining_balance',
            'collateral_type'           => $loan->collateral ?? '-',
            'collateral'                => $loan->collateral ?? '-',
            'agunan'                    => $loan->collateral ?? '-',
            'jaminan'                   => $loan->collateral ?? '-',
            'keperluan'                 => $loan->purpose ?? '-',
            'status'                    => $loan->status,
            'tanggal_pengajuan'         => $loan->application_date,
            'tanggal_cair'              => $loan->disbursement_date,
            'due_date'                  => $dueDateStr,
            'tanggal_jatuh_tempo_akhir' => $dueDateStr,
            'remaining_balance'         => $remainingBalance,
            'remaining_principal'       => $remainingBalance,
            'sisa_saldo_pokok'          => $remainingBalance,
            'sisa_pokok'                => $remainingBalance,
            'outstanding_principal'     => $remainingBalance,
            'total_paid_principal'      => $totalPaidPrincipal,
            'total_pokok_terbayar'      => $totalPaidPrincipal,
        ];

        // Tabel Angsuran
        $rows = $loan->installments->sortBy(function ($inst) {
            return $inst->paid_at ? $inst->paid_at->timestamp : ($inst->due_date ? $inst->due_date->timestamp : $inst->installment_number);
        })->values()->map(function (LoanInstallment $inst) {
            $dateStr = $inst->paid_at?->format('Y-m-d') ?? $inst->due_date?->format('Y-m-d') ?? null;
            $tellerName = $inst->teller?->name ?? $inst->paidByUser?->name ?? 'Kasir';
            return [
                'id'                  => $inst->id,
                'loan_id'             => $inst->loan_id,
                'date'                => $dateStr,
                'transaction_date'    => $dateStr,
                'receipt_number'      => $inst->receipt_number ?? '-',
                'no_bukti'            => $inst->receipt_number ?? '-',
                'installment_order'   => (int) $inst->installment_number,
                'angsuran_ke'         => (int) $inst->installment_number,
                'installment_number'  => (int) $inst->installment_number,
                'principal_amount'    => (float) $inst->principal_amount,
                'angsuran_pokok'      => (float) $inst->principal_amount,
                'interest_amount'     => (float) $inst->interest_amount,
                'jasa_pinjaman'       => (float) $inst->interest_amount,
                'late_fee'            => (float) ($inst->penalty_fee ?? $inst->penalty_amount ?? 0),
                'denda'               => (float) ($inst->penalty_fee ?? $inst->penalty_amount ?? 0),
                'penalty_amount'      => (float) ($inst->penalty_fee ?? $inst->penalty_amount ?? 0),
                'teller_name'         => $tellerName,
                'paraf'               => $tellerName,
                'beginning_balance'   => (float) ($inst->beginning_balance ?? 0),
                'sisa_pokok_awal'     => (float) ($inst->beginning_balance ?? 0),
                'ending_balance'      => (float) ($inst->ending_balance ?? 0),
                'sisa_pokok_akhir'    => (float) ($inst->ending_balance ?? 0),
                'total_amount'        => (float) $inst->total_amount,
                'total_bayar'         => (float) $inst->total_amount,
                'due_date'            => $inst->due_date?->format('Y-m-d'),
                'jatuh_tempo'         => $inst->due_date?->format('Y-m-d'),
                'tanggal_bayar'       => $inst->paid_at?->format('Y-m-d H:i'),
                'paid_at'             => $inst->paid_at?->format('Y-m-d H:i'),
                'status'              => $inst->status,
                'notes'               => $inst->notes,
            ];
        })->values()->all();

        $summary = [
            'total_angsuran_selesai' => $loan->installments->where('status', 'paid')->count(),
            'total_angsuran_unpaid'  => $loan->installments->where('status', 'unpaid')->count(),
            'total_jasa_keseluruhan' => (float) $loan->installments->sum('interest_amount'),
            'total_pokok_terbayar'   => $totalPaidPrincipal,
            'remaining_balance'      => $remainingBalance,
        ];

        return [
            'header'       => $header,
            'installments' => $rows,
            'summary'      => $summary,
        ];
    }

    /**
     * Kembalikan data Kartu Pinjaman Kuning CUM PELITA secara lengkap.
     * Endpoint: GET /api/loans/{id}/card & GET /api/admin/loans/{id}/card
     */
    public function getLoanCard(int $id): JsonResponse
    {
        $loan = Loan::with(['member', 'installments.teller'])->find($id);

        if (!$loan) {
            return response()->json([
                'success' => false,
                'message' => 'Data pinjaman tidak ditemukan.',
            ], 404);
        }

        $cardData = $this->buildLoanCardData($loan);

        return response()->json([
            'success' => true,
            'message' => 'Kartu pinjaman berhasil diambil.',
            'data'    => array_merge($cardData['header'], [
                'header'       => $cardData['header'],
                'installments' => $cardData['installments'],
                'summary'      => $cardData['summary'],
            ]),
        ], 200);
    }

    /**
     * Mengambil daftar pilihan pinjaman aktif untuk dropdown frontend.
     * Endpoint: GET /api/loans/dropdown-list & GET /api/admin/loans/dropdown-list
     */
    public function getLoanDropdownList(Request $request): JsonResponse
    {
        $loans = Loan::with('member')
            ->whereIn('status', [
                'active', 'ACTIVE',
                'approved', 'APPROVED',
                'APPROVED_BY_MANAGER', 'approved_by_manager',
                'DISBURSED', 'disbursed',
                'completed', 'lunas'
            ])
            ->orderBy('id', 'desc')
            ->get()
            ->map(function (Loan $loan) {
                $member = $loan->member;
                $memberName = $member?->name ?? 'Anggota';
                $memberNo   = $member?->member_number ?? '-';
                $originalAmount = (float) $loan->amount;
                $totalPaid = (float) ($loan->installments->where('status', 'paid')->sum('principal_amount') ?? 0);
                if ($loan->remaining_principal !== null && $loan->remaining_principal < $originalAmount) {
                    $sisaPokok = max(0.0, round((float) $loan->remaining_principal, 2));
                } else {
                    $sisaPokok = max(0.0, round($originalAmount - $totalPaid, 2));
                }
                
                $label = "{$memberName} ({$memberNo}) - No. SH: {$loan->loan_code} (Sisa Rp " . number_format($sisaPokok, 0, ',', '.') . ")";

                return [
                    'id'                    => $loan->id,
                    'loan_id'               => $loan->id,
                    'loan_code'             => $loan->loan_code,
                    'loan_number'           => $loan->loan_code,
                    'member_id'             => $loan->member_id,
                    'member_name'           => $memberName,
                    'member_no'             => $memberNo,
                    'original_amount'       => $originalAmount,
                    'plafon_awal'           => $originalAmount,
                    'amount'                => $originalAmount,
                    'plafon'                => $originalAmount,
                    'remaining_principal'   => $sisaPokok,
                    'sisa_saldo_pokok'      => $sisaPokok,
                    'remaining_balance'     => $sisaPokok,
                    'sisa_pokok'            => $sisaPokok,
                    'outstanding_principal' => $sisaPokok,
                    'status'                => $loan->status,
                    'label'                 => $label,
                ];
            });

        return response()->json([
            'success' => true,
            'message' => 'Daftar pilihan pinjaman berhasil diambil.',
            'data'    => $loans,
        ], 200);
    }

    /**
     * Cetak / Export PDF Kartu Pinjaman Kuning (Credo Union Modifikasi Pelita).
     * Endpoint: GET /api/loans/{id}/card/pdf & GET /api/admin/loans/{id}/card/pdf & GET /api/loans/{id}/print
     */
    public function exportLoanCardPdf(Request $request, int $id)
    {
        // Autentikasi token dari query string jika Bearer token tidak ada (misal dibuka di tab browser baru)
        if ($request->has('token') && !$request->bearerToken()) {
            $tokenModel = \Laravel\Sanctum\PersonalAccessToken::findToken($request->query('token'));
            if ($tokenModel) {
                \Illuminate\Support\Facades\Auth::setUser($tokenModel->tokenable);
            } else {
                abort(401, 'Token tidak valid atau kedaluwarsa.');
            }
        }

        $loan = Loan::with(['member', 'installments.teller', 'approver'])->find($id);

        if (!$loan) {
            return response()->json([
                'success' => false,
                'message' => 'Data pinjaman tidak ditemukan.',
            ], 404);
        }

        $cardData = $this->buildLoanCardData($loan);

        $data = [
            'loan'         => array_merge($loan->toArray(), $cardData['header']),
            'member'       => $cardData['header']['member'],
            'installments' => $cardData['installments'],
            'summary'      => $cardData['summary'],
        ];

        $pdf = \Barryvdh\DomPDF\Facade\Pdf::loadView('reports.loan_card_pdf', $data)
            ->setPaper('a4', 'portrait')
            ->setOption('isHtml5ParserEnabled', true)
            ->setOption('isRemoteEnabled', false)
            ->setOption('defaultFont', 'sans-serif');

        $fileName = "Kartu_Pinjaman_{$loan->loan_code}_" . ($cardData['header']['member']['member_number'] ?? $loan->id) . ".pdf";

        if ($request->query('stream') || $request->boolean('inline') || $request->query('inline') === '1') {
            return $pdf->stream($fileName);
        }

        return $pdf->download($fileName);
    }

    // MIGRASI PINJAMAN BERJALAN (POST /api/loans/migrate-existing & POST /api/admin/loans/migrate-existing)

    /**
     * Endpoint Migrasi Saldo Pinjaman Berjalan (Cut-off Balance).
     *
     * Persyaratan:
     * - DILARANG membuat mutasi Kas Keluar (KK)
     * - Bypass approval manajer (langsung status active)
     * - Membuat jadwal sisa angsuran di loan_installments sesuai current_balance & remaining_tenor
     */
    public function storeMigratedLoan(Request $request): JsonResponse
    {
        $request->validate([
            'member_id'           => 'required|exists:members,id',
            'interest_method'     => 'nullable|in:flat,declining_balance',
            'original_amount'     => 'required_without_all:plafon_awal,plafon,amount|numeric|min:1',
            'plafon_awal'         => 'nullable|numeric|min:1',
            'plafon'              => 'nullable|numeric|min:1',
            'amount'              => 'nullable|numeric|min:1',
            'current_balance'     => 'nullable|numeric|min:0',
            'remaining_principal' => 'nullable|numeric|min:0',
            'sisa_saldo_pokok'    => 'nullable|numeric|min:0',
            'sisa_pokok'          => 'nullable|numeric|min:0',
            'remaining_amount'    => 'nullable|numeric|min:0',
            'remaining_tenor'     => 'nullable|integer|min:1',
            'tenor_months'        => 'nullable|integer|min:1',
            'duration_months'     => 'nullable|integer|min:1',
            'due_date'            => 'nullable|date',
            'interest_rate'       => 'nullable|numeric|min:0|max:100',
            'application_date'    => 'nullable|date',
            'disbursement_date'   => 'nullable|date',
            'collateral'          => 'nullable|string|max:255',
            'purpose'             => 'nullable|string|max:255',
            'notes'               => 'nullable|string',
        ]);

        $member = Member::find($request->input('member_id'));
        if (!$member) {
            return response()->json([
                'success' => false,
                'message' => 'Anggota tidak ditemukan.',
            ], 404);
        }

        // 1. Plafon Awal (Total Nilai Akad Pinjaman saat pertama kali dicairkan)
        $originalAmount = $this->cleanAmount(
            $request->input('original_amount') 
            ?? $request->input('plafon_awal') 
            ?? $request->input('plafon') 
            ?? $request->input('amount')
        );

        // 2. Sisa Saldo Pokok (Outstanding Pokok per Tanggal Cut-Off Migrasi)
        // Jika sisa pokok tidak diisi secara terpisah, default bernilai sama dengan plafon awal (jika belum ada angsuran)
        $rawRemaining = $request->input('remaining_principal') 
            ?? $request->input('sisa_saldo_pokok') 
            ?? $request->input('current_balance') 
            ?? $request->input('sisa_pokok') 
            ?? $request->input('remaining_amount');

        $remainingPrincipal = ($rawRemaining !== null && $rawRemaining !== '') 
            ? $this->cleanAmount($rawRemaining) 
            : $originalAmount;

        // Sisa pokok tidak boleh lebih besar dari plafon awal
        if ($remainingPrincipal > $originalAmount) {
            $remainingPrincipal = $originalAmount;
        }

        $remainingTenor = (int) (
            $request->input('remaining_tenor') 
            ?? $request->input('tenor_months') 
            ?? $request->input('duration_months') 
            ?? 12
        );
        if ($remainingTenor < 1) {
            $remainingTenor = 12;
        }

        $interestMethod = $request->input('interest_method', 'declining_balance');
        $defaultRate    = ($interestMethod === 'flat') ? 1.00 : 2.50;
        $interestRate   = $request->has('interest_rate') && !is_null($request->input('interest_rate'))
            ? (float) $request->input('interest_rate')
            : $defaultRate;

        $dueDate  = $request->has('due_date') && !empty($request->input('due_date'))
            ? Carbon::parse($request->input('due_date'))
            : now()->addMonths($remainingTenor);
        $disbDate = $request->has('disbursement_date') && !empty($request->input('disbursement_date'))
            ? Carbon::parse($request->input('disbursement_date'))
            : now();
        $appDate  = $request->input('application_date', $disbDate->toDateString());

        // Estimasi angsuran bulanan berdasarkan sisa saldo pokok dan sisa tenor
        $principalChunk     = (float) ceil($remainingPrincipal / $remainingTenor);
        $flatInterest       = (float) round($originalAmount * ($interestRate / 100), 0);
        $firstMonthInterest = ($interestMethod === 'flat')
            ? $flatInterest
            : (float) round($remainingPrincipal * ($interestRate / 100), 0);
        $monthlyInstallment = $principalChunk + $firstMonthInterest;

        DB::beginTransaction();
        try {
            $loanCode = $request->input('loan_code') ?? $this->generateUniqueLoanCode();

            $loan = Loan::create([
                'loan_code'           => $loanCode,
                'member_id'           => $member->id,
                'amount'              => $originalAmount,
                'interest_rate'       => $interestRate,
                'interest_method'     => $interestMethod,
                'duration_months'     => $remainingTenor,
                'tenor_months'        => $remainingTenor,
                'monthly_installment' => $monthlyInstallment,
                'remaining_amount'    => $remainingPrincipal,
                'remaining_principal' => $remainingPrincipal,
                'status'              => 'active',
                'application_date'    => $appDate,
                'disbursement_date'   => $disbDate->toDateString(),
                'due_date'            => $dueDate->toDateString(),
                'notes'               => $request->input('notes') ?? 'Migrasi Pinjaman Berjalan (Cut-off Balance)',
                'purpose'             => $request->input('purpose') ?? 'Migrasi Saldo Awal Pinjaman',
                'collateral'          => $request->input('collateral'),
            ]);

            // Buat jadwal sisa angsuran di loan_installments sesuai remainingPrincipal & remainingTenor
            $runningBalance = $remainingPrincipal;
            for ($i = 1; $i <= $remainingTenor; $i++) {
                $jasa = ($interestMethod === 'flat')
                    ? $flatInterest
                    : (float) round($runningBalance * ($interestRate / 100), 0);

                $isLastMonth     = ($i === $remainingTenor);
                $endingBalance   = $isLastMonth ? 0.0 : max(0.0, $runningBalance - $principalChunk);
                $actualPrincipal = $runningBalance - $endingBalance;
                $instDueDate     = $disbDate->copy()->addMonths($i);

                LoanInstallment::create([
                    'loan_id'            => $loan->id,
                    'installment_number' => $i,
                    'beginning_balance'  => $runningBalance,
                    'principal_amount'   => $actualPrincipal,
                    'interest_amount'    => $jasa,
                    'ending_balance'     => $endingBalance,
                    'penalty_fee'        => 0.0,
                    'penalty_amount'     => 0.0,
                    'total_amount'       => $actualPrincipal + $jasa,
                    'due_date'           => $instDueDate->toDateString(),
                    'status'             => 'unpaid',
                    'paid_by_member_id'  => $loan->member_id,
                ]);

                $runningBalance = $endingBalance;
            }

            DB::commit();

            $responseData = array_merge($loan->fresh(['installments', 'member'])->toArray(), [
                'original_amount'       => (float) $loan->amount,
                'plafon_awal'           => (float) $loan->amount,
                'amount'                => (float) $loan->amount,
                'plafon'                => (float) $loan->amount,
                'remaining_principal'   => (float) $loan->remaining_principal,
                'sisa_saldo_pokok'      => (float) $loan->remaining_principal,
                'sisa_pokok'            => (float) $loan->remaining_principal,
                'remaining_amount'      => (float) $loan->remaining_principal,
                'current_balance'       => (float) $loan->remaining_principal,
                'outstanding_principal' => (float) $loan->remaining_principal,
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Migrasi saldo pinjaman berjalan berhasil disimpan.',
                'data'    => $responseData,
            ], 201);
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error("[LoanController] Gagal migrasi pinjaman: " . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Gagal menyimpan migrasi pinjaman: ' . $e->getMessage(),
            ], 500);
        }
    }

    // PEMBAYARAN CICILAN (POST /api/loans/installments/{installmentId}/pay & POST /api/loans/repayments)

    /**
     * Bayar satu angsuran pinjaman (Kalkulasi Fleksibel).
     * Endpoint: POST /api/loans/installments/{installmentId}/pay
     *
     * Jurnal yang dibuat (split entry):
     *   Debet : Kas (1000)                           → Total Bayar
     *   Kredit: Piutang Pinjaman (1024)               → Angsuran Pokok
     *   Kredit: Pendapatan Jasa Pinjaman (4180)       → Bunga / Jasa
     *   Kredit: Pendapatan Denda Pinjaman (4182)      → Denda (jika ada)
     */
    public function payInstallment(Request $request, int $installmentId): JsonResponse
    {
        $request->validate([
            'receipt_number'   => 'nullable|string|max:100',
            'penalty_fee'      => 'nullable|numeric|min:0',
            'penalty_amount'   => 'nullable|numeric|min:0',
            'principal_amount' => 'nullable|numeric|min:0',
            'interest_amount'  => 'nullable|numeric|min:0',
            'paid_at'          => 'nullable|date',
            'payment_method'   => 'nullable|string',
            'notes'            => 'nullable|string',
        ]);

        $installment = LoanInstallment::with('loan.member')->find($installmentId);

        if (!$installment) {
            return response()->json([
                'success' => false,
                'message' => 'Data angsuran tidak ditemukan.',
            ], 404);
        }

        if ($installment->status === 'paid') {
            return response()->json([
                'success' => false,
                'message' => 'Angsuran ini sudah lunas.',
            ], 400);
        }

        if ($installment->installment_number > 1) {
            $prevInstallment = LoanInstallment::where('loan_id', $installment->loan_id)
                ->where('installment_number', $installment->installment_number - 1)
                ->first();

            if ($prevInstallment && $prevInstallment->status !== 'paid') {
                return response()->json([
                    'success' => false,
                    'message' => "Angsuran sebelumnya (Ke-{$prevInstallment->installment_number}) wajib dilunasi terlebih dahulu.",
                ], 400);
            }
        }

        $loan        = $installment->loan;
        $member      = $loan?->member;
        $authUser    = $request->user();
        $paymentDate = $request->input('paid_at', now()->toDateString());

        if ($lockRes = \App\Services\PeriodLockService::validateDate($paymentDate)) {
            return $lockRes;
        }

        // Kalkulasi fleksibel 3 komponen: Pokok, Jasa, Denda
        $penaltyFee    = (float) ($request->input('penalty_amount') ?? $request->input('penalty_fee', 0.0));
        $principalPaid = $request->has('principal_amount')
            ? (float) $request->input('principal_amount')
            : (float) $installment->principal_amount;
        $interestPaid  = $request->has('interest_amount')
            ? (float) $request->input('interest_amount')
            : (float) $installment->interest_amount;
        $totalPaid     = $principalPaid + $interestPaid + $penaltyFee;

        // Validasi total setoran tidak boleh kosong / <= 0
        if ($totalPaid <= 0) {
            return response()->json([
                'success' => false,
                'message' => 'Total pembayaran harus lebih besar dari Rp 0.',
            ], 422);
        }

        DB::beginTransaction();
        try {
            // Generate nomor bukti
            $receiptNo = $request->input('receipt_number')
                ?? ('KM-' . date('Ymd') . '-' . str_pad((string) mt_rand(1, 9999), 4, '0', STR_PAD_LEFT));

            // 1. Update installment (tetap 'paid' meski hanya bayar bunga pokok = 0)
            $installment->update([
                'status'           => 'paid',
                'paid_at'          => $paymentDate,
                'receipt_number'   => $receiptNo,
                'principal_amount' => $principalPaid,
                'interest_amount'  => $interestPaid,
                'penalty_fee'      => $penaltyFee,
                'penalty_amount'   => $penaltyFee,
                'total_amount'     => $totalPaid,
                'paid_by'          => $authUser?->id,
                'notes'            => $request->input('notes'),
            ]);

            // 2. HANYA principal_amount yang memotong sisa pokok pinjaman
            $newRemainingPrincipal = max(0.0, (float) ($loan->remaining_principal ?? $loan->remaining_amount ?? 0) - $principalPaid);
            $loan->update([
                'remaining_principal' => $newRemainingPrincipal,
                'remaining_amount'    => $newRemainingPrincipal,
                'status'              => $newRemainingPrincipal <= 0 ? 'completed' : $loan->status,
            ]);

            $loan->recalculateSchedule();
            $newRemainingPrincipal = (float) $loan->remaining_principal;

            // 3. Catat Transaksi Kas Masuk (KM approved)
            $kasAccount = $this->getKasAccount();
            $memberName = $member?->name ?? 'Anggota';
            $trxNumber  = 'KM-' . date('Ymd') . '-' . str_pad((string) mt_rand(1, 9999), 4, '0', STR_PAD_LEFT);

            $trx = Transaction::create([
                'transaction_number' => $trxNumber,
                'receipt_number'     => $receiptNo,
                'member_id'          => $loan->member_id,
                'account_id'         => $kasAccount->id,
                'book_type'          => 'BUKU_BIRU',
                'category'           => 'angsuran_pinjaman',
                'operator_id'        => $authUser?->id,
                'approved_by'        => $authUser?->id,
                'type'               => 'deposit',
                'amount'             => $totalPaid,
                'beginning_balance'  => (float) $kasAccount->balance,
                'ending_balance'     => (float) $kasAccount->balance + $totalPaid,
                'payment_method'     => $request->input('payment_method', 'cash'),
                'transaction_date'   => $paymentDate,
                'description'        => "Pembayaran Angsuran Pinjaman ke-{$installment->installment_number} ({$loan->loan_code}) - {$memberName}",
                'status'             => 'approved',
                'approved_at'        => now(),
            ]);

            $kasAccount->increment('balance', $totalPaid);

            // 4. Jurnal Split: Kas (1000/1010) | Piutang (1024 jika pokok > 0) | Jasa (4180 jika bunga > 0) | Denda (4182 jika denda > 0)
            $this->recordInstallmentPaymentJournal($trx, $principalPaid, $interestPaid, $penaltyFee, $authUser?->id, $loan);

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => "Angsuran ke-{$installment->installment_number} berhasil dibayar.",
                'data'    => [
                    'installment'       => $installment->fresh(),
                    'installment_order' => (int) $installment->installment_number,
                    'angsuran_ke'       => (int) $installment->installment_number,
                    'loan_sisa_pokok'   => $newRemainingPrincipal,
                    'remaining_balance' => $newRemainingPrincipal,
                    'loan_status'       => $loan->fresh()->status,
                    'total_dibayar'     => $totalPaid,
                    'principal_paid'    => $principalPaid,
                    'interest_paid'     => $interestPaid,
                    'penalty_paid'      => $penaltyFee,
                    'receipt_number'    => $receiptNo,
                ],
            ], 200);

        } catch (\Exception $e) {
            DB::rollBack();
            Log::error("[LoanController] Gagal bayar angsuran #{$installmentId}: " . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Gagal memproses pembayaran: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Handler pembayaran angsuran alternatif (POST /api/loans/repayments atau POST /api/loans/{id}/repayment)
     */
    public function storeRepayment(Request $request, ?int $id = null): JsonResponse
    {
        $installmentId = $request->input('installment_id');
        $loanId = $id ?? $request->input('loan_id');

        if (!$installmentId && $loanId) {
            $installment = LoanInstallment::where('loan_id', $loanId)
                ->where('status', '!=', 'paid')
                ->orderBy('installment_number')
                ->first();
            $installmentId = $installment?->id;
        }

        // Jika tidak ada installment belum bayar (misal pembayaran ekstra / bunga berjalan fleksibel), buat baris urutan baru
        if (!$installmentId && $loanId) {
            $loan = Loan::find($loanId);
            if ($loan) {
                $nextOrder = (LoanInstallment::where('loan_id', $loanId)->max('installment_number') ?? 0) + 1;
                $currentBalance = (float) ($loan->remaining_principal ?? $loan->remaining_amount ?? $loan->amount);
                $principalPaid = (float) $request->input('principal_amount', 0);
                $interestPaid  = (float) $request->input('interest_amount', 0);
                $penaltyFee    = (float) ($request->input('penalty_amount') ?? $request->input('penalty_fee', 0));
                $newBalance    = max(0.0, $currentBalance - $principalPaid);

                $newInstallment = LoanInstallment::create([
                    'loan_id'            => $loan->id,
                    'installment_number' => $nextOrder,
                    'beginning_balance'  => $currentBalance,
                    'principal_amount'   => $principalPaid,
                    'interest_amount'    => $interestPaid,
                    'penalty_fee'        => $penaltyFee,
                    'penalty_amount'     => $penaltyFee,
                    'total_amount'       => $principalPaid + $interestPaid + $penaltyFee,
                    'ending_balance'     => $newBalance,
                    'due_date'           => now()->toDateString(),
                    'paid_by_member_id'  => $loan->member_id,
                    'status'             => 'unpaid',
                ]);
                $installmentId = $newInstallment->id;
            }
        }

        if (!$installmentId) {
            return response()->json([
                'success' => false,
                'message' => 'ID Angsuran (installment_id) atau ID Pinjaman tidak valid.',
            ], 422);
        }

        return $this->payInstallment($request, (int) $installmentId);
    }

    // DAFTAR PINJAMAN ANGGOTA / ADMIN

    /**
     * Daftar pinjaman milik anggota yang sedang login.
     * Endpoint: GET /api/user/loans
     */
    public function getMyLoans(Request $request): JsonResponse
    {
        $authUser = $request->user();
        $memberId = $authUser instanceof Member
            ? $authUser->id
            : Member::where('user_id', $authUser->id)->value('id');

        if (!$memberId) {
            return response()->json(['success' => false, 'message' => 'Data anggota tidak ditemukan.'], 404);
        }

        $loans = Loan::with(['member', 'installments'])
            ->where('member_id', $memberId)
            ->latest()
            ->get()
            ->map(function (Loan $loan) {
                $data = $this->formatLoanSummary($loan);

                // Enrich with full installment detail for member app
                $installments = $loan->installments ?? collect();
                $paidInstallments  = $installments->where('status', 'paid');
                $totalPaidAll      = (float) $paidInstallments->sum('total_amount');
                $totalPaidPrincipal = (float) $paidInstallments->sum('principal_amount');
                $totalPaidInterest  = (float) $paidInstallments->sum('interest_amount');
                $totalKewajiban    = (float) $installments->sum('total_amount');
                $monthlyInstallment = (float) ($loan->monthly_installment ?? 0);
                $tenor = (int) ($loan->tenor_months ?? $loan->duration_months ?? 12);
                if ($totalKewajiban <= 0 && $monthlyInstallment > 0) {
                    $totalKewajiban = $monthlyInstallment * $tenor;
                }

                $data['no_kontrak']           = $loan->loan_code;
                $data['plafon_disetujui']      = (float) $loan->amount;
                $data['tenor_waktu']           = $tenor;
                $data['suku_bunga']            = (float) $loan->interest_rate;
                $data['angsuran_bulanan']      = $monthlyInstallment;
                $data['estimasi_angsuran']     = $monthlyInstallment;
                $data['total_kewajiban']       = $totalKewajiban;
                $data['total_harus_dibayar']   = $totalKewajiban;
                $data['total_dibayar']         = $totalPaidAll;
                $data['total_paid']            = $totalPaidAll;
                $data['total_paid_principal']  = $totalPaidPrincipal;
                $data['total_paid_interest']   = $totalPaidInterest;
                $data['angsuran_selesai']      = $paidInstallments->count();
                $data['angsuran_tersisa']      = $installments->where('status', '!=', 'paid')->count();
                $data['total_angsuran']        = $installments->count();

                // Jadwal angsuran lengkap
                $data['installments'] = $installments->sortBy('installment_number')->map(function ($inst) {
                    return [
                        'id'                 => $inst->id,
                        'angsuran_ke'        => (int) $inst->installment_number,
                        'installment_number' => (int) $inst->installment_number,
                        'due_date'           => $inst->due_date?->format('Y-m-d'),
                        'paid_at'            => $inst->paid_at?->format('Y-m-d'),
                        'principal_amount'   => (float) $inst->principal_amount,
                        'angsuran_pokok'     => (float) $inst->principal_amount,
                        'interest_amount'    => (float) $inst->interest_amount,
                        'jasa_pinjaman'      => (float) $inst->interest_amount,
                        'penalty_amount'     => (float) ($inst->penalty_fee ?? $inst->penalty_amount ?? 0),
                        'denda'              => (float) ($inst->penalty_fee ?? $inst->penalty_amount ?? 0),
                        'total_amount'       => (float) $inst->total_amount,
                        'total_bayar'        => (float) $inst->total_amount,
                        'beginning_balance'  => (float) ($inst->beginning_balance ?? 0),
                        'sisa_pokok_awal'    => (float) ($inst->beginning_balance ?? 0),
                        'ending_balance'     => (float) ($inst->ending_balance ?? 0),
                        'sisa_pokok_akhir'   => (float) ($inst->ending_balance ?? 0),
                        'status'             => $inst->status,
                        'is_paid'            => $inst->status === 'paid',
                    ];
                })->values()->all();

                return $data;
            });

        return response()->json([
            'success' => true,
            'data'    => $loans,
        ], 200);
    }

    /**
     * Daftar pinjaman yang sudah disetujui (untuk Admin/Manager).
     * Endpoint: GET /api/admin/loans
     */
    public function getApprovedLoans(Request $request): JsonResponse
    {
        $status = $request->input('status', 'approved');
        $perPage = (int) $request->input('per_page', 20);

        if ($status === 'approved' && ($request->boolean('today_only') || $request->has('today'))) {
            $paginated = Loan::with(['member', 'installments'])
                ->whereIn('status', ['approved', 'APPROVED_BY_MANAGER', 'approved_by_manager'])
                ->whereDate('updated_at', today())
                ->latest()
                ->paginate($perPage);

            $paginated->getCollection()->transform(fn (Loan $loan) => $this->formatLoanSummary($loan));

            return response()->json([
                'success' => true,
                'data'    => $paginated,
            ], 200);
        }

        $loans = Loan::with(['member', 'installments'])
            ->when($status !== 'all', function ($q) use ($status) {
                if (in_array(strtolower($status), ['approved', 'approved_by_manager'])) {
                    $q->whereIn('status', ['approved', 'APPROVED_BY_MANAGER', 'approved_by_manager']);
                } elseif (in_array(strtolower($status), ['pending_manager', 'waiting_manager_approval'])) {
                    $q->whereIn('status', ['pending_manager', 'WAITING_MANAGER_APPROVAL', 'menunggu_ketua']);
                } else {
                    $q->where('status', $status);
                }
            })
            ->latest()
            ->paginate($perPage);

        $loans->getCollection()->transform(fn (Loan $loan) => $this->formatLoanSummary($loan));

        return response()->json([
            'success' => true,
            'data'    => $loans,
        ], 200);
    }

    // PRIVATE HELPERS

    /**
     * Generate semua baris jadwal cicilan (saldo menurun atau flat) ke tabel loan_installments.
     */
    private function generateInstallmentRows(Loan $loan, Carbon $disbursementDate): void
    {
        $tenor          = (int) ($loan->tenor_months ?? $loan->duration_months ?? 12);
        $method         = $loan->interest_method ?? 'declining_balance';
        $defaultRate    = ($method === 'flat') ? 1.00 : 2.50;
        $rate           = (float) ($loan->interest_rate ?? $defaultRate);
        $plafon         = (float) $loan->amount;
        $principalChunk = (float) ceil($plafon / $tenor);
        $currentBalance = $plafon;
        $flatInterest   = (float) round($plafon * ($rate / 100), 0);

        for ($i = 1; $i <= $tenor; $i++) {
            $jasa            = ($method === 'flat')
                ? $flatInterest
                : (float) round($currentBalance * ($rate / 100), 0);

            $isLastMonth     = ($i === $tenor);
            $endingBalance   = $isLastMonth ? 0.0 : max(0.0, $currentBalance - $principalChunk);
            $actualPrincipal = $currentBalance - $endingBalance;

            LoanInstallment::create([
                'loan_id'            => $loan->id,
                'installment_number' => $i,
                'beginning_balance'  => $currentBalance,
                'principal_amount'   => $actualPrincipal,
                'interest_amount'    => $jasa,
                'ending_balance'     => $endingBalance,
                'penalty_fee'        => 0.0,
                'penalty_amount'     => 0.0,
                'total_amount'       => $actualPrincipal + $jasa,
                'due_date'           => $disbursementDate->copy()->addMonths($i)->toDateString(),
                'status'             => 'unpaid',
                // FK backward compat (jika kolom ada di tabel lama)
                'paid_by_member_id'  => $loan->member_id,
            ]);

            $currentBalance = $endingBalance;
        }
    }

    /**
     * Catat jurnal pencairan pinjaman:
     *   Debet  → Piutang Pinjaman (1024) naik
     *   Kredit → Kas (1000) turun
     */
    private function recordDisbursementJournal(Transaction $trx, Loan $loan, ?int $userId): void
    {
        try {
            $payMethod       = strtolower($trx->payment_method ?? 'cash');
            $cashCode        = (str_contains($payMethod, 'transfer') || str_contains($payMethod, 'bank') || str_contains($payMethod, 'bri')) ? '1010' : '1000';
            $cashAccount     = ChartOfAccount::where('account_code', $cashCode)->first() ?? ChartOfAccount::where('account_code', '1000')->first();
            $piutangAccount  = ChartOfAccount::where('account_code', '1024')->first();

            if (!$cashAccount || !$piutangAccount) {
                Log::warning("[LoanController] COA {$cashCode} atau 1024 tidak ditemukan, jurnal pencairan dilewati.");
                return;
            }

            $rawVoucher = $loan->loan_code;
            $voucher = str_starts_with((string) $rawVoucher, 'KK-') ? (string) $rawVoucher : 'KK-' . $rawVoucher;
            $baseVoucher = $voucher;
            $suffix  = 1;
            while (JournalEntry::where('voucher_number', $voucher)->exists()) {
                $voucher = $baseVoucher . '-' . $suffix++;
            }

            $journal = JournalEntry::create([
                'transaction_id' => $trx->id,
                'entry_date'     => $trx->transaction_date,
                'voucher_number' => $voucher,
                'description'    => $trx->description,
                'created_by'     => $userId,
            ]);

            // Debet Piutang (1024)
            JournalDetail::create([
                'journal_entry_id' => $journal->id,
                'account_id'       => $piutangAccount->id,
                'debit'            => $loan->amount,
                'credit'           => 0,
                'description'      => "Piutang Pinjaman: {$loan->loan_code}",
            ]);

            // Kredit Kas (1000)
            JournalDetail::create([
                'journal_entry_id' => $journal->id,
                'account_id'       => $cashAccount->id,
                'debit'            => 0,
                'credit'           => $loan->amount,
                'description'      => "Pencairan Kas: {$loan->loan_code}",
            ]);
        } catch (\Exception $e) {
            Log::warning("[LoanController] Gagal jurnal pencairan: " . $e->getMessage());
        }
    }

    /**
     * Catat jurnal pembayaran cicilan (split 3 akun):
     *   Debet  → Kas (1000)                    = total
     *   Kredit → Piutang Pinjaman (1024)        = pokok
     *   Kredit → Jasa Pinjaman (4180)           = bunga
     *   Kredit → Denda Pinjaman (4182)          = denda (jika > 0)
     */
    private function recordInstallmentPaymentJournal(
        Transaction $trx,
        float       $principal,
        float       $interest,
        float       $penalty,
        ?int        $userId,
        ?Loan       $loan = null
    ): void {
        try {
            $total = round($principal + $interest + $penalty, 2);
            if ($total <= 0) {
                return;
            }

            $cashCoa    = ChartOfAccount::whereIn('account_code', ['1000', '1010'])->first() ?? ChartOfAccount::where('account_code', '1000')->first();
            $piutangCoa = $principal > 0 ? ChartOfAccount::where('account_code', '1024')->first() : null;
            $jasaCoa    = $interest > 0 ? ChartOfAccount::where('account_code', '4180')->first() : null;
            $dendaCoa   = $penalty > 0 ? ChartOfAccount::where('account_code', '4182')->first() : null;

            if (!$cashCoa) {
                Log::warning("[LoanController] Akun Kas (1000/1010) tidak ditemukan, jurnal dilewati.");
                return;
            }

            $rawVoucher = $trx->receipt_number ?? $trx->transaction_number;
            $voucher = str_starts_with((string) $rawVoucher, 'KM-') ? (string) $rawVoucher : 'KM-' . $rawVoucher;
            $baseVoucher = $voucher;
            $suffix  = 1;
            while (JournalEntry::where('voucher_number', $voucher)->exists()) {
                $voucher = $baseVoucher . '-' . $suffix++;
            }

            $journal = JournalEntry::create([
                'transaction_id' => $trx->id,
                'entry_date'     => $trx->transaction_date,
                'voucher_number' => $voucher,
                'description'    => $trx->description,
                'created_by'     => $userId,
            ]);

            // Debet: Kas (1000/1010) — total pembayaran masuk
            JournalDetail::create([
                'journal_entry_id' => $journal->id,
                'account_id'       => $cashCoa->id,
                'debit'            => $total,
                'credit'           => 0,
                'description'      => 'Penerimaan Angsuran Pinjaman',
            ]);

            // Kredit: Piutang (1024) — HANYA jika ada pokok ($principal > 0)
            if ($principal > 0 && $piutangCoa) {
                JournalDetail::create([
                    'journal_entry_id' => $journal->id,
                    'account_id'       => $piutangCoa->id,
                    'debit'            => 0,
                    'credit'           => $principal,
                    'description'      => 'Angsuran Pokok Pinjaman',
                ]);
            }

            // Kredit: Jasa Pinjaman (4180) — HANYA jika ada bunga/jasa ($interest > 0)
            if ($interest > 0 && $jasaCoa) {
                $rateStr = $loan ? number_format((float) ($loan->interest_rate ?? 2.50), 2) . '%' : '2.50%';
                $methodLabel = ($loan && $loan->interest_method === 'flat') ? 'Flat' : 'Saldo Menurun';
                JournalDetail::create([
                    'journal_entry_id' => $journal->id,
                    'account_id'       => $jasaCoa->id,
                    'debit'            => 0,
                    'credit'           => $interest,
                    'description'      => "Pendapatan Jasa Pinjaman {$rateStr} ({$methodLabel})",
                ]);
            }

            // Kredit: Denda (4182) — HANYA jika ada denda ($penalty > 0)
            if ($penalty > 0 && $dendaCoa) {
                JournalDetail::create([
                    'journal_entry_id' => $journal->id,
                    'account_id'       => $dendaCoa->id,
                    'debit'            => 0,
                    'credit'           => $penalty,
                    'description'      => 'Pendapatan Denda Keterlambatan',
                ]);
            }
        } catch (\Exception $e) {
            Log::warning("[LoanController] Gagal jurnal pembayaran cicilan: " . $e->getMessage());
        }
    }

    /**
     * Alias method untuk kompatibilitas nama recordRepaymentJournal.
     */
    private function recordRepaymentJournal(
        Transaction $trx,
        float       $principal,
        float       $interest,
        float       $penalty,
        ?int        $userId,
        ?Loan       $loan = null
    ): void {
        $this->recordInstallmentPaymentJournal($trx, $principal, $interest, $penalty, $userId, $loan);
    }

    /**
     * Format ringkasan pinjaman untuk response list.
     */
    private function formatLoanSummary(Loan $loan): array
    {
        $tenor         = (int) ($loan->tenor_months ?? $loan->duration_months ?? 12);
        $amount        = (float) $loan->amount;
        $totalPaid     = (float) ($loan->installments->where('status', 'paid')->sum('principal_amount') ?? 0);
        $sisaPokok     = (float) ($loan->remaining_principal ?? max(0.0, round($amount - $totalPaid, 2)));
        $member        = $loan->member;

        $canDisburse = in_array(strtoupper($loan->status), ['APPROVED_BY_MANAGER', 'APPROVED', 'approved_by_manager', 'approved']);

        return [
            'id'                    => $loan->id,
            'loan_id'               => $loan->id,
            'loan_code'             => $loan->loan_code,
            'loan_number'           => $loan->loan_code,
            'no_sh'                 => $loan->loan_code,
            'member_id'             => $loan->member_id,
            'member_name'           => $member?->name ?? '-',
            'nama_anggota'          => $member?->name ?? '-',
            'member_no'             => $member?->member_number ?? '-',
            'no_anggota'            => $member?->member_number ?? '-',
            'original_amount'       => $amount,
            'plafon_awal'           => $amount,
            'plafon'                => $amount,
            'amount'                => $amount,
            'nominal'               => $amount,
            'total_amount'          => $amount,
            'loan_amount'           => $amount,
            'tenor_months'          => $tenor,
            'duration_months'       => $tenor,
            'tenor'                 => $tenor,
            'interest_rate'         => (float) $loan->interest_rate,
            'interest_method'       => $loan->interest_method ?? 'declining_balance',
            'monthly_installment'   => (float) ($loan->monthly_installment ?? 0),
            'angsuran_bulanan'      => (float) ($loan->monthly_installment ?? 0),
            'status'                => $loan->status,
            'can_disburse'          => $canDisburse,
            'disburse_url'          => "/api/loans/{$loan->id}/disburse",
            'notes'                 => $loan->notes,
            'collateral'            => $loan->collateral,
            'purpose'               => $loan->purpose,
            'sisa_pokok'            => $sisaPokok,
            'sisa_saldo_pokok'      => $sisaPokok,
            'remaining_principal'   => $sisaPokok,
            'remaining_amount'      => $sisaPokok,
            'remaining_balance'     => $sisaPokok,
            'outstanding_principal' => $sisaPokok,
            'total_dibayar'         => $totalPaid,
            'total_paid_principal'  => $totalPaid,
            'total_pokok_terbayar'  => $totalPaid,
            'angsuran_selesai'      => $loan->installments->where('status', 'paid')->count(),
            'total_angsuran'        => $loan->installments->count(),
            'disbursement_date'     => $loan->disbursement_date,
            'due_date'              => $loan->due_date,
            'application_date'      => $loan->application_date,
            'created_at'            => $loan->created_at?->toIso8601String(),
            'member'                => $member ? [
                'id'            => $member->id,
                'name'          => $member->name,
                'nama'          => $member->name,
                'member_number' => $member->member_number,
                'no_anggota'    => $member->member_number,
                'phone'         => $member->phone,
                'address'       => $member->address,
            ] : null,
            'installments'        => $loan->installments->map(fn ($inst) => [
                'id'           => $inst->id,
                'period_text'  => 'Angsuran Ke-' . $inst->installment_number,
                'payment_date' => $inst->paid_at?->toDateString() ?? $inst->due_date?->toDateString(),
                'amount'       => (float) $inst->total_amount,
                'status'       => $inst->status === 'paid' ? 'Lunas' : 'Belum Bayar',
            ])->values()->all(),
        ];
    }

    /**
     * Verifikasi tingkat pertama (Admin)
     * Endpoint: POST /api/admin/loans/{id}/verify
     */
    public function verify(Request $request, int $id): JsonResponse
    {
        $loan = Loan::find($id);

        if (!$loan) {
            return response()->json([
                'success' => false,
                'message' => 'Data pengajuan pinjaman tidak ditemukan.',
            ], 404);
        }

        $pendingAdminStatuses = ['pending_admin', 'WAITING_ADMIN_VERIFICATION', 'pending'];
        if (!in_array($loan->status, $pendingAdminStatuses) && !in_array(strtoupper($loan->status), $pendingAdminStatuses)) {
            return response()->json([
                'success' => false,
                'message' => "Status pinjaman saat ini bukan pending_admin. Status: {$loan->status}.",
            ], 400);
        }

        $request->validate([
            'collateral'       => 'nullable|string|max:255',
            'tenor'            => 'nullable|integer|min:1|max:360',
            'interest_method'  => 'nullable|string|in:declining_balance,flat',
            'interest_rate'    => 'nullable|numeric|min:0|max:100',
        ]);

        if ($request->has('collateral')) {
            $loan->collateral = $request->input('collateral');
        }
        if ($request->has('tenor')) {
            $loan->tenor_months = (int) $request->input('tenor');
            $loan->duration_months = (int) $request->input('tenor');
        }
        if ($request->has('interest_method')) {
            $loan->interest_method = $request->input('interest_method');
        }
        if ($request->has('interest_rate')) {
            $loan->interest_rate = (float) $request->input('interest_rate');
        }

        if (\Illuminate\Support\Facades\Schema::hasColumn('loans', 'admin_fee')) {
            $loan->admin_fee = 0.00;
        }

        $loan->status = 'WAITING_MANAGER_APPROVAL';
        $loan->admin_verified_at = now();
        $loan->admin_verified_by = $request->user()?->id;
        $loan->save();

        return response()->json([
            'success' => true,
            'status'  => 'success',
            'message' => 'Verifikasi tingkat pertama (Admin) berhasil, status menjadi WAITING_MANAGER_APPROVAL.',
            'data'    => $loan,
        ], 200);
    }

    /**
     * Persetujuan Final Manajer
     * Endpoint: POST /api/manager/loans/{id}/approve
     */
    public function approve(Request $request, int $id): JsonResponse
    {
        $user = $request->user();
        if (!$user || strtolower($user->role ?? '') !== 'manager') {
            return response()->json([
                'success' => false,
                'message' => 'Akses ditolak: Hanya role Manajer yang dapat menyetujui pinjaman.',
            ], 403);
        }

        return $this->approveByManager($request, $id);
    }

    /**
     * Daftar pengajuan pinjaman pending manajer.
     * Endpoint: GET /api/manager/approvals & GET /api/manager/loans/pending
     */
    public function pendingManagerLoans(Request $request): JsonResponse
    {
        $loans = Loan::with('member')
            ->whereIn('status', ['WAITING_MANAGER_APPROVAL', 'pending_manager', 'menunggu_ketua'])
            ->latest()
            ->get()
            ->map(function (Loan $loan) {
                $amount = (float) $loan->amount;
                $tenor = (int) ($loan->tenor_months ?? $loan->duration_months ?? $loan->tenor ?? 12);
                $member = $loan->member;
                $sisaPokok = (float) ($loan->remaining_principal ?? $amount);

                return [
                    'id'                  => $loan->id,
                    'loan_id'             => $loan->id,
                    'loan_code'           => $loan->loan_code,
                    'loan_number'         => $loan->loan_code,
                    'no_sh'               => $loan->loan_code,
                    'member_id'           => $loan->member_id,
                    'member_name'         => $member?->name ?? '',
                    'nama_anggota'        => $member?->name ?? '',
                    'member_no'           => $member?->member_number ?? '',
                    'no_anggota'          => $member?->member_number ?? '',
                    'original_amount'     => $amount,
                    'plafon_awal'         => $amount,
                    'amount'              => $amount,
                    'plafon'              => $amount,
                    'nominal'             => $amount,
                    'total_amount'        => $amount,
                    'loan_amount'         => $amount,
                    'remaining_principal' => $sisaPokok,
                    'sisa_saldo_pokok'    => $sisaPokok,
                    'sisa_pokok'          => $sisaPokok,
                    'remaining_balance'   => $sisaPokok,
                    'outstanding_principal' => $sisaPokok,
                    'monthly_installment' => (float) ($loan->monthly_installment ?? 0),
                    'angsuran_bulanan'    => (float) ($loan->monthly_installment ?? 0),
                    'tenor_months'        => $tenor,
                    'duration_months'     => $tenor,
                    'tenor'               => $tenor,
                    'interest_rate'       => (float) $loan->interest_rate,
                    'interest_method'     => $loan->interest_method ?? 'declining_balance',
                    'purpose'             => $loan->purpose ?? $loan->notes ?? '',
                    'notes'               => $loan->notes ?? $loan->purpose ?? '',
                    'collateral'          => $loan->collateral ?? '',
                    'status'              => $loan->status,
                    'application_date'    => $loan->application_date,
                    'created_at'          => $loan->created_at?->toIso8601String(),
                    'member'              => $member ? [
                        'id'            => $member->id,
                        'name'          => $member->name,
                        'nama'          => $member->name,
                        'member_number' => $member->member_number,
                        'no_anggota'    => $member->member_number,
                        'phone'         => $member->phone,
                        'address'       => $member->address,
                    ] : null,
                ];
            });

        return response()->json([
            'success' => true,
            'message' => 'Daftar pengajuan pinjaman pending manajer berhasil diambil.',
            'data'    => $loans,
        ], 200);
    }

    /**
     * Mengambil riwayat transaksi operasional pinjaman (Pencairan & Pembayaran Angsuran).
     * Endpoint: GET /api/loans/transactions-history & GET /api/admin/loans/transactions-history
     */
    public function getLoanTransactionsHistory(Request $request): JsonResponse
    {
        try {
            $loanCategories = [
                'pencairan_pinjaman',
                'angsuran_pinjaman',
                'pinjaman',
                'angsuran',
                'loan_disbursement',
                'loan_repayment',
                'repayment',
            ];

            $query = Transaction::with(['member'])
                ->where(function ($q) use ($loanCategories) {
                    $q->whereIn('category', $loanCategories)
                      ->orWhere('description', 'like', '%Pencairan Pinjaman%')
                      ->orWhere('description', 'like', '%Angsuran Pinjaman%');
                })
                ->where(function ($q) {
                    $q->whereNull('category')
                      ->orWhereNotIn('category', [
                          'simpanan_pokok', 'simpanan_wajib', 'simpanan_sukarela',
                          'simpanan_harian', 'tarik_buku_putih', 'tarik_simpanan', 'bunga_simpanan'
                      ]);
                })
                ->where('receipt_number', 'not like', 'KM-BM-INT%')
                ->where('transaction_number', 'not like', 'KM-BM-INT%');

            // Filter optional: search, type, date range, member_id
            if ($search = $request->input('search')) {
                $query->where(function ($q) use ($search) {
                    $q->where('receipt_number', 'like', "%{$search}%")
                      ->orWhere('transaction_number', 'like', "%{$search}%")
                      ->orWhere('description', 'like', "%{$search}%")
                      ->orWhereHas('member', function ($mq) use ($search) {
                          $mq->where('name', 'like', "%{$search}%")
                             ->orWhere('member_number', 'like', "%{$search}%");
                      });
                });
            }

            if ($type = $request->input('transaction_type') ?? $request->input('type')) {
                $upperType = strtoupper($type);
                if (in_array($upperType, ['DISBURSEMENT', 'PENCAIRAN', 'WITHDRAWAL', 'KELUAR'])) {
                    $query->where(function ($q) {
                        $q->whereIn('category', ['pencairan_pinjaman', 'pinjaman', 'loan_disbursement'])
                          ->orWhere('type', 'withdrawal')
                          ->orWhere('description', 'like', '%Pencairan Pinjaman%');
                    });
                } elseif (in_array($upperType, ['REPAYMENT', 'ANGSURAN', 'DEPOSIT', 'MASUK'])) {
                    $query->where(function ($q) {
                        $q->whereIn('category', ['angsuran_pinjaman', 'angsuran', 'loan_repayment', 'repayment'])
                          ->orWhere('type', 'deposit')
                          ->orWhere('description', 'like', '%Angsuran Pinjaman%');
                    });
                }
            }

            if ($memberId = $request->input('member_id')) {
                $query->where('member_id', $memberId);
            }

            if ($startDate = $request->input('start_date') ?? $request->input('from')) {
                $query->whereDate('transaction_date', '>=', $startDate);
            }

            if ($endDate = $request->input('end_date') ?? $request->input('to')) {
                $query->whereDate('transaction_date', '<=', $endDate);
            }

            $perPage = (int) $request->input('per_page', 20);
            $paginated = $query->orderBy('transaction_date', 'desc')
                ->orderBy('id', 'desc')
                ->paginate($perPage);

            // Preload matching LoanInstallment by receipt numbers
            $receiptNos = $paginated->getCollection()->map(fn($t) => $t->receipt_number)->filter()->unique()->values();
            $trxNos = $paginated->getCollection()->map(fn($t) => $t->transaction_number)->filter()->unique()->values();
            $allVouchers = $receiptNos->merge($trxNos)->unique()->values();

            $installments = LoanInstallment::with('loan')
                ->whereIn('receipt_number', $allVouchers)
                ->get()
                ->keyBy('receipt_number');

            $paginated->getCollection()->transform(function (Transaction $trx) use ($installments) {
                $receiptNo = $trx->receipt_number ?: $trx->transaction_number;
                $isDisbursement = in_array($trx->category, ['pencairan_pinjaman', 'pinjaman', 'loan_disbursement'])
                    || str_contains(strtolower($trx->description ?? ''), 'pencairan')
                    || in_array(strtolower($trx->type ?? ''), ['withdrawal', 'expense']);

                $transactionType = $isDisbursement ? 'DISBURSEMENT' : 'REPAYMENT';
                $member = $trx->member;

                $loanNumber = null;
                $installmentOrder = null;
                $principalAmount = 0.0;
                $interestAmount = 0.0;
                $penaltyAmount = 0.0;

                $inst = $installments->get($trx->receipt_number) ?? $installments->get($trx->transaction_number);
                if ($inst) {
                    $loanNumber = $inst->loan?->loan_code;
                    $installmentOrder = (int) $inst->installment_number;
                    $principalAmount = (float) $inst->principal_amount;
                    $interestAmount = (float) $inst->interest_amount;
                    $penaltyAmount = (float) ($inst->penalty_fee ?? $inst->penalty_amount ?? 0);
                } else {
                    if (preg_match('/(SH-[A-Za-z0-9-]+)/i', $trx->description ?? '', $m)) {
                        $loanNumber = $m[1];
                    }
                    if ($isDisbursement) {
                        $installmentOrder = null;
                        $principalAmount = (float) $trx->amount;
                        $interestAmount = 0.0;
                        $penaltyAmount = 0.0;
                    } else {
                        if (preg_match('/ke-(\d+)/i', $trx->description ?? '', $mOrd)) {
                            $installmentOrder = (int) $mOrd[1];
                        }
                        $principalAmount = (float) $trx->amount;
                        $interestAmount = 0.0;
                        $penaltyAmount = (float) ($trx->denda ?? 0);
                    }
                }

                $totalAmount = (float) $trx->amount;
                $dateStr = $trx->transaction_date 
                    ? (is_string($trx->transaction_date) ? substr($trx->transaction_date, 0, 10) : $trx->transaction_date->format('Y-m-d'))
                    : null;

                return [
                    'id'                => $trx->id,
                    'receipt_number'    => $receiptNo,
                    'no_bukti'          => $receiptNo,
                    'transaction_type'  => $transactionType,
                    'tipe_transaksi'    => $transactionType,
                    'member_id'         => $trx->member_id,
                    'member_name'       => $member?->name ?? 'Anggota',
                    'nama_anggota'      => $member?->name ?? 'Anggota',
                    'member_number'     => $member?->member_number ?? '-',
                    'no_anggota'        => $member?->member_number ?? '-',
                    'loan_number'       => $loanNumber ?? '-',
                    'no_sh'             => $loanNumber ?? '-',
                    'installment_order' => $installmentOrder,
                    'angsuran_ke'       => $installmentOrder,
                    'principal_amount'  => $principalAmount,
                    'pokok'             => $principalAmount,
                    'interest_amount'   => $interestAmount,
                    'jasa'              => $interestAmount,
                    'penalty_amount'    => $penaltyAmount,
                    'denda'             => $penaltyAmount,
                    'total_amount'      => $totalAmount,
                    'total_bayar'       => $totalAmount,
                    'date'              => $dateStr,
                    'tanggal'           => $dateStr,
                    'description'       => $trx->description,
                    'status'            => $trx->status ?? 'approved',
                ];
            });

            // Summary Khusus Operasional Pinjaman
            $totalPinjamanDicairkan = (float) Loan::whereIn('status', ['DISBURSED', 'active', 'completed', 'lunas', 'approved'])
                ->whereNotNull('disbursement_date')
                ->sum('amount');

            if ($totalPinjamanDicairkan <= 0) {
                $totalPinjamanDicairkan = (float) Transaction::where(function ($q) {
                    $q->whereIn('category', ['pencairan_pinjaman', 'pinjaman', 'loan_disbursement'])
                      ->orWhere('description', 'like', '%Pencairan Pinjaman%');
                })->where('type', 'withdrawal')->sum('amount');
            }

            $totalPokokDiterima = (float) LoanInstallment::where('status', 'paid')->sum('principal_amount');
            $totalJasaDiterima  = (float) LoanInstallment::where('status', 'paid')->sum('interest_amount');
            $totalDendaDiterima = (float) LoanInstallment::where('status', 'paid')->sum(DB::raw('COALESCE(penalty_fee, penalty_amount, 0)'));
            $totalSisaPinjaman  = max(0.0, round($totalPinjamanDicairkan - $totalPokokDiterima, 2));

            $summary = [
                'total_pinjaman_dicairkan' => $totalPinjamanDicairkan,
                'total_pokok_diterima'     => $totalPokokDiterima,
                'total_jasa_diterima'      => $totalJasaDiterima,
                'total_denda_diterima'     => $totalDendaDiterima,
                'total_sisa_pinjaman'      => $totalSisaPinjaman,
            ];

            return response()->json([
                'success' => true,
                'status'  => 'success',
                'message' => 'Riwayat transaksi operasional pinjaman berhasil diambil.',
                'summary' => $summary,
                'data'    => $paginated,
            ], 200);

        } catch (\Exception $e) {
            Log::error('[LoanController] Gagal mengambil riwayat transaksi pinjaman: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'status'  => 'error',
                'message' => 'Gagal mengambil riwayat transaksi pinjaman: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Update / Koreksi Data Kartu Pinjaman
     * Endpoint: PUT /api/loans/{id}, PATCH /api/loans/{id}, PUT /api/admin/loans/{id}
     */
    public function update(Request $request, int $id): JsonResponse
    {
        $loan = Loan::with(['member', 'installments'])->find($id);

        if (!$loan) {
            return response()->json([
                'success' => false,
                'status'  => 'error',
                'message' => 'Data pinjaman tidak ditemukan.',
            ], 404);
        }

        // Ambil input dengan fallback alias fleksibel
        $amountInput            = $request->input('amount') ?? $request->input('principal_amount') ?? $request->input('plafon') ?? $request->input('nominal');
        $rateInput              = $request->input('interest_rate') ?? $request->input('bunga') ?? $request->input('rate');
        $tenorInput             = $request->input('duration_months') ?? $request->input('tenor_months') ?? $request->input('tenor');
        $methodInput            = $request->input('interest_method') ?? $request->input('metode_bunga');
        $appDateInput           = $request->input('application_date') ?? $request->input('tanggal_pengajuan');
        $disbDateInput          = $request->input('disbursement_date') ?? $request->input('tanggal_pencairan');
        $dueDateInput           = $request->input('due_date') ?? $request->input('jatuh_tempo');
        $remainingPrincipalInput= $request->input('remaining_principal') ?? $request->input('remaining_amount') ?? $request->input('sisa_pokok') ?? $request->input('current_balance');
        $notesInput             = $request->input('notes') ?? $request->input('keterangan') ?? $request->input('catatan');
        $purposeInput           = $request->input('purpose') ?? $request->input('tujuan');
        $collateralInput        = $request->input('collateral') ?? $request->input('agunan') ?? $request->input('jaminan');
        $statusInput            = $request->input('status');
        $memberIdInput          = $request->input('member_id');

        $newAmount = $amountInput !== null ? $this->cleanAmount($amountInput) : (float) $loan->amount;
        $newTenor  = $tenorInput !== null ? max(1, (int) $tenorInput) : (int) ($loan->tenor_months ?? $loan->duration_months ?? 12);
        $newRate   = $rateInput !== null ? (float) $rateInput : (float) $loan->interest_rate;
        $newMethod = $methodInput !== null ? $methodInput : ($loan->interest_method ?? 'declining_balance');

        // Hitung total pokok yang sudah dibayar pada cicilan
        $totalPaidPrincipal = (float) $loan->installments()->where('status', 'paid')->sum('principal_amount');

        if ($remainingPrincipalInput !== null) {
            $newRemainingPrincipal = $this->cleanAmount($remainingPrincipalInput);
        } else {
            $newRemainingPrincipal = max(0.0, round($newAmount - $totalPaidPrincipal, 2));
        }

        $principalChunk      = (float) ceil($newAmount / $newTenor);
        $firstMonthInterest  = round($newAmount * ($newRate / 100), 0);
        $monthlyInstallment  = $principalChunk + $firstMonthInterest;

        $updateData = [
            'amount'              => $newAmount,
            'duration_months'     => $newTenor,
            'tenor_months'        => $newTenor,
            'interest_rate'       => $newRate,
            'interest_method'     => $newMethod,
            'monthly_installment' => $monthlyInstallment,
            'remaining_amount'    => $newRemainingPrincipal,
            'remaining_principal' => $newRemainingPrincipal,
        ];

        if ($appDateInput !== null) {
            $updateData['application_date'] = Carbon::parse($appDateInput)->toDateString();
        }
        if ($disbDateInput !== null) {
            $updateData['disbursement_date'] = Carbon::parse($disbDateInput)->toDateString();
        }
        if ($dueDateInput !== null) {
            $updateData['due_date'] = Carbon::parse($dueDateInput)->toDateString();
        }
        if ($notesInput !== null) {
            $updateData['notes'] = $notesInput;
        }
        if ($purposeInput !== null) {
            $updateData['purpose'] = $purposeInput;
        }
        if ($collateralInput !== null) {
            $updateData['collateral'] = $collateralInput;
        }
        if ($statusInput !== null) {
            $updateData['status'] = $statusInput;
        }
        if ($memberIdInput !== null && Member::where('id', $memberIdInput)->exists()) {
            $updateData['member_id'] = $memberIdInput;
        }

        DB::beginTransaction();
        try {
            $loan->update($updateData);

            // Jika belum ada angsuran yang berstatus paid, perbarui seluruh baris cicilan
            $hasPaidInstallments = $loan->installments()->where('status', 'paid')->exists();
            if (!$hasPaidInstallments && $loan->installments()->exists()) {
                $loan->installments()->delete();
                $disbDate = $loan->disbursement_date ? Carbon::parse($loan->disbursement_date) : now();
                $this->generateInstallmentRows($loan, $disbDate);
            }

            DB::commit();

            return response()->json([
                'success' => true,
                'status'  => 'success',
                'message' => 'Kartu pinjaman berhasil diperbarui.',
                'data'    => $loan->fresh(['member', 'installments']),
            ], 200);

        } catch (\Exception $e) {
            DB::rollBack();
            Log::error("[LoanController] Gagal update pinjaman #{$id}: " . $e->getMessage());
            return response()->json([
                'success' => false,
                'status'  => 'error',
                'message' => 'Gagal memperbarui data pinjaman: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Hapus Data Kartu Pinjaman
     * Endpoint: DELETE /api/loans/{id}, DELETE /api/admin/loans/{id}
     */
    public function destroy(Request $request, int $id): JsonResponse
    {
        $loan = Loan::with('installments')->find($id);

        if (!$loan) {
            return response()->json([
                'success' => false,
                'status'  => 'error',
                'message' => 'Data pinjaman tidak ditemukan.',
            ], 404);
        }

        $paidInstallmentsCount = $loan->installments()->where('status', 'paid')->count();
        $force = $request->boolean('force');

        if ($paidInstallmentsCount > 0 && !$force) {
            return response()->json([
                'success' => false,
                'status'  => 'error',
                'message' => "Pinjaman tidak dapat dihapus karena sudah memiliki {$paidInstallmentsCount} transaksi angsuran yang sudah dibayar.",
            ], 422);
        }

        DB::beginTransaction();
        try {
            // Hapus baris jadwal cicilan terkait
            $loan->installments()->delete();

            // Bersihkan transaksi pencairan terkait jika ada
            Transaction::where('receipt_number', 'KK-' . $loan->loan_code)->delete();
            Transaction::where('description', 'like', "%({$loan->loan_code})%")->delete();

            // Hapus record pinjaman
            $loan->delete();

            DB::commit();

            return response()->json([
                'success' => true,
                'status'  => 'success',
                'message' => 'Kartu pinjaman berhasil dihapus.',
            ], 200);

        } catch (\Exception $e) {
            DB::rollBack();
            Log::error("[LoanController] Gagal menghapus pinjaman #{$id}: " . $e->getMessage());
            return response()->json([
                'success' => false,
                'status'  => 'error',
                'message' => 'Gagal menghapus kartu pinjaman: ' . $e->getMessage(),
            ], 500);
        }
    }
}

