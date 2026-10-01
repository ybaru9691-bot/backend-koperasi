<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\JournalDetail;
use App\Models\JournalEntry;
use App\Models\Transaction;
use App\Models\Loan;
use App\Models\Member;
use App\Services\JournalService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;
use App\Models\ChartOfAccount;

class ReportController extends Controller
{
    public function cashRecap(Request $request): JsonResponse
    {
        try {
            $startDate = $request->input('start_date');
            $endDate   = $request->input('end_date');
            $month     = $request->input('month');
            $year      = $request->input('year');

            $query = Transaction::query()->with('member');

            if ($startDate && $endDate) {
                $query->whereBetween('transaction_date', [$startDate, $endDate]);
            } elseif ($month && $year) {
                $query->whereYear('transaction_date', $year)
                      ->whereMonth('transaction_date', $month);
            }

            $query->where(function($q) {
                $q->whereNull('status')
                  ->orWhere('status', '!=', 'rejected');
            });

            $transactions = $query->orderBy('transaction_date', 'desc')
                ->orderBy('id', 'desc')
                ->get();

            $totalCashIn = 0.0;
            $totalCashOut = 0.0;

            $rekapList = $transactions->map(function ($trx) use (&$totalCashIn, &$totalCashOut) {
                $isKM = in_array(strtolower($trx->type ?? ''), ['deposit', 'in', 'kas_masuk', 'km']);
                $amount = (float) $trx->amount;

                if ($isKM) {
                    $totalCashIn += $amount;
                } else {
                    $totalCashOut += $amount;
                }

                $member = $trx->member;
                $memberName = $member?->name ?? 'Anggota Umum';
                $memberNo = $member?->member_number ?? $member?->no_anggota ?? '-';
                $receiptNo = $trx->formatted_receipt_no ?? ($isKM ? 'KM-' . $trx->id : 'KK-' . $trx->id);
                
                $dateStr = $trx->transaction_date
                    ? (is_string($trx->transaction_date) ? substr($trx->transaction_date, 0, 10) : $trx->transaction_date->format('Y-m-d'))
                    : ($trx->created_at ? $trx->created_at->format('Y-m-d') : date('Y-m-d'));

                $desc = $trx->description ?? ($isKM ? 'Penerimaan Kas' : 'Pengeluaran Kas');

                return [
                    'id'               => $trx->id,
                    'nota'             => $receiptNo,
                    'receipt_number'   => $receiptNo,
                    'member_name'      => $memberName,
                    'member_number'    => $memberNo,
                    'member_no'        => $memberNo,
                    'member'           => "{$memberName} (No. {$memberNo})",
                    'type'             => $isKM ? 'IN' : 'OUT',
                    'transaction_type' => $isKM ? 'IN' : 'OUT',
                    'desc'             => $desc,
                    'description'      => $desc,
                    'amount'           => $amount,
                    'date'             => $dateStr,
                    'status'           => $trx->status ?? 'approved',
                ];
            });

            // Hitung data Rekap Pinjaman Beredar (Tab 2)
            $loans = Loan::with('member')
                ->whereIn('status', ['active', 'approved', 'disbursed', 'DISBURSED', 'ACTIVE'])
                ->get();

            $totalPinjamanBeredar = (float) $loans->sum('amount');
            $sisaPokokPinjaman = (float) $loans->sum('remaining_principal');
            $totalAngsuranTerkumpul = $totalPinjamanBeredar - $sisaPokokPinjaman;
            if ($totalAngsuranTerkumpul < 0) {
                $totalAngsuranTerkumpul = 0.0;
            }

            $peminjamList = $loans->map(function ($loan) {
                return [
                    'id'          => $loan->id,
                    'loan_code'   => $loan->loan_code,
                    'member_id'   => $loan->member_id,
                    'name'        => $loan->member?->name ?? 'Peminjam',
                    'memberNo'    => $loan->member?->member_number ?? '-',
                    'member_no'   => $loan->member?->member_number ?? '-',
                    'plafon'      => (float) $loan->amount,
                    'sisaPokok'   => (float) ($loan->remaining_principal ?? $loan->amount),
                    'sisa_pokok'  => (float) ($loan->remaining_principal ?? $loan->amount),
                    'status'      => ($loan->status == 'overdue' || $loan->status == 'menunggak') ? 'Menunggak' : 'Lancar',
                    'church'      => $loan->member?->church_sector ?? $loan->member?->church ?? 'HKBP Ressort Dame Duri',
                ];
            });

            $netCashBalance = $totalCashIn - $totalCashOut;

            return response()->json([
                'success' => true,
                'message' => 'Data rekapitulasi kas & pinjaman berhasil diambil',
                'data'    => [
                    // Summary Kas
                    'total_cash_in'            => $totalCashIn,
                    'totalCashIn'             => $totalCashIn,
                    'total_cash_out'           => $totalCashOut,
                    'totalCashOut'            => $totalCashOut,
                    'net_cash_balance'         => $netCashBalance,
                    'netCashBalance'          => $netCashBalance,
                    
                    // Summary Pinjaman
                    'total_pinjaman_beredar'   => $totalPinjamanBeredar,
                    'total_angsuran_terkumpul' => $totalAngsuranTerkumpul,
                    'sisa_pokok_pinjaman'      => $sisaPokokPinjaman,

                    // Daftar Mutasi Kas
                    'transactions'             => $rekapList,
                    'rekap_kas_list'           => $rekapList,

                    // Daftar Peminjam Aktif
                    'borrowers'                => $peminjamList,
                    'peminjam_list'            => $peminjamList,
                ],
            ], 200);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Gagal mengambil rekapitulasi kas: ' . $e->getMessage(),
                'data'    => null,
            ], 500);
        }
    }
    
    public function trialBalance(Request $request): JsonResponse
    {
        try {
            $startDate = $request->input('start_date');
            $endDate   = $request->input('end_date');
            $month     = $request->input('month');
            $year      = $request->input('year');
            $period    = $request->input('period');
            $week      = $request->input('week') ?? $request->input('minggu');

            [$resolvedStart, $resolvedEnd, $periodLabel] = \App\Services\WorksheetReportService::resolvePeriodDates(
                $startDate, $endDate, $period, $month, $year, $week
            );

            $service = app(\App\Services\WorksheetReportService::class);
            $result  = $service->generateWorksheet($resolvedStart, $resolvedEnd, $periodLabel);

            $result['status']      = 'active';
            $result['week_status'] = 'active';
            $result['is_locked']   = false;

            return response()->json([
                'success'     => true,
                'status'      => 'active',
                'week_status' => 'active',
                'is_locked'   => false,
                'message'     => 'Laporan Neraca Lajur berhasil dihitung',
                'data'        => $result,
            ], 200);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Gagal menghitung Neraca Lajur: ' . $e->getMessage(),
                'data'    => null,
            ], 200);
        }
    }

    public function lockWeek(Request $request): JsonResponse
    {
        try {
            $month     = $request->input('month');
            $year      = $request->input('year');
            $week      = $request->input('week') ?? $request->input('minggu');
            $startDate = $request->input('start_date');
            $endDate   = $request->input('end_date');

            [$resolvedStart, $resolvedEnd, $periodLabel] = \App\Services\WorksheetReportService::resolvePeriodDates(
                $startDate, $endDate, null, $month, $year, $week
            );

            $wKey = $week ? strtoupper(trim($week)) : 'ALL';
            $lockKey = "week_lock_{$year}_{$month}_{$wKey}";
            Cache::forever($lockKey, 'locked');

            if (\Illuminate\Support\Facades\Schema::hasTable('weekly_period_locks')) {
                \App\Models\WeeklyPeriodLock::updateOrCreate(
                    [
                        'year'  => (int) $year,
                        'month' => (int) $month,
                        'week'  => $wKey,
                    ],
                    [
                        'start_date' => $resolvedStart,
                        'end_date'   => $resolvedEnd,
                        'is_locked'  => true,
                        'status'     => 'LOCKED',
                        'locked_at'  => now(),
                        'locked_by'  => optional($request->user())->id,
                        'notes'      => 'Terkunci / Siap Evaluasi via Neraca Lajur',
                    ]
                );
            }

            return response()->json([
                'success'     => true,
                'status'      => 'locked',
                'week_status' => 'locked',
                'is_locked'   => true,
                'message'     => 'Transaksi pekan ' . ($week ?? 'terpilih') . ' berhasil dikunci dan ditandai siap evaluasi.',
                'data'        => [
                    'year'        => $year,
                    'month'       => $month,
                    'week'        => $week,
                    'is_locked'   => true,
                    'week_status' => 'locked',
                    'start_date'  => $resolvedStart,
                    'end_date'    => $resolvedEnd,
                ],
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Gagal mengunci periode pekan: ' . $e->getMessage(),
            ], 500);
        }
    }

    public function incomeStatement(Request $request): JsonResponse
    {
        try {
            $startDate = $request->input('start_date');
            $endDate   = $request->input('end_date');
            $month     = $request->input('month');
            $year      = $request->input('year');
            $period    = $request->input('period');
            $week      = $request->input('week') ?? $request->input('minggu');

            [$resolvedStart, $resolvedEnd, $periodLabel] = \App\Services\WorksheetReportService::resolvePeriodDates(
                $startDate, $endDate, $period, $month, $year, $week
            );

            $service = app(\App\Services\WorksheetReportService::class);
            $result  = $service->generateWorksheet($resolvedStart, $resolvedEnd, $periodLabel);

            // Filter hanya REVENUE & EXPENSE
            $revenues = collect($result['accounts'])->where('account_type', 'REVENUE')->values();
            $expenses = collect($result['accounts'])->where('account_type', 'EXPENSE')->values();

            $totalRevenue = (float) ($revenues->sum('rugi_laba_credit') ?: $revenues->sum('saldo_credit'));
            $totalExpense = (float) ($expenses->sum('rugi_laba_debit') ?: $expenses->sum('saldo_debit'));
            $netIncome    = $totalRevenue - $totalExpense;

            return response()->json([
                'success' => true,
                'message' => 'Laporan Laba/Rugi berhasil dihitung',
                'data'    => [
                    'period'        => $result['period'],
                    'revenue'       => [
                        'items' => $revenues,
                        'total' => $totalRevenue,
                    ],
                    'expense'       => [
                        'items' => $expenses,
                        'total' => $totalExpense,
                    ],
                    'net_income'    => $netIncome,
                    'is_profit'     => $netIncome >= 0,
                ],
            ], 200);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Gagal menghitung Laba/Rugi: ' . $e->getMessage(),
                'data'    => null,
            ], 200);
        }
    }



    /**
     * Menghitung Saldo Awal Akun secara dinamis dengan memperhitungkan Saldo Awal Cut-Off
     * dari tabel initial_account_balances dan mutasi transaksi jurnal.
     */
    protected function calculateAccountOpeningBalance($coa, ?string $startDate): float
    {
        $normalBalance = strtoupper((string) $coa->normal_balance);

        // 1. Ambil record Cut-Off dari tabel initial_account_balances jika tersedia
        $initialRecord = null;
        if (Schema::hasTable('initial_account_balances')) {
            $initialRecord = DB::table('initial_account_balances')
                ->where('account_code', $coa->account_code)
                ->orderBy('cutoff_date', 'desc')
                ->first();
        }

        if ($initialRecord) {
            $cutoffDate = $initialRecord->cutoff_date;
            $initDebit  = (float) $initialRecord->debit;
            $initCredit = (float) $initialRecord->credit;
            $initBalance = ($normalBalance === 'DEBIT') ? ($initDebit - $initCredit) : ($initCredit - $initDebit);

            if ($startDate) {
                if ($startDate > $cutoffDate) {
                    // Ada saldo awal cut-off dan filter tanggal startDate > cutoffDate:
                    // Saldo Awal = Saldo Cut-Off + Mutasi Operasional Riil (cutoff_date s/d < startDate, non-KM-IMP)
                    $mutasi = DB::table('journal_details')
                        ->join('journal_entries', 'journal_details.journal_entry_id', '=', 'journal_entries.id')
                        ->where('journal_details.account_id', $coa->id)
                        ->whereDate('journal_entries.entry_date', '>=', $cutoffDate)
                        ->whereDate('journal_entries.entry_date', '<', $startDate)
                        ->where(function ($q) {
                            $q->whereNull('journal_entries.voucher_number')
                              ->orWhere(function ($vn) {
                                  $vn->where('journal_entries.voucher_number', 'not like', 'KM-IMP%')
                                     ->where('journal_entries.voucher_number', 'not like', 'KM-IMP-P%');
                              });
                        })
                        ->selectRaw('COALESCE(SUM(journal_details.debit), 0) as total_debit, COALESCE(SUM(journal_details.credit), 0) as total_credit')
                        ->first();

                    $mDebit = (float) ($mutasi->total_debit ?? 0);
                    $mCredit = (float) ($mutasi->total_credit ?? 0);
                    $netMutasi = ($normalBalance === 'DEBIT') ? ($mDebit - $mCredit) : ($mCredit - $mDebit);

                    return (float) ($initBalance + $netMutasi);
                } else {
                    // Filter tanggal sebelum atau sama dengan tanggal cut-off
                    $prior = DB::table('journal_details')
                        ->join('journal_entries', 'journal_details.journal_entry_id', '=', 'journal_entries.id')
                        ->where('journal_details.account_id', $coa->id)
                        ->whereDate('journal_entries.entry_date', '<', $startDate)
                        ->where(function ($q) {
                            $q->whereNull('journal_entries.voucher_number')
                              ->orWhere(function ($vn) {
                                  $vn->where('journal_entries.voucher_number', 'not like', 'KM-IMP%')
                                     ->where('journal_entries.voucher_number', 'not like', 'KM-IMP-P%');
                              });
                        })
                        ->selectRaw('COALESCE(SUM(journal_details.debit), 0) as total_debit, COALESCE(SUM(journal_details.credit), 0) as total_credit')
                        ->first();

                    $pDebit = (float) ($prior->total_debit ?? 0);
                    $pCredit = (float) ($prior->total_credit ?? 0);
                    return ($normalBalance === 'DEBIT') ? ($pDebit - $pCredit) : ($pCredit - $pDebit);
                }
            } else {
                // Tidak ada filter startDate: saldo awal adalah saldo cut-off
                return (float) $initBalance;
            }
        }

        // 2. Jika belum ada data cut-off: hitung mutasi sebelum startDate (non-KM-IMP)
        if ($startDate) {
            $awalData = DB::table('journal_details')
                ->join('journal_entries', 'journal_details.journal_entry_id', '=', 'journal_entries.id')
                ->where('journal_details.account_id', $coa->id)
                ->whereDate('journal_entries.entry_date', '<', $startDate)
                ->where(function ($q) {
                    $q->whereNull('journal_entries.voucher_number')
                      ->orWhere(function ($vn) {
                          $vn->where('journal_entries.voucher_number', 'not like', 'KM-IMP%')
                             ->where('journal_entries.voucher_number', 'not like', 'KM-IMP-P%');
                      });
                })
                ->selectRaw('COALESCE(SUM(journal_details.debit), 0) as total_debit, COALESCE(SUM(journal_details.credit), 0) as total_credit')
                ->first();

            $tDebit = (float) ($awalData->total_debit ?? 0);
            $tCredit = (float) ($awalData->total_credit ?? 0);
            return ($normalBalance === 'DEBIT') ? ($tDebit - $tCredit) : ($tCredit - $tDebit);
        }

        return 0.0;
    }

    public function getLedger(Request $request): JsonResponse
    {
        try {
            $accountId = $request->input('account_id');
            $accountCode = $request->input('account_code');
            if ($accountId) {
                $coa = ChartOfAccount::find($accountId);
            } elseif ($accountCode) {
                $coa = ChartOfAccount::where('account_code', $accountCode)->first();
            } else {
                $coa = ChartOfAccount::where('account_code', '1000')->first();
            }

            if (!$coa) {
                return response()->json(['success' => false, 'message' => 'Akun tidak ditemukan!'], 404);
            }

            $startDate = $request->input('start_date');
            $endDate = $request->input('end_date');
            $perPage = (int) $request->input('per_page', 25);
            if ($perPage <= 0 || $perPage > 250) {
                $perPage = 25;
            }
            $page = (int) $request->input('page', 1);
            if ($page <= 0) {
                $page = 1;
            }

            $normalBalance = strtoupper((string) $coa->normal_balance);
            $openingBalance = $this->calculateAccountOpeningBalance($coa, $startDate);

            // 2. Query mutasi dalam periode (Non-KM-IMP)
            $query = DB::table('journal_details')
                ->join('journal_entries', 'journal_details.journal_entry_id', '=', 'journal_entries.id')
                ->where('journal_details.account_id', $coa->id)
                ->where(function ($q) {
                    $q->whereNull('journal_entries.voucher_number')
                      ->orWhere(function ($vn) {
                          $vn->where('journal_entries.voucher_number', 'not like', 'KM-IMP%')
                             ->where('journal_entries.voucher_number', 'not like', 'KM-IMP-P%');
                      });
                });

            if ($startDate) {
                $query->whereDate('journal_entries.entry_date', '>=', $startDate);
            }
            if ($endDate) {
                $query->whereDate('journal_entries.entry_date', '<=', $endDate);
            }

            // Total agregasi mutasi dalam periode
            $periodTotals = (clone $query)->selectRaw('COALESCE(SUM(journal_details.debit), 0) as total_debit, COALESCE(SUM(journal_details.credit), 0) as total_credit')->first();
            $periodTotalDebit = (float) ($periodTotals->total_debit ?? 0);
            $periodTotalCredit = (float) ($periodTotals->total_credit ?? 0);
            $netPeriodChange = ($normalBalance === 'DEBIT') ? ($periodTotalDebit - $periodTotalCredit) : ($periodTotalCredit - $periodTotalDebit);

            $endingBalance = $openingBalance + $netPeriodChange;

            // 3. Hitung opening balance untuk halaman yang sedang diminta (jika page > 1)
            $offset = ($page - 1) * $perPage;
            $pageOpeningBalance = $openingBalance;

            if ($page > 1 && $offset > 0) {
                $priorSub = (clone $query)
                    ->select('journal_details.debit', 'journal_details.credit')
                    ->orderBy('journal_entries.entry_date', 'asc')
                    ->orderBy('journal_entries.id', 'asc')
                    ->orderBy('journal_details.id', 'asc')
                    ->limit($offset);

                $priorTotals = DB::table(DB::raw("({$priorSub->toSql()}) as prior_entries"))
                    ->mergeBindings($priorSub)
                    ->selectRaw('COALESCE(SUM(debit), 0) as prior_debit, COALESCE(SUM(credit), 0) as prior_credit')
                    ->first();

                $priorDebit = (float) ($priorTotals->prior_debit ?? 0);
                $priorCredit = (float) ($priorTotals->prior_credit ?? 0);
                $priorNet = ($normalBalance === 'DEBIT') ? ($priorDebit - $priorCredit) : ($priorCredit - $priorDebit);
                $pageOpeningBalance += $priorNet;
            }

            // 4. Paginate mutasi transaksi
            $paginatedEntries = $query->select(
                'journal_entries.id as entry_id',
                'journal_entries.entry_date',
                'journal_entries.voucher_number',
                'journal_entries.description as journal_description',
                'journal_details.id as detail_id',
                'journal_details.debit',
                'journal_details.credit',
                'journal_details.description as detail_description'
            )
            ->orderBy('journal_entries.entry_date', 'asc')
            ->orderBy('journal_entries.id', 'asc')
            ->orderBy('journal_details.id', 'asc')
            ->paginate($perPage);

            $runningBalance = $pageOpeningBalance;
            $paginatedEntries->getCollection()->transform(function ($entry) use (&$runningBalance, $normalBalance) {
                $debit = (float) $entry->debit;
                $credit = (float) $entry->credit;
                
                if ($normalBalance === 'DEBIT') {
                    $runningBalance += ($debit - $credit);
                } else {
                    $runningBalance += ($credit - $debit);
                }

                return [
                    'entry_id'       => $entry->entry_id,
                    'date'           => $entry->entry_date,
                    'entry_date'     => $entry->entry_date,
                    'voucher_number' => $entry->voucher_number,
                    'description'    => $entry->detail_description ?? $entry->journal_description,
                    'debit'          => $debit,
                    'credit'         => $credit,
                    'balance'        => round($runningBalance, 2),
                ];
            });

            return response()->json([
                'success' => true,
                'data' => [
                    'account'              => $coa,
                    'opening_balance'      => $openingBalance,
                    'page_opening_balance' => $pageOpeningBalance,
                    'entries'              => $paginatedEntries->items(),
                    'transactions'         => $paginatedEntries->items(),
                    'pagination'           => [
                        'current_page' => $paginatedEntries->currentPage(),
                        'per_page'     => $paginatedEntries->perPage(),
                        'total'        => $paginatedEntries->total(),
                        'last_page'    => $paginatedEntries->lastPage(),
                    ],
                    'total_debit'          => $periodTotalDebit,
                    'total_credit'         => $periodTotalCredit,
                    'ending_balance'       => $endingBalance,
                ]
            ], 200);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Gagal memuat data ledger: ' . $e->getMessage()
            ], 500);
        }
    }

    public function journalLedger(Request $request): JsonResponse
    {
        try {
            $accountCode = $request->input('account_code');
            $accountId   = $request->input('account_id');

            if ($accountId) {
                $coa = \App\Models\ChartOfAccount::find($accountId);
            } elseif ($accountCode) {
                $coa = \App\Models\ChartOfAccount::where('account_code', $accountCode)->first();
            } else {
                $coa = \App\Models\ChartOfAccount::where('account_code', '1000')->first();
            }

            if (!$coa) {
                return response()->json([
                    'success' => false,
                    'message' => 'Akun tidak ditemukan!',
                    'data'    => null,
                ], 404);
            }
            
            $normalBalance = strtoupper((string) $coa->normal_balance);
            $startDate = $request->input('start_date');
            $endDate   = $request->input('end_date');
            $perPage   = (int) $request->input('per_page', 25);
            if ($perPage <= 0 || $perPage > 250) {
                $perPage = 25;
            }
            $page = (int) $request->input('page', 1);
            if ($page <= 0) {
                $page = 1;
            }

            // 1. Hitung Saldo Awal Dinamis (berdasarkan Saldo Awal Cut-Off & Mutasi Jurnal)
            $beginningBalance = $this->calculateAccountOpeningBalance($coa, $startDate);

            // 2. Query mutasi dalam periode (Non-KM-IMP)
            $query = DB::table('journal_details')
                ->join('journal_entries', 'journal_details.journal_entry_id', '=', 'journal_entries.id')
                ->where('journal_details.account_id', $coa->id)
                ->where(function ($q) {
                    $q->whereNull('journal_entries.voucher_number')
                      ->orWhere(function ($vn) {
                          $vn->where('journal_entries.voucher_number', 'not like', 'KM-IMP%')
                             ->where('journal_entries.voucher_number', 'not like', 'KM-IMP-P%');
                      });
                });

            if ($startDate) {
                $query->whereDate('journal_entries.entry_date', '>=', $startDate);
            }
            if ($endDate) {
                $query->whereDate('journal_entries.entry_date', '<=', $endDate);
            }

            // Total agregasi mutasi periode
            $periodTotals = (clone $query)->selectRaw('COALESCE(SUM(journal_details.debit), 0) as total_debit, COALESCE(SUM(journal_details.credit), 0) as total_credit')->first();
            $periodTotalDebit = (float) ($periodTotals->total_debit ?? 0);
            $periodTotalCredit = (float) ($periodTotals->total_credit ?? 0);
            $netPeriodChange = ($normalBalance === 'DEBIT') ? ($periodTotalDebit - $periodTotalCredit) : ($periodTotalCredit - $periodTotalDebit);

            $endingBalance = $beginningBalance + $netPeriodChange;

            // 3. Hitung opening balance untuk page saat ini
            $offset = ($page - 1) * $perPage;
            $pageOpeningBalance = $beginningBalance;

            if ($page > 1 && $offset > 0) {
                $priorSub = (clone $query)
                    ->select('journal_details.debit', 'journal_details.credit')
                    ->orderBy('journal_entries.entry_date', 'asc')
                    ->orderBy('journal_entries.id', 'asc')
                    ->orderBy('journal_details.id', 'asc')
                    ->limit($offset);

                $priorTotals = DB::table(DB::raw("({$priorSub->toSql()}) as prior_entries"))
                    ->mergeBindings($priorSub)
                    ->selectRaw('COALESCE(SUM(debit), 0) as prior_debit, COALESCE(SUM(credit), 0) as prior_credit')
                    ->first();

                $priorDebit = (float) ($priorTotals->prior_debit ?? 0);
                $priorCredit = (float) ($priorTotals->prior_credit ?? 0);
                $priorNet = ($normalBalance === 'DEBIT') ? ($priorDebit - $priorCredit) : ($priorCredit - $priorDebit);
                $pageOpeningBalance += $priorNet;
            }

            // 4. Paginate mutasi transaksi
            $paginatedEntries = $query->select(
                'journal_entries.id as entry_id',
                'journal_entries.entry_date',
                'journal_entries.voucher_number',
                'journal_entries.description as journal_description',
                'journal_details.id as detail_id',
                'journal_details.debit',
                'journal_details.credit',
                'journal_details.description as detail_description'
            )
            ->orderBy('journal_entries.entry_date', 'asc')
            ->orderBy('journal_entries.id', 'asc')
            ->orderBy('journal_details.id', 'asc')
            ->paginate($perPage);

            $runningBalance = $pageOpeningBalance;
            $paginatedEntries->getCollection()->transform(function ($entry) use (&$runningBalance, $normalBalance) {
                $debit  = (float) $entry->debit;
                $credit = (float) $entry->credit;

                if ($normalBalance === 'DEBIT') {
                    $runningBalance += ($debit - $credit);
                } else {
                    $runningBalance += ($credit - $debit);
                }

                return [
                    'entry_id'        => $entry->entry_id,
                    'entry_date'      => $entry->entry_date,
                    'date'            => $entry->entry_date,
                    'voucher_number'  => $entry->voucher_number,
                    'description'     => $entry->detail_description ?? $entry->journal_description,
                    'debit'           => $debit,
                    'credit'          => $credit,
                    'balance'         => round($runningBalance, 2),
                ];
            });

            return response()->json([
                'success' => true,
                'message' => "Buku Besar akun [{$coa->account_code}] berhasil diambil",
                'data'    => [
                    'account_code'      => $coa->account_code,
                    'account_name'      => $coa->account_name,
                    'account'           => $coa,
                    'beginning_balance' => $beginningBalance,
                    'opening_balance'   => $beginningBalance,
                    'page_opening_balance' => $pageOpeningBalance,
                    'entries'           => $paginatedEntries->items(),
                    'transactions'      => $paginatedEntries->items(),
                    'pagination'        => [
                        'current_page' => $paginatedEntries->currentPage(),
                        'per_page'     => $paginatedEntries->perPage(),
                        'total'        => $paginatedEntries->total(),
                        'last_page'    => $paginatedEntries->lastPage(),
                    ],
                    'total_debit'       => $periodTotalDebit,
                    'total_credit'      => $periodTotalCredit,
                    'ending_balance'    => $endingBalance,
                ],
            ], 200);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Gagal mengambil Buku Besar: ' . $e->getMessage(),
                'data'    => null,
            ], 200);
        }
    }
    /**
     * Validasi autentikasi untuk download / export (Sanctum session, Bearer header, atau query token).
     */
    private function validateExportAuth(Request $request): bool
    {
        if (auth('sanctum')->check() || $request->user('sanctum')) {
            return true;
        }

        $token = $request->bearerToken() 
            ?? $request->query('token') 
            ?? $request->query('access_token') 
            ?? $request->input('token');

        if (!empty($token)) {
            $accessToken = \Laravel\Sanctum\PersonalAccessToken::findToken($token);
            if ($accessToken) {
                return true;
            }
        }

        return false;
    }

    public function exportTrialBalance(Request $request, $type)
    {
        if (!$this->validateExportAuth($request)) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthorized: Token autentikasi tidak valid atau telah kadaluarsa.'
            ], 401);
        }

        $startDate = $request->input('start_date');
        $endDate   = $request->input('end_date');
        
        $month  = $request->input('month');
        $year   = $request->input('year');
        $period = $request->input('period');
        $week   = $request->input('week') ?? $request->input('minggu');

        [$startDate, $endDate, $periodLabel] = \App\Services\WorksheetReportService::resolvePeriodDates(
            $startDate, $endDate, $period, $month, $year, $week
        );

        $service = app(\App\Services\WorksheetReportService::class);
        $result  = $service->generateWorksheet($startDate, $endDate, $periodLabel);
        
        $cleanLabel = preg_replace('/[^a-zA-Z0-9_-]/', '_', $periodLabel ?? 'Report');
        $fileName = 'Neraca_Lajur_' . $cleanLabel;

        if (strtolower($type) === 'pdf') {
            $pdf = \Barryvdh\DomPDF\Facade\Pdf::loadView('exports.trial-balance', [
                'result' => $result,
                'startDate' => $startDate,
                'endDate' => $endDate
            ]);
            $pdf->setPaper('a4', 'landscape');
            return $pdf->download($fileName . '.pdf');
        } elseif (in_array(strtolower($type), ['excel', 'xlsx', 'csv'])) {
            $headers = [
                "Content-type"        => "text/csv; charset=UTF-8",
                "Content-Disposition" => "attachment; filename=" . $fileName . ".csv",
                "Pragma"              => "no-cache",
                "Cache-Control"       => "must-revalidate, post-check=0, pre-check=0",
                "Expires"             => "0"
            ];
            $columns = [
                'Kode Akun', 'Nama Akun', 
                'Saldo Awal Debit', 'Saldo Awal Kredit', 
                'Mutasi Debit', 'Mutasi Kredit',
                'Percobaan Debit', 'Percobaan Kredit',
                'Laba Rugi Debit', 'Laba Rugi Kredit',
                'Neraca Akhir Debit', 'Neraca Akhir Kredit'
            ];
            $callback = function() use($result, $columns) {
                $file = fopen('php://output', 'w');
                fputcsv($file, $columns);
                foreach ($result['accounts'] as $acc) {
                    fputcsv($file, [
                        $acc['account_code'] ?? '',
                        $acc['account_name'] ?? '',
                        $acc['awal_debit'] ?? $acc['initial_debit'] ?? 0,
                        $acc['awal_credit'] ?? $acc['initial_credit'] ?? 0,
                        $acc['mutasi_debit'] ?? $acc['adjustment_debit'] ?? 0,
                        $acc['mutasi_credit'] ?? $acc['adjustment_credit'] ?? 0,
                        $acc['percobaan_debit'] ?? $acc['trial_debit'] ?? 0,
                        $acc['percobaan_credit'] ?? $acc['trial_credit'] ?? 0,
                        $acc['rugi_laba_debit'] ?? 0,
                        $acc['rugi_laba_credit'] ?? 0,
                        $acc['neraca_debit'] ?? 0,
                        $acc['neraca_credit'] ?? 0,
                    ]);
                }
                // Baris Summary / Total
                if (!empty($result['summary'])) {
                    $s = $result['summary'];
                    fputcsv($file, [
                        'TOTAL',
                        '',
                        $s['total_awal_debit'] ?? $s['initial_debit'] ?? 0,
                        $s['total_awal_credit'] ?? $s['initial_credit'] ?? 0,
                        $s['total_mutasi_debit'] ?? $s['adjustment_debit'] ?? 0,
                        $s['total_mutasi_credit'] ?? $s['adjustment_credit'] ?? 0,
                        $s['total_percobaan_debit'] ?? $s['trial_debit'] ?? 0,
                        $s['total_percobaan_credit'] ?? $s['trial_credit'] ?? 0,
                        $s['total_rugi_laba_debit'] ?? $s['rugi_laba_debit'] ?? 0,
                        $s['total_rugi_laba_credit'] ?? $s['rugi_laba_credit'] ?? 0,
                        $s['total_neraca_debit'] ?? $s['neraca_debit'] ?? 0,
                        $s['total_neraca_credit'] ?? $s['neraca_credit'] ?? 0,
                    ]);
                }
                fclose($file);
            };
            return response()->stream($callback, 200, $headers);
        }
        return response()->json(['success' => false, 'message' => 'Tipe export tidak valid.'], 400);
    }

    /**
     * Export Buku Besar ke format Excel (.xlsx)
     */
    public function exportExcelLedger(Request $request)
    {
        return $this->exportJournalLedger($request, 'excel');
    }

    /**
     * Export Buku Besar ke format PDF (.pdf)
     */
    public function exportPdfLedger(Request $request)
    {
        return $this->exportJournalLedger($request, 'pdf');
    }

    /**
     * Handler Utama Export Buku Besar (Journal Ledger)
     */
    public function exportJournalLedger(Request $request, $type = 'excel')
    {
        if (!$this->validateExportAuth($request)) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthorized: Token autentikasi tidak valid atau telah kadaluarsa.'
            ], 401);
        }

        $accountCode = $request->input('account_code') ?? $request->input('accountCode') ?? $request->input('code') ?? $request->input('account');
        $accountId   = $request->input('account_id') ?? $request->input('accountId') ?? $request->input('id');

        if ($accountId) {
            $coa = \App\Models\ChartOfAccount::find($accountId);
        } elseif ($accountCode) {
            $coa = \App\Models\ChartOfAccount::where('account_code', $accountCode)->first();
        } else {
            $coa = \App\Models\ChartOfAccount::where('account_code', '1000')->first();
        }

        if (!$coa) {
            return response()->json(['success' => false, 'message' => 'Akun perkiraan tidak ditemukan!'], 404);
        }

        $accountCode = $coa->account_code;
        $normalBalance = strtoupper((string) $coa->normal_balance);

        $startDate = $request->input('start_date');
        $endDate   = $request->input('end_date');

        // 1. Hitung Saldo Awal Dinamis (berdasarkan Saldo Awal Cut-Off & Mutasi Jurnal)
        $beginningBalance = $this->calculateAccountOpeningBalance($coa, $startDate);

        $query = DB::table('journal_details')
            ->join('journal_entries', 'journal_details.journal_entry_id', '=', 'journal_entries.id')
            ->where('journal_details.account_id', $coa->id)
            ->where(function ($q) {
                $q->whereNull('journal_entries.voucher_number')
                  ->orWhere(function ($vn) {
                      $vn->where('journal_entries.voucher_number', 'not like', 'KM-IMP%')
                         ->where('journal_entries.voucher_number', 'not like', 'KM-IMP-P%');
                  });
            });

        if (!empty($startDate)) {
            $query->whereDate('journal_entries.entry_date', '>=', $startDate);
        }
        if (!empty($endDate)) {
            $query->whereDate('journal_entries.entry_date', '<=', $endDate);
        }

        $periodTotals = (clone $query)->selectRaw('COALESCE(SUM(journal_details.debit), 0) as total_debit, COALESCE(SUM(journal_details.credit), 0) as total_credit')->first();
        $periodTotalDebit = (float) ($periodTotals->total_debit ?? 0);
        $periodTotalCredit = (float) ($periodTotals->total_credit ?? 0);

        $entries = $query->select(
            'journal_entries.entry_date',
            'journal_entries.voucher_number',
            'journal_entries.description as journal_description',
            'journal_details.debit',
            'journal_details.credit',
            'journal_details.description as detail_description'
        )
        ->orderBy('journal_entries.entry_date', 'asc')
        ->orderBy('journal_entries.id', 'asc')
        ->orderBy('journal_details.id', 'asc')
        ->get();

        $balance = $beginningBalance;
        $totalDebit = 0;
        $totalCredit = 0;

        $rows = $entries->map(function ($entry) use (&$balance, $normalBalance, &$totalDebit, &$totalCredit) {
            $debit  = (float) $entry->debit;
            $credit = (float) $entry->credit;
            
            if ($normalBalance === 'DEBIT') {
                $balance += ($debit - $credit);
            } else {
                $balance += ($credit - $debit);
            }

            $totalDebit += $debit;
            $totalCredit += $credit;

            return [
                'entry_date'     => $entry->entry_date,
                'voucher_number' => $entry->voucher_number,
                'description'    => $entry->detail_description ?? $entry->journal_description,
                'debit'          => $debit,
                'credit'         => $credit,
                'balance'        => round($balance, 2),
            ];
        });

        $exportType = strtolower((string)$type);

        // ─── PDF EXPORT ───
        if ($exportType === 'pdf') {
            $pdf = \Barryvdh\DomPDF\Facade\Pdf::loadView('exports.ledger-pdf', [
                'coa'              => $coa,
                'beginningBalance' => $beginningBalance,
                'rows'             => $rows,
                'startDate'        => $startDate,
                'endDate'          => $endDate,
                'totalDebit'       => $totalDebit,
                'totalCredit'      => $totalCredit,
                'endingBalance'    => $balance,
            ]);
            $pdf->setPaper('a4', 'portrait');
            return $pdf->download('buku_besar_' . $accountCode . '_' . date('Ymd') . '.pdf');
        } 
        // ─── EXCEL / XLSX / CSV EXPORT ───
        elseif (in_array($exportType, ['excel', 'xlsx'])) {
            $spreadsheet = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
            $sheet = $spreadsheet->getActiveSheet();
            $sheet->setTitle('Buku Besar');

            // Metadata Title
            $sheet->setCellValue('A1', 'KOPERASI KREDIT PELITA HKBP DAME DURI');
            $sheet->setCellValue('A2', 'LAPORAN BUKU BESAR (GENERAL LEDGER)');
            $sheet->setCellValue('A3', 'Akun: [' . $coa->account_code . '] ' . $coa->account_name . ' (' . strtoupper($coa->account_type) . ')');
            $sheet->setCellValue('A4', 'Periode: ' . ($startDate ?: 'Awal') . ' s/d ' . ($endDate ?: 'Sekarang'));
            $sheet->setCellValue('A5', 'Saldo Awal: ' . number_format($beginningBalance, 2, ',', '.'));

            // Table Header
            $headers = ['Tanggal', 'No. Bukti / Voucher', 'Keterangan', 'Debit (Rp)', 'Kredit (Rp)', 'Saldo (Rp)'];
            $sheet->fromArray([$headers], null, 'A7');

            // Saldo Awal Row
            $sheet->fromArray([[
                $startDate ?: '-',
                '-',
                'Saldo Awal Periode',
                0,
                0,
                $beginningBalance
            ]], null, 'A8');

            // Data Rows
            $rowNum = 9;
            foreach ($rows as $r) {
                $sheet->setCellValue('A' . $rowNum, $r['entry_date']);
                $sheet->setCellValue('B' . $rowNum, $r['voucher_number']);
                $sheet->setCellValue('C' . $rowNum, $r['description']);
                $sheet->setCellValue('D' . $rowNum, (float)$r['debit']);
                $sheet->setCellValue('E' . $rowNum, (float)$r['credit']);
                $sheet->setCellValue('F' . $rowNum, (float)$r['balance']);
                $rowNum++;
            }

            // Total / Summary Row
            $sheet->setCellValue('A' . $rowNum, 'TOTAL MUTASI & SALDO AKHIR');
            $sheet->setCellValue('D' . $rowNum, $totalDebit);
            $sheet->setCellValue('E' . $rowNum, $totalCredit);
            $sheet->setCellValue('F' . $rowNum, $balance);

            // Auto-width columns
            foreach (range('A', 'F') as $col) {
                $sheet->getColumnDimension($col)->setAutoSize(true);
            }

            $fileName = 'buku_besar_' . $accountCode . '_' . date('Ymd') . '.xlsx';
            $writer = new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($spreadsheet);

            return response()->streamDownload(function() use ($writer) {
                $writer->save('php://output');
            }, $fileName, [
                'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            ]);
        } elseif ($exportType === 'csv') {
            $fileName = 'buku_besar_' . $accountCode . '_' . date('Ymd') . '.csv';
            $headers = [
                "Content-type"        => "text/csv; charset=UTF-8",
                "Content-Disposition" => "attachment; filename=" . $fileName,
                "Pragma"              => "no-cache",
                "Cache-Control"       => "must-revalidate, post-check=0, pre-check=0",
                "Expires"             => "0"
            ];
            $columns = ['Tanggal', 'No. Bukti', 'Keterangan', 'Debit', 'Kredit', 'Saldo'];
            $callback = function() use($rows, $columns, $beginningBalance, $totalDebit, $totalCredit, $balance) {
                $file = fopen('php://output', 'w');
                fputcsv($file, $columns);
                fputcsv($file, ['-', '-', 'Saldo Awal Periode', 0, 0, $beginningBalance]);
                foreach ($rows as $row) {
                    fputcsv($file, [
                        $row['entry_date'], $row['voucher_number'], $row['description'],
                        $row['debit'], $row['credit'], $row['balance']
                    ]);
                }
                fputcsv($file, ['TOTAL', '', '', $totalDebit, $totalCredit, $balance]);
                fclose($file);
            };
            return response()->stream($callback, 200, $headers);
        }

        return response()->json(['success' => false, 'message' => 'Tipe export tidak valid. Gunakan: excel, xlsx, pdf, atau csv.'], 400);
    }
}
