<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Member;
use App\Models\Transaction;
use App\Models\Account;
use App\Models\Loan;
use App\Models\LoanInstallment;
use App\Models\ChartOfAccount;
use App\Models\JournalEntry;
use App\Models\JournalDetail;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Cache;

class ManagerDashboardController extends Controller
{
    /**
     * Mengambil ringkasan dashboard untuk Ketua (monitoring & approval)
     *
     * @return JsonResponse
     */
    public function getDashboardSummary(): JsonResponse
    {
        try {
            $data = Cache::remember('manager_dashboard_summary_data', 60, function () {
                $balanceSummary = app(\App\Services\SavingsBalanceService::class)->getCoopSavingsSummary();

                // 6. Pending Approvals (Transaksi & Pinjaman)
                $pendingApprovals = [];
                try {
                    $pendingTransactions = Transaction::with(['member:id,name,member_number,phone'])
                        ->where('status', 'pending')
                        ->orderBy('created_at', 'desc')
                        ->take(20)
                        ->get()
                        ->map(function ($trx) {
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
                                'status'       => $trx->status,
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
                            ->map(function ($loan) {
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

                    $pendingApprovals = $pendingTransactions->concat($pendingLoans)->values()->toArray();
                } catch (\Exception $e) {
                    $pendingApprovals = [];
                }

                // 7. Latest 5 Transactions
                $latestTransactions = [];
                try {
                    $latestTransactions = Transaction::with(['member:id,name,member_number'])
                        ->latest()
                        ->take(5)
                        ->get()
                        ->map(function ($trx) {
                            $typeIn = in_array(strtolower($trx->type ?? ''), ['deposit', 'in', 'kas_masuk', 'km']);
                            return [
                                'id'                   => (string) $trx->id,
                                'transaction_number'   => $trx->transaction_number ?? '',
                                'receipt_number'       => $trx->receipt_number ?? '',
                                'formatted_receipt_no' => $trx->formatted_receipt_no ?? '',
                                'member' => [
                                    'full_name'     => ($trx->member && $trx->member->name) ? $trx->member->name : 'Anggota Umum',
                                    'member_number' => ($trx->member && $trx->member->member_number) ? $trx->member->member_number : '-',
                                ],
                                'type'           => $trx->type ?? 'deposit',
                                'isIncome'       => $typeIn,
                                'amount'         => (float) ($trx->amount ?? 0),
                                'payment_method' => $trx->payment_method ?? 'cash',
                                'status'         => $trx->status ?? 'pending',
                                'description'    => $trx->description ?? '',
                                'date'           => $trx->created_at ? $trx->created_at->setTimezone('Asia/Jakarta')->translatedFormat('d M Y, H:i') : '',
                            ];
                        })->toArray();
                } catch (\Exception $e) {
                    $latestTransactions = [];
                }

                // 8. Chart 6 Bulan Terakhir (Single Grouped Query)
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

                $monthlyChartData = [];
                for ($i = 5; $i >= 0; $i--) {
                    $date = now()->subMonths($i);
                    $monthStr = $date->format('Y-m');
                    $monthLabel = $date->locale('id')->isoFormat('MMM');

                    $km = isset($monthlyCashflows[$monthStr]) ? (float) $monthlyCashflows[$monthStr]->total_km : 0.0;
                    $kk = isset($monthlyCashflows[$monthStr]) ? (float) $monthlyCashflows[$monthStr]->total_kk : 0.0;

                    $monthlyChartData[] = [
                        'month'      => $monthLabel,
                        'label'      => $date->locale('id')->isoFormat('MMMM YYYY'),
                        'kas_masuk'  => $km,
                        'kas_keluar' => $kk,
                    ];
                }

                return array_merge($balanceSummary, [
                    'total_transactions_count' => Transaction::count(),
                    'pending_approvals_count'  => count($pendingApprovals),
                    'pending_transactions'     => $pendingApprovals,
                    'pending_approvals'        => $pendingApprovals,
                    'latest_transactions'      => $latestTransactions,
                    'monthly_chart_data'       => $monthlyChartData,
                    'cashflow_chart'           => $monthlyChartData,
                ]);
            });

            return response()->json([
                'success' => true,
                'message' => 'Data ringkasan dashboard berhasil diambil',
                'data'    => $data,
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
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
                    'saldo_kas' => 0,
                    'total_kas_masuk_current_month' => 0,
                    'total_kas_keluar_current_month' => 0,
                    'total_deposits' => 0,
                    'total_withdrawals' => 0,
                    'total_transactions_count' => 0,
                    'pending_approvals_count' => 0,
                    'pending_transactions' => [],
                    'pending_approvals' => [],
                    'latest_transactions' => [],
                    'monthly_chart_data' => [],
                    'cashflow_chart' => [],
                ],
            ], 200);
        }
    }



    /**
     * Mengambil ringkasan laporan keuangan manajer secara real-time dari database
     * Endpoint: GET /api/manager/financial-summary & GET /api/manager/reports/summary
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function getFinancialSummary(Request $request): JsonResponse
    {
        try {
            $period = $request->query('period'); // Format: YYYY-MM
            $startDate = $request->query('start_date');
            $endDate = $request->query('end_date');
            
            $month = $request->query('month') ?? $request->query('bulan');
            $year = $request->query('year') ?? $request->query('tahun');

            // Ekstrak tahun & bulan jika query period diberikan dalam format YYYY-MM
            if ($period && $period !== 'all' && $period !== 'Semua') {
                $parts = explode('-', $period);
                if (count($parts) === 2 && is_numeric($parts[0]) && is_numeric($parts[1])) {
                    $year = (int) $parts[0];
                    $month = (int) $parts[1];
                }
            }

            // Closure filter tanggal terpadu untuk query builder
            $applyDateFilter = function ($q, string $dateColumn = 'transaction_date') use ($month, $year, $startDate, $endDate, $period) {
                if ($month && $year) {
                    $q->whereMonth($dateColumn, $month)->whereYear($dateColumn, $year);
                } elseif ($startDate && $endDate) {
                    $q->whereBetween($dateColumn, [$startDate, $endDate]);
                } elseif ($period && $period !== 'all' && $period !== 'Semua') {
                    $q->where($dateColumn, 'like', $period . '%');
                } else {
                    $currentMonth = now()->month;
                    $currentYear  = now()->year;
                    $q->whereMonth($dateColumn, $currentMonth)->whereYear($dateColumn, $currentYear);
                }
            };

            // Label Periode
            if ($month && $year) {
                $periodLabel = Carbon::createFromDate($year, $month, 1)->locale('id')->isoFormat('MMMM YYYY');
            } elseif ($startDate && $endDate) {
                $periodLabel = Carbon::parse($startDate)->format('d M Y') . ' - ' . Carbon::parse($endDate)->format('d M Y');
            } elseif ($period && $period !== 'all' && $period !== 'Semua') {
                $periodLabel = Carbon::parse($period . '-01')->locale('id')->isoFormat('MMMM YYYY');
            } else {
                $currentMonth = $month ?? now()->month;
                $currentYear  = $year ?? now()->year;
                $periodLabel = Carbon::createFromDate($currentYear, $currentMonth, 1)->locale('id')->isoFormat('MMMM YYYY');
            }

            $queryKM = Transaction::where('status', 'approved')
                ->where(function ($q) {
                    $q->where('type', 'deposit')
                      ->orWhere('type', 'in')
                      ->orWhere('type', 'kas_masuk')
                      ->orWhere('type', 'KM');
                });
            $applyDateFilter($queryKM, 'transaction_date');
                
            $queryKK = Transaction::where('status', 'approved')
                ->where(function ($q) {
                    $q->where('type', 'withdrawal')
                      ->orWhere('type', 'out')
                      ->orWhere('type', 'kas_keluar')
                      ->orWhere('type', 'KK');
                });
            $applyDateFilter($queryKK, 'transaction_date');

            $totalKM = (float) ($queryKM->sum('amount') ?? 0);
            $totalKK = (float) ($queryKK->sum('amount') ?? 0);
            $netCashflow = $totalKM - $totalKK;

            // 1. PISAHKAN PENDAPATAN VS SIMPANAN:
            // Hitung Total Pendapatan Operasional Koperasi (Jasa/Bunga Pinjaman, Provisi, Denda, Admin)
            // vs Beban Operasional Koperasi (Beban Operasional, Listrik, ATK, Bunga Buku Putih)
            $expenseAccountCodes = [
                '5100', '7110', '5200', '7100', '7101', '7102', '7103', '7104', '7105', 
                '7108', '7109', '7111', '7112', '7114', '7115', '7116', '7117', '7120', 
                '7121', '7122', '7123', '7131', '7145', '7147', '7160', '7161', '7167', 
                '7168', '7169', '7170', '7171'
            ];

            $totalRevenue = 0.0;
            $totalExpense = 0.0;

            // Method 1: Jika kolom account_code tersedia di tabel transactions
            if (\Illuminate\Support\Facades\Schema::hasColumn('transactions', 'account_code')) {
                $revTrxQuery = Transaction::where('status', 'approved')
                    ->where(function ($q) {
                        $q->where('type', 'deposit')
                          ->orWhere('type', 'in')
                          ->orWhere('type', 'kas_masuk')
                          ->orWhere('type', 'KM');
                    })
                    ->where(function ($q) {
                        $q->whereIn('account_code', ['4180', '4170', '4182', '4191', '4192', '4193', '4194', '4195'])
                          ->orWhere('category', 'pendapatan_lain');
                    });
                $applyDateFilter($revTrxQuery, 'transaction_date');

                $expTrxQuery = Transaction::where('status', 'approved')
                    ->where(function ($q) {
                        $q->where('type', 'withdrawal')
                          ->orWhere('type', 'out')
                          ->orWhere('type', 'kas_keluar')
                          ->orWhere('type', 'KK');
                    })
                    ->whereIn('account_code', ['5100', '7110', '5200']);
                $applyDateFilter($expTrxQuery, 'transaction_date');

                $totalRevenue = (float) $revTrxQuery->sum('amount');
                $totalExpense = (float) $expTrxQuery->sum('amount');
            }

            // Method 2: Query dari Jurnal / Buku Besar jika ada
            if ($totalRevenue == 0 && $totalExpense == 0 && class_exists(\App\Models\JournalDetail::class)) {
                $revAccountIds = \App\Models\ChartOfAccount::whereIn('account_code', ['4180', '4170', '4182', '4191', '4192', '4193', '4194', '4195'])
                    ->orWhere('account_code', 'like', '419%')
                    ->orWhere('account_type', 'REVENUE')
                    ->pluck('id');

                $expAccountIds = \App\Models\ChartOfAccount::whereIn('account_code', $expenseAccountCodes)
                    ->orWhere('account_type', 'EXPENSE')
                    ->pluck('id');

                $dateClosure = function ($q) use ($applyDateFilter) {
                    $applyDateFilter($q, 'entry_date');
                };

                $jRev = (float) \App\Models\JournalDetail::whereIn('account_id', $revAccountIds)
                    ->whereHas('journalEntry', $dateClosure)
                    ->sum('credit');

                $jExp = (float) \App\Models\JournalDetail::whereIn('account_id', $expAccountIds)
                    ->whereHas('journalEntry', $dateClosure)
                    ->sum('debit');

                $totalRevenue = max($totalRevenue, $jRev);
                $totalExpense = max($totalExpense, $jExp);
            }

            // Method 3: Filter transaksi berdasarkan deskripsi murni operasional (bukan simpanan modal)
            $descRevQuery = Transaction::where('status', 'approved')
                ->where(function ($q) {
                    $q->where('type', 'deposit')
                      ->orWhere('type', 'in')
                      ->orWhere('type', 'kas_masuk')
                      ->orWhere('type', 'KM');
                })
                ->where(function ($q) {
                    $q->where('description', 'like', '%jasa pinjaman%')
                      ->orWhere('description', 'like', '%bunga pinjaman%')
                      ->orWhere('description', 'like', '%provisi%')
                      ->orWhere('description', 'like', '%denda keterlambatan%')
                      ->orWhere('description', 'like', '%denda%')
                      ->orWhere('description', 'like', '%pendapatan lain%')
                      ->orWhere('description', 'like', '%uang pangkal%')
                      ->orWhere('category', 'pendapatan_lain');
                })
                ->where(function ($q) {
                    $q->where('description', 'not like', '%simpanan%')
                      ->where('description', 'not like', '%setoran awal%')
                      ->where('description', 'not like', '%buku biru%')
                      ->where('description', 'not like', '%buku putih%')
                      ->where('description', 'not like', '%tabungan%');
                });
            $applyDateFilter($descRevQuery, 'transaction_date');

            $totalRevenue = max($totalRevenue, (float) $descRevQuery->sum('amount'));

            $descExpQuery = Transaction::where('status', 'approved')
                ->where(function ($q) {
                    $q->where('type', 'withdrawal')
                      ->orWhere('type', 'out')
                      ->orWhere('type', 'kas_keluar')
                      ->orWhere('type', 'KK');
                })
                ->where(function ($q) {
                    $q->where('description', 'like', '%beban%')
                      ->orWhere('description', 'like', '%biaya%')
                      ->orWhere('description', 'like', '%listrik%')
                      ->orWhere('description', 'like', '%gaji%')
                      ->orWhere('description', 'like', '%atk%')
                      ->orWhere('description', 'like', '%operasional%')
                      ->orWhere('category', 'beban_operasional');
                })
                ->where(function ($q) {
                    $q->where('description', 'not like', '%penarikan simpanan%')
                      ->where('description', 'not like', '%tarik simpanan%')
                      ->where('description', 'not like', '%tarik buku%');
                });
            $applyDateFilter($descExpQuery, 'transaction_date');
            $totalExpense = max($totalExpense, (float) $descExpQuery->sum('amount'));

            // 2. HITUNG SHU BERSIH & ALOKASI SHU ANGGOTA:
            // Prioritas 1: Ambil dari Master Acuan SHU Koperasi (MonthlyCooperativeBenchmark)
            $queryM = $month ? (int) $month : now()->month;
            $queryY = $year ? (int) $year : now()->year;
            $fiscalYear = ($queryM >= 6) ? ($queryY + 1) : $queryY;

            $benchmark = \App\Models\MonthlyCooperativeBenchmark::where('fiscal_year', $fiscalYear)
                ->where('month', $queryM)
                ->whereNotNull('net_income')
                ->latest('updated_at')
                ->first();

            if ($benchmark && $benchmark->net_income !== null) {
                $netShu = (float) $benchmark->net_income;
                $shuPercentage = (float) ($benchmark->dividend_allocation_percent ?? 25.0);
                $allocatedShu = (float) round(($shuPercentage / 100) * $netShu, 2);
                $isManualBenchmark = true;
            } else {
                // Gunakan pendapatan operasional murni (BUKAN dari arus kas / net_cashflow)
                $netShu = max(0.0, round($totalRevenue - $totalExpense, 2));
                $shuPercentage = (float) ($request->query('shu_percentage') ?? \Illuminate\Support\Facades\Cache::get('setting_shu_percentage', config('koperasi.shu_percentage', 25.0)));
                $allocatedShu = (float) round(($shuPercentage / 100) * $netShu, 2);
                $isManualBenchmark = false;
            }

            // Rincian Kas Masuk (KM) Real-Time via Direct SQL Aggregation
            $kmItems = (clone $queryKM)
                ->selectRaw("COALESCE(NULLIF(description, ''), 'Setoran Kas Masuk') as category_name, SUM(amount) as amount, COUNT(*) as count")
                ->groupBy('category_name')
                ->orderByDesc('amount')
                ->get();

            $kmCategories = [];
            foreach ($kmItems as $idx => $item) {
                $sum = (float) $item->amount;
                $kmCategories[] = [
                    'code'          => '101.' . ($idx + 1),
                    'category_name' => $item->category_name,
                    'amount'        => $sum,
                    'percentage'    => $totalKM > 0 ? round(($sum / $totalKM) * 100, 1) : 0.0,
                    'count'         => (int) $item->count,
                ];
            }

            // Rincian Kas Keluar (KK) Real-Time via Direct SQL Aggregation
            $kkItems = (clone $queryKK)
                ->selectRaw("COALESCE(NULLIF(description, ''), 'Pengeluaran Kas Keluar') as category_name, SUM(amount) as amount, COUNT(*) as count")
                ->groupBy('category_name')
                ->orderByDesc('amount')
                ->get();

            $kkCategories = [];
            foreach ($kkItems as $idx => $item) {
                $sum = (float) $item->amount;
                $kkCategories[] = [
                    'code'          => '201.' . ($idx + 1),
                    'category_name' => $item->category_name,
                    'amount'        => $sum,
                    'percentage'    => $totalKK > 0 ? round(($sum / $totalKK) * 100, 1) : 0.0,
                    'count'         => (int) $item->count,
                ];
            }

            // Rekapitulasi Mutasi Transaksi Real-Time Terfilter Periode via Direct SQL Aggregation
            $auditQuery = DB::table('transactions');
            $applyDateFilter($auditQuery, 'transaction_date');

            $auditSummary = $auditQuery->selectRaw("
                COUNT(*) as total_input,
                SUM(CASE WHEN status = 'approved' THEN 1 ELSE 0 END) as total_approved,
                SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) as total_need_acc,
                SUM(CASE WHEN status = 'rejected' THEN 1 ELSE 0 END) as total_rejected
            ")->first();

            $totalInputAdmin = (int) ($auditSummary->total_input ?? 0);
            $autoApprovedCount = (int) ($auditSummary->total_approved ?? 0);
            $manualApprovalPendingCount = (int) ($auditSummary->total_need_acc ?? 0);
            $rejectedCount = (int) ($auditSummary->total_rejected ?? 0);

            // Rasio Keuangan Real-Time
            $liquidityRatio = $totalKK > 0 ? round(($totalKM / $totalKK) * 100, 1) : ($totalKM > 0 ? 100.0 : 0.0);
            $operationalRatio = $totalKK > 0 ? round(($totalKM / $totalKK) * 100, 1) : 100.0;

            return response()->json([
                'success' => true,
                'status'  => 'success',
                'message' => 'Laporan Ringkasan Keuangan Manajer berhasil diambil secara real-time',
                'data'    => [
                    'total_cash_in'                 => $totalKM,
                    'total_cash_out'                => $totalKK,
                    'net_cashflow'                  => $netCashflow,
                    'total_revenue'                 => $totalRevenue,
                    'total_expense'                 => $totalExpense,
                    'net_profit'                    => $netShu,
                    'net_shu'                       => $netShu,
                    'benchmark_net_profit'          => $netShu,
                    'laba_bersih_acuan'             => $netShu,
                    'shu_percentage'                => $shuPercentage,
                    'dividend_percent'              => $shuPercentage,
                    'dividend_allocation_percent'   => $shuPercentage,
                    'dividend_pool'                 => $allocatedShu,
                    'allocated_dividend'            => $allocatedShu,
                    'allocated_shu'                 => $allocatedShu,
                    'is_manual_benchmark'           => $isManualBenchmark,
                    'in_categories'                 => $kmCategories,
                    'out_categories'                => $kkCategories,
                    'period'                        => $periodLabel,
                    'report_date'                   => now()->format('d M Y'),
                    'total_km'                      => $totalKM,
                    'total_kas_masuk'               => $totalKM,
                    'total_kk'                      => $totalKK,
                    'total_kas_keluar'              => $totalKK,
                    'alokasi_shu_70'                => $allocatedShu,
                    'shu_allocation_70'             => $allocatedShu,
                    'km_categories'                 => $kmCategories,
                    'rincian_km'                    => $kmCategories,
                    'kk_categories'                 => $kkCategories,
                    'rincian_kk'                    => $kkCategories,
                    'total_input_admin'             => $totalInputAdmin,
                    'total_input'                   => $totalInputAdmin,
                    'auto_approved_count'           => $autoApprovedCount,
                    'total_approved'                => $autoApprovedCount,
                    'manual_approval_pending_count' => $manualApprovalPendingCount,
                    'pending_count'                 => $manualApprovalPendingCount,
                    'total_need_acc'                => $manualApprovalPendingCount,
                    'rejected_count'                => $rejectedCount,
                    'total_rejected'                => $rejectedCount,
                    'liquidity_ratio'               => $liquidityRatio,
                    'operational_coverage_ratio'    => $operationalRatio,
                    'loan_collectibility'           => 100.0,
                ],
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Gagal mengambil ringkasan keuangan: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Export Laporan Eksekutif Keuangan dalam Format PDF
     * Endpoint: GET /api/manager/reports/executive-summary/pdf
     */
    public function exportExecutiveSummaryPdf(Request $request)
    {
        try {
            $month = $request->input('month') ?? $request->input('bulan');
            $year  = $request->input('year') ?? $request->input('tahun');
            
            $period = $request->query('period'); // Format: YYYY-MM
            $startDate = $request->query('start_date');
            $endDate = $request->query('end_date');

            if ($period && $period !== 'all' && $period !== 'Semua') {
                $parts = explode('-', $period);
                if (count($parts) === 2 && is_numeric($parts[0]) && is_numeric($parts[1])) {
                    $year = (int) $parts[0];
                    $month = (int) $parts[1];
                }
            }

            $applyDateFilter = function ($q, string $dateColumn = 'transaction_date') use ($month, $year, $startDate, $endDate, $period) {
                if ($month && $year) {
                    $q->whereMonth($dateColumn, $month)->whereYear($dateColumn, $year);
                } elseif ($startDate && $endDate) {
                    $q->whereBetween($dateColumn, [$startDate, $endDate]);
                } elseif ($period && $period !== 'all' && $period !== 'Semua') {
                    $q->where($dateColumn, 'like', $period . '%');
                } else {
                    $currentMonth = now()->month;
                    $currentYear  = now()->year;
                    $q->whereMonth($dateColumn, $currentMonth)->whereYear($dateColumn, $currentYear);
                }
            };

            if ($month && $year) {
                $periodLabel = Carbon::createFromDate($year, $month, 1)->locale('id')->isoFormat('MMMM YYYY');
            } elseif ($startDate && $endDate) {
                $periodLabel = Carbon::parse($startDate)->format('d M Y') . ' - ' . Carbon::parse($endDate)->format('d M Y');
            } elseif ($period && $period !== 'all' && $period !== 'Semua') {
                $periodLabel = Carbon::parse($period . '-01')->locale('id')->isoFormat('MMMM YYYY');
            } else {
                $currentMonth = $month ?? now()->month;
                $currentYear  = $year ?? now()->year;
                $periodLabel = Carbon::createFromDate($currentYear, $currentMonth, 1)->locale('id')->isoFormat('MMMM YYYY');
            }

            // 1. Agregasi Rincian Kas Masuk (KM) Real-Time via Direct SQL Aggregation
            $queryKM = Transaction::where('status', 'approved')
                ->where(function ($q) {
                    $q->where('type', 'deposit')
                      ->orWhere('type', 'in')
                      ->orWhere('type', 'kas_masuk')
                      ->orWhere('type', 'KM');
                });
            $applyDateFilter($queryKM, 'transaction_date');

            $kmItems = $queryKM
                ->selectRaw("COALESCE(NULLIF(description, ''), 'Setoran Kas Masuk') as category_name, SUM(amount) as amount, COUNT(*) as count")
                ->groupBy('category_name')
                ->orderByDesc('amount')
                ->get();

            $totalKM = (float) $kmItems->sum('amount');

            $kmCategories = [];
            foreach ($kmItems as $idx => $item) {
                $sum = (float) $item->amount;
                $kmCategories[] = [
                    'code'          => '101.' . ($idx + 1),
                    'category_name' => $item->category_name,
                    'amount'        => $sum,
                    'percentage'    => $totalKM > 0 ? round(($sum / $totalKM) * 100, 1) : 0.0,
                    'count'         => (int) $item->count,
                ];
            }

            // 2. Agregasi Rincian Kas Keluar (KK) Real-Time via Direct SQL Aggregation
            $queryKK = Transaction::where('status', 'approved')
                ->where(function ($q) {
                    $q->where('type', 'withdrawal')
                      ->orWhere('type', 'out')
                      ->orWhere('type', 'kas_keluar')
                      ->orWhere('type', 'KK');
                });
            $applyDateFilter($queryKK, 'transaction_date');

            $kkItems = $queryKK
                ->selectRaw("COALESCE(NULLIF(description, ''), 'Pengeluaran Kas Keluar') as category_name, SUM(amount) as amount, COUNT(*) as count")
                ->groupBy('category_name')
                ->orderByDesc('amount')
                ->get();

            $totalKK = (float) $kkItems->sum('amount');
            $netCashflow = $totalKM - $totalKK;

            $kkCategories = [];
            foreach ($kkItems as $idx => $item) {
                $sum = (float) $item->amount;
                $kkCategories[] = [
                    'code'          => '201.' . ($idx + 1),
                    'category_name' => $item->category_name,
                    'amount'        => $sum,
                    'percentage'    => $totalKK > 0 ? round(($sum / $totalKK) * 100, 1) : 0.0,
                    'count'         => (int) $item->count,
                ];
            }

            // 3. HITUNG SHU BERSIH & ALOKASI SHU ANGGOTA:
            // Prioritas 1: Ambil dari Master Acuan SHU Koperasi (MonthlyCooperativeBenchmark)
            $queryM = $month ? (int) $month : now()->month;
            $queryY = $year ? (int) $year : now()->year;
            $fiscalYear = ($queryM >= 6) ? ($queryY + 1) : $queryY;

            $benchmark = \App\Models\MonthlyCooperativeBenchmark::where('fiscal_year', $fiscalYear)
                ->where('month', $queryM)
                ->whereNotNull('net_income')
                ->latest('updated_at')
                ->first();

            $totalRevenue = 0.0;
            $totalExpense = 0.0;

            if ($benchmark && $benchmark->net_income !== null) {
                $netShu = (float) $benchmark->net_income;
                $shuPercentage = (float) ($benchmark->dividend_allocation_percent ?? 25.0);
                $allocatedShu = (float) round(($shuPercentage / 100) * $netShu, 2);
                $isManualBenchmark = true;
            } else {
                // Query Pendapatan Operasional Murni hanya jika belum ada benchmark
                if (\Illuminate\Support\Facades\Schema::hasColumn('transactions', 'account_code')) {
                    $revTrxQuery = Transaction::where('status', 'approved')
                        ->where(function ($q) {
                            $q->where('type', 'deposit')
                              ->orWhere('type', 'in')
                              ->orWhere('type', 'kas_masuk')
                              ->orWhere('type', 'KM');
                        })
                        ->where(function ($q) {
                            $q->whereIn('account_code', ['4180', '4170', '4182', '4191', '4192', '4193', '4194', '4195'])
                              ->orWhere('category', 'pendapatan_lain');
                        });
                    $applyDateFilter($revTrxQuery, 'transaction_date');

                    $expTrxQuery = Transaction::where('status', 'approved')
                        ->where(function ($q) {
                            $q->where('type', 'withdrawal')
                              ->orWhere('type', 'out')
                              ->orWhere('type', 'kas_keluar')
                              ->orWhere('type', 'KK');
                        })
                        ->whereIn('account_code', ['5100', '7110', '5200']);
                    $applyDateFilter($expTrxQuery, 'transaction_date');

                    $totalRevenue = (float) $revTrxQuery->sum('amount');
                    $totalExpense = (float) $expTrxQuery->sum('amount');
                }

                if ($totalRevenue == 0 && $totalExpense == 0 && class_exists(\App\Models\JournalDetail::class)) {
                    $revAccountIds = \App\Models\ChartOfAccount::whereIn('account_code', ['4180', '4170', '4182', '4191', '4192', '4193', '4194', '4195'])
                        ->orWhere('account_code', 'like', '419%')
                        ->orWhere('account_type', 'REVENUE')
                        ->pluck('id');
                    $expAccountIds = \App\Models\ChartOfAccount::where('account_type', 'EXPENSE')->pluck('id');

                    $dateClosure = function ($q) use ($applyDateFilter) {
                        $applyDateFilter($q, 'entry_date');
                    };

                    $totalRevenue = (float) \App\Models\JournalDetail::whereIn('account_id', $revAccountIds)
                        ->whereHas('journalEntry', $dateClosure)
                        ->sum('credit');

                    $totalExpense = (float) \App\Models\JournalDetail::whereIn('account_id', $expAccountIds)
                        ->whereHas('journalEntry', $dateClosure)
                        ->sum('debit');
                }

                $netShu = max(0.0, round($totalRevenue - $totalExpense, 2));
                $shuPercentage = (float) ($request->query('shu_percentage') ?? \Illuminate\Support\Facades\Cache::get('setting_shu_percentage', config('koperasi.shu_percentage', 25.0)));
                $allocatedShu = (float) round(($shuPercentage / 100) * $netShu, 2);
                $isManualBenchmark = false;
            }

            $data = [
                'period'                      => $periodLabel,
                'report_date'                 => now()->format('d M Y'),
                'total_km'                    => $totalKM,
                'total_kk'                    => $totalKK,
                'total_cash_in'               => $totalKM,
                'total_cash_out'              => $totalKK,
                'net_cashflow'                => $netCashflow,
                'total_revenue'               => $totalRevenue,
                'total_expense'               => $totalExpense,
                'net_profit'                  => $netShu,
                'net_shu'                     => $netShu,
                'shu_percentage'              => $shuPercentage,
                'dividend_percent'            => $shuPercentage,
                'dividend_allocation_percent' => $shuPercentage,
                'dividend_pool'               => $allocatedShu,
                'allocated_dividend'          => $allocatedShu,
                'allocated_shu'               => $allocatedShu,
                'alokasi_shu_70'              => $allocatedShu,
                'is_manual_benchmark'         => $isManualBenchmark,
                'km_categories'               => $kmCategories,
                'kk_categories'               => $kkCategories,
                'in_categories'               => $kmCategories,
                'out_categories'              => $kkCategories,
            ];

            $pdf = \Barryvdh\DomPDF\Facade\Pdf::loadView('reports.executive_summary_pdf', $data)
                ->setPaper('a4', 'portrait')
                ->setOption('isHtml5ParserEnabled', true)
                ->setOption('isRemoteEnabled', false)
                ->setOption('defaultFont', 'sans-serif');
            
            $monthFilename = $month ?? now()->month;
            $yearFilename  = $year ?? now()->year;
            return $pdf->download("Laporan_Eksekutif_Keuangan_{$monthFilename}_{$yearFilename}.pdf");

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Gagal membuat file PDF laporan eksekutif keuangan: ' . $e->getMessage()
            ], 500);
        }
    }


    
    //mentriger pembagian bunga bulanan untuk buku putih(secara otomatis)oleh manajer
    public function triggerMonthlyInterest(Request $request): JsonResponse
    {
        try {
            $month = (int) ($request->input('month') ?? now()->month);
            $year  = (int) ($request->input('year') ?? now()->year);
            $user  = $request->user();
            $userId = $user ? $user->id : null;

            $service = app(\App\Services\BukuPutihInterestService::class);
            $result = $service->distribute($month, $year, $userId);

            return response()->json([
                'success' => true,
                'status'  => 'success',
                'message' => $result['message'],
                'data'    => $result,
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'status'  => 'error',
                'message' => 'Gagal menjalankan pembagian bunga: ' . $e->getMessage()
            ], 400);
        }
    }


    /**
     * Reset Data Testing Total / Clean Data (Admin & Super Admin)
     * Endpoint: POST /api/admin/reset-test-data & POST /api/system/reset-test-data
     */
    public function resetTestData(Request $request): JsonResponse
    {
        try {
            // Matikan foreign key checks sementara untuk eksekusi TRUNCATE
            DB::statement('SET FOREIGN_KEY_CHECKS=0;');

            $tablesToClean = [
                // 1. Data Pinjaman & Angsuran
                'loan_installments',
                'loan_approvals',
                'loan_applications',
                'loans',
                // 2. Data Transaksi & Jurnal Akuntansi
                'journal_details',
                'journal_items',
                'journal_entries',
                'cash_flows',
                'transactions',
                // 3. Data Keanggotaan
                'members',
                // 4. Data Pendukung & Notifikasi
                'notifications',
                'activity_logs',
                'audit_logs',
            ];

            $cleanedTables = [];

            foreach ($tablesToClean as $tableName) {
                if (Schema::hasTable($tableName)) {
                    DB::table($tableName)->truncate();
                    $cleanedTables[] = $tableName;
                }
            }

            // Reset saldo pada tabel accounts
            if (Schema::hasTable('accounts')) {
                Account::query()->update(['balance' => 0.00]);
            }

            // Aktifkan kembali foreign key checks
            DB::statement('SET FOREIGN_KEY_CHECKS=1;');

            return response()->json([
                'success' => true,
                'message' => 'Semua data operasional (transaksi, jurnal, pinjaman, anggota, dan notifikasi) berhasil dibersihkan total dan ID auto-increment telah di-reset ke 1. Akun users login dan master Chart of Accounts (COA) tetap aman.',
                'cleaned_tables'   => $cleanedTables,
                'preserved_tables' => ['users', 'chart_of_accounts'],
            ], 200);

        } catch (\Exception $e) {
            // Pastikan foreign key checks selalu diaktifkan kembali jika terjadi error
            DB::statement('SET FOREIGN_KEY_CHECKS=1;');

            return response()->json([
                'success' => false,
                'message' => 'Gagal membersihkan data testing: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Penyesuaian Saldo / Beban Operasional oleh Manajer (Terintegrasi Buku Besar)
     * Endpoint: POST /api/manager/transactions/adjust-balance
     */
    public function adjustBalance(Request $request): JsonResponse
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

        $type = strtolower($request->input('type', 'expense'));
        $isExpense = in_array($type, ['expense', 'kk', 'out', 'withdrawal']);
        $dupMessage = $isExpense
            ? 'Maaf, Transaksi KK ini sudah ada coba lagi dengan no berbeda'
            : 'Maaf, Transaksi KM ini sudah ada coba lagi dengan no berbeda';

        $validator = Validator::make($request->all(), [
            'voucher_no'       => 'nullable|string|max:50',
            'voucher_number'   => 'nullable|string|max:50',
            'transaction_number'=> 'nullable|string|max:50',
            'receipt_number'   => 'nullable|string|max:50',
            'account_code'     => 'nullable|string',
            'coa_id'           => 'nullable',
            'account_id'       => 'nullable',
            'type'             => 'required|in:expense,income',
            'amount'           => 'required|numeric|min:0.01',
            'description'      => 'required|string|max:255',
            'transaction_date' => 'nullable|date',
            'date'             => 'nullable|date',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validasi gagal',
                'errors'  => $validator->errors(),
            ], 422);
        }

        if (!empty($rawVoucher)) {
            if (Transaction::where('transaction_number', $rawVoucher)->orWhere('receipt_number', $rawVoucher)->exists() || JournalEntry::where('voucher_number', $rawVoucher)->exists()) {
                return response()->json([
                    'success' => false,
                    'message' => $dupMessage,
                    'errors'  => [
                        'voucher_no'         => [$dupMessage],
                        'transaction_number' => [$dupMessage],
                    ],
                ], 422);
            }
        }

        $rawDate = $request->input('date') ?? $request->input('transaction_date') ?? now()->toDateString();
        $transactionDate = Carbon::parse($rawDate)->format('Y-m-d');

        // Validasi Periode Terkunci (Global Lock Validation)
        if ($lockRes = \App\Services\PeriodLockService::validateDate($transactionDate)) {
            return $lockRes;
        }

        try {
            DB::beginTransaction();

            $amount      = (float) $request->input('amount');
            $description = trim($request->input('description'));
            $authUser    = $request->user();
            $userId      = $authUser ? $authUser->id : null;

            // Cari Akun COA yang dipilih (Mendukung coa_id, account_id, maupun account_code)
            $selectedAccount = null;
            if ($request->filled('coa_id')) {
                $val = $request->input('coa_id');
                $selectedAccount = ChartOfAccount::find($val) ?? ChartOfAccount::where('account_code', (string) $val)->first();
            } elseif ($request->filled('account_id')) {
                $val = $request->input('account_id');
                $selectedAccount = ChartOfAccount::find($val) ?? ChartOfAccount::where('account_code', (string) $val)->first();
            } elseif ($request->filled('account_code')) {
                $val = $request->input('account_code');
                $selectedAccount = ChartOfAccount::where('account_code', (string) $val)->first() ?? ChartOfAccount::find($val);
            }

            if (!$selectedAccount) {
                return response()->json([
                    'success' => false,
                    'message' => 'Akun perkiraan (COA) tidak ditemukan!',
                ], 422);
            }

            // Cari Akun Kas (1000 Kas Tunai / 1001 Kas Kasir)
            $cashAccount = ChartOfAccount::where('account_code', '1000')
                ->orWhere('account_code', '1001')
                ->first();

            if (!$cashAccount) {
                $cashAccount = ChartOfAccount::firstOrCreate(
                    ['account_code' => '1000'],
                    [
                        'account_name'   => 'Kas Tunai',
                        'account_type'   => 'ASSET',
                        'normal_balance' => 'DEBIT',
                        'is_active'      => true,
                    ]
                );
            }

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
            if ($type === 'expense') {
                $kasMaster->decrement('balance', $amount);
            } else {
                $kasMaster->increment('balance', $amount);
            }
            $endingBalance = (float) $kasMaster->fresh()->balance;

            // Tentukan Nomor Bukti (Prioritaskan input manual user jika ada)
            $rawVoucherNo = trim($request->input('voucher_no') ?? $request->input('voucher_number') ?? $request->input('receipt_number') ?? '');
            if (!empty($rawVoucherNo)) {
                $voucherNumber = $rawVoucherNo;

                // Cek duplikasi nomor bukti jika sudah ada
                if (Transaction::where('transaction_number', $voucherNumber)->orWhere('receipt_number', $voucherNumber)->exists() || JournalEntry::where('voucher_number', $voucherNumber)->exists()) {
                    $prefixLabel = $type === 'expense' ? 'KK' : 'KM';
                    return response()->json([
                        'success' => false,
                        'message' => "Maaf, Transaksi {$prefixLabel} ini sudah ada coba lagi dengan no berbeda",
                        'errors'  => [
                            'voucher_no'         => ["Maaf, Transaksi {$prefixLabel} ini sudah ada coba lagi dengan no berbeda"],
                            'voucher_number'     => ["Maaf, Transaksi {$prefixLabel} ini sudah ada coba lagi dengan no berbeda"],
                            'transaction_number' => ["Maaf, Transaksi {$prefixLabel} ini sudah ada coba lagi dengan no berbeda"],
                        ]
                    ], 422);
                }
            } else {
                $isIncome = ($type !== 'expense');
                $datePart = Carbon::parse($transactionDate)->format('Ymd');
                $prefix = ($isIncome ? 'KM-ADJ-' : 'KK-ADJ-') . $datePart . '-';

                $maxNumber = DB::table('journal_entries')
                    ->where('voucher_number', 'LIKE', "{$prefix}%")
                    ->lockForUpdate()
                    ->pluck('voucher_number')
                    ->map(function ($vn) {
                        return (int) substr($vn, strrpos($vn, '-') + 1);
                    })
                    ->max() ?? 0;

                $nextSeq = $maxNumber + 1;
                $voucherNumber = $prefix . str_pad($nextSeq, 4, '0', STR_PAD_LEFT);
            }

            $trxType = $type === 'expense' ? 'withdrawal' : 'deposit';
            $category = $type === 'expense' ? 'beban_operasional' : 'pendapatan_lain';
            $formattedDesc = "[Penyesuaian Manajer] " . $description;

            // 1. Simpan Baris Transaksi ke Tabel transactions
            $transaction = Transaction::create([
                'transaction_number' => $voucherNumber,
                'receipt_number'     => $voucherNumber,
                'account_id'         => $kasMaster->id,
                'member_id'          => null,
                'operator_id'        => $userId,
                'approved_by'        => $userId,
                'type'               => $trxType,
                'category'           => $category,
                'amount'             => $amount,
                'beginning_balance'  => $beginningBalance,
                'ending_balance'     => $endingBalance,
                'payment_method'     => 'cash',
                'transaction_date'   => $transactionDate,
                'description'        => $formattedDesc,
                'status'             => 'approved',
                'approved_at'        => now(),
            ]);

            // Hapus jika ada jurnal otomatis yang sempat dibuat oleh observer
            JournalEntry::where('transaction_id', $transaction->id)->delete();

            // 2. Buat Double-Entry Jurnal Umum & Buku Besar
            $journal = JournalEntry::create([
                'transaction_id' => $transaction->id,
                'entry_date'     => $transactionDate,
                'voucher_number' => $voucherNumber,
                'description'    => $description,
                'created_by'     => $userId,
            ]);

            // 3. Simpan Baris Detail Jurnal Lawan (Beban/Pendapatan vs Kas)
            if ($type === 'expense') {
                // DEBET: Sisi Beban (Wajib pakai ID akun yang dipilih user dari dropdown)
                JournalDetail::create([
                    'journal_entry_id' => $journal->id,
                    'account_id'       => $selectedAccount->id,
                    'debit'            => $amount,
                    'credit'           => 0,
                    'description'      => $description,
                ]);

                // KREDIT: Sisi Kas (Akun 1000 - Kas Berkurang)
                JournalDetail::create([
                    'journal_entry_id' => $journal->id,
                    'account_id'       => $cashAccount->id,
                    'debit'            => 0,
                    'credit'           => $amount,
                    'description'      => $description,
                ]);
            } else {
                // DEBET: Sisi Kas (Akun 1000 - Kas Bertambah)
                JournalDetail::create([
                    'journal_entry_id' => $journal->id,
                    'account_id'       => $cashAccount->id,
                    'debit'            => $amount,
                    'credit'           => 0,
                    'description'      => $description,
                ]);

                // KREDIT: Sisi Pendapatan / Sumber Terpilih (Wajib pakai ID akun yang dipilih user)
                JournalDetail::create([
                    'journal_entry_id' => $journal->id,
                    'account_id'       => $selectedAccount->id,
                    'debit'            => 0,
                    'credit'           => $amount,
                    'description'      => $description,
                ]);
            }

            Cache::forget('manager_dashboard_summary_data');
            Cache::forget('dashboard_summary_data');

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Penyesuaian kas (' . $voucherNumber . ') berhasil dicatat ke Buku Besar.',
                'data'    => [
                    'journal_id'       => $journal->id,
                    'voucher_number'   => $journal->voucher_number,
                    'type'             => $type,
                    'account_code'     => $selectedAccount->account_code,
                    'account_name'     => $selectedAccount->account_name,
                    'amount'           => $amount,
                    'description'      => $description,
                    'transaction_date' => $transactionDate,
                    'created_by'       => $authUser ? $authUser->name : 'Manager',
                ]
            ], 201);

        } catch (\Illuminate\Database\QueryException $e) {
            DB::rollBack();
            if ($e->getCode() === '23000' || ($e->errorInfo[1] ?? 0) === 1062 || str_contains($e->getMessage(), '1062') || str_contains($e->getMessage(), 'Duplicate entry')) {
                $prefixLabel = ($type ?? '') === 'expense' ? 'KK' : 'KM';
                $dupMessage  = "Maaf, Transaksi {$prefixLabel} ini sudah ada coba lagi dengan no berbeda";
                return response()->json([
                    'success' => false,
                    'message' => $dupMessage,
                    'errors'  => [
                        'voucher_no'         => [$dupMessage],
                        'voucher_number'     => [$dupMessage],
                        'transaction_number' => [$dupMessage],
                    ],
                ], 422);
            }

            return response()->json([
                'success' => false,
                'message' => 'Gagal memproses penyesuaian saldo: ' . $e->getMessage(),
            ], 500);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'success' => false,
                'message' => 'Gagal memproses penyesuaian saldo: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * 1. API STATISTIK COUNTER ANGGOTA
     * Endpoint: GET /api/manager/members/stats
     */
    public function getMemberStats(Request $request): JsonResponse
    {
        try {
            $totalMembers = Member::count();
            $totalAktif = Member::whereIn('status', ['active', 'aktif', 'approved'])->count();

            // Jika semua anggota di DB terdata, pastikan statistik sinkron
            if ($totalAktif === 0 && $totalMembers > 0) {
                $totalAktif = $totalMembers;
            }

            $anggotaBaruBulanIni = Member::whereMonth('created_at', now()->month)
                ->whereYear('created_at', now()->year)
                ->count();

            $nonAktifPensiun = Member::whereNotIn('status', ['active', 'aktif', 'approved'])->count();

            return response()->json([
                'success' => true,
                'status'  => 'success',
                'message' => 'Statistik anggota berhasil diambil',
                'data'    => [
                    'total_aktif'            => $totalAktif,
                    'total_active'           => $totalAktif,
                    'total_members'          => $totalMembers,
                    'anggota_baru_bulan_ini' => $anggotaBaruBulanIni,
                    'new_this_month'         => $anggotaBaruBulanIni,
                    'non_aktif_pensiun'      => $nonAktifPensiun,
                    'non_active'             => $nonAktifPensiun,
                    'inactive_count'         => $nonAktifPensiun,
                ],
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Gagal mengambil statistik anggota: ' . $e->getMessage(),
                'data'    => [
                    'total_aktif'            => 0,
                    'total_active'           => 0,
                    'total_members'          => 0,
                    'anggota_baru_bulan_ini' => 0,
                    'new_this_month'         => 0,
                    'non_aktif_pensiun'      => 0,
                    'non_active'             => 0,
                    'inactive_count'         => 0,
                ],
            ], 500);
        }
    }

    /**
     * 2. API LIST & SEARCH ANGGOTA
     * Endpoint: GET /api/manager/members
     */
    public function getManagerMembers(Request $request): JsonResponse
    {
        try {
            $query = Member::with(['loans.installments']);

            // 1. Search filter (Nama dan Nomor Anggota)
            $search = trim((string) ($request->input('search') ?? $request->input('q') ?? $request->input('query') ?? $request->input('keyword') ?? ''));
            if ($search !== '') {
                $cleanNumber = ltrim($search, '0'); // contoh "0001" menjadi "1"

                $query->where(function ($q) use ($search, $cleanNumber) {
                    $q->where('name', 'LIKE', "%{$search}%")
                      ->orWhere('member_number', 'LIKE', "%{$search}%");

                    if ($cleanNumber !== '') {
                        $q->orWhere('member_number', $cleanNumber);
                    }
                });
            }

            // 2. Status filter
            if ($request->filled('status') && !in_array(strtolower($request->status), ['all', 'semua', 'semua status', ''])) {
                $statusFilter = strtolower($request->status);
                if (in_array($statusFilter, ['active', 'aktif', 'approved'])) {
                    $query->whereIn('status', ['active', 'aktif', 'approved']);
                } elseif (in_array($statusFilter, ['inactive', 'non-aktif', 'non_aktif', 'pensiun'])) {
                    $query->whereNotIn('status', ['active', 'aktif', 'approved']);
                } else {
                    $query->where('status', $request->status);
                }
            }

            $perPage = (int) ($request->input('per_page') ?? $request->input('limit') ?? 25);
            if ($perPage <= 0) {
                $perPage = 25;
            }

            // 3. Sorting
            $sort = $request->input('sort');
            if ($sort === 'member_number' || $sort === 'no_anggota') {
                $query->orderBy('member_number', 'asc');
            } else {
                $query->orderBy('name', 'asc');
            }

            $paginated = $query->paginate($perPage);

            // Transform Collection through Paginator
            $paginated->through(function ($member) {
                $pokok    = (float) ($member->principal_savings ?? 0);
                $wajib    = (float) ($member->mandatory_savings ?? 0);
                $sukarela = (float) ($member->voluntary_savings ?? 0);
                $harian   = (float) ($member->daily_savings ?? 0);
                $duka     = (float) ($member->grief_fund ?: ($member->social_fund ?? 0));
                $totalSavings = $pokok + $wajib + $sukarela + $harian;

                // Hitung status pinjaman
                $activeLoan = $member->loans ? $member->loans->first(function ($l) {
                    return in_array($l->status, ['approved', 'active']);
                }) : null;

                $hasActiveLoan = !is_null($activeLoan);
                $plafonPinjaman = $activeLoan ? (float) $activeLoan->amount : 0.0;
                $sisaPokok = 0.0;
                if ($activeLoan) {
                    $totalPaid = (float) ($activeLoan->installments ? $activeLoan->installments->where('status', 'paid')->sum('principal_amount') : 0);
                    $sisaPokok = max(0.0, $plafonPinjaman - $totalPaid);
                }

                return [
                    'id'                        => $member->id,
                    'nia'                       => $member->member_number,
                    'no_register'               => $member->member_number,
                    'no_buku'                   => $member->member_number,
                    'member_number'             => $member->member_number,
                    'no_anggota'                => $member->member_number,
                    'nik'                       => $member->nik,
                    'buku_putih_no'             => $member->buku_putih_no,
                    'no_buku_putih'             => $member->buku_putih_no,
                    'has_buku_biru'             => (bool) $member->has_buku_biru,
                    'has_buku_putih'            => (bool) $member->has_buku_putih,
                    'full_name'                 => $member->name,
                    'name'                      => $member->name,
                    'nama'                      => $member->name,
                    'phone'                     => $member->phone,
                    'no_hp'                     => $member->phone,
                    'church'                    => $member->church_sector,
                    'church_unit'               => $member->church_sector,
                    'church_sector'             => $member->church_sector,
                    'sektor_gereja'             => $member->church_sector,
                    'join_date'                 => $member->created_at ? $member->created_at->format('Y-m-d') : null,
                    'tanggal_daftar'            => $member->created_at ? $member->created_at->format('d M Y') : null,
                    'address'                   => $member->address,
                    'alamat'                    => $member->address,
                    'status'                    => $member->status,
                    'principal_savings'         => $pokok,
                    'simpanan_pokok'            => $pokok,
                    'mandatory_savings'         => $wajib,
                    'simpanan_wajib'            => $wajib,
                    'voluntary_savings'         => $sukarela,
                    'simpanan_sukarela'         => $sukarela,
                    'daily_savings'             => $harian,
                    'tabungan_harian'           => $harian,
                    'dana_duka'                 => $duka,
                    'total_savings'             => $totalSavings,
                    'total_portfolio'           => $totalSavings,
                    'total_portofolio'          => $totalSavings,
                    'total_simpanan'            => $totalSavings,
                    'total_portofolio_simpanan' => $totalSavings,
                    'total_saldo'               => $totalSavings,
                    'has_active_loan'           => $hasActiveLoan,
                    'plafon_pinjaman'           => $plafonPinjaman,
                    'sisa_pinjaman'             => $sisaPokok,
                    'loan_balance'              => $sisaPokok,
                    'sisa_pokok'                => $sisaPokok,
                ];
            });

            return response()->json([
                'success'        => true,
                'status'         => 'success',
                'message'        => 'Data anggota berhasil diambil',
                'current_page'   => $paginated->currentPage(),
                'data'           => $paginated->items(),
                'first_page_url' => $paginated->url(1),
                'from'           => $paginated->firstItem(),
                'last_page'      => $paginated->lastPage(),
                'last_page_url'  => $paginated->url($paginated->lastPage()),
                'links'          => $paginated->linkCollection()->toArray(),
                'next_page_url'  => $paginated->nextPageUrl(),
                'path'           => $paginated->path(),
                'per_page'       => $paginated->perPage(),
                'prev_page_url'  => $paginated->previousPageUrl(),
                'to'             => $paginated->lastItem(),
                'total'          => $paginated->total(),
            ], 200);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Gagal mengambil data anggota: ' . $e->getMessage(),
                'data'    => [],
            ], 500);
        }
    }

    /**
     * 3. API DETAIL PORTOFOLIO ANGGOTA (Modal 3 Tab)
     * Endpoint: GET /api/manager/members/{id}/detail & GET /api/manager/members/{id}
     */
    public function getManagerMemberDetail($id): JsonResponse
    {
        try {
            $member = Member::with(['loans.installments', 'user'])->find($id);

            if (!$member) {
                return response()->json([
                    'success' => false,
                    'message' => 'Data anggota tidak ditemukan.',
                ], 404);
            }

            // Tab 1: Informasi Personal
            $personalInfo = [
                'id'                  => $member->id,
                'full_name'           => $member->name,
                'name'                => $member->name,
                'nia'                 => $member->member_number,
                'member_number'       => $member->member_number,
                'nik'                 => $member->nik,
                'buku_putih_no'       => $member->buku_putih_no,
                'no_buku_putih'       => $member->buku_putih_no,
                'has_buku_biru'       => (bool) $member->has_buku_biru,
                'has_buku_putih'      => (bool) $member->has_buku_putih,
                'phone'               => $member->phone,
                'no_wa'               => $member->phone,
                'email'               => $member->email,
                'place_of_birth'      => $member->place_of_birth,
                'date_of_birth'       => $member->date_of_birth ? Carbon::parse($member->date_of_birth)->format('Y-m-d') : null,
                'gender'              => $member->gender,
                'occupation'          => $member->occupation,
                'education'           => $member->education,
                'family_status'       => $member->family_status,
                'church_unit'         => $member->church_sector,
                'church_sector'       => $member->church_sector,
                'address'             => $member->address,
                'join_date'           => $member->created_at ? $member->created_at->format('d M Y') : '-',
                'status'              => $member->status,
                'heir' => [
                    'name'            => $member->heir_name,
                    'relationship'    => $member->heir_relationship,
                    'address'         => $member->heir_address,
                    'place_of_birth'  => $member->heir_place_of_birth,
                    'date_of_birth'   => $member->heir_date_of_birth ? Carbon::parse($member->heir_date_of_birth)->format('Y-m-d') : null,
                ],
            ];

            // Tab 2: Portofolio Simpanan
            $pokok    = (float) ($member->principal_savings ?? $member->simpanan_pokok ?? 0);
            $wajib    = (float) ($member->mandatory_savings ?? $member->simpanan_wajib ?? 0);
            $sukarela = (float) ($member->voluntary_savings ?? $member->simpanan_sukarela ?? 0);
            $harian   = (float) ($member->daily_savings ?? $member->tabungan_harian ?? 0);
            $duka     = (float) ($member->grief_fund ?: ($member->social_fund ?? $member->dana_duka ?? 0));
            $pangkal  = (float) ($member->registration_fee ?? $member->uang_pangkal ?? 0);
            $totalPortofolio = $pokok + $wajib + $sukarela + $harian;
            $totalSaham = $pokok + $wajib + $sukarela;

            $savingsPortfolio = [
                'simpanan_pokok'            => $pokok,
                'simpanan_wajib'            => $wajib,
                'simpanan_sukarela'         => $sukarela,
                'simpanan_harian'           => $harian,
                'tabungan_harian'           => $harian,
                'daily_savings'             => $harian,
                'buku_putih_no'             => $member->buku_putih_no,
                'no_buku_putih'             => $member->buku_putih_no,
                'principal_savings'         => $pokok,
                'mandatory_savings'         => $wajib,
                'voluntary_savings'         => $sukarela,
                'dana_duka'                 => $duka,
                'grief_fund'                => $duka,
                'social_fund'               => $duka,
                'uang_pangkal'              => $pangkal,
                'registration_fee'          => $pangkal,
                'total_simpanan'            => $totalPortofolio,
                'total_portofolio_simpanan' => $totalPortofolio,
                'total_portfolio'           => $totalPortofolio,
                'total_saham'               => $totalSaham,
                'total_saldo'               => $totalPortofolio,
                'buku_biru'                 => [
                    'simpanan_pokok'    => $pokok,
                    'simpanan_wajib'    => $wajib,
                    'simpanan_sukarela' => $sukarela,
                    'dana_duka'         => $duka,
                    'uang_pangkal'      => $pangkal,
                    'total'             => $totalSaham,
                ],
                'buku_putih'                => [
                    'buku_putih_no'     => $member->buku_putih_no,
                    'no_buku_putih'     => $member->buku_putih_no,
                    'simpanan_harian'   => $harian,
                    'tabungan_harian'   => $harian,
                    'daily_savings'     => $harian,
                    'total'             => $harian,
                ],
            ];

            // Tab 3: Riwayat Pinjaman & Track Record
            $loans = $member->loans ?? collect([]);
            $activeLoanObj = $loans->first(function ($l) {
                return in_array($l->status, ['approved', 'active']);
            });

            $hasActiveLoan = !is_null($activeLoanObj);
            $activeLoanData = null;

            if ($activeLoanObj) {
                $plafon = (float) $activeLoanObj->amount;
                $installments = $activeLoanObj->installments ?? collect([]);
                $totalPaid = (float) $installments->where('status', 'paid')->sum('principal_amount');
                $sisaPokok = max(0.0, $plafon - $totalPaid);

                // Tentukan track record (Lancar / Perhatian Khusus / Macet)
                $hasOverdue = $installments->contains(function ($inst) {
                    return $inst->status !== 'paid' && $inst->due_date && Carbon::parse($inst->due_date)->isPast();
                });
                $trackRecord = $sisaPokok <= 0 ? 'Lunas' : ($hasOverdue ? 'Perhatian Khusus' : 'Lancar');

                $activeLoanData = [
                    'id'               => $activeLoanObj->id,
                    'loan_code'        => $activeLoanObj->loan_code,
                    'plafon_pinjaman'  => $plafon,
                    'total_dibayar'    => $totalPaid,
                    'sisa_pokok'       => $sisaPokok,
                    'duration_months'  => $activeLoanObj->duration_months,
                    'interest_rate'    => $activeLoanObj->interest_rate,
                    'status'           => $activeLoanObj->status,
                    'track_record'     => $trackRecord,
                    'approved_at'      => $activeLoanObj->approved_at ? $activeLoanObj->approved_at->format('Y-m-d') : null,
                    'installments'     => $installments->map(function ($inst) {
                        return [
                            'id'               => $inst->id,
                            'installment_no'   => $inst->installment_number,
                            'due_date'         => $inst->due_date,
                            'principal_amount' => (float) $inst->principal_amount,
                            'interest_amount'  => (float) $inst->interest_amount,
                            'total_amount'     => (float) ($inst->principal_amount + $inst->interest_amount),
                            'status'           => $inst->status,
                            'paid_at'          => $inst->paid_at,
                        ];
                    }),
                ];
            }

            $loanHistory = $loans->map(function ($l) {
                $insts = $l->installments ?? collect([]);
                $paid = (float) $insts->where('status', 'paid')->sum('principal_amount');
                return [
                    'id'               => $l->id,
                    'loan_code'        => $l->loan_code,
                    'amount'           => (float) $l->amount,
                    'total_paid'       => $paid,
                    'remaining'        => max(0.0, (float) $l->amount - $paid),
                    'duration_months'  => $l->duration_months,
                    'status'           => $l->status,
                    'created_at'       => $l->created_at ? $l->created_at->format('Y-m-d') : null,
                ];
            });

            $loanPortfolio = [
                'has_active_loan' => $hasActiveLoan,
                'active_loan'     => $activeLoanData,
                'loan_history'    => $loanHistory,
            ];

            return response()->json([
                'success' => true,
                'status'  => 'success',
                'message' => 'Detail portofolio anggota berhasil diambil',
                'data'    => [
                    'id'                        => $member->id,
                    'name'                      => $member->name,
                    'full_name'                 => $member->name,
                    'member_number'             => $member->member_number,
                    'nia'                       => $member->member_number,
                    'nik'                       => $member->nik,
                    'phone'                     => $member->phone,
                    'no_wa'                     => $member->phone,
                    'church_sector'             => $member->church_sector,
                    'church_unit'               => $member->church_sector,
                    'status'                    => $member->status,
                    'join_date'                 => $member->created_at ? $member->created_at->format('d M Y') : '-',
                    'address'                   => $member->address,
                    
                    // Portofolio Simpanan langsung di level data
                    'simpanan_pokok'            => $pokok,
                    'simpanan_wajib'            => $wajib,
                    'simpanan_sukarela'         => $sukarela,
                    'simpanan_harian'           => $harian,
                    'tabungan_harian'           => $harian,
                    'daily_savings'             => $harian,
                    'principal_savings'         => $pokok,
                    'mandatory_savings'         => $wajib,
                    'voluntary_savings'         => $sukarela,
                    'dana_duka'                 => $duka,
                    'uang_pangkal'              => $pangkal,
                    'total_simpanan'            => $totalPortofolio,
                    'total_portofolio_simpanan' => $totalPortofolio,
                    'total_portfolio'           => $totalPortofolio,
                    'total_saham'               => $totalSaham,
                    'total_saldo'               => $totalPortofolio,
                    
                    // Struktur Tab
                    'personal_info'             => $personalInfo,
                    'savings_portfolio'         => $savingsPortfolio,
                    'simpanan'                  => $savingsPortfolio,
                    'savings'                   => $savingsPortfolio,
                    'loan_portfolio'            => $loanPortfolio,
                    'loans'                     => $loanPortfolio,
                    'pinjaman'                  => $loanPortfolio,
                ],
            ], 200);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Gagal mengambil detail anggota: ' . $e->getMessage(),
            ], 500);
        }
    }
}
