<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Member;
use App\Models\Transaction;
use App\Models\Loan;
use App\Models\LoanInstallment;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Cache;
use Carbon\Carbon;

class DashboardController extends Controller
{
    /**
     * Mengembalikan data profil user/admin yang sedang login.
     * Endpoint: GET /api/me
     */
    public function me(Request $request): JsonResponse
    {
        $user = $request->user();
        
        $role = ($user instanceof Member)
            ? 'anggota'
            : ($user->role ?? 'admin');

        return response()->json([
            'success' => true,
            'message' => 'Data user berhasil diambil',
            'data' => [
                'id'           => $user->id,
                'name'         => $user->name,
                'email'        => $user->email ?? null,
                'phone_number' => $user->phone_number ?? null,
                'avatar'       => $user->avatar ?? null,
                'avatar_url'   => $user->avatar_url ?? null,
                'nik'          => $user->nik ?? null,
                'role'         => $role,
            ],
        ], 200);
    }

    /**
     * Mengembalikan data ringkasan Dashboard (Membedakan Role Admin vs Anggota)
     * Endpoint: GET /api/dashboard-summary
     */
    public function summary(Request $request): JsonResponse
    {
        try {
            $user = $request->user();
            
            if (!$user) {
                return response()->json([
                    'success' => false,
                    'message' => 'Sesi tidak valid. Silakan login ulang.',
                    'data' => null,
                ], 401);
            }

            // Tentukan role berdasarkan tipe instance model
            if ($user instanceof Member) {
                return $this->getAnggotaDashboard($user);
            }

            $role = strtolower($user->role ?? 'anggota');
            if ($role === 'admin' || $role === 'manager' || $role === 'ketua' || $role === 'pengurus') {
                return $this->getAdminDashboard();
            }

            return $this->getAnggotaDashboard($user);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'role' => 'admin',
                'message' => 'Gagal mengambil data dashboard: ' . $e->getMessage(),
                'error_detail' => [
                    'line' => $e->getLine(),
                    'file' => basename($e->getFile()),
                ],
                'data' => [
                    'total_members' => 0,
                    'total_aset' => 0,
                    'total_savings' => 0,
                    'total_loans' => 0,
                    'total_kas_masuk_current_month' => 0,
                    'total_kas_keluar_current_month' => 0,
                    'cashflow_chart' => [],
                    'pending_approvals_count' => 0,
                    'pending_approvals' => [],
                    'latest_transactions' => [],
                ],
            ], 200);
        }
    }

    /**
     * Mengembalikan data profil dan ringkasan lengkap untuk Anggota yang sedang login.
     * Endpoint: GET /api/member/profile
     */
    public function memberProfile(Request $request): JsonResponse
    {
        try {
            $user = $request->user();
            
            if ($user instanceof Member) {
                $member = $user;
            } else {
                $member = Member::where('user_id', $user->id)->first();
            }

            if (!$member) {
                return response()->json([
                    'success' => false,
                    'status' => 'error',
                    'message' => 'Profil anggota tidak ditemukan',
                    'data' => null
                ], 404);
            }

            $pokok = (float) ($member->principal_savings ?? 0);
            $wajib = (float) ($member->mandatory_savings ?? 0);
            $sukarela = (float) ($member->voluntary_savings ?? 0);
            $daily = (float) ($member->daily_savings ?? 0);
            $totalSaldo = $pokok + $wajib + $sukarela + $daily;

            // Riwayat transaksi tanpa mock
            $dbTransactions = Transaction::where('member_id', $member->id)
                ->latest()
                ->get();

            $userTransactions = $dbTransactions->map(function ($trx) {
                $typeIn = in_array($trx->type, ['deposit', 'in']);
                $dateStr = $trx->transaction_date 
                    ? (is_string($trx->transaction_date) ? substr($trx->transaction_date, 0, 10) : $trx->transaction_date->format('Y-m-d'))
                    : ($trx->created_at ? $trx->created_at->format('Y-m-d') : date('Y-m-d'));

                return [
                    'code'   => $trx->transaction_number ?? ('KM-' . $trx->id),
                    'title'  => $trx->description ?? 'Setoran Simpanan',
                    'date'   => $dateStr,
                    'amount' => (int) $trx->amount,
                    'type'   => $typeIn ? 'in' : 'out',
                    'status' => $trx->status,
                ];
            })->values()->toArray();

            return response()->json([
                'status' => 'success',
                'success' => true,
                'data' => [
                    'id' => $member->id,
                    'name' => $member->name,
                    'member_number' => $member->member_number,
                    'no_register' => $member->member_number,
                    'nik' => $member->nik,
                    'phone' => $member->phone,
                    'email' => $member->email,
                    'status' => $member->status,
                    'has_buku_biru' => (bool) $member->has_buku_biru,
                    'has_buku_putih' => (bool) $member->has_buku_putih,
                    'daily_savings' => (float) $daily,
                    'estimated_shu' => $this->calculateEstimatedShu($member, $pokok, $wajib),
                    'simpanan' => [
                        'simpanan_pokok' => (int) $pokok,
                        'simpanan_wajib' => (int) $wajib,
                        'simpanan_sukarela' => (int) $sukarela,
                        'daily_savings' => (int) $daily,
                        'total_saldo' => (int) $totalSaldo,
                        'buku_biru' => $member->buku_biru,
                        'buku_putih' => $member->buku_putih,
                    ],
                    'ahli_waris' => [
                        'nama' => $member->heir_name ?? '-',
                        'hubungan' => $member->heir_relationship ?? '-',
                        'alamat' => $member->heir_address ?? '-',
                    ],
                    'transactions' => $userTransactions,
                ]
            ], 200);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'status' => 'error',
                'message' => 'Gagal mengambil data profil anggota: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Data Dashboard Khusus Admin Koperasi
     */
    private function getAdminDashboard(): JsonResponse
    {
        try {
            $data = Cache::remember('dashboard_summary_data', now()->addMinutes(2), function () {
                $balanceSummary = app(\App\Services\SavingsBalanceService::class)->getCoopSavingsSummary();

                // 6. Data Cashflow Chart (6 Bulan Terakhir dalam 1 single grouped query)
                $sixMonthsAgo = Carbon::now()->subMonths(5)->startOfMonth()->toDateString();
                $monthlyCashflows = Transaction::where('status', 'approved')
                    ->where('transaction_date', '>=', $sixMonthsAgo)
                    ->selectRaw("
                        SUBSTR(transaction_date, 1, 7) as month_period,
                        SUM(CASE WHEN type IN ('deposit', 'in', 'kas_masuk', 'KM') THEN amount ELSE 0 END) as total_km,
                        SUM(CASE WHEN type IN ('withdrawal', 'out', 'kas_keluar', 'KK') THEN amount ELSE 0 END) as total_kk
                    ")
                    ->groupBy('month_period')
                    ->get()
                    ->keyBy('month_period');

                $cashflowChart = [];
                for ($i = 5; $i >= 0; $i--) {
                    $date = Carbon::now()->subMonths($i);
                    $monthStr = $date->format('Y-m');
                    $monthLabel = $date->locale('id')->isoFormat('MMMM YYYY');

                    $km = isset($monthlyCashflows[$monthStr]) ? (float) $monthlyCashflows[$monthStr]->total_km : 0.0;
                    $kk = isset($monthlyCashflows[$monthStr]) ? (float) $monthlyCashflows[$monthStr]->total_kk : 0.0;

                    $cashflowChart[] = [
                        'month' => $monthStr,
                        'label' => $monthLabel,
                        'kas_masuk' => $km,
                        'kas_keluar' => $kk,
                    ];
                }

                // 7. Pending Approvals (Safe Relation Load)
                $pendingApprovals = [];
                try {
                    $pendingTrxs = Transaction::where('status', 'pending')
                        ->with(['member:id,name,member_number,phone'])
                        ->orderBy('created_at', 'desc')
                        ->take(20)
                        ->get()
                        ->map(function($trx) {
                            return [
                                'id'           => $trx->id,
                                'type'         => 'transaksi',
                                'category'     => $trx->description ?? 'Transaksi',
                                'description'  => $trx->description ?? 'Transaksi',
                                'amount'       => (float) ($trx->amount ?? 0),
                                'nominal'      => (float) ($trx->amount ?? 0),
                                'total_amount' => (float) ($trx->amount ?? 0),
                                'member_name'  => ($trx->member && $trx->member->name) ? $trx->member->name : 'Anggota Umum',
                                'member_no'    => ($trx->member && $trx->member->member_number) ? $trx->member->member_number : '-',
                                'date'         => $trx->created_at ? $trx->created_at->format('Y-m-d H:i') : now()->format('Y-m-d H:i'),
                                'status'       => $trx->status ?? 'pending',
                            ];
                        });

                    $pendingLoans = collect([]);
                    if (Schema::hasTable('loans')) {
                        $pendingLoans = Loan::with(['member:id,name,member_number,phone'])
                            ->whereIn('status', [
                                'WAITING_MANAGER_APPROVAL', 'pending_manager', 'menunggu_ketua',
                                'pending_admin', 'WAITING_ADMIN_VERIFICATION', 'pending'
                            ])
                            ->latest()
                            ->take(20)
                            ->get()
                            ->map(function($loan) {
                                return [
                                    'id'              => $loan->id,
                                    'loan_id'         => $loan->id,
                                    'loan_code'       => $loan->loan_code,
                                    'type'            => 'pinjaman',
                                    'category'        => 'Pengajuan Pinjaman #' . ($loan->loan_code ?? $loan->id),
                                    'description'     => $loan->purpose ?: ('Pengajuan Pinjaman #' . ($loan->loan_code ?? $loan->id)),
                                    'amount'          => (float) ($loan->amount ?? 0),
                                    'plafon'          => (float) ($loan->amount ?? 0),
                                    'nominal'         => (float) ($loan->amount ?? 0),
                                    'total_amount'    => (float) ($loan->amount ?? 0),
                                    'member_name'     => ($loan->member && $loan->member->name) ? $loan->member->name : 'Anggota Umum',
                                    'member_no'       => ($loan->member && $loan->member->member_number) ? $loan->member->member_number : '-',
                                    'date'            => $loan->created_at ? $loan->created_at->format('Y-m-d H:i') : now()->format('Y-m-d H:i'),
                                    'status'          => $loan->status,
                                    'tenor_months'    => (int) ($loan->tenor_months ?? $loan->duration_months ?? 12),
                                    'interest_rate'   => (float) $loan->interest_rate,
                                    'interest_method' => $loan->interest_method ?? 'declining_balance',
                                    'collateral'      => $loan->collateral,
                                    'purpose'         => $loan->purpose,
                                ];
                            });
                    }

                    $pendingApprovals = $pendingTrxs->concat($pendingLoans)->values()->toArray();
                } catch (\Exception $e) {
                    $pendingApprovals = [];
                }

                // 8. Latest 5 Transactions
                $latestTransactions = [];
                try {
                    $dbTransactions = Transaction::with(['member'])
                        ->latest()
                        ->take(5)
                        ->get();

                    $latestTransactions = $dbTransactions->map(function ($trx) {
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
                    })->toArray();
                } catch (\Exception $e) {
                    $latestTransactions = [];
                }

                return array_merge($balanceSummary, [
                    'cashflow_chart'          => $cashflowChart,
                    'pending_approvals_count' => count($pendingApprovals),
                    'pending_approvals'       => $pendingApprovals,
                    'latest_transactions'     => $latestTransactions,
                ]);
            });

            return response()->json([
                'role' => 'admin',
                'success' => true,
                'data' => $data,
            ], 200);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'role' => 'admin',
                'message' => 'Gagal memuat dashboard: ' . $e->getMessage(),
                'error_detail' => [
                    'line' => $e->getLine(),
                    'file' => basename($e->getFile()),
                ],
                'data' => [
                    'total_members' => 0,
                    'total_aset' => 0,
                    'total_savings' => 0,
                    'total_loans' => 0,
                    'total_kas_masuk_current_month' => 0,
                    'total_kas_keluar_current_month' => 0,
                    'cashflow_chart' => [],
                    'pending_approvals_count' => 0,
                    'pending_approvals' => [],
                    'latest_transactions' => [],
                ],
            ], 200);
        }
    }

    /**
     * Data Dashboard Khusus Anggota Koperasi (Pribadi) - Live Data Tanpa Mock
     */
    private function getAnggotaDashboard($user): JsonResponse
    {
        if ($user instanceof Member) {
            $member = $user;
        } else {
            $member = Member::where('user_id', $user->id)->first();
        }

        if (!$member) {
            return response()->json([
                'success' => false,
                'message' => 'Data anggota tidak ditemukan',
                'data' => null
            ], 404);
        }

        // Ambil transaksi disetujui untuk sum dinamis
        $transactions = Transaction::where('member_id', $member->id)
            ->where('status', 'approved')
            ->get();

        $pokok = (float) ($member->principal_savings ?? 0);
        $wajib = (float) ($member->mandatory_savings ?? 0);
        $sukarela = (float) ($member->voluntary_savings ?? 0);

        foreach ($transactions as $trx) {
            if ($trx->description === 'Setoran Simpanan Awal Anggota') {
                continue; 
            }

            $amount = (float) $trx->amount;
            $desc = strtolower($trx->description ?? '');

            if ($trx->type === 'deposit') {
                if (str_contains($desc, 'wajib')) {
                    $wajib += $amount;
                } elseif (str_contains($desc, 'sukarela')) {
                    $sukarela += $amount;
                } elseif (str_contains($desc, 'pokok')) {
                    $pokok += $amount;
                } else {
                    $sukarela += $amount;
                }
            } elseif ($trx->type === 'withdrawal') {
                if (str_contains($desc, 'wajib')) {
                    $wajib -= $amount;
                } elseif (str_contains($desc, 'sukarela')) {
                    $sukarela -= $amount;
                } elseif (str_contains($desc, 'pokok')) {
                    $pokok -= $amount;
                } else {
                    $sukarela -= $amount;
                }
            }
        }

        $saldoSimpanan = $pokok + $wajib + $sukarela;

        // Ambil data pinjaman terkini langsung dari tabel loans
        $currentLoan = Loan::with('installments')
            ->where('member_id', $member->id)
            ->whereIn('status', [
                'pending_admin', 'pending', 'WAITING_ADMIN_VERIFICATION',
                'pending_manager', 'WAITING_MANAGER_APPROVAL', 'menunggu_ketua',
                'APPROVED_BY_MANAGER', 'approved', 'APPROVED',
                'DISBURSED', 'disbursed', 'active', 'ACTIVE'
            ])
            ->latest()
            ->first();

        $hasActiveLoan   = false;
        $loanStatus      = null;
        $totalPinjaman   = 0.0;
        $currentLoanData = null;

        if ($currentLoan) {
            $loanStatus = $currentLoan->status;
            $hasActiveLoan = !in_array(strtoupper($loanStatus), ['PAID_OFF', 'COMPLETED', 'LUNAS', 'REJECTED', 'REJECTED_BY_MANAGER', 'REJECTED_BY_ADMIN', 'REJECTED_BY_KETUA']);

            $installments = $currentLoan->installments ?? collect();
            $paidInstallments   = $installments->where('status', 'paid');
            $totalPaidPrincipal = (float) $paidInstallments->sum('principal_amount');
            $totalPaidInterest  = (float) $paidInstallments->sum('interest_amount');
            $totalPaidAll       = (float) $paidInstallments->sum('total_amount');
            $sisaPokok = max(0.0, (float) ($currentLoan->remaining_principal ?? ($currentLoan->amount - $totalPaidPrincipal)));

            // total_pinjaman = sisa pokok (jika sudah cair), atau plafon (jika belum cair)
            if (in_array(strtoupper($loanStatus), ['DISBURSED', 'ACTIVE', 'APPROVED'])) {
                $totalPinjaman = $sisaPokok > 0 ? $sisaPokok : (float) $currentLoan->amount;
            }

            $tenor = (int) ($currentLoan->tenor_months ?? $currentLoan->duration_months ?? 12);
            $amount = (float) $currentLoan->amount;
            $monthlyInstallment = (float) ($currentLoan->monthly_installment ?? 0);

            // Hitung estimasi total bayar = jumlah dari seluruh jadwal angsuran
            $totalKewajibanAll = (float) $installments->sum('total_amount');
            if ($totalKewajibanAll <= 0 && $monthlyInstallment > 0) {
                $totalKewajibanAll = $monthlyInstallment * $tenor;
            }

            $currentLoanData = [
                'id'                       => $currentLoan->id,
                'loan_id'                  => $currentLoan->id,
                'loan_code'                => $currentLoan->loan_code,
                'no_kontrak'               => $currentLoan->loan_code,
                'no_sh'                    => $currentLoan->loan_code,
                'amount'                   => $amount,
                'plafon'                   => $amount,
                'plafon_disetujui'         => $amount,
                'nominal'                  => $amount,
                'remaining_principal'      => $sisaPokok,
                'sisa_pokok'               => $sisaPokok,
                'remaining_amount'         => $sisaPokok,
                'tenor_months'             => $tenor,
                'tenor_waktu'              => $tenor,
                'duration_months'          => $tenor,
                'interest_rate'            => (float) $currentLoan->interest_rate,
                'suku_bunga'               => (float) $currentLoan->interest_rate,
                'interest_method'          => $currentLoan->interest_method ?? 'declining_balance',
                'monthly_installment'      => $monthlyInstallment,
                'angsuran_bulanan'         => $monthlyInstallment,
                'estimasi_angsuran'        => $monthlyInstallment,
                'total_kewajiban'          => $totalKewajibanAll,
                'total_harus_dibayar'      => $totalKewajibanAll,
                'total_dibayar'            => $totalPaidAll,
                'total_paid'               => $totalPaidAll,
                'total_paid_principal'     => $totalPaidPrincipal,
                'total_paid_interest'      => $totalPaidInterest,
                'angsuran_selesai'         => $paidInstallments->count(),
                'angsuran_tersisa'         => $installments->where('status', '!=', 'paid')->count(),
                'total_angsuran'           => $installments->count(),
                'status'                   => $currentLoan->status,
                'application_date'         => $currentLoan->application_date,
                'disbursement_date'        => $currentLoan->disbursement_date,
                'due_date'                 => $currentLoan->due_date,
                'notes'                    => $currentLoan->notes,
                'purpose'                  => $currentLoan->purpose,
                'collateral'               => $currentLoan->collateral,
                'can_disburse'             => in_array(strtoupper($loanStatus), ['APPROVED_BY_MANAGER', 'APPROVED']),
                // Jadwal angsuran lengkap untuk ditampilkan di UI anggota
                'installments'             => $installments->sortBy('installment_number')->map(function ($inst) {
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
                })->values()->all(),
            ];
        }

        $pendingApprovalsCount = Transaction::where('member_id', $member->id)
            ->where('status', 'pending')
            ->count();

        // Riwayat 5 transaksi terakhir milik anggota (tidak ada mock data)
        $dbTransactions = Transaction::where('member_id', $member->id)
            ->latest()
            ->take(5)
            ->get();

        $userTransactions = $dbTransactions->map(function ($trx) use ($member) {
            $typeIn = in_array($trx->type, ['deposit', 'in']);
            $dateStr = $trx->transaction_date 
                ? (is_string($trx->transaction_date) ? substr($trx->transaction_date, 0, 10) : $trx->transaction_date->format('Y-m-d'))
                : ($trx->created_at ? $trx->created_at->format('Y-m-d') : date('Y-m-d'));

            return [
                'transaction_number' => $trx->transaction_number ?? ('KM-' . $trx->id),
                'member' => ['full_name' => $member->name],
                'type' => $trx->type,
                'amount' => (float) $trx->amount,
                'status' => $trx->status,
                'date' => $dateStr,
                'description' => $trx->description ?? '',
            ];
        })->toArray();

        return response()->json([
            'role' => 'anggota',
            'data' => [
                'name'                    => $member->name,
                'member_number'           => $member->member_number,
                'saldo_simpanan'          => $saldoSimpanan,
                'simpanan_pokok'          => $pokok,
                'simpanan_wajib'          => $wajib,
                'simpanan_sukarela'       => $sukarela,
                'buku_biru'               => $member->buku_biru,
                'buku_putih'              => $member->buku_putih,
                'total_pinjaman'          => $totalPinjaman,
                'has_active_loan'         => $hasActiveLoan,
                'loan_status'             => $loanStatus,
                'current_loan'            => $currentLoanData,
                'active_loan'             => $currentLoanData,
                'pending_approvals_count' => $pendingApprovalsCount,
                'estimated_shu'           => $this->calculateEstimatedShu($member, $pokok, $wajib),
                'latest_transactions'     => $userTransactions,
            ],
        ], 200);
    }

    /**
     * Menghitung estimasi SHU anggota secara dinamis berdasarkan net cashflow dan porsi modal.
     */
    private function calculateEstimatedShu($member, $pokok, $wajib)
    {
        if ($member->status !== 'active') {
            return 0;
        }

        $cacheKey = 'global_shu_pool_stats_' . date('Y-m');
        $poolStats = Cache::remember($cacheKey, now()->addMinutes(15), function () {
            $km = (float) Transaction::where('status', 'approved')
                ->where(function($q) {
                    $q->where('type', 'deposit')
                      ->orWhere('type', 'in')
                      ->orWhere('type', 'kas_masuk')
                      ->orWhere('type', 'KM');
                })
                ->sum('amount');

            $kk = (float) Transaction::where('status', 'approved')
                ->where(function($q) {
                    $q->where('type', 'withdrawal')
                      ->orWhere('type', 'out')
                      ->orWhere('type', 'kas_keluar')
                      ->orWhere('type', 'KK');
                })
                ->sum('amount');

            $shares = Member::where('status', 'active')
                ->selectRaw('SUM(principal_savings + mandatory_savings) as total')
                ->value('total') ?: 1;

            return [
                'totalKM'        => $km,
                'totalKK'        => $kk,
                'totalAllShares' => $shares,
            ];
        });

        $totalKM        = (float) ($poolStats['totalKM'] ?? 0.0);
        $totalKK        = (float) ($poolStats['totalKK'] ?? 0.0);
        $totalAllShares = (float) ($poolStats['totalAllShares'] ?? 1.0);

        $netCashflow = $totalKM - $totalKK;
        if ($netCashflow <= 0) {
            $netCashflow = 0;
        }

        $shuMemberPool = $netCashflow * 0.70;

        $memberShares = $pokok + $wajib;

        return (int) round(($memberShares / $totalAllShares) * $shuMemberPool);
    }

    /**
     * GET /api/dashboard/cashflow-chart
     * Agregasi total nominal Kas Masuk (KM) vs Kas Keluar (KK) secara bulanan untuk 6 bulan terakhir
     */
    public function cashflowChart(Request $request): JsonResponse
    {
        try {
            $sixMonthsAgo = Carbon::now()->subMonths(5)->startOfMonth()->toDateString();
            $monthlyCashflows = Transaction::where('status', 'approved')
                ->where('transaction_date', '>=', $sixMonthsAgo)
                ->selectRaw("
                    SUBSTR(transaction_date, 1, 7) as month_period,
                    SUM(CASE WHEN type IN ('deposit', 'in', 'kas_masuk', 'KM') THEN amount ELSE 0 END) as total_km,
                    SUM(CASE WHEN type IN ('withdrawal', 'out', 'kas_keluar', 'KK') THEN amount ELSE 0 END) as total_kk
                ")
                ->groupBy('month_period')
                ->get()
                ->keyBy('month_period');

            $labels = [];
            $kasMasuk = [];
            $kasKeluar = [];

            for ($i = 5; $i >= 0; $i--) {
                $date = Carbon::now()->subMonths($i);
                $monthStr = $date->format('Y-m');

                $labels[] = $monthStr;
                $kasMasuk[] = isset($monthlyCashflows[$monthStr]) ? (float) $monthlyCashflows[$monthStr]->total_km : 0.0;
                $kasKeluar[] = isset($monthlyCashflows[$monthStr]) ? (float) $monthlyCashflows[$monthStr]->total_kk : 0.0;
            }

            return response()->json([
                'success' => true,
                'data'    => [
                    'labels'     => $labels,
                    'kas_masuk'  => $kasMasuk,
                    'kas_keluar' => $kasKeluar,
                ],
            ], 200);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Gagal mengambil data grafik arus kas: ' . $e->getMessage(),
                'data'    => [
                    'labels'     => [],
                    'kas_masuk'  => [],
                    'kas_keluar' => [],
                ]
            ], 500);
        }
    }

    /**
     * Mengembalikan data tren keuangan bulanan (KM vs KK) untuk tahun anggaran berjalan.
     * Endpoint: GET /api/admin/dashboard/monthly-trend
     */
    public function monthlyTrend(Request $request): JsonResponse
    {
        try {
            $year = $request->input('year') ?? $request->input('tahun') ?? date('Y');
            $currentYear = date('Y');
            $maxMonth = ($year == $currentYear) ? (int)date('m') : 12;

            $driver = \Illuminate\Support\Facades\DB::connection()->getDriverName();
            $monthExpr = ($driver === 'sqlite')
                ? "CAST(strftime('%m', transaction_date) AS INTEGER) as month_num"
                : "MONTH(transaction_date) as month_num";

            $monthlyData = \Illuminate\Support\Facades\DB::table('transactions')
                ->select(
                    \Illuminate\Support\Facades\DB::raw($monthExpr),
                    \Illuminate\Support\Facades\DB::raw("SUM(CASE WHEN type IN ('KM', 'deposit', 'in', 'kas_masuk') THEN amount ELSE 0 END) as total_km"),
                    \Illuminate\Support\Facades\DB::raw("SUM(CASE WHEN type IN ('KK', 'withdrawal', 'out', 'kas_keluar') THEN amount ELSE 0 END) as total_kk")
                )
                ->whereYear('transaction_date', $year)
                ->where('status', 'approved')
                ->groupBy('month_num')
                ->get()
                ->keyBy('month_num');

            $monthsMap = [
                1 => 'Jan',
                2 => 'Feb',
                3 => 'Mar',
                4 => 'Apr',
                5 => 'Mei',
                6 => 'Jun',
                7 => 'Jul',
                8 => 'Agu',
                9 => 'Sep',
                10 => 'Okt',
                11 => 'Nov',
                12 => 'Des'
            ];

            $resultData = [];
            for ($m = 1; $m <= $maxMonth; $m++) {
                $dbRow = $monthlyData->get($m);
                $resultData[] = [
                    'month'     => $monthsMap[$m] ?? '',
                    'month_num' => $m,
                    'total_km'  => (float) ($dbRow->total_km ?? 0),
                    'total_kk'  => (float) ($dbRow->total_kk ?? 0),
                ];
            }

            return response()->json([
                'success' => true,
                'message' => 'Data grafik bulanan berhasil dimuat',
                'data'    => $resultData
            ], 200);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Gagal mengambil data tren bulanan: ' . $e->getMessage(),
                'data'    => []
            ], 500);
        }
    }
}
