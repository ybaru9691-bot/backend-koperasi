<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Account;
use App\Models\ChartOfAccount;
use App\Models\JournalDetail;
use App\Models\JournalEntry;
use App\Models\Loan;
use App\Models\Member;
use App\Models\Transaction;
use App\Services\JournalService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class MemberResignationController extends Controller
{
    /**
     * Hitung tier biaya administrasi penutupan buku / pengunduran diri:
     * - Saldo < Rp 1.000.000 -> Potongan Rp 100.000
     * - Saldo Rp 1.000.000 - Rp 10.000.000 -> Potongan Rp 150.000
     * - Saldo Rp 10.000.001 - Rp 50.000.000 -> Potongan Rp 200.000
     * - Saldo > Rp 50.000.000 -> Potongan Rp 300.000
     */
    public static function calculateExitFee(float $amount): float
    {
        if ($amount < 1000000) {
            return 100000.00;
        } elseif ($amount <= 10000000) {
            return 150000.00;
        } elseif ($amount <= 50000000) {
            return 200000.00;
        } else {
            return 300000.00;
        }
    }

    /**
     * Preview Penutupan Rekening Buku Putih Saja (Untuk Modal Dialog Flutter)
     * Endpoint: GET /api/members/{id}/close-white-book/preview
     */
    public function previewCloseWhiteBook(Request $request, $id): JsonResponse
    {
        $member = Member::find($id);
        if (!$member) {
            return response()->json([
                'success' => false,
                'message' => 'Anggota tidak ditemukan',
            ], 404);
        }

        $daily = (float) ($member->daily_savings ?? 0.0);
        $fee   = self::calculateExitFee($daily);
        $canClose = $daily >= $fee && $daily > 0;
        $netRefund = max(0.0, $daily - $fee);

        return response()->json([
            'success' => true,
            'data'    => [
                'member_id'     => $member->id,
                'member_name'   => $member->name,
                'daily_savings' => $daily,
                'fee'           => $fee,
                'net_refund'    => $netRefund,
                'can_close'     => $canClose,
                'message'       => $canClose ? 'Siap ditutup' : 'Saldo simpanan harian tidak mencukupi untuk biaya potongan penutupan',
            ],
        ], 200);
    }

    /**
     * A. Endpoint Tutup Rekening Buku Putih Saja
     * Endpoint: POST /api/members/{id}/close-white-book
     */
    public function closeWhiteBook(Request $request, $id): JsonResponse
    {
        $authUser = $request->user();
        $userRole = strtolower($authUser->role ?? '');
        $allowedRoles = ['admin', 'manager', 'ketua', 'pengurus', 'superadmin'];

        if (!$authUser || !in_array($userRole, $allowedRoles)) {
            return response()->json([
                'success' => false,
                'message' => 'Akses ditolak. Aksi penutupan buku putih hanya dapat dilakukan oleh Pengurus / Manajer.',
            ], 403);
        }

        $member = Member::find($id);
        if (!$member) {
            return response()->json([
                'success' => false,
                'message' => 'Anggota tidak ditemukan',
            ], 404);
        }

        $daily = (float) ($member->daily_savings ?? 0.0);
        if ($daily <= 0) {
            return response()->json([
                'success' => false,
                'message' => 'Saldo Simpanan Harian (Buku Putih) sudah kosong (Rp 0) atau rekening sudah ditutup.',
            ], 400);
        }

        // 2. Hitung tier biaya potongan berdasarkan saldo simpanan harian tersebut
        $fee = self::calculateExitFee($daily);

        // 3. Validasi: Saldo simpanan harian harus >= biaya potongan
        if ($daily < $fee) {
            $formattedDaily = number_format($daily, 0, ',', '.');
            $formattedFee   = number_format($fee, 0, ',', '.');
            return response()->json([
                'success' => false,
                'message' => "Saldo Simpanan Harian (Rp {$formattedDaily}) tidak mencukupi untuk biaya potongan penutupan (Rp {$formattedFee}).",
            ], 400);
        }

        $netRefund = $daily - $fee;

        // Normalisasi input nomor bukti KK
        $rawVoucher = $request->input('voucher_no')
            ?? $request->input('transaction_number')
            ?? $request->input('voucher_number')
            ?? $request->input('receipt_number')
            ?? $request->input('no_bukti');

        if ($rawVoucher !== null) {
            $rawVoucher = trim((string) $rawVoucher);
        }

        if (empty($rawVoucher)) {
            $rawVoucher = 'KK-CLOSE-BP-' . date('Ymd') . '-' . str_pad((string) mt_rand(1, 9999), 4, '0', STR_PAD_LEFT);
        } else {
            // Validasi duplikasi nomor bukti
            if (Transaction::where('transaction_number', $rawVoucher)->orWhere('receipt_number', $rawVoucher)->exists() || JournalEntry::where('voucher_number', $rawVoucher)->exists()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Maaf, Transaksi KK ini sudah ada coba lagi dengan no berbeda',
                    'errors'  => [
                        'voucher_no'         => ['Maaf, Transaksi KK ini sudah ada coba lagi dengan no berbeda'],
                        'transaction_number' => ['Maaf, Transaksi KK ini sudah ada coba lagi dengan no berbeda'],
                    ],
                ], 422);
            }
        }

        $rawDate = $request->input('date') ?? $request->input('transaction_date') ?? now()->toDateString();
        $transactionDate = Carbon::parse($rawDate)->format('Y-m-d');

        try {
            DB::beginTransaction();

            $accountKas = Account::where('account_type', 'kas')->first();
            if (!$accountKas) {
                $accountKas = Account::firstOrCreate(
                    ['account_number' => 'KAS-101'],
                    [
                        'account_name' => 'Kas Koperasi',
                        'account_type' => 'kas',
                        'category'     => 'asset',
                        'balance'      => 0.00,
                    ]
                );
            }

            if ($netRefund > 0) {
                $accountKas->decrement('balance', $netRefund);
            }

            // 4. Terbitkan transaksi Kas Keluar (KK) pencairan tabungan harian
            $desc = "Penutupan Rekening Buku Putih (Simpanan Harian) - {$member->name} (Potongan Penalti: Rp " . number_format($fee, 0, ',', '.') . ")";
            $transaction = Transaction::create([
                'transaction_number' => $rawVoucher,
                'receipt_number'     => $rawVoucher,
                'member_id'          => $member->id,
                'account_id'         => $accountKas->id,
                'book_type'          => 'BUKU_PUTIH',
                'operator_id'        => $authUser->id,
                'approved_by'        => $authUser->id,
                'type'               => 'withdrawal',
                'category'           => 'penarikan_simpanan',
                'amount'             => $netRefund,
                'beginning_balance'  => $daily,
                'ending_balance'     => 0.00,
                'payment_method'     => 'cash',
                'transaction_date'   => $transactionDate,
                'description'        => $desc,
                'status'             => 'approved',
                'approved_at'        => now(),
            ]);

            // Double-Entry Jurnal Umum
            JournalEntry::where('transaction_id', $transaction->id)->delete();
            $journal = JournalEntry::create([
                'transaction_id' => $transaction->id,
                'entry_date'     => $transactionDate,
                'voucher_number' => $rawVoucher,
                'description'    => $desc,
                'created_by'     => $authUser->id,
            ]);

            $coaHarian  = ChartOfAccount::where('account_code', '2021')->first()
                ?? ChartOfAccount::firstOrCreate(
                    ['account_code' => '2021'],
                    [
                        'account_name'   => 'Simpanan Harian',
                        'account_type'   => 'LIABILITY',
                        'normal_balance' => 'CREDIT',
                        'is_active'      => true,
                    ]
                );

            $coaPenalti = ChartOfAccount::where('account_code', '4183')->first()
                ?? ChartOfAccount::where('account_code', '4192')->first()
                ?? ChartOfAccount::firstOrCreate(
                    ['account_code' => '4183'],
                    [
                        'account_name'   => 'Pendapatan Administrasi & Denda',
                        'account_type'   => 'REVENUE',
                        'normal_balance' => 'CREDIT',
                        'is_active'      => true,
                    ]
                );

            $cashCoa = ChartOfAccount::where('account_code', '1000')->first()
                ?? ChartOfAccount::where('account_code', '1001')->first()
                ?? ChartOfAccount::firstOrCreate(
                    ['account_code' => '1000'],
                    [
                        'account_name'   => 'Kas Tunai',
                        'account_type'   => 'ASSET',
                        'normal_balance' => 'DEBIT',
                        'is_active'      => true,
                    ]
                );

            // Debet: Akun Simpanan Harian (Buku Putih - 2021) sebesar total saldo
            JournalDetail::create([
                'journal_entry_id' => $journal->id,
                'account_id'       => $coaHarian->id,
                'debit'            => $daily,
                'credit'           => 0,
                'description'      => $desc,
            ]);

            // Kredit: Akun Pendapatan Administrasi / Biaya Keluar (sebesar potongan)
            if ($fee > 0) {
                JournalDetail::create([
                    'journal_entry_id' => $journal->id,
                    'account_id'       => $coaPenalti->id,
                    'debit'            => 0,
                    'credit'           => $fee,
                    'description'      => "Biaya Administrasi Penutupan Rekening Buku Putih - {$member->name}",
                ]);
            }

            // Kredit: Akun Kas (1000) sebesar nominal bersih yang diserahkan
            if ($netRefund > 0) {
                JournalDetail::create([
                    'journal_entry_id' => $journal->id,
                    'account_id'       => $cashCoa->id,
                    'debit'            => 0,
                    'credit'           => $netRefund,
                    'description'      => "Pencairan Kas Penutupan Buku Putih - {$member->name}",
                ]);
            }

            // 5. Set saldo Buku Putih menjadi 0 dan ubah status akun Buku Putih menjadi closed
            // 6. STATUS ANGGOTA KOPERASI TETAP ACTIVE (karena masih memiliki Buku Biru)
            $member->update([
                'daily_savings'  => 0.00,
                'has_buku_putih' => false,
            ]);

            DB::commit();

            Cache::forget('manager_dashboard_summary_data');
            Cache::forget('dashboard_summary_data');

            return response()->json([
                'success' => true,
                'status'  => 'success',
                'message' => 'Rekening Buku Putih berhasil ditutup dan dana bersih sebesar Rp ' . number_format($netRefund, 0, ',', '.') . ' telah dicairkan.',
                'data'    => [
                    'member_id'       => $member->id,
                    'member_name'     => $member->name,
                    'daily_savings'   => $daily,
                    'fee'             => $fee,
                    'net_refund'      => $netRefund,
                    'voucher_no'      => $rawVoucher,
                    'member_status'   => $member->status,
                    'has_buku_putih'  => false,
                    'has_buku_biru'   => (bool) $member->has_buku_biru,
                ],
            ], 200);

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
                'message' => 'Gagal memproses penutupan buku putih: ' . $e->getMessage(),
            ], 500);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'success' => false,
                'message' => 'Gagal memproses penutupan buku putih: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Preview Resign Total / Tutup Buku Biru (Untuk Modal Dialog Flutter)
     * Endpoint: GET /api/members/{id}/resign-total/preview & GET /api/members/{id}/resign/preview
     */
    public function previewResignTotal(Request $request, $id): JsonResponse
    {
        $member = Member::find($id);
        if (!$member) {
            return response()->json([
                'success' => false,
                'message' => 'Anggota tidak ditemukan',
            ], 404);
        }

        $sp = (float) ($member->principal_savings ?? 0.0);
        $sw = (float) ($member->mandatory_savings ?? 0.0);
        $ss = (float) ($member->voluntary_savings ?? 0.0);
        $daily = (float) ($member->daily_savings ?? 0.0);
        $totalSaham = $sp + $sw + $ss;
        $totalSimpanan = $totalSaham + $daily;

        // Cek pinjaman aktif
        $activeLoans = Loan::where('member_id', $member->id)
            ->whereIn('status', ['approved', 'disbursed', 'active', 'pending_admin', 'pending_manager'])
            ->where('remaining_amount', '>', 0)
            ->get();
        $loanRemaining = (float) $activeLoans->sum('remaining_amount');

        // Cek Tunggakan SW >= 6 Bulan
        $sixMonthsAgo = Carbon::now()->subMonths(6)->toDateString();
        $regDate = $member->created_at ? Carbon::parse($member->created_at) : null;
        $isSwArrears6Months = false;
        if ($regDate && $regDate->lt(Carbon::parse($sixMonthsAgo))) {
            $hasRecentDeposit = Transaction::where('member_id', $member->id)
                ->where('status', 'approved')
                ->where('type', 'deposit')
                ->where('transaction_date', '>=', $sixMonthsAgo)
                ->exists();
            if (!$hasRecentDeposit && $sw <= 20000) {
                $isSwArrears6Months = true;
            }
        }
        $eligibleForShu = !$isSwArrears6Months;

        // Hitung tier biaya potongan berdasarkan Total Saham
        $penalty = self::calculateExitFee($totalSaham > 0 ? $totalSaham : $totalSimpanan);
        $netRefund = max(0.0, $totalSimpanan - $penalty);

        return response()->json([
            'success' => true,
            'data'    => [
                'member_id'              => $member->id,
                'member_name'            => $member->name,
                'principal_savings'      => $sp,
                'mandatory_savings'      => $sw,
                'voluntary_savings'      => $ss,
                'total_saham'            => $totalSaham,
                'daily_savings'          => $daily,
                'total_simpanan'         => $totalSimpanan,
                'has_active_loan'        => ($loanRemaining > 0),
                'loan_remaining'         => $loanRemaining,
                'is_sw_arrears_6_months' => $isSwArrears6Months,
                'eligible_for_shu'       => $eligibleForShu,
                'sw_arrears_warning'     => $isSwArrears6Months ? 'Tunggakan SW ≥ 6 bulan: Hak SHU/Deviden gugur' : null,
                'penalty'                => $penalty,
                'net_refund'             => $netRefund,
                'can_resign'             => ($loanRemaining <= 0),
            ],
        ], 200);
    }

    /**
     * B. Endpoint Resign Total / Tutup Buku Biru (Keluar Koperasi)
     * Endpoint: POST /api/members/{id}/resign-total & POST /api/members/{id}/resign
     */
    public function resignTotal(Request $request, $id): JsonResponse
    {
        return $this->resignMember($request, $id);
    }

    /**
     * Layanan Pengunduran Diri / Tutup Keanggotaan Anggota (Resign / Exit)
     * Endpoint: POST /api/manager/members/{id}/resign & POST /api/admin/members/{id}/resign & POST /api/members/{id}/resign-total
     */
    public function resignMember(Request $request, $id): JsonResponse
    {
        $authUser = $request->user();
        $userRole = strtolower($authUser->role ?? '');
        $allowedRoles = ['admin', 'manager', 'ketua', 'pengurus', 'superadmin'];

        if (!$authUser || !in_array($userRole, $allowedRoles)) {
            return response()->json([
                'success' => false,
                'message' => 'Akses ditolak. Aksi keluar anggota hanya dapat dilakukan oleh Pengurus / Manajer.'
            ], 403);
        }

        $member = Member::find($id);
        if (!$member) {
            return response()->json([
                'success' => false,
                'message' => 'Anggota tidak ditemukan'
            ], 404);
        }

        if ($member->status === 'resigned' || $member->status === 'inactive') {
            return response()->json([
                'success' => false,
                'message' => 'Anggota ini sudah berstatus tidak aktif atau telah mengundurkan diri.'
            ], 400);
        }

        // 1. Cek pinjaman aktif: Tolak jika anggota masih memiliki pokok/bunga pinjaman yang belum lunas
        $activeLoans = Loan::where('member_id', $member->id)
            ->whereIn('status', ['approved', 'disbursed', 'active', 'pending_admin', 'pending_manager'])
            ->where('remaining_amount', '>', 0)
            ->get();
        $loanRemaining = (float) $activeLoans->sum('remaining_amount');

        // Jika rute resign-total dipanggil dan pinjaman belum lunas serta tidak ada instruksi auto_deduct_loan
        if ($loanRemaining > 0 && !$request->boolean('auto_deduct_loan', false) && ($request->is('*/resign-total*') || $request->has('voucher_no') || $request->has('transaction_number'))) {
            $formattedLoan = number_format($loanRemaining, 0, ',', '.');
            return response()->json([
                'success' => false,
                'message' => "Tidak dapat memproses resign total: Anggota masih memiliki pinjaman aktif yang belum lunas sebesar Rp {$formattedLoan}. Harap lunasi pinjaman terlebih dahulu.",
            ], 400);
        }

        // 2. Hitung Total Modal Saham: Saldo SP + Saldo SW + Saldo SS
        $sp = (float) ($member->principal_savings ?? 0.0);
        $sw = (float) ($member->mandatory_savings ?? 0.0);
        $ss = (float) ($member->voluntary_savings ?? 0.0);
        $daily = (float) ($member->daily_savings ?? 0.0);

        $totalSaham = $sp + $sw + $ss;
        $totalSimpanan = $totalSaham + $daily;

        // 3. Cek Tunggakan SW >= 6 Bulan
        $sixMonthsAgo = Carbon::now()->subMonths(6)->toDateString();
        $regDate = $member->created_at ? Carbon::parse($member->created_at) : null;
        $isSwArrears6Months = false;
        if ($regDate && $regDate->lt(Carbon::parse($sixMonthsAgo))) {
            $hasRecentDeposit = Transaction::where('member_id', $member->id)
                ->where('status', 'approved')
                ->where('type', 'deposit')
                ->where('transaction_date', '>=', $sixMonthsAgo)
                ->exists();
            if (!$hasRecentDeposit && $sw <= 20000) {
                $isSwArrears6Months = true;
            }
        }
        $eligibleForShu = !$isSwArrears6Months;

        // 4. Hitung tier biaya potongan berdasarkan Total Saham
        $penalty = self::calculateExitFee($totalSaham > 0 ? $totalSaham : $totalSimpanan);

        // Jika auto_deduct_loan diaktifkan (misal legacy test flow), kurangi sisa pinjaman
        $loanDeduction = ($loanRemaining > 0 && ($request->boolean('auto_deduct_loan') || !$request->is('*/resign-total*'))) ? $loanRemaining : 0.0;
        $netRefund = max(0.0, $totalSimpanan - $penalty - $loanDeduction);

        // Normalisasi input nomor bukti KK
        $rawVoucher = $request->input('voucher_no')
            ?? $request->input('transaction_number')
            ?? $request->input('voucher_number')
            ?? $request->input('receipt_number')
            ?? $request->input('no_bukti');

        if ($rawVoucher !== null) {
            $rawVoucher = trim((string) $rawVoucher);
        }

        if (empty($rawVoucher)) {
            $rawVoucher = 'KK-RESIGN-' . date('Ymd') . '-' . str_pad((string) mt_rand(1, 9999), 4, '0', STR_PAD_LEFT);
        } else {
            // Validasi duplikasi nomor bukti
            if (Transaction::where('transaction_number', $rawVoucher)->orWhere('receipt_number', $rawVoucher)->exists() || JournalEntry::where('voucher_number', $rawVoucher)->exists()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Maaf, Transaksi KK ini sudah ada coba lagi dengan no berbeda',
                    'errors'  => [
                        'voucher_no'         => ['Maaf, Transaksi KK ini sudah ada coba lagi dengan no berbeda'],
                        'transaction_number' => ['Maaf, Transaksi KK ini sudah ada coba lagi dengan no berbeda'],
                    ],
                ], 422);
            }
        }

        $rawDate = $request->input('date') ?? $request->input('transaction_date') ?? now()->toDateString();
        $transactionDate = Carbon::parse($rawDate)->format('Y-m-d');

        try {
            DB::beginTransaction();

            $accountKas = Account::where('account_type', 'kas')->first();
            if (!$accountKas) {
                $accountKas = Account::firstOrCreate(
                    ['account_number' => 'KAS-101'],
                    [
                        'account_name' => 'Kas Koperasi',
                        'account_type' => 'kas',
                        'category'     => 'asset',
                        'balance'      => 0.00,
                    ]
                );
            }

            if ($netRefund > 0) {
                $accountKas->decrement('balance', $netRefund);
            }

            // 6. Terbitkan transaksi Kas Keluar (KK) pencairan modal anggota
            $desc = "Pengembalian Simpanan Bersih Anggota Keluar (Resign Total) - {$member->name} (Potongan Penalti: Rp " . number_format($penalty, 0, ',', '.') . ")";
            $trx = Transaction::create([
                'transaction_number' => $rawVoucher,
                'receipt_number'     => $rawVoucher,
                'member_id'          => $member->id,
                'account_id'         => $accountKas->id,
                'book_type'          => 'BUKU_BIRU',
                'operator_id'        => $authUser->id,
                'approved_by'        => $authUser->id,
                'type'               => 'withdrawal',
                'category'           => 'penarikan_simpanan',
                'amount'             => $netRefund,
                'beginning_balance'  => $totalSimpanan,
                'ending_balance'     => 0.00,
                'payment_method'     => 'cash',
                'transaction_date'   => $transactionDate,
                'description'        => $desc,
                'status'             => 'approved',
                'approved_at'        => now(),
            ]);

            try {
                app(JournalService::class)->generateJournal($trx);
            } catch (\Exception $e) {
                Log::warning("[MemberResignationController] Gagal generate jurnal resign: " . $e->getMessage());
            }

            // Lunaskan pinjaman jika ada deduction
            if ($loanDeduction > 0) {
                foreach ($activeLoans as $loan) {
                    $loan->update([
                        'status'           => 'paid_off',
                        'remaining_amount' => 0.00,
                        'notes'            => ($loan->notes ? $loan->notes . ' | ' : '') . 'Dilunasi melalui pemotongan simpanan saat keluar/resign.',
                    ]);
                }
            }

            // 7. Ubah status keanggotaan menjadi resigned / inactive dan nol-kan simpanan
            $member->update([
                'principal_savings' => 0.00,
                'mandatory_savings' => 0.00,
                'voluntary_savings' => 0.00,
                'daily_savings'     => 0.00,
                'has_buku_biru'     => false,
                'has_buku_putih'    => false,
                'status'            => 'inactive',
            ]);

            DB::commit();

            Cache::forget('manager_dashboard_summary_data');
            Cache::forget('dashboard_summary_data');

            return response()->json([
                'success' => true,
                'status'  => 'success',
                'message' => 'Proses pengunduran diri / resign total anggota berhasil diselesaikan.',
                'data'    => [
                    'member_id'              => $member->id,
                    'member_name'            => $member->name,
                    'total_saham'            => $totalSaham,
                    'daily_savings'          => $daily,
                    'total_simpanan'         => $totalSimpanan,
                    'penalty'                => $penalty,
                    'loan_deduction'         => $loanDeduction,
                    'net_refund'             => $netRefund,
                    'voucher_no'             => $rawVoucher,
                    'status'                 => 'inactive',
                    'is_sw_arrears_6_months' => $isSwArrears6Months,
                    'eligible_for_shu'       => $eligibleForShu,
                ],
            ], 200);

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
                'message' => 'Gagal memproses pengunduran diri anggota: ' . $e->getMessage(),
            ], 500);
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('[MemberResignationController] Error resignMember: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Gagal memproses pengunduran diri anggota: ' . $e->getMessage(),
            ], 500);
        }
    }
}
