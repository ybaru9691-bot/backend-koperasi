<?php

namespace App\Services;

use App\Models\ChartOfAccount;
use App\Models\JournalDetail;
use App\Models\JournalEntry;
use App\Models\Member;
use App\Models\MonthlyCooperativeBenchmark;
use App\Models\ShuDistribution;
use App\Models\Transaction;
use Barryvdh\DomPDF\Facade\Pdf;
use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class DividendService
{
    public const DEFAULT_PERCENTAGE = 25.0;

    /**
     * Resolusi parameter tanggal siklus deviden (Baku Cut-off 21 s/d 20)
     * Contoh: Periode September 2026 = 21 Agustus 2026 s/d 20 September 2026.
     */
    public function resolveDates(int $month, int $year): array
    {
        $currentMonthObj = Carbon::createFromDate($year, $month, 1);
        $prevMonthObj    = $currentMonthObj->copy()->subMonth();

        $startDate       = Carbon::createFromDate($prevMonthObj->year, $prevMonthObj->month, 21)->startOfDay()->toDateString();
        $cutoffDate      = Carbon::createFromDate($year, $month, 20)->endOfDay()->toDateString();
        $executionDate   = Carbon::createFromDate($year, $month, 20)->toDateString();
        $sixMonthsStart  = Carbon::parse($cutoffDate)->subMonths(6)->toDateString();
        $periodLabel     = $currentMonthObj->locale('id')->isoFormat('MMMM YYYY');
        $targetYm        = $currentMonthObj->format('Ym');
        $defaultVoucher  = "BM-DIV-{$targetYm}";

        return [
            'month'            => $month,
            'year'             => $year,
            'start_date'       => $startDate,
            'execution_date'   => $executionDate,
            'cutoff_date'      => $cutoffDate,
            'six_months_start' => $sixMonthsStart,
            'period_label'     => $periodLabel,
            'target_ym'        => $targetYm,
            'default_voucher'  => $defaultVoucher,
        ];
    }

    /**
     * Cek apakah deviden untuk periode Ym sudah pernah didistribusikan
     */
    public function isAlreadyDistributed(string $targetYm, int $month, int $year): bool
    {
        return Transaction::where(function ($q) use ($targetYm, $month, $year) {
            $q->where('receipt_number', 'like', 'DIV-' . $targetYm . '-%')
              ->orWhere('receipt_number', 'like', 'BM-DIV-' . $targetYm . '-%')
              ->orWhere(function ($sub) use ($month, $year) {
                  $sub->where('category', 'bunga_saham')
                      ->where('book_type', 'BUKU_BIRU')
                      ->whereMonth('transaction_date', $month)
                      ->whereYear('transaction_date', $year);
              });
        })->exists();
    }

    /**
     * Hitung akumulasi total modal/saham seluruh anggota Buku Biru aktif
     */
    public function getHistoricalCoopSharesAtDate(string $cutoffDate, int $month): float
    {
        $cacheKey = "coop_historical_shares_{$cutoffDate}_{$month}";
        return Cache::remember($cacheKey, 300, function () {
            $total = (float) (Member::where('status', 'active')
                ->whereNotNull('member_number')
                ->where('member_number', '!=', '-')
                ->where('member_number', '!=', '')
                ->where(function ($q) {
                    $q->where('principal_savings', '>', 0)
                      ->orWhere('mandatory_savings', '>', 0)
                      ->orWhere('voluntary_savings', '>', 0)
                      ->orWhere('has_buku_biru', true);
                })
                ->selectRaw('SUM(COALESCE(principal_savings, 0) + COALESCE(mandatory_savings, 0) + COALESCE(voluntary_savings, 0)) as total')
                ->value('total') ?? 0.0);

            return round($total, 2);
        });
    }

    /**
     * Hitung total pendapatan operasional, beban kantor, dan SHU Bersih MURNI untuk siklus 21 s/d 20.
     * PRIORITAS: Jika manajer telah mengisi parameter di MonthlyCooperativeBenchmark, gunakan nilai tersebut.
     */
    public function calculateNetProfit(int $month, int $year, bool $checkBenchmark = true): array
    {
        $dates = $this->resolveDates($month, $year);
        $startDate = $dates['start_date'];
        $endDate   = $dates['cutoff_date'];
        $today     = now()->toDateString();

        // Validasi cut-off tanggal berjalan: Jika siklus cut-off belum dimulai per hari ini, set otomatis 0
        if ($startDate > $today) {
            return [
                'start_date'                  => $startDate,
                'end_date'                    => $endDate,
                'total_income'                => 0.0,
                'total_expense'               => 0.0,
                'net_profit'                  => 0.0,
                'shu_bersih'                  => 0.0,
                'is_manual_benchmark'         => false,
                'dividend_allocation_percent' => self::DEFAULT_PERCENTAGE,
                'total_coop_shares'           => null,
            ];
        }

        // Cek apakah ada master parameter SHU bulanan yang diinput manual oleh manajer
        // Cocokkan fiscal_year dan month secara fleksibel (baik $year adalah fiscal_year maupun calendar_year)
        if ($checkBenchmark) {
            $fiscalYearFormula = ($month >= 6) ? ($year + 1) : $year;

            // Prioritas 1: Cocokkan record dengan net_income > 0 (baik fiscal_year = $year maupun $fiscalYearFormula)
            $benchmark = MonthlyCooperativeBenchmark::whereIn('fiscal_year', [$year, $fiscalYearFormula])
                ->where('month', $month)
                ->whereNotNull('net_income')
                ->where('net_income', '>', 0)
                ->latest('updated_at')
                ->first();

            // Prioritas 2: Fallback record terupdate
            if (!$benchmark) {
                $benchmark = MonthlyCooperativeBenchmark::whereIn('fiscal_year', [$year, $fiscalYearFormula])
                    ->where('month', $month)
                    ->whereNotNull('net_income')
                    ->latest('updated_at')
                    ->first();
            }

            if ($benchmark && $benchmark->net_income !== null) {
                $manualNetIncome = (float) $benchmark->net_income;
                return [
                    'start_date'                  => $startDate,
                    'end_date'                    => $endDate,
                    'total_income'                => $manualNetIncome,
                    'total_expense'               => 0.0,
                    'net_profit'                  => $manualNetIncome,
                    'shu_bersih'                  => $manualNetIncome,
                    'is_manual_benchmark'         => true,
                    'dividend_allocation_percent' => (float) ($benchmark->dividend_allocation_percent ?? self::DEFAULT_PERCENTAGE),
                    'total_coop_shares'           => $benchmark->total_coop_shares ? (float) $benchmark->total_coop_shares : null,
                ];
            }
        }

        // Filter Murni Kas Masuk Operasional Koperasi (bukan setoran simpanan anggota)
        $cacheKey = "coop_profit_raw_{$month}_{$year}";
        $incomeData = Cache::remember($cacheKey, 300, function () use ($startDate, $endDate) {
            $row = Transaction::query()
                ->whereDate('transaction_date', '>=', $startDate)
                ->whereDate('transaction_date', '<=', $endDate)
                ->where('status', 'approved')
                ->selectRaw("
                    SUM(CASE 
                        WHEN (type IN ('in', 'kas_masuk', 'KM') OR category IN ('pendapatan_lain', 'jasa_pinjaman', 'provisi', 'provisi_pinjaman', 'denda', 'administrasi', 'uang_pangkal', 'pend_fotocopy', 'pend_sembako', 'jasa_bank'))
                             AND (category IS NULL OR category NOT IN ('simpanan_pokok', 'simpanan_wajib', 'simpanan_sukarela', 'simpanan_harian', 'bunga_simpanan', 'bunga_saham', 'transfer'))
                             AND (type != 'deposit')
                             AND (description IS NULL OR (
                                 description NOT LIKE '%saldo awal%'
                                 AND description NOT LIKE '%simpanan%'
                                 AND description NOT LIKE '%harian%'
                                 AND description NOT LIKE '%tabungan%'
                                 AND description NOT LIKE '%wajib%'
                                 AND description NOT LIKE '%pokok%'
                                 AND description NOT LIKE '%sukarela%'
                             ))
                        THEN amount ELSE 0 END
                    ) as total_km,
                    SUM(CASE 
                        WHEN (type IN ('out', 'kas_keluar', 'KK') OR category IN ('beban_operasional', 'biaya', 'atk', 'komunikasi', 'gaji', 'listrik', 'wifi', 'beban'))
                             AND (category IS NULL OR category NOT IN ('simpanan_pokok', 'simpanan_wajib', 'simpanan_sukarela', 'simpanan_harian', 'tarik_buku_putih', 'tarik_buku_biru', 'tarik_simpanan'))
                             AND (type != 'withdrawal')
                             AND (description IS NULL OR (
                                 description NOT LIKE '%penarikan%'
                                 AND description NOT LIKE '%deviden%'
                                 AND description NOT LIKE '%simpanan%'
                                 AND description NOT LIKE '%harian%'
                                 AND description NOT LIKE '%tarik%'
                             ))
                        THEN amount ELSE 0 END
                    ) as total_kk
                ")
                ->first();

            return [
                'total_km' => (float) ($row->total_km ?? 0.0),
                'total_kk' => (float) ($row->total_kk ?? 0.0),
            ];
        });

        $totalKm = $incomeData['total_km'];
        $totalKk = $incomeData['total_kk'];
        $netShu  = max(0.0, round($totalKm - $totalKk, 2));

        return [
            'start_date'                  => $startDate,
            'end_date'                    => $endDate,
            'total_income'                => $totalKm,
            'total_expense'               => $totalKk,
            'net_profit'                  => $netShu,
            'shu_bersih'                  => $netShu,
            'is_manual_benchmark'         => false,
            'dividend_allocation_percent' => self::DEFAULT_PERCENTAGE,
            'total_coop_shares'           => null,
        ];
    }

    /**
     * Hitung saldo saham (SP + SW + SS) anggota yang berhak memperoleh Jasa Saham (0.6%)
     * Aturan Baku:
     * 1. Sanksi Bulanan: Wajib ada setoran Simpanan Wajib (SW) pada siklus bulan berjalan agar berhak atas Jasa Saham 0,6%.
     * 2. Setoran pada bulan berjalan (M) baru berbunga di bulan M+1 (tidak dihitung di bulan M).
     * 3. Penarikan pada bulan berjalan (M) langsung mengurangi dasar pengali jasa di bulan M.
     * Formula: Dasar Jasa = Saldo Akhir - Total Setoran Bulan M = max(0, Saldo Awal - Total Penarikan)
     */
    public function getQualifyingShareBalanceForMonth(Member $member, int $payoutMonth, int $payoutYear): float
    {
        $dates = $this->resolveDates($payoutMonth, $payoutYear);
        $sp = (float) ($member->principal_savings ?? $member->simpanan_pokok ?? 200000.0);
        if ($sp <= 0 && $member->has_buku_biru) {
            $sp = 200000.0;
        }
        $sw = (float) ($member->mandatory_savings ?? $member->simpanan_wajib ?? 0.0);
        $ss = (float) ($member->voluntary_savings ?? $member->simpanan_sukarela ?? 0.0);
        $totalSaham = round($sp + $sw + $ss, 2);

        // Cek apakah anggota membayar SW pada siklus bulan berjalan
        $hasPaidSw = Transaction::where('member_id', $member->id)
            ->where('book_type', 'BUKU_BIRU')
            ->where('status', 'approved')
            ->whereIn('type', ['deposit', 'in', 'kas_masuk', 'KM'])
            ->whereDate('transaction_date', '>=', $dates['start_date'])
            ->whereDate('transaction_date', '<=', $dates['cutoff_date'])
            ->where(function ($q) {
                $q->where('category', 'simpanan_wajib')
                  ->orWhere('description', 'like', '%wajib%');
            })
            ->exists();

        if (!$hasPaidSw) {
            return 0.0;
        }

        // Ambil total setoran simpanan anggota pada siklus bulan berjalan (21 bulan M-1 s.d. 20 bulan M)
        $cycleDeposits = (float) Transaction::where('member_id', $member->id)
            ->where('book_type', 'BUKU_BIRU')
            ->where('status', 'approved')
            ->whereIn('type', ['deposit', 'in', 'kas_masuk', 'KM'])
            ->whereDate('transaction_date', '>=', $dates['start_date'])
            ->whereDate('transaction_date', '<=', $dates['cutoff_date'])
            ->where(function ($q) {
                $q->whereNull('category')
                  ->orWhereNotIn('category', ['bunga_saham', 'bunga_simpanan', 'jurnal_penyesuaian', 'memorial']);
            })
            ->where(function ($q) {
                $q->whereNull('description')
                  ->orWhere(function ($sub) {
                      $sub->where('description', 'not like', '%saldo awal%')
                          ->where('description', 'not like', '%deviden%')
                          ->where('description', 'not like', '%bunga saham%');
                  });
            })
            ->sum('amount');

        return max(0.0, round($totalSaham - $cycleDeposits, 2));
    }

    /**
     * Hitung ringkasan statistik saham koperasi untuk alokasi deviden (di-cache 5 menit)
     */
    public function getCooperativeShareStats(array $dates, float $dividendPool): array
    {
        $cacheKey = "coop_share_stats_{$dates['target_ym']}_" . round($dividendPool, 2);
        return Cache::remember($cacheKey, 300, function () use ($dates, $dividendPool) {
            $members = Member::query()
                ->whereNotNull('member_number')
                ->where('member_number', '!=', '-')
                ->where('member_number', '!=', '')
                ->where(function ($q) {
                    $q->where('principal_savings', '>', 0)
                      ->orWhere('mandatory_savings', '>', 0)
                      ->orWhere('voluntary_savings', '>', 0)
                      ->orWhere('has_buku_biru', true);
                })
                ->select([
                    'id', 'member_number', 'principal_savings', 'mandatory_savings',
                    'voluntary_savings', 'has_buku_biru', 'status', 'created_at'
                ])
                ->get();

            $recentDepositMemberIds = DB::table('transactions')
                ->where('book_type', 'BUKU_BIRU')
                ->whereIn('type', ['deposit', 'in', 'kas_masuk', 'KM'])
                ->where('status', 'approved')
                ->whereDate('transaction_date', '>=', $dates['six_months_start'])
                ->whereDate('transaction_date', '<=', $dates['cutoff_date'])
                ->whereNotNull('member_id')
                ->pluck('member_id')
                ->unique()
                ->flip()
                ->all();

            $cutoffCarbon = Carbon::parse($dates['cutoff_date']);
            $sixMonthsPriorDate = $cutoffCarbon->copy()->subMonths(6);

            $totalBukuBiruMembers = 0;
            $eligibleCount = 0;
            $ineligibleCount = 0;
            $swArrearsCount = 0;
            $totalAllShares = 0.0;
            $totalEligibleShares = 0.0;

            foreach ($members as $member) {
                $sp = (float) ($member->principal_savings ?? $member->simpanan_pokok ?? 0.0);
                if ($sp <= 0 && $member->has_buku_biru) {
                    $sp = 200000.0;
                }
                $sw = (float) ($member->mandatory_savings ?? $member->simpanan_wajib ?? 0.0);
                $ss = (float) ($member->voluntary_savings ?? $member->simpanan_sukarela ?? 0.0);
                $totalSaham = round($sp + $sw + $ss, 2);

                $memberNo = trim((string) ($member->member_number ?? ''));
                if (empty($memberNo) || $memberNo === '-' || str_starts_with($memberNo, 'BP-')) {
                    continue;
                }
                if (!$member->has_buku_biru && $totalSaham <= 0) {
                    continue;
                }

                $totalBukuBiruMembers++;
                $totalAllShares += $totalSaham;

                $memberCreatedAt = $member->created_at ? Carbon::parse($member->created_at) : null;
                $isMemberOlderThan6Mo = $memberCreatedAt ? $memberCreatedAt->lte($sixMonthsPriorDate) : true;
                $hasRecentDeposit = isset($recentDepositMemberIds[$member->id]);
                
                $isSwArrears = ($isMemberOlderThan6Mo && !$hasRecentDeposit && $sw <= 20000.0);
                $isEligible = ($totalSaham > 0 && !$isSwArrears && $member->status !== 'inactive' && $member->status !== 'resigned');

                if ($isSwArrears) {
                    $swArrearsCount++;
                }

                if ($isEligible) {
                    $eligibleCount++;
                    $totalEligibleShares += $totalSaham;
                } else {
                    $ineligibleCount++;
                }
            }

            $totalLembarKoperasi = round($totalEligibleShares / 1000.0, 2);
            $hargaDevidenPerLembar = ($totalLembarKoperasi > 0 && $dividendPool > 0)
                ? ($dividendPool / $totalLembarKoperasi)
                : 0.0;

            return [
                'total_buku_biru_members'  => $totalBukuBiruMembers,
                'eligible_count'           => $eligibleCount,
                'ineligible_count'         => $ineligibleCount,
                'sw_arrears_count'         => $swArrearsCount,
                'total_all_shares'         => round($totalAllShares, 2),
                'total_eligible_shares'    => round($totalEligibleShares, 2),
                'total_lembar_koperasi'    => $totalLembarKoperasi,
                'harga_deviden_per_lembar' => round($hargaDevidenPerLembar, 6),
            ];
        });
    }

    /**
     * Kalkulasi preview pembagian deviden Buku Biru (Siklus Cut-off 21 s/d 20)
     */
    public function preview(int $month, int $year, float $percentage = self::DEFAULT_PERCENTAGE, ?int $memberId = null): array
    {
        $percentage = max(0.0, min(100.0, $percentage));
        $dates = $this->resolveDates($month, $year);
        $profitData = $this->calculateNetProfit($month, $year);
        $shuBersih = $profitData['shu_bersih'];
        $dividendPool = round($shuBersih * ($percentage / 100.0), 2);
        $isDistributed = $this->isAlreadyDistributed($dates['target_ym'], $month, $year);

        if ($memberId !== null) {
            $stats = $this->getCooperativeShareStats($dates, $dividendPool);
            $totalBukuBiruMembers   = $stats['total_buku_biru_members'];
            $eligibleCount          = $stats['eligible_count'];
            $ineligibleCount        = $stats['ineligible_count'];
            $swArrearsCount         = $stats['sw_arrears_count'];
            $totalAllShares         = $stats['total_all_shares'];
            $totalEligibleShares    = $stats['total_eligible_shares'];
            $totalLembarKoperasi    = $stats['total_lembar_koperasi'];
            $hargaDevidenPerLembar  = $stats['harga_deviden_per_lembar'];

            $members = Member::query()->where('id', $memberId)->get();

            $recentDepositMemberIds = DB::table('transactions')
                ->where('member_id', $memberId)
                ->where('book_type', 'BUKU_BIRU')
                ->whereIn('type', ['deposit', 'in', 'kas_masuk', 'KM'])
                ->where('status', 'approved')
                ->whereDate('transaction_date', '>=', $dates['six_months_start'])
                ->whereDate('transaction_date', '<=', $dates['cutoff_date'])
                ->pluck('member_id')
                ->unique()
                ->flip()
                ->all();

            $cycleDepositsByMember = DB::table('transactions')
                ->where('member_id', $memberId)
                ->where('book_type', 'BUKU_BIRU')
                ->where('status', 'approved')
                ->whereIn('type', ['deposit', 'in', 'kas_masuk', 'KM'])
                ->whereDate('transaction_date', '>=', $dates['start_date'])
                ->whereDate('transaction_date', '<=', $dates['cutoff_date'])
                ->where(function ($q) {
                    $q->whereNull('category')
                      ->orWhereNotIn('category', ['bunga_saham', 'bunga_simpanan', 'jurnal_penyesuaian', 'memorial']);
                })
                ->where(function ($q) {
                    $q->whereNull('description')
                      ->orWhere(function ($sub) {
                          $sub->where('description', 'not like', '%saldo awal%')
                              ->where('description', 'not like', '%deviden%')
                              ->where('description', 'not like', '%bunga saham%');
                      });
                })
                ->selectRaw('member_id, SUM(amount) as total_deposits')
                ->groupBy('member_id')
                ->pluck('total_deposits', 'member_id')
                ->all();

            $cyclePaidSwMemberIds = DB::table('transactions')
                ->where('member_id', $memberId)
                ->where('book_type', 'BUKU_BIRU')
                ->where('status', 'approved')
                ->whereIn('type', ['deposit', 'in', 'kas_masuk', 'KM'])
                ->whereDate('transaction_date', '>=', $dates['start_date'])
                ->whereDate('transaction_date', '<=', $dates['cutoff_date'])
                ->where(function ($q) {
                    $q->where('category', 'simpanan_wajib')
                      ->orWhere('description', 'like', '%wajib%');
                })
                ->pluck('member_id')
                ->unique()
                ->flip()
                ->all();
        } else {
            // Ambil data anggota pemilik Buku Biru yang valid
            $members = Member::query()
                ->whereNotNull('member_number')
                ->where('member_number', '!=', '-')
                ->where('member_number', '!=', '')
                ->where(function ($q) {
                    $q->where('principal_savings', '>', 0)
                      ->orWhere('mandatory_savings', '>', 0)
                      ->orWhere('voluntary_savings', '>', 0)
                      ->orWhere('has_buku_biru', true);
                })
                ->orderBy('member_number', 'asc')
                ->get();

            // Cari transaksi simpanan anggota dalam 6 bulan terakhir untuk pengecekan tunggakan SW
            $recentDepositMemberIds = DB::table('transactions')
                ->where('book_type', 'BUKU_BIRU')
                ->whereIn('type', ['deposit', 'in', 'kas_masuk', 'KM'])
                ->where('status', 'approved')
                ->whereDate('transaction_date', '>=', $dates['six_months_start'])
                ->whereDate('transaction_date', '<=', $dates['cutoff_date'])
                ->whereNotNull('member_id')
                ->pluck('member_id')
                ->unique()
                ->flip()
                ->all();

            // Ambil transaksi setoran anggota pada siklus berjalan untuk mengecualikan setoran baru dari dasar jasa
            $cycleDepositsByMember = DB::table('transactions')
                ->where('book_type', 'BUKU_BIRU')
                ->where('status', 'approved')
                ->whereNotNull('member_id')
                ->whereIn('type', ['deposit', 'in', 'kas_masuk', 'KM'])
                ->whereDate('transaction_date', '>=', $dates['start_date'])
                ->whereDate('transaction_date', '<=', $dates['cutoff_date'])
                ->where(function ($q) {
                    $q->whereNull('category')
                      ->orWhereNotIn('category', ['bunga_saham', 'bunga_simpanan', 'jurnal_penyesuaian', 'memorial']);
                })
                ->where(function ($q) {
                    $q->whereNull('description')
                      ->orWhere(function ($sub) {
                          $sub->where('description', 'not like', '%saldo awal%')
                              ->where('description', 'not like', '%deviden%')
                              ->where('description', 'not like', '%bunga saham%');
                      });
                })
                ->selectRaw('member_id, SUM(amount) as total_deposits')
                ->groupBy('member_id')
                ->pluck('total_deposits', 'member_id')
                ->all();

            // Ambil ID anggota yang membayar Simpanan Wajib (SW) pada siklus berjalan
            $cyclePaidSwMemberIds = DB::table('transactions')
                ->where('book_type', 'BUKU_BIRU')
                ->where('status', 'approved')
                ->whereNotNull('member_id')
                ->whereIn('type', ['deposit', 'in', 'kas_masuk', 'KM'])
                ->whereDate('transaction_date', '>=', $dates['start_date'])
                ->whereDate('transaction_date', '<=', $dates['cutoff_date'])
                ->where(function ($q) {
                    $q->where('category', 'simpanan_wajib')
                      ->orWhere('description', 'like', '%wajib%');
                })
                ->pluck('member_id')
                ->unique()
                ->flip()
                ->all();

            $totalBukuBiruMembers = 0;
            $eligibleCount = 0;
            $ineligibleCount = 0;
            $swArrearsCount = 0;
            $totalAllShares = 0.0;
            $totalEligibleShares = 0.0;
        }

        $cutoffCarbon = Carbon::parse($dates['cutoff_date']);
        $sixMonthsPriorDate = $cutoffCarbon->copy()->subMonths(6);

        $memberCalculations = [];

        foreach ($members as $member) {
            $sp = (float) ($member->principal_savings ?? $member->simpanan_pokok ?? 0.0);
            if ($sp <= 0 && $member->has_buku_biru) {
                $sp = 200000.0;
            }
            $sw = (float) ($member->mandatory_savings ?? $member->simpanan_wajib ?? 0.0);
            $ss = (float) ($member->voluntary_savings ?? $member->simpanan_sukarela ?? 0.0);
            $totalSaham = round($sp + $sw + $ss, 2);

            // Filter Buku Biru: pastikan nomor anggota valid dan bukan nasabah Buku Putih murni
            $memberNo = trim((string) ($member->member_number ?? ''));
            if (empty($memberNo) || $memberNo === '-' || str_starts_with($memberNo, 'BP-')) {
                continue;
            }
            if (!$member->has_buku_biru && $totalSaham <= 0) {
                continue;
            }

            if ($memberId === null) {
                $totalBukuBiruMembers++;
                $totalAllShares += $totalSaham;
            }

            // Evaluasi status keaktifan dan tunggakan Simpanan Wajib (SW) >= 6 bulan
            $memberCreatedAt = $member->created_at ? Carbon::parse($member->created_at) : null;
            $isMemberOlderThan6Mo = $memberCreatedAt ? $memberCreatedAt->lte($sixMonthsPriorDate) : true;
            $hasRecentDeposit = isset($recentDepositMemberIds[$member->id]);
            
            // Tunggakan jika terdaftar >= 6 bulan, tidak ada setoran dalam 6 bulan terakhir, dan SW <= 20000
            $isSwArrears = ($isMemberOlderThan6Mo && !$hasRecentDeposit && $sw <= 20000.0);
            $isEligible = ($totalSaham > 0 && !$isSwArrears && $member->status !== 'inactive' && $member->status !== 'resigned');

            if ($isSwArrears) {
                if ($memberId === null) $swArrearsCount++;
                $ineligibilityReason = 'Gugur Hak SHU - Tunggakan SW ≥ 6 Bulan';
            } elseif ($totalSaham <= 0) {
                $ineligibilityReason = 'Saldo Saham Rp 0';
            } elseif ($member->status === 'inactive' || $member->status === 'resigned') {
                $ineligibilityReason = 'Anggota Non-Aktif / Resign';
            } else {
                $ineligibilityReason = null;
            }

            if ($isEligible) {
                if ($memberId === null) {
                    $eligibleCount++;
                    $totalEligibleShares += $totalSaham;
                }
            } else {
                if ($memberId === null) $ineligibleCount++;
            }

            $lembarSaham = round($totalSaham / 1000.0, 2);
            $hasPaidSw = isset($cyclePaidSwMemberIds[$member->id]);
            $cycleDeposits = (float) ($cycleDepositsByMember[$member->id] ?? 0.0);
            // Dasar Jasa = Saldo Akhir - Setoran Siklus Bulan M (Setoran baru berbunga di M+1)
            $qualifyingSaham = max(0.0, round($totalSaham - $cycleDeposits, 2));
            // Sanksi Bulanan SW: Jasa Saham 0,6% hanya diberikan jika menyetor SW pada bulan berjalan
            $jasaSahamBulanan = $hasPaidSw ? round($qualifyingSaham * 0.006, 2) : 0.0;

            $memberCalculations[] = [
                'member_id'              => $member->id,
                'member_number'          => $member->member_number,
                'name'                   => $member->name,
                'nik'                    => $member->nik,
                'status'                 => $member->status,
                'principal_savings'      => $sp,
                'mandatory_savings'      => $sw,
                'voluntary_savings'      => $ss,
                'total_saham'            => $totalSaham,
                'lembar_saham'           => $lembarSaham,
                'qualifying_saham'       => $qualifyingSaham,
                'has_paid_sw'            => $hasPaidSw,
                'jasa_saham'             => $jasaSahamBulanan,
                'is_eligible'            => $isEligible,
                'is_sw_arrears'          => $isSwArrears,
                'ineligibility_reason'   => $ineligibilityReason,
                'share_percentage'       => 0.0,
                'deviden_amount'         => 0.0,
            ];
        }

        if ($memberId === null) {
            // Hitung nominal deviden per lembar saham koperasi
            $totalLembarKoperasi = round($totalEligibleShares / 1000.0, 2);
            $hargaDevidenPerLembar = ($totalLembarKoperasi > 0 && $dividendPool > 0)
                ? ($dividendPool / $totalLembarKoperasi)
                : 0.0;
        }

        // Hitung nominal deviden untuk masing-masing anggota berhak
        $totalCalculatedDividend = 0.0;
        foreach ($memberCalculations as &$calc) {
            if ($calc['is_eligible'] && $totalEligibleShares > 0 && $dividendPool > 0) {
                $sharePct = ($calc['total_saham'] / $totalEligibleShares);
                // Rumus Baku: Lembar Saham Anggota x Harga Deviden Per Lembar
                $devAmount = round(($calc['total_saham'] / 1000.0) * $hargaDevidenPerLembar, 2);
                $calc['share_percentage'] = round($sharePct * 100, 4);
                $calc['deviden_amount']   = $devAmount;
                $totalCalculatedDividend += $devAmount;
            } else {
                $calc['share_percentage'] = 0.0;
                $calc['deviden_amount']   = 0.0;
            }
        }
        unset($calc);

        $summary = [
            'month'                     => $month,
            'year'                      => $year,
            'period_label'              => $dates['period_label'],
            'start_date'                => $dates['start_date'],
            'execution_date'            => $dates['execution_date'],
            'cutoff_date'               => $dates['cutoff_date'],
            'six_months_start'          => $dates['six_months_start'],
            'percentage'                => $percentage,
            'total_income'              => $profitData['total_income'],
            'total_expense'             => $profitData['total_expense'],
            'shu_bersih'                => $shuBersih,
            'dividend_pool'             => $dividendPool,
            'harga_deviden_per_lembar'  => round($hargaDevidenPerLembar, 6),
            'total_distributed_dividend'=> round($totalCalculatedDividend, 2),
            'total_buku_biru_members'   => $totalBukuBiruMembers,
            'eligible_members_count'    => $eligibleCount,
            'ineligible_members_count'  => $ineligibleCount,
            'sw_arrears_count'          => $swArrearsCount,
            'total_all_shares'          => round($totalAllShares, 2),
            'total_eligible_shares'     => round($totalEligibleShares, 2),
            'total_lembar_koperasi'     => $totalLembarKoperasi,
            'default_voucher_no'        => $dates['default_voucher'],
            'is_already_distributed'    => $isDistributed,
        ];

        return [
            'summary' => $summary,
            'members' => $memberCalculations,
            'details' => $memberCalculations,
        ];
    }

    /**
     * Eksekusi pendistribusian deviden Buku Biru & pencatatan Jurnal Bukti Memorial
     * Tanpa mengubah/menutup status periode akuntansi.
     */
    public function distribute(
        int $month,
        int $year,
        float $percentage,
        string $voucherNo,
        ?int $userId = null,
        ?string $notes = null
    ): array {
        $voucherNo = trim($voucherNo);
        if (empty($voucherNo)) {
            throw new \InvalidArgumentException('Nomor bukti transaksi (voucher_no) wajib diisi.');
        }

        $dates = $this->resolveDates($month, $year);
        $executionDate = $dates['execution_date'];

        // 1. Validasi Periode Terkunci
        if (\App\Services\PeriodLockService::isLocked($executionDate)) {
            throw new \Exception("Aksi ditolak: Periode akuntansi tanggal {$executionDate} sudah ditutup / terkunci.");
        }

        // 2. Validasi Duplikasi Nomor Bukti
        $isVoucherUsedInTransactions = Transaction::where('receipt_number', $voucherNo)
            ->orWhere('transaction_number', $voucherNo)
            ->exists();

        $isVoucherUsedInJournals = JournalEntry::where('voucher_number', $voucherNo)->exists();

        if ($isVoucherUsedInTransactions || $isVoucherUsedInJournals) {
            throw new \InvalidArgumentException("Maaf, Transaksi dengan nomor bukti '{$voucherNo}' sudah ada, coba lagi dengan nomor berbeda.");
        }

        // 3. Cek apakah periode ini sudah pernah didistribusikan
        if ($this->isAlreadyDistributed($dates['target_ym'], $month, $year)) {
            throw new \Exception("Deviden Buku Biru untuk periode {$dates['period_label']} sudah pernah didistribusikan sebelumnya.");
        }

        $previewData = $this->preview($month, $year, $percentage);
        $summary = $previewData['summary'];
        $membersData = $previewData['members'];
        $totalDividend = (float) $summary['total_distributed_dividend'];

        if ($totalDividend <= 0) {
            return [
                'success'           => true,
                'message'           => "Tidak ada deviden yang perlu didistribusikan untuk periode {$dates['period_label']} (Total Deviden Rp 0).",
                'summary'           => $summary,
                'processed_count'   => 0,
                'total_distributed' => 0.0,
            ];
        }

        // Ambil Akun COA untuk Jurnal: Debet Beban Jasa Simpanan (7145), Kredit Simpanan Sukarela (2020)
        $coaDebit = ChartOfAccount::where('account_code', '7145')->first()
            ?? ChartOfAccount::where('account_name', 'like', '%jasa simpanan%')->first()
            ?? ChartOfAccount::firstOrCreate(
                ['account_code' => '7145'],
                ['account_name' => 'Jasa Simpanan Anggota', 'account_type' => 'EXPENSE', 'normal_balance' => 'DEBIT', 'is_active' => true]
            );

        $coaCredit = ChartOfAccount::where('account_code', '2020')->first()
            ?? ChartOfAccount::where('account_name', 'like', '%simpanan sukarela%')->first()
            ?? ChartOfAccount::firstOrCreate(
                ['account_code' => '2020'],
                ['account_name' => 'Simpanan Sukarela', 'account_type' => 'LIABILITY', 'normal_balance' => 'CREDIT', 'is_active' => true]
            );

        return DB::transaction(function () use (
            $dates,
            $summary,
            $membersData,
            $totalDividend,
            $voucherNo,
            $userId,
            $executionDate,
            $percentage,
            $notes,
            $coaDebit,
            $coaCredit
        ) {
            $targetYm = $dates['target_ym'];
            $periodLabel = $dates['period_label'];
            $processedCount = 0;
            $actualDistributed = 0.0;

            // Iterasi anggota yang berhak menerima deviden
            foreach ($membersData as $item) {
                $devAmount = (float) ($item['deviden_amount'] ?? 0.0);
                if (!$item['is_eligible'] || $devAmount <= 0) {
                    continue;
                }

                $member = Member::find($item['member_id']);
                if (!$member) {
                    continue;
                }

                $beginSs = (float) ($member->voluntary_savings ?? $member->simpanan_sukarela ?? 0.0);
                $endSs   = $beginSs + $devAmount;

                $trxNumber = 'TRX-DIV-' . $targetYm . '-' . str_pad((string) $member->id, 4, '0', STR_PAD_LEFT);
                $receiptNumber = $voucherNo;

                // 1. Simpan Transaksi Mutasi ke Rekening Simpanan Sukarela Anggota
                Transaction::create([
                    'transaction_number' => $trxNumber,
                    'receipt_number'     => $receiptNumber,
                    'member_id'          => $member->id,
                    'account_id'         => null,
                    'book_type'          => 'BUKU_BIRU',
                    'operator_id'        => $userId,
                    'approved_by'        => $userId,
                    'type'               => 'deposit',
                    'category'           => 'bunga_saham',
                    'amount'             => $devAmount,
                    'beginning_balance'  => $beginSs,
                    'ending_balance'     => $endSs,
                    'payment_method'     => 'memorial',
                    'transaction_date'   => $executionDate,
                    'description'        => "Deviden / Jasa Saham Simpanan Sukarela Periode {$periodLabel} ({$percentage}%)",
                    'status'             => 'approved',
                    'approved_at'        => now(),
                ]);

                // 2. Tambah Saldo Simpanan Sukarela Anggota
                $member->increment('voluntary_savings', $devAmount);

                // 3. Simpan Riwayat Distribusi SHU di tabel shu_distributions
                ShuDistribution::create([
                    'period_id'        => null,
                    'period_name'      => $periodLabel,
                    'member_id'        => $member->id,
                    'member_name'      => $member->name,
                    'member_number'    => $member->member_number,
                    'simpanan_pokok'   => (float) ($member->principal_savings ?? 0.0),
                    'simpanan_wajib'   => (float) ($member->mandatory_savings ?? 0.0),
                    'total_saham'      => (float) ($item['total_saham'] ?? 0.0),
                    'jasa_saham'       => $devAmount,
                    'deviden'          => $devAmount,
                    'gross_shu'        => $devAmount,
                    'potongan_duka'    => 0.0,
                    'potongan_wajib'   => 0.0,
                    'total_potongan'   => 0.0,
                    'net_shu'          => $devAmount,
                    'status'           => 'distributed',
                    'distributed_at'   => now(),
                    'notes'            => $notes ?: "Distribusi Deviden Buku Biru {$periodLabel} ({$percentage}%)",
                ]);

                // 3b. Simpan juga di tabel member_shu_distributions jika tabel ada
                if (class_exists(\App\Models\MemberShuDistribution::class)) {
                    \App\Models\MemberShuDistribution::create([
                        'period_id'        => null,
                        'member_id'        => $member->id,
                        'jasa_saham'       => $devAmount,
                        'deviden'          => $devAmount,
                        'gross_shu'        => $devAmount,
                        'potongan_duka'    => 0.0,
                        'potongan_wajib'   => 0.0,
                        'total_potongan'   => 0.0,
                        'net_shu'          => $devAmount,
                        'status'           => 'distributed',
                        'distributed_at'   => now(),
                    ]);
                }

                $processedCount++;
                $actualDistributed += $devAmount;
            }

            // 4. Catat Jurnal Double-Entry Bukti Memorial
            if ($actualDistributed > 0) {
                $journal = JournalEntry::create([
                    'transaction_id' => null,
                    'entry_date'     => $executionDate,
                    'voucher_number' => $voucherNo,
                    'description'    => "Pembagian Deviden / SHU Buku Biru Periode {$periodLabel} ({$percentage}%)",
                    'created_by'     => $userId,
                ]);

                // Sisi Debet: Beban Jasa Simpanan / Deviden (COA 7145)
                JournalDetail::create([
                    'journal_entry_id' => $journal->id,
                    'account_id'       => $coaDebit->id,
                    'debit'            => $actualDistributed,
                    'credit'           => 0.0,
                    'description'      => "Beban Deviden / Jasa Simpanan Buku Biru {$periodLabel}",
                ]);

                // Sisi Kredit: Simpanan Sukarela Anggota (COA 2020)
                JournalDetail::create([
                    'journal_entry_id' => $journal->id,
                    'account_id'       => $coaCredit->id,
                    'debit'            => 0.0,
                    'credit'           => $actualDistributed,
                    'description'      => "Penyaluran Deviden ke Simpanan Sukarela Anggota {$periodLabel}",
                ]);
            }

            // 5. Invalidate Caches
            Cache::forget('dashboard_summary_data');
            Cache::forget('manager_dashboard_summary_data');
            Cache::forget('koperasi_financial_summary');

            Log::info("[DividendService] Distribusi deviden Buku Biru periode {$periodLabel} berhasil dieksekusi ({$processedCount} anggota, total Rp {$actualDistributed}, no bukti: {$voucherNo}).");

            return [
                'success'           => true,
                'message'           => "Pembagian Deviden Buku Biru periode {$periodLabel} ({$percentage}%) berhasil didistribusikan ke {$processedCount} anggota.",
                'summary'           => array_merge($summary, [
                    'is_already_distributed'     => true,
                    'total_distributed_dividend' => $actualDistributed,
                    'voucher_number'             => $voucherNo,
                ]),
                'voucher_number'    => $voucherNo,
                'processed_count'   => $processedCount,
                'total_distributed' => $actualDistributed,
                'distribution'      => [
                    'processed_count'   => $processedCount,
                    'total_distributed' => $actualDistributed,
                    'voucher_number'    => $voucherNo,
                ],
            ];
        });
    }

    /**
     * Generate Cetak PDF Laporan Pembagian Deviden Buku Biru sesuai standar resmi koperasi
     */
    public function exportPdf(int $month, int $year, float $percentage = self::DEFAULT_PERCENTAGE)
    {
        ini_set('memory_limit', '1024M');
        set_time_limit(300);

        $preview = $this->preview($month, $year, $percentage);
        $summary = $preview['summary'];
        $members = $preview['members'];

        $viewName = view()->exists('reports.dividend_report_pdf')
            ? 'reports.dividend_report_pdf'
            : (view()->exists('dividend_report_pdf') ? 'dividend_report_pdf' : 'exports.dividend_report_pdf');

        $pdf = Pdf::loadView($viewName, [
            'summary' => $summary,
            'members' => $members,
            'details' => $members,
        ])
        ->setPaper('a4', 'portrait')
        ->setOption('isHtml5ParserEnabled', true)
        ->setOption('isRemoteEnabled', false);

        $fileName = "Laporan_Pembagian_Deviden_Buku_Biru_{$summary['month']}_{$summary['year']}.pdf";

        return $pdf->download($fileName);
    }

    /**
     * Lembar Buku Saham & Rekapitulasi 1 Tahun Buku Anggota (Individual Statement)
     * Persis Format Audit Fisik CUM Pelita (12 Bulan Juni s/d Mei, Siklus 21 s/d 20)
     */
    public function getMemberDividendStatement(int $memberId, ?int $fiscalYear = null, ?int $month = null, ?int $year = null): array
    {
        $member = Member::findOrFail($memberId);

        // Tentukan periode 12 bulan buku (Juni tahun sebelumnya s/d Mei tahun berjalan)
        $targetYear = $fiscalYear ?? $year ?? now()->year;
        $startYear  = $fiscalYear ? ($fiscalYear - 1) : (($month && $month >= 6) ? $targetYear : ($targetYear - 1));
        $endYear    = $startYear + 1;

        $fiscalMonths = [];
        $shortMonthNames = [
            6 => 'Jun', 7 => 'Jul', 8 => 'AUG', 9 => 'Sep', 10 => 'OKT', 11 => 'NOV', 12 => 'DES',
            1 => 'Jan', 2 => 'FEB', 3 => 'MAR', 4 => 'APR', 5 => 'MEI'
        ];

        for ($m = 6; $m <= 12; $m++) {
            $fiscalMonths[] = [
                'month'       => $m,
                'year'        => $startYear,
                'month_code'  => $shortMonthNames[$m] . '-' . substr((string)$startYear, 2, 2),
                'short_code'  => $shortMonthNames[$m],
                'month_name'  => Carbon::createFromDate($startYear, $m, 1)->locale('id')->isoFormat('MMMM'),
                'month_label' => Carbon::createFromDate($startYear, $m, 1)->locale('id')->isoFormat('MMMM YYYY'),
            ];
        }
        for ($m = 1; $m <= 5; $m++) {
            $fiscalMonths[] = [
                'month'       => $m,
                'year'        => $endYear,
                'month_code'  => $shortMonthNames[$m] . '-' . substr((string)$endYear, 2, 2),
                'short_code'  => $shortMonthNames[$m],
                'month_name'  => Carbon::createFromDate($endYear, $m, 1)->locale('id')->isoFormat('MMMM'),
                'month_label' => Carbon::createFromDate($endYear, $m, 1)->locale('id')->isoFormat('MMMM YYYY'),
            ];
        }

        $fiscalPeriodLabel = "Juni {$startYear} - Mei {$endYear}";

        // Nilai SP Baku / Master Anggota (Konstan sejak pendaftaran)
        $initialSp = (float) ($member->principal_savings ?? $member->simpanan_pokok ?? 200000.0);
        if ($initialSp <= 0) {
            $initialSp = 200000.0;
        }

        // Saldo simpanan anggota per data master (murni setoran kasir tanpa injeksi deviden memorial)
        $spCurrent = max($initialSp, (float) ($member->principal_savings ?? 0.0));
        $swCurrent = (float) ($member->mandatory_savings ?? 0.0);
        
        $ssMemorialDividends = (float) Transaction::where('member_id', $member->id)
            ->where('book_type', 'BUKU_BIRU')
            ->where('status', 'approved')
            ->where(function ($q) {
                $q->where('category', 'bunga_saham')
                  ->orWhere('payment_method', 'memorial')
                  ->orWhere('receipt_number', 'like', 'BM-DIV%')
                  ->orWhere('receipt_number', 'like', 'DIV-%')
                  ->orWhere('transaction_number', 'like', 'TRX-DIV%')
                  ->orWhere('description', 'like', '%deviden%')
                  ->orWhere('description', 'like', '%bunga saham%');
            })
            ->sum('amount');
        
        $ssCurrent = max(0.0, round((float) ($member->voluntary_savings ?? 0.0) - $ssMemorialDividends, 2));
        $totalSahamCurrent = round($spCurrent + $swCurrent + $ssCurrent, 2);

        // Cari transaksi mutasi anggota selama 1 tahun buku (MURNI transaksi kasir tunai KM / KK)
        // Mulai dari 21 April tahun awal agar cut-off M-2 untuk bulan Juni (M=6 -> M-2=April) tercakup
        $firstCycleStart = Carbon::createFromDate($startYear, 4, 21)->startOfDay()->toDateString();
        $lastCycleEnd    = Carbon::createFromDate($endYear, 5, 20)->endOfDay()->toDateString();

        $allMemberTrxs = Transaction::where('member_id', $member->id)
            ->where('book_type', 'BUKU_BIRU')
            ->whereDate('transaction_date', '>=', $firstCycleStart)
            ->whereDate('transaction_date', '<=', $lastCycleEnd)
            ->where('status', 'approved')
            ->where(function ($q) {
                $q->whereNull('category')
                  ->orWhereNotIn('category', ['bunga_saham', 'bunga_simpanan', 'jurnal_penyesuaian', 'memorial']);
            })
            ->where(function ($q) {
                $q->whereNull('payment_method')
                  ->orWhere('payment_method', '!=', 'memorial');
            })
            ->where(function ($q) {
                $q->whereNull('receipt_number')
                  ->orWhere(function ($sub) {
                      $sub->where('receipt_number', 'not like', 'BM-DIV%')
                          ->where('receipt_number', 'not like', 'DIV-%');
                  });
            })
            ->where(function ($q) {
                $q->whereNull('transaction_number')
                  ->orWhere('transaction_number', 'not like', 'TRX-DIV%');
            })
            ->where(function ($q) {
                $q->whereNull('description')
                  ->orWhere(function ($sub) {
                      $sub->where('description', 'not like', '%deviden%')
                          ->where('description', 'not like', '%bunga saham%')
                          ->where('description', 'not like', '%jurnal memorial%');
                  });
            })
            ->select([
                'id',
                'member_id',
                'transaction_number',
                'receipt_number',
                'type',
                'amount',
                'transaction_date',
                'category',
                'payment_method',
                'description',
                'status',
            ])
            ->orderBy('transaction_date', 'asc')
            ->orderBy('id', 'asc')
            ->get();

        // Hitung Saldo Awal per 20 Mei (sebelum Juni)
        // Hitung net perubahan transaksi dari 21 Mei tahun awal s.d. 20 Mei tahun akhir
        $fiscalYearTrxStart = Carbon::createFromDate($startYear, 5, 21)->startOfDay()->toDateString();
        $netSwChange = 0.0; $netSsChange = 0.0; $netSpChange = 0.0;
        foreach ($allMemberTrxs as $t) {
            $tDate = substr((string)$t->transaction_date, 0, 10);
            if ($tDate < $fiscalYearTrxStart) {
                continue;
            }

            $amt = (float) $t->amount;
            $desc = strtolower($t->description ?? '');
            $cat = $t->category ?? '';
            $isDep = in_array($t->type, ['deposit', 'in', 'kas_masuk', 'KM']);

            if (str_contains($desc, 'wajib') || $cat === 'simpanan_wajib') {
                $netSwChange += ($isDep ? $amt : -$amt);
            } elseif (str_contains($desc, 'pokok') || $cat === 'simpanan_pokok') {
                $netSpChange += ($isDep ? $amt : -$amt);
            } elseif (str_contains($desc, 'saldo awal')) {
                // Abaikan transaksi saldo awal import agar tidak mengurangi saldo awal berjalan dua kali
                continue;
            } else {
                $netSsChange += ($isDep ? $amt : -$amt);
            }
        }

        $swRunning = max(0.0, round($swCurrent - $netSwChange, 2));
        $ssRunning = max(0.0, round($ssCurrent - $netSsChange, 2));
        $spRunning = max($initialSp, round($spCurrent - $netSpChange, 2)); // SP selalu konsisten terisi penuh (tidak pernah 0)
        $sahamRunning = round($swRunning + $ssRunning + $spRunning, 2);

        $saldoAwalRow = [
            'is_saldo_awal'    => true,
            'month'            => null,
            'year'             => $startYear,
            'month_name'       => 'Saldo Awal',
            'month_label'      => 'Saldo Awal (Per 20 Mei)',
            'transaction_date' => '',
            'voucher_no'       => '',
            'setoran_sw'       => 0.0,
            'setoran_ss'       => 0.0,
            'setoran_sp'       => 0.0,
            'total_setoran'    => 0.0,
            'penarikan_sw'     => 0.0,
            'penarikan_ss'     => 0.0,
            'penarikan_sp'     => 0.0,
            'total_penarikan'  => 0.0,
            'saldo_sw'         => $swRunning,
            'saldo_ss'         => $ssRunning,
            'saldo_sp'         => $spRunning,
            'total_saham'      => $sahamRunning,
            'jasa_saham'       => 0.0,
            'deviden'          => 0.0,
        ];

        $monthlyRecords = [];
        $coopBenchmarks = [];
        $accumJasaSaham = 0.0;
        $accumDeviden   = 0.0;

        $savedBenchmarks = MonthlyCooperativeBenchmark::where('fiscal_year', $targetYear)
            ->get()
            ->keyBy('month');

        // Cek Tunggakan SW >= 6 Bulan untuk evaluasi kelayakan Deviden / SHU
        $sixMonthsPriorFiscalEnd = Carbon::createFromDate($endYear, 5, 20)->subMonths(6);
        $memberCreatedAt = $member->created_at ? Carbon::parse($member->created_at) : null;
        $isMemberOlderThan6Mo = $memberCreatedAt ? $memberCreatedAt->lte($sixMonthsPriorFiscalEnd) : true;

        $hasPaidSwInFiscalYear = $allMemberTrxs->contains(function ($t) {
            $cat = $t->category ?? '';
            $desc = strtolower($t->description ?? '');
            $isSw = str_contains($desc, 'wajib') || $cat === 'simpanan_wajib';
            $isDep = in_array($t->type, ['deposit', 'in', 'kas_masuk', 'KM']);
            return $isSw && $isDep;
        });

        // Tunggakan jika terdaftar >= 6 bulan, tidak ada setoran SW pada tahun buku, dan saldo SW <= 20000
        $isSwArrears6Months = ($isMemberOlderThan6Mo && !$hasPaidSwInFiscalYear && $swCurrent <= 20000.0);

        foreach ($fiscalMonths as $fm) {
            $m = $fm['month'];
            $y = $fm['year'];
            
            // Siklus 21 bulan sebelumnya s/d 20 bulan berjalan
            $prevM = Carbon::createFromDate($y, $m, 1)->subMonth();
            $mStart = Carbon::createFromDate($prevM->year, $prevM->month, 21)->startOfDay()->toDateString();
            $mEnd   = Carbon::createFromDate($y, $m, 20)->endOfDay()->toDateString();
            $today  = now()->toDateString();
            $isFutureMonth = ($mStart > $today);

            $saved = $savedBenchmarks->get($m);

            // 1. Patokan Koperasi Siklus 21-20
            // Prioritas 1: input manual manajer di tabel MonthlyCooperativeBenchmark
            // Prioritas 2: query laba bersih riil dari transaksi kas (calculateNetProfit)
            // Jika siklus belum berjalan (future month) atau keduanya 0 → $shuBulan = 0, rumus deviden = 0
            if ($isFutureMonth) {
                $shuBulan = 0.0;
            } else {
                if ($saved && $saved->net_income !== null) {
                    $shuBulan = (float) $saved->net_income;
                } else {
                    $profit   = $this->calculateNetProfit($m, $y);
                    $shuBulan = (float) $profit['shu_bersih'];
                }
            }

            $divPercent = $saved ? (float) $saved->dividend_allocation_percent : self::DEFAULT_PERCENTAGE;

            // Total Saham Koperasi Bulanan
            if ($saved && $saved->total_coop_shares !== null) {
                $sahamKoperasiBulanan = (float) $saved->total_coop_shares;
            } else {
                $sahamKoperasiBulanan = $this->getHistoricalCoopSharesAtDate($mEnd, $m);
            }

            $lembarKoperasi = round($sahamKoperasiBulanan / 1000.0, 2);
            $danaDev25 = round($shuBulan * ($divPercent / 100.0), 2);
            $hargaDevPerLembar = ($lembarKoperasi > 0 && $danaDev25 > 0) ? ($danaDev25 / $lembarKoperasi) : 0.0;

            $coopBenchmarks[] = [
                'month'                    => $m,
                'year'                     => $y,
                'month_name'               => $fm['short_code'],
                'month_label'              => $fm['month_label'],
                // Pemetaan Nama Baku Laporan Koperasi
                'total_saham'              => $sahamKoperasiBulanan,
                'shu_setelah_biaya'        => $shuBulan,
                'shu_deviden_pool'         => $danaDev25,
                'lembar_saham'             => $lembarKoperasi,
                'harga_saham_deviden'      => round($hargaDevPerLembar, 6),
                // Alias Kompatibilitas Legacy & Frontend
                'total_saham_koperasi'     => $sahamKoperasiBulanan,
                'total_coop_shares'        => $sahamKoperasiBulanan,
                'shu_bersih_koperasi'      => $shuBulan,
                'net_income'               => $shuBulan,
                'jumlah_lembar_koperasi'   => $lembarKoperasi,
                'dana_deviden_25'          => $danaDev25,
                'dana_deviden'             => $danaDev25,
                'harga_deviden_per_lembar' => round($hargaDevPerLembar, 6),
                'harga_saham_display'      => (int) round($hargaDevPerLembar),
            ];

            // 2. Transaksi Anggota dalam siklus tgl 21-20
            // Jika bulan belum berjalan (future month), jangan proses transaksi
            $cycleTrxs = $isFutureMonth ? collect() : $allMemberTrxs->filter(function ($trx) use ($mStart, $mEnd) {
                $tDate = substr((string)$trx->transaction_date, 0, 10);
                return $tDate >= $mStart && $tDate <= $mEnd;
            });

            // Catat Saldo Awal Bulan M (sebelum mutasi transaksi siklus bulan M)
            $sahamAwalBulan = $sahamRunning;

            $validTrxs = $cycleTrxs->filter(function ($t) {
                $desc = strtolower($t->description ?? '');
                return !str_contains($desc, 'saldo awal');
            })->values();

            if ($validTrxs->isNotEmpty()) {
                // Hitung total penarikan dan setoran SW siklus ini untuk kalkulasi Jasa Saham
                $accSwInCycle = 0.0;
                $totalPenarikanCycle = 0.0;
                foreach ($validTrxs as $vt) {
                    $amt  = (float) $vt->amount;
                    $desc = strtolower($vt->description ?? '');
                    $cat  = $vt->category ?? '';
                    $isDep = in_array($vt->type, ['deposit', 'in', 'kas_masuk', 'KM']);

                    if (str_contains($desc, 'wajib') || $cat === 'simpanan_wajib') {
                        if ($isDep) { $accSwInCycle += $amt; }
                        else        { $totalPenarikanCycle += $amt; }
                    } elseif (str_contains($desc, 'pokok') || $cat === 'simpanan_pokok') {
                        if (!$isDep) { $totalPenarikanCycle += $amt; }
                    } else {
                        if (!$isDep) { $totalPenarikanCycle += $amt; }
                    }
                }

                $hasPaidSw = ($accSwInCycle > 0);
                $dasarJasa = max(0.0, round($sahamAwalBulan - $totalPenarikanCycle, 2));
                $jasa = $hasPaidSw ? round($dasarJasa * 0.006, 2) : 0.0;
                $accumJasaSaham += $jasa;

                foreach ($validTrxs as $idx => $t) {
                    $amt  = (float) $t->amount;
                    $desc = strtolower($t->description ?? '');
                    $cat  = $t->category ?? '';
                    $isDep = in_array($t->type, ['deposit', 'in', 'kas_masuk', 'KM']);

                    $swIn = 0.0; $ssIn = 0.0; $spIn = 0.0;
                    $swOut = 0.0; $ssOut = 0.0; $spOut = 0.0;

                    if (str_contains($desc, 'wajib') || $cat === 'simpanan_wajib') {
                        if ($isDep) { $swIn = $amt; $swRunning += $amt; }
                        else        { $swOut = $amt; $swRunning -= $amt; }
                    } elseif (str_contains($desc, 'pokok') || $cat === 'simpanan_pokok') {
                        if ($isDep) { $spIn = $amt; $spRunning += $amt; }
                        else        { $spOut = $amt; $spRunning -= $amt; }
                    } else {
                        if ($isDep) { $ssIn = $amt; $ssRunning += $amt; }
                        else        { $ssOut = $amt; $ssRunning -= $amt; }
                    }

                    $spRunning    = max($initialSp, $spRunning);
                    $sahamRunning = round($swRunning + $ssRunning + $spRunning, 2);

                    $isLastTrx = ($idx === $validTrxs->count() - 1);

                    // Alokasi Deviden dihitung pada saldo akhir transaksi terakhir dalam siklus
                    if ($isLastTrx) {
                        $dev = (!$isSwArrears6Months) ? round(($sahamRunning / 1000.0) * $hargaDevPerLembar, 2) : 0.0;
                        $accumDeviden += $dev;
                    } else {
                        $dev = 0.0;
                    }

                    $tDateFormatted = Carbon::parse($t->transaction_date)->format('d-M-y');
                    $tVoucher = $t->receipt_number ?: $t->transaction_number ?: '-';

                    $monthlyRecords[] = [
                        'is_saldo_awal'    => false,
                        'month'            => $m,
                        'year'             => $y,
                        'month_name'       => $fm['short_code'],
                        'month_code'       => $fm['month_code'] ?? ($fm['short_code'] . '-' . substr((string)$y, 2, 2)),
                        'month_label'      => $fm['month_label'],
                        'transaction_date' => $tDateFormatted,
                        'voucher_no'       => $tVoucher,
                        'setoran_sw'       => round($swIn, 2),
                        'setoran_ss'       => round($ssIn, 2),
                        'setoran_sp'       => round($spIn, 2),
                        'total_setoran'    => round($swIn + $ssIn + $spIn, 2),
                        'penarikan_sw'     => round($swOut, 2),
                        'penarikan_ss'     => round($ssOut, 2),
                        'penarikan_sp'     => round($spOut, 2),
                        'total_penarikan'  => round($swOut + $ssOut + $spOut, 2),
                        'saldo_sw'         => $swRunning,
                        'saldo_ss'         => $ssRunning,
                        'saldo_sp'         => $spRunning,
                        'total_saham'      => $sahamRunning,
                        'qualifying_saham' => $isLastTrx ? $dasarJasa : 0.0,
                        'has_paid_sw'      => $hasPaidSw,
                        'jasa_saham'       => $isLastTrx ? $jasa : 0.0,
                        'deviden'          => $dev,
                    ];
                }
            } else {
                // Tidak ada transaksi di bulan ini atau bulan belum berjalan: Baris carry-over
                $spRunning = max($initialSp, $spRunning);
                $sahamRunning = round($swRunning + $ssRunning + $spRunning, 2);

                $dasarJasa = $sahamRunning;
                $hasPaidSw = false;
                // Sanksi Bulanan / Future: Tidak ada setoran SW di bulan ini -> Jasa = 0
                $jasa = 0.0;
                $accumJasaSaham += $jasa;

                // Sanksi Tahunan: Hak deviden gugur jika menunggak SW >= 6 bulan (atau bulan future = 0)
                $dev = (!$isSwArrears6Months && !$isFutureMonth) ? round(($sahamRunning / 1000.0) * $hargaDevPerLembar, 2) : 0.0;
                $accumDeviden += $dev;

                $monthlyRecords[] = [
                    'is_saldo_awal'    => false,
                    'month'            => $m,
                    'year'             => $y,
                    'month_name'       => $fm['short_code'],
                    'month_code'       => $fm['month_code'] ?? ($fm['short_code'] . '-' . substr((string)$y, 2, 2)),
                    'month_label'      => $fm['month_label'],
                    'transaction_date' => null,
                    'voucher_no'       => '',
                    'setoran_sw'       => 0.0,
                    'setoran_ss'       => 0.0,
                    'setoran_sp'       => 0.0,
                    'total_setoran'    => 0.0,
                    'penarikan_sw'     => 0.0,
                    'penarikan_ss'     => 0.0,
                    'penarikan_sp'     => 0.0,
                    'total_penarikan'  => 0.0,
                    'saldo_sw'         => $swRunning,
                    'saldo_ss'         => $ssRunning,
                    'saldo_sp'         => $spRunning,
                    'total_saham'      => $sahamRunning,
                    'qualifying_saham' => $dasarJasa,
                    'has_paid_sw'      => false,
                    'jasa_saham'       => $jasa,
                    'deviden'          => $dev,
                ];
            }
        }

        // Rekapitulasi Akhir
        $potonganDuka = 20000.0;
        $potonganTunggakanSw = ($swCurrent < 20000.0) ? (20000.0 - $swCurrent) : 0.0;
        $totalPenerimaanBersih = max(0.0, round($accumJasaSaham + $accumDeviden - $potonganDuka - $potonganTunggakanSw, 2));

        $rekapitulasi = [
            'total_jasa_saham'        => round($accumJasaSaham, 2),
            'total_deviden'           => round($accumDeviden, 2),
            'jumlah_bruto'            => round($accumJasaSaham + $accumDeviden, 2),
            'potongan_duka'           => $potonganDuka,
            'potongan_tunggakan_sw'   => round($potonganTunggakanSw, 2),
            'total_penerimaan_bersih' => $totalPenerimaanBersih,
        ];

        return [
            'member' => [
                'id'                 => $member->id,
                'name'               => $member->name,
                'member_number'      => $member->member_number,
                'nik'                => $member->nik,
                'address'            => $member->address,
                'phone'              => $member->phone,
                'status'             => $member->status,
                'has_buku_biru'      => (bool) $member->has_buku_biru,
                'created_at'         => $member->created_at ? $member->created_at->toDateString() : null,
                'principal_savings'  => $spCurrent,
                'mandatory_savings'  => $swCurrent,
                'voluntary_savings'  => $ssCurrent,
                'total_saham'        => $totalSahamCurrent,
            ],
            'fiscal_year'          => $targetYear,
            'fiscal_period_label'  => $fiscalPeriodLabel,
            'saldo_awal'           => $saldoAwalRow,
            'monthly_records'      => $monthlyRecords,
            'monthly_parameters'   => $coopBenchmarks,
            'coop_benchmarks'      => $coopBenchmarks,
            'rekapitulasi'         => $rekapitulasi,
        ];
    }

    /**
     * Cetak PDF Lembar Buku Saham & Deviden 1 Anggota (Individual Statement)
     */
    public function exportMemberStatementPdf(int $memberId, ?int $fiscalYear = null, ?int $month = null, ?int $year = null)
    {
        ini_set('memory_limit', '1024M');
        set_time_limit(300);

        $data = $this->getMemberDividendStatement($memberId, $fiscalYear, $month, $year);

        $viewName = view()->exists('reports.member_share_dividend_pdf')
            ? 'reports.member_share_dividend_pdf'
            : (view()->exists('reports.member_dividend_statement_pdf')
                ? 'reports.member_dividend_statement_pdf'
                : (view()->exists('member_share_dividend_pdf')
                    ? 'member_share_dividend_pdf'
                    : (view()->exists('member_dividend_statement_pdf') ? 'member_dividend_statement_pdf' : 'exports.member_dividend_statement_pdf')));

        $pdf = Pdf::loadView($viewName, $data)
            ->setPaper('a4', 'portrait')
            ->setOption('isHtml5ParserEnabled', true)
            ->setOption('isRemoteEnabled', false);

        $memberNo = $data['member']['member_number'] ?? $memberId;
        $fileName = "Buku_Saham_Deviden_Anggota_{$memberNo}_{$data['fiscal_year']}.pdf";

        return $pdf->download($fileName);
    }

    /**
     * Konversi nama atau kode bulan (misal 'AUG', 'Agustus', 8) ke integer 1..12
     */
    public function parseMonth($month): int
    {
        if (is_numeric($month)) {
            $m = (int) $month;
            if ($m >= 1 && $m <= 12) {
                return $m;
            }
        }

        $normalized = strtoupper(trim((string)$month));
        $map = [
            'JAN' => 1, 'JANUARI' => 1, 'JANUARY' => 1, '01' => 1, '1' => 1,
            'FEB' => 2, 'FEBRUARI' => 2, 'FEBRUARY' => 2, '02' => 2, '2' => 2,
            'MAR' => 3, 'MARET' => 3, 'MARCH' => 3, '03' => 3, '3' => 3,
            'APR' => 4, 'APRIL' => 4, '04' => 4, '4' => 4,
            'MEI' => 5, 'MAY' => 5, '05' => 5, '5' => 5,
            'JUN' => 6, 'JUNI' => 6, 'JUNE' => 6, '06' => 6, '6' => 6,
            'JUL' => 7, 'JULI' => 7, 'JULY' => 7, '07' => 7, '7' => 7,
            'AUG' => 8, 'AGT' => 8, 'AGUSTUS' => 8, 'AUGUST' => 8, '08' => 8, '8' => 8,
            'SEP' => 9, 'SEPTEMBER' => 9, '09' => 9, '9' => 9,
            'OKT' => 10, 'OCT' => 10, 'OKTOBER' => 10, 'OCTOBER' => 10, '10' => 10,
            'NOV' => 11, 'NOVEMBER' => 11, '11' => 11,
            'DES' => 12, 'DEC' => 12, 'DESEMBER' => 12, 'DECEMBER' => 12, '12' => 12,
        ];

        if (isset($map[$normalized])) {
            return $map[$normalized];
        }

        throw new \InvalidArgumentException("Format bulan '{$month}' tidak valid. Gunakan angka 1-12 atau kode bulan (misal: 'AUG', 'SEP', 'OKT').");
    }

    /**
     * Mencatat nilai "SHU setelah dikurangi biaya" per bulan (Juni s/d Mei)
     * Otomatis hitung:
     * - allocated_shu_25 = net_profit * 0.25
     * - total_shares_capital = jumlah seluruh saham anggota pada bulan tersebut (SP + SW + SS)
     * - share_unit_price = allocated_shu_25 / (total_shares_capital / 1000)
     */
    public function recordMonthlyShu(
        int $fiscalYear,
        int $month,
        float $netProfit,
        ?float $totalShares = null,
        float $percentage = self::DEFAULT_PERCENTAGE,
        ?int $userId = null,
        ?string $notes = null
    ): array {
        $calendarYear = ($month >= 6) ? ($fiscalYear - 1) : $fiscalYear;
        $dates = $this->resolveDates($month, $calendarYear);

        // Hitung total saham koperasi jika tidak dispesifikasi
        if ($totalShares === null || $totalShares <= 0) {
            $totalShares = $this->getHistoricalCoopSharesAtDate($dates['cutoff_date'], $month);
        }

        $allocatedShu25 = round($netProfit * ($percentage / 100.0), 2);
        $totalSharesUnits = round($totalShares / 1000.0, 2);
        $shareUnitPrice = ($totalSharesUnits > 0 && $allocatedShu25 > 0)
            ? round($allocatedShu25 / $totalSharesUnits, 6)
            : 0.0;

        $existing = MonthlyCooperativeBenchmark::where('fiscal_year', $fiscalYear)
            ->where('month', $month)
            ->first();

        $benchmark = MonthlyCooperativeBenchmark::updateOrCreate(
            [
                'fiscal_year' => $fiscalYear,
                'month'       => $month,
            ],
            [
                'cycle_start_date'            => $dates['start_date'],
                'cycle_end_date'              => $dates['cutoff_date'],
                'net_income'                  => $netProfit,
                'dividend_allocation_percent' => $percentage,
                'total_coop_shares'           => $totalShares,
                'notes'                       => $notes,
                'updated_by'                  => $userId,
                'created_by'                  => $existing ? $existing->created_by : $userId,
            ]
        );

        Cache::flush();

        $shortMonthNames = [
            6 => 'Jun', 7 => 'Jul', 8 => 'AUG', 9 => 'Sep', 10 => 'OKT', 11 => 'NOV', 12 => 'DES',
            1 => 'Jan', 2 => 'FEB', 3 => 'MAR', 4 => 'APR', 5 => 'MEI'
        ];

        return [
            'record' => [
                'id'                          => $benchmark->id,
                'fiscal_year'                 => $fiscalYear,
                'calendar_year'               => $calendarYear,
                'month'                       => $month,
                'month_name'                  => $shortMonthNames[$month] ?? (string)$month,
                'month_label'                 => $dates['period_label'],
                'cycle_start_date'            => $dates['start_date'],
                'cycle_end_date'              => $dates['cutoff_date'],
                'net_profit'                  => round($netProfit, 2),
                'net_income'                  => round($netProfit, 2),
                'allocated_shu_25'            => $allocatedShu25,
                'dividend_pool'               => $allocatedShu25,
                'dividend_allocation_percent' => round($percentage, 2),
                'total_shares_capital'        => round($totalShares, 2),
                'total_shares_units'          => $totalSharesUnits,
                'lembar_saham'                => $totalSharesUnits,
                'share_unit_price'            => $shareUnitPrice,
                'harga_deviden_per_lembar'    => $shareUnitPrice,
                'notes'                       => $notes,
            ],
            'recap_12_months' => $this->getShuRecap12Months($fiscalYear),
        ];
    }

    /**
     * Rekap status pembagian SHU 12 bulan (Juni s/d Mei)
     */
    public function getShuRecap12Months(int $fiscalYear): array
    {
        $startYear = $fiscalYear - 1;
        $endYear   = $fiscalYear;

        $shortMonthNames = [
            6 => 'Jun', 7 => 'Jul', 8 => 'AUG', 9 => 'Sep', 10 => 'OKT', 11 => 'NOV', 12 => 'DES',
            1 => 'Jan', 2 => 'FEB', 3 => 'MAR', 4 => 'APR', 5 => 'MEI'
        ];

        $fiscalMonthsOrder = [
            ['m' => 6,  'y' => $startYear],
            ['m' => 7,  'y' => $startYear],
            ['m' => 8,  'y' => $startYear],
            ['m' => 9,  'y' => $startYear],
            ['m' => 10, 'y' => $startYear],
            ['m' => 11, 'y' => $startYear],
            ['m' => 12, 'y' => $startYear],
            ['m' => 1,  'y' => $endYear],
            ['m' => 2,  'y' => $endYear],
            ['m' => 3,  'y' => $endYear],
            ['m' => 4,  'y' => $endYear],
            ['m' => 5,  'y' => $endYear],
        ];

        $savedBenchmarks = MonthlyCooperativeBenchmark::where('fiscal_year', $fiscalYear)
            ->get()
            ->keyBy('month');

        $recap = [];
        $totalNetProfit = 0.0;
        $totalAllocated25 = 0.0;
        $totalDistributedAll = 0.0;
        $distributedCount = 0;

        foreach ($fiscalMonthsOrder as $fm) {
            $m = $fm['m'];
            $y = $fm['y'];
            $dates = $this->resolveDates($m, $y);
            $saved = $savedBenchmarks->get($m);

            if ($saved && $saved->net_income !== null) {
                $netProfit = (float) $saved->net_income;
                $isManual  = true;
            } else {
                $autoProfit = $this->calculateNetProfit($m, $y, false);
                $netProfit  = (float) $autoProfit['shu_bersih'];
                $isManual   = false;
            }

            $divPercent = $saved ? (float) $saved->dividend_allocation_percent : self::DEFAULT_PERCENTAGE;

            if ($saved && $saved->total_coop_shares !== null) {
                $totalShares = (float) $saved->total_coop_shares;
            } else {
                $totalShares = $this->getHistoricalCoopSharesAtDate($dates['cutoff_date'], $m);
            }

            $lembarKoperasi = round($totalShares / 1000.0, 2);
            $allocated25 = round($netProfit * ($divPercent / 100.0), 2);
            $shareUnitPrice = ($lembarKoperasi > 0 && $allocated25 > 0) ? round($allocated25 / $lembarKoperasi, 6) : 0.0;

            // Cek status distribusi
            $isDistributed = $this->isAlreadyDistributed($dates['target_ym'], $m, $y);
            $distributedAmount = 0.0;
            $distributedAt = null;
            $voucherNo = null;

            if ($isDistributed) {
                $distTrx = Transaction::where('book_type', 'BUKU_BIRU')
                    ->where('category', 'bunga_saham')
                    ->where(function ($q) use ($dates, $m, $y) {
                        $q->where('receipt_number', 'like', 'DIV-' . $dates['target_ym'] . '-%')
                          ->orWhere('receipt_number', 'like', 'BM-DIV-' . $dates['target_ym'] . '-%')
                          ->orWhere(function ($sub) use ($m, $y) {
                              $sub->whereMonth('transaction_date', $m)->whereYear('transaction_date', $y);
                          });
                    })
                    ->selectRaw('SUM(amount) as total_amount, MAX(transaction_date) as last_date, MAX(receipt_number) as voucher')
                    ->first();

                $distributedAmount = (float) ($distTrx->total_amount ?? 0.0);
                $distributedAt = $distTrx->last_date ?? null;
                $voucherNo = $distTrx->voucher ?? null;
                $distributedCount++;
            }

            $totalNetProfit += $netProfit;
            $totalAllocated25 += $allocated25;
            $totalDistributedAll += $distributedAmount;

            $recap[] = [
                'month'                    => $m,
                'year'                     => $y,
                'fiscal_year'              => $fiscalYear,
                'month_name'               => $shortMonthNames[$m],
                'month_label'              => $dates['period_label'],
                'cycle_start_date'         => $dates['start_date'],
                'cycle_end_date'           => $dates['cutoff_date'],
                'net_profit'               => round($netProfit, 2),
                'net_income'               => round($netProfit, 2),
                'allocated_shu_25'         => round($allocated25, 2),
                'dividend_pool'            => round($allocated25, 2),
                'dividend_allocation_percent' => round($divPercent, 2),
                'total_shares_capital'     => round($totalShares, 2),
                'total_shares_units'       => $lembarKoperasi,
                'lembar_saham'             => $lembarKoperasi,
                'share_unit_price'         => round($shareUnitPrice, 6),
                'harga_deviden_per_lembar' => round($shareUnitPrice, 6),
                'is_distributed'           => $isDistributed,
                'status'                   => $isDistributed ? 'sudah dibagikan' : 'belum dibagikan',
                'status_label'             => $isDistributed ? 'Sudah Dibagikan' : 'Belum Dibagikan',
                'total_distributed'        => round($distributedAmount, 2),
                'distributed_at'           => $distributedAt,
                'voucher_no'               => $voucherNo,
                'is_manual_override'       => $isManual,
            ];
        }

        return [
            'fiscal_year'                 => $fiscalYear,
            'fiscal_period_label'         => "Juni {$startYear} - Mei {$endYear}",
            'total_net_profit'            => round($totalNetProfit, 2),
            'total_allocated_shu_25'      => round($totalAllocated25, 2),
            'total_distributed_dividend'  => round($totalDistributedAll, 2),
            'distributed_months_count'    => $distributedCount,
            'pending_months_count'        => 12 - $distributedCount,
            'monthly_recap'               => $recap,
            'months'                      => $recap,
        ];
    }
}
