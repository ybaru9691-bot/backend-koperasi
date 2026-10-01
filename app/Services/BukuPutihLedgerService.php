<?php

namespace App\Services;

use App\Models\AccountingPeriod;
use App\Models\Member;
use App\Models\Transaction;
use Barryvdh\DomPDF\Facade\Pdf;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class BukuPutihLedgerService
{
    public const INTEREST_RATE = 0.006; // 0.6% per bulan

    /**
     * Resolusi periode akuntansi & 12 siklus bulanan (Juni s/d Mei, cut-off per tgl 20)
     */
    public function resolvePeriod(?int $periodId = null, ?int $fiscalYear = null, ?int $year = null): array
    {
        $period = null;
        if ($periodId) {
            $period = AccountingPeriod::find($periodId);
        }

        if ($period) {
            $startDate = Carbon::parse($period->start_date);
            $endDate   = Carbon::parse($period->end_date);
            $startYear = $startDate->year;
            $endYear   = $endDate->year;

            // Jika periode disimpan dengan tahun yang sama, periksa bulannya
            if ($startYear === $endYear) {
                if ($startDate->month >= 6) {
                    $endYear = $startYear + 1;
                } else {
                    $startYear = $endYear - 1;
                }
            }
            $periodName = $period->period_name ?: ("Tahun Buku " . $startYear . "/" . $endYear);
        } elseif ($fiscalYear) {
            $startYear  = $fiscalYear - 1;
            $endYear    = $fiscalYear;
            $periodName = "Tahun Buku " . $fiscalYear;
        } elseif ($year) {
            $startYear  = $year - 1;
            $endYear    = $year;
            $periodName = "Tahun Buku " . $year;
        } else {
            // Cari periode aktif di database
            $activePeriod = AccountingPeriod::where('is_locked', false)
                ->whereIn('status', ['open', 'OPEN', 'terbuka'])
                ->latest('id')
                ->first();

            if (!$activePeriod) {
                $activePeriod = AccountingPeriod::where('is_locked', false)->latest('id')->first();
            }

            if ($activePeriod) {
                $period     = $activePeriod;
                $startDate  = Carbon::parse($activePeriod->start_date);
                $endDate    = Carbon::parse($activePeriod->end_date);
                $startYear  = $startDate->year;
                $endYear    = $endDate->year;
                if ($startYear === $endYear) {
                    if ($startDate->month >= 6) {
                        $endYear = $startYear + 1;
                    } else {
                        $startYear = $endYear - 1;
                    }
                }
                $periodName = $activePeriod->period_name ?: ("Tahun Buku " . $startYear . "/" . $endYear);
            } else {
                $now        = now();
                $startYear  = ($now->month >= 6) ? $now->year : ($now->year - 1);
                $endYear    = $startYear + 1;
                $periodName = "Tahun Buku " . $startYear . "/" . $endYear;
            }
        }

        $shortMonthNames = [
            6 => 'Jun', 7 => 'Jul', 8 => 'Agu', 9 => 'Sep', 10 => 'Okt', 11 => 'Nov', 12 => 'Des',
            1 => 'Jan', 2 => 'Feb', 3 => 'Mar', 4 => 'Apr', 5 => 'Mei'
        ];

        $fiscalMonths = [];
        for ($m = 6; $m <= 12; $m++) {
            $prevM = Carbon::createFromDate($startYear, $m, 1)->subMonth();
            $fiscalMonths[] = [
                'month'       => $m,
                'year'        => $startYear,
                'month_name'  => Carbon::createFromDate($startYear, $m, 1)->locale('id')->isoFormat('MMMM'),
                'month_label' => Carbon::createFromDate($startYear, $m, 1)->locale('id')->isoFormat('MMMM YYYY'),
                'short_code'  => $shortMonthNames[$m],
                'cycle_start' => Carbon::createFromDate($prevM->year, $prevM->month, 21)->startOfDay()->toDateString(),
                'cycle_end'   => Carbon::createFromDate($startYear, $m, 20)->endOfDay()->toDateString(),
            ];
        }
        for ($m = 1; $m <= 5; $m++) {
            $prevM = Carbon::createFromDate($endYear, $m, 1)->subMonth();
            $fiscalMonths[] = [
                'month'       => $m,
                'year'        => $endYear,
                'month_name'  => Carbon::createFromDate($endYear, $m, 1)->locale('id')->isoFormat('MMMM'),
                'month_label' => Carbon::createFromDate($endYear, $m, 1)->locale('id')->isoFormat('MMMM YYYY'),
                'short_code'  => $shortMonthNames[$m],
                'cycle_start' => Carbon::createFromDate($prevM->year, $prevM->month, 21)->startOfDay()->toDateString(),
                'cycle_end'   => Carbon::createFromDate($endYear, $m, 20)->endOfDay()->toDateString(),
            ];
        }

        return [
            'period'            => $period,
            'period_id'         => $period ? $period->id : null,
            'period_name'       => $periodName,
            'start_year'        => $startYear,
            'end_year'          => $endYear,
            'period_label'      => "Juni {$startYear} - Mei {$endYear}",
            'period_code'       => "{$startYear}/{$endYear}",
            'fiscal_months'     => $fiscalMonths,
            'first_cycle_start' => Carbon::createFromDate($startYear, 5, 21)->startOfDay()->toDateString(),
            'last_cycle_end'    => Carbon::createFromDate($endYear, 5, 20)->endOfDay()->toDateString(),
        ];
    }

    /**
     * Dapatkan nominal mutasi murni Simpanan Harian (Buku Putih) dari transaksi.
     * Khusus untuk transaksi pembukaan rekening/pendaftaran, ambil porsi simpanan harian murni
     * (ending_balance atau daily_savings jika transaksi mencakup biaya registrasi/dana duka).
     */
    public function getBukuPutihTransactionAmount(Transaction $trx, ?Member $member = null): float
    {
        $amt = (float) $trx->amount;
        $desc = strtolower($trx->description ?? '');
        $cat = $trx->category ?? '';

        $isRegistration = str_contains($desc, 'pembukaan rekening')
            || str_contains($desc, 'pendaftaran')
            || str_contains($desc, 'registrasi')
            || $cat === 'pendaftaran';

        if ($isRegistration) {
            if ((float) $trx->ending_balance > 0 && (float) $trx->ending_balance < $amt) {
                return (float) $trx->ending_balance;
            }
            if ($member && (float) $member->daily_savings > 0 && (float) $member->daily_savings < $amt) {
                return (float) $member->daily_savings;
            }
        }

        return $amt;
    }

    /**
     * Hitung Saldo Awal per 31 Mei (akhir periode sebelumnya / cut-off 20 Mei)
     */
    public function calculateInitialBalance(Member $member, string $firstCycleStart): float
    {
        $currentDailySavings = (float) ($member->daily_savings ?? 0.0);

        // Cari semua transaksi Buku Putih anggota yang terjadi sejak siklus pertama periode berjalan
        $trxsAfterStart = Transaction::where('member_id', $member->id)
            ->where('book_type', 'BUKU_PUTIH')
            ->where('status', 'approved')
            ->whereDate('transaction_date', '>=', $firstCycleStart)
            ->get();

        $netChange = 0.0;
        foreach ($trxsAfterStart as $trx) {
            $desc  = strtolower($trx->description ?? '');
            $cat   = $trx->category ?? '';
            $type  = $trx->type ?? '';
            $amt   = $this->getBukuPutihTransactionAmount($trx, $member);

            $isDeposit = in_array($type, ['deposit', 'in', 'kas_masuk', 'KM'])
                || str_contains($desc, 'bunga')
                || str_contains($desc, 'jasa')
                || $cat === 'bunga_simpanan';

            $isWithdrawal = in_array($type, ['withdrawal', 'out', 'kas_keluar', 'KK']);

            if ($isDeposit) {
                $netChange += $amt;
            } elseif ($isWithdrawal) {
                $netChange -= $amt;
            }
        }

        // Saldo Awal = Saldo Saat Ini dikurangi Mutasi Bersih selama periode
        $initialBalance = max(0.0, round($currentDailySavings - $netChange, 2));

        return $initialBalance;
    }

    /**
     * Menghasilkan Data Mutasi 12 Bulan Buku Putih (Simpanan Harian) Anggota
     * Format Multi-Row per Transaksi:
     * - Setiap mutasi kas (KM/KK) dan bunga (BM) menjadi objek/row tersendiri (tidak digabung string).
     * - Bunga bulan M dihitung dari Saldo Akhir Bulan M-1 ($saldoAkhirBulanLalu * 0.006).
     * - Bunga wajib dihitung tiap bulan selama ada saldo mengendap.
     * - Saldo berjalan terupdate secara kronologis di setiap baris.
     */
    public function getMemberBukuPutihLedger(int $memberId, ?int $periodId = null, ?int $fiscalYear = null, ?int $year = null): array
    {
        $member = Member::findOrFail($memberId);

        $periodInfo      = $this->resolvePeriod($periodId, $fiscalYear, $year);
        $fiscalMonths    = $periodInfo['fiscal_months'];
        $firstCycleStart = $periodInfo['first_cycle_start'];
        $lastCycleEnd    = $periodInfo['last_cycle_end'];

        $initialBalance = $this->calculateInitialBalance($member, $firstCycleStart);
        $saldoAkhirBulanSebelumnya = $initialBalance;

        // Ambil SEMUA transaksi Buku Putih anggota dalam 1 tahun buku
        $allTrxs = Transaction::where('member_id', $member->id)
            ->where('book_type', 'BUKU_PUTIH')
            ->where('status', 'approved')
            ->whereDate('transaction_date', '>=', $firstCycleStart)
            ->whereDate('transaction_date', '<=', $lastCycleEnd)
            ->orderBy('transaction_date', 'asc')
            ->orderBy('id', 'asc')
            ->get();

        // 1. Cek apakah anggota PERNAH melakukan transaksi kas riil (KM/KK) minimal 1 kali di dalam periode berjalan ini
        $hasRealCashInPeriod = $allTrxs->contains(function ($trx) {
            $desc  = strtolower($trx->description ?? '');
            $cat   = $trx->category ?? '';
            $type  = $trx->type ?? '';
            $isMemorial = ($trx->payment_method === 'memorial')
                || str_starts_with((string) $trx->receipt_number, 'BM-INT')
                || str_starts_with((string) $trx->receipt_number, 'INT-')
                || str_starts_with((string) $trx->receipt_number, 'BM-')
                || str_contains($desc, 'bunga')
                || str_contains($desc, 'jasa')
                || $cat === 'bunga_simpanan';

            $isRealCash = in_array($type, ['deposit', 'in', 'kas_masuk', 'KM', 'withdrawal', 'out', 'kas_keluar', 'KK']);

            return !$isMemorial && $isRealCash;
        });

        $rawStatus = strtolower(trim((string) ($member->status ?? '')));
        $inactiveStatuses = ['inactive', 'non-active', 'pasif', 'keluar', 'resigned', 'blokir', '0'];
        $isExplicitlyInactive = in_array($rawStatus, $inactiveStatuses, true);

        // ATURAN STATUS KEAKTIFAN 1 PERIODE:
        // - Jika anggota secara eksplisit nonaktif ($isExplicitlyInactive), status WAJIB TIDAK AKTIF dan bunga Rp 0.
        // - Jika anggota PERNAH setor/tarik kas (KM/KK) minimal 1x di periode berjalan ini, status WAJIB AKTIF.
        // - Jika TIDAK PERNAH bertransaksi kas sama sekali di periode berjalan ini, status TIDAK AKTIF dan bunga Rp 0.
        $isPeriodActive = !$isExplicitlyInactive && $hasRealCashInPeriod;

        $cycles                = [];
        $allFlatTransactions   = [];
        $grandTotalDeposits    = 0.0;
        $grandTotalWithdrawals = 0.0;
        $grandTotalJasa        = 0.0;
        $activeMonthsCount     = 0;

        // Tambahkan baris saldo awal ke flat transactions
        $allFlatTransactions[] = [
            'date'            => Carbon::createFromDate($periodInfo['start_year'], 5, 31)->toDateString(),
            'evidence_no'     => '-',
            'type'            => 'SALDO_AWAL',
            'description'     => "Saldo Awal per 31 Mei {$periodInfo['start_year']}",
            'deposit'         => 0.0,
            'withdrawal'      => 0.0,
            'interest'        => 0.0,
            'balance'         => $initialBalance,
        ];

        foreach ($fiscalMonths as $fm) {
            $m      = $fm['month'];
            $y      = $fm['year'];
            $mStart = $fm['cycle_start'];
            $mEnd   = $fm['cycle_end'];

            // 1. Tentukan Dasar Perhitungan Bunga: Saldo Akhir Bulan Lalu (M-1)
            $saldoDasarBunga = $saldoAkhirBulanSebelumnya;

            // 2. Ambil transaksi kasir (KM/KK) riil dalam siklus tanggal 21 M-1 s/d 20 M
            $cycleTrxs = $allTrxs->filter(function ($trx) use ($mStart, $mEnd) {
                if (!$trx->transaction_date) {
                    return false;
                }
                $tDate = Carbon::parse($trx->transaction_date)->toDateString();
                $desc  = strtolower($trx->description ?? '');
                $cat   = $trx->category ?? '';
                $type  = $trx->type ?? '';
                $isMemorial = ($trx->payment_method === 'memorial')
                    || str_starts_with((string) $trx->receipt_number, 'BM-INT')
                    || str_starts_with((string) $trx->receipt_number, 'INT-')
                    || str_starts_with((string) $trx->receipt_number, 'BM-')
                    || str_contains($desc, 'bunga buku putih')
                    || str_contains($desc, 'jasa simpanan harian')
                    || $cat === 'bunga_simpanan';

                $isRealCash = in_array($type, ['deposit', 'in', 'kas_masuk', 'KM', 'withdrawal', 'out', 'kas_keluar', 'KK']);

                return $tDate >= $mStart && $tDate <= $mEnd && !$isMemorial && $isRealCash;
            });

            if ($cycleTrxs->isNotEmpty()) {
                $activeMonthsCount++;
            }

            // 3. Hitung Jasa Bunga 0.6%:
            // Bunga diberikan secara penuh jika status periode AKTIF dan terdapat saldo dasar mengendap > 0
            $targetYm = Carbon::createFromDate($y, $m, 1)->format('Ym');
            $existingBmTrx = $allTrxs->first(function ($trx) use ($mStart, $mEnd, $targetYm) {
                $desc  = strtolower($trx->description ?? '');
                $cat   = $trx->category ?? '';
                $rNo   = (string) ($trx->receipt_number ?? '');
                $tDate = Carbon::parse($trx->transaction_date)->toDateString();

                $isBm = (str_starts_with($rNo, 'BM-INT-' . $targetYm) || str_starts_with($rNo, 'INT-' . $targetYm) || $cat === 'bunga_simpanan')
                    && ($trx->payment_method === 'memorial' || str_contains($desc, 'bunga') || str_contains($desc, 'jasa'));

                return $isBm && $tDate >= $mStart && $tDate <= $mEnd;
            });

            if ($existingBmTrx && (float) $existingBmTrx->amount > 0) {
                $jasaBulanIni = (float) $existingBmTrx->amount;
            } else {
                $jasaBulanIni = ($isPeriodActive && $saldoDasarBunga > 0)
                    ? (float) round($saldoDasarBunga * self::INTEREST_RATE, 2)
                    : 0.0;
            }

            $cycleRows           = [];
            $cycleDeposits       = 0.0;
            $cycleWithdrawals    = 0.0;
            $currentCycleBalance = $saldoDasarBunga;

            // Masukkan setiap mutasi kasir (KM / KK) sebagai baris / objek tersendiri
            foreach ($cycleTrxs as $trx) {
                $amt   = $this->getBukuPutihTransactionAmount($trx, $member);
                $type  = $trx->type;
                $tDate = Carbon::parse($trx->transaction_date)->toDateString();
                $vNo   = $trx->receipt_number ?: $trx->transaction_number ?: '-';
                $desc  = $trx->description ?: ($type === 'deposit' ? 'Setoran Simpanan Harian' : 'Penarikan Simpanan Harian');

                $isDeposit    = in_array($type, ['deposit', 'in', 'kas_masuk', 'KM']);
                $isWithdrawal = in_array($type, ['withdrawal', 'out', 'kas_keluar', 'KK']);

                if ($isDeposit) {
                    $cycleDeposits += $amt;
                    $currentCycleBalance = round($currentCycleBalance + $amt, 2);
                    $rowItem = [
                        'date'            => $tDate,
                        'evidence_no'     => $vNo,
                        'type'            => 'KM',
                        'description'     => $desc,
                        'deposit'         => $amt,
                        'withdrawal'      => 0.0,
                        'interest'        => 0.0,
                        'balance'         => $currentCycleBalance,
                        'voucher_no'      => $vNo,
                        'running_balance' => $currentCycleBalance,
                        'is_memorial'     => false,
                    ];
                } elseif ($isWithdrawal) {
                    $cycleWithdrawals += $amt;
                    $currentCycleBalance = round(max(0.0, $currentCycleBalance - $amt), 2);
                    $rowItem = [
                        'date'            => $tDate,
                        'evidence_no'     => $vNo,
                        'type'            => 'KK',
                        'description'     => $desc,
                        'deposit'         => 0.0,
                        'withdrawal'      => $amt,
                        'interest'        => 0.0,
                        'balance'         => $currentCycleBalance,
                        'voucher_no'      => $vNo,
                        'running_balance' => $currentCycleBalance,
                        'is_memorial'     => false,
                    ];
                } else {
                    continue;
                }

                $cycleRows[]           = $rowItem;
                $allFlatTransactions[] = $rowItem;
            }

            // Injeksi baris BM Bunga 0.6% HANYA jika status periode AKTIF dan terdapat nilai bunga
            $sortedCycleRows = $cycleRows;
            if ($isPeriodActive && ($jasaBulanIni > 0 || $existingBmTrx)) {
                $bmVoucher = $existingBmTrx ? ($existingBmTrx->receipt_number ?: "BM-INT-{$targetYm}") : "BM-INT-{$targetYm}";
                $bmDate    = $existingBmTrx ? Carbon::parse($existingBmTrx->transaction_date)->toDateString() : Carbon::createFromDate($y, $m, 20)->toDateString();

                $grandTotalJasa += $jasaBulanIni;

                $bmRow = [
                    'date'            => $bmDate,
                    'evidence_no'     => $bmVoucher,
                    'type'            => 'BM',
                    'description'     => "Jasa Tabungan Harian Bulan {$fm['month_name']} {$y}",
                    'deposit'         => 0.0,
                    'withdrawal'      => 0.0,
                    'interest'        => $jasaBulanIni,
                    'balance'         => 0.0,
                    'voucher_no'      => $bmVoucher,
                    'running_balance' => 0.0,
                    'is_memorial'     => true,
                ];

                // Sisipkan baris BM secara kronologis (atau di posisi tanggal 20)
                $inserted = false;
                $sortedCycleRows = [];
                foreach ($cycleRows as $row) {
                    if (!$inserted && $row['date'] > $bmDate) {
                        $sortedCycleRows[] = $bmRow;
                        $inserted = true;
                    }
                    $sortedCycleRows[] = $row;
                }
                if (!$inserted) {
                    $sortedCycleRows[] = $bmRow;
                }
                $allFlatTransactions[] = $bmRow;
            }

            // Hitung running balance secara presisi berurutan dari baris pertama s/d terakhir dalam siklus ini
            $rolling = $saldoDasarBunga;
            foreach ($sortedCycleRows as &$r) {
                if ($r['type'] === 'KM') {
                    $rolling = round($rolling + $r['deposit'], 2);
                } elseif ($r['type'] === 'KK') {
                    $rolling = round(max(0.0, $rolling - $r['withdrawal']), 2);
                } elseif ($r['type'] === 'BM') {
                    $rolling = round($rolling + $r['interest'], 2);
                }
                $r['balance']         = $rolling;
                $r['running_balance'] = $rolling;
            }
            unset($r);

            $saldoAkhir = $rolling;

            $grandTotalDeposits    += $cycleDeposits;
            $grandTotalWithdrawals += $cycleWithdrawals;

            $cycleObj = [
                'month_name'         => "{$fm['month_name']} {$y}",
                'month'              => $m,
                'year'               => $y,
                'short_code'         => $fm['short_code'],
                'cycle_start'        => $mStart,
                'cycle_end'          => $mEnd,
                'opening_balance'    => $saldoDasarBunga,
                'saldo_awal'         => $saldoDasarBunga,
                'saldo_dasar_bunga'  => $saldoDasarBunga,
                'total_deposit'      => round($cycleDeposits, 2),
                'total_setoran'      => round($cycleDeposits, 2),
                'setoran'            => round($cycleDeposits, 2),
                'total_withdrawal'   => round($cycleWithdrawals, 2),
                'total_penarikan'    => round($cycleWithdrawals, 2),
                'penarikan'          => round($cycleWithdrawals, 2),
                'interest'           => $jasaBulanIni,
                'jasa'               => $jasaBulanIni,
                'bunga'              => $jasaBulanIni,
                'interest_rate'      => $isPeriodActive ? self::INTEREST_RATE : 0.0,
                'is_active'          => $isPeriodActive,
                'closing_balance'    => $saldoAkhir,
                'saldo_akhir'        => $saldoAkhir,
                'saldo'              => $saldoAkhir,
                'rows'               => $sortedCycleRows,
                'transactions'       => $sortedCycleRows,
            ];

            $cycles[] = $cycleObj;

            // Simpan saldo akhir ini untuk modal perhitungan bunga bulan berikutnya (M+1)
            $saldoAkhirBulanSebelumnya = $saldoAkhir;
        }

        // Evaluasi Status Keaktifan Final Menggunakan Helper evaluateMembershipStatus
        $membershipStatus = $this->evaluateMembershipStatus($cycles, $isExplicitlyInactive);
        $statusKeaktifan  = $membershipStatus['status_keaktifan'];
        $isActiveMember   = $membershipStatus['is_active'];
        $statusLabel      = $isActiveMember ? 'AKTIF' : 'TIDAK AKTIF';
        $consecutiveInactive = $membershipStatus['consecutive_inactive_months'] ?? 0;
        $passiveMonthsCount = count($fiscalMonths) - $activeMonthsCount;

        $periodCode = "{$periodInfo['start_year']}/{$periodInfo['end_year']}";
        $bukuPutihNo = $member->buku_putih_no
            ?: ($member->member_number ? ('2021-' . str_pad((string) $member->member_number, 4, '0', STR_PAD_LEFT)) : '-');

        $summary = [
            'status'                           => $statusLabel,
            'status_label'                     => $statusLabel,
            'status_badge'                     => $statusLabel,
            'badge_status'                     => $statusLabel,
            'badge_color'                      => $isActiveMember ? 'green' : 'red',
            'membership_status'                => $statusLabel,
            'status_keaktifan'                 => $statusKeaktifan,
            'is_active'                        => $isActiveMember,
            'is_aktif'                         => $isActiveMember,
            'active_months_count'              => $activeMonthsCount,
            'passive_months_count'             => $passiveMonthsCount,
            'consecutive_inactive_months'      => $consecutiveInactive,
            'max_consecutive_inactive_months'  => $membershipStatus['max_consecutive_inactive_months'] ?? 0,
            'saldo_awal_per_31_mei'            => $initialBalance,
            'opening_balance'                  => $initialBalance,
            'saldo_awal'                       => $initialBalance,
            'total_setoran'                    => round($grandTotalDeposits, 2),
            'total_deposit'                    => round($grandTotalDeposits, 2),
            'total_penarikan'                  => round($grandTotalWithdrawals, 2),
            'total_withdrawal'                 => round($grandTotalWithdrawals, 2),
            'total_jasa'                       => round($grandTotalJasa, 2),
            'total_interest'                   => round($grandTotalJasa, 2),
            'saldo_akhir_per_20_mei'           => $saldoAkhirBulanSebelumnya,
            'closing_balance'                  => $saldoAkhirBulanSebelumnya,
            'saldo_akhir'                      => $saldoAkhirBulanSebelumnya,
            'interest_rate'                    => $isActiveMember ? self::INTEREST_RATE : 0.0,
            'total_months'                     => count($cycles),
            'total_transactions_count'         => count($allFlatTransactions),
        ];

        return [
            'member_id'                    => $member->id,
            'period'                       => $periodCode,
            'status'                       => $statusLabel,
            'status_label'                 => $statusLabel,
            'status_badge'                 => $statusLabel,
            'badge_status'                 => $statusLabel,
            'badge_color'                  => $isActiveMember ? 'green' : 'red',
            'membership_status'            => $statusLabel,
            'status_keaktifan'             => $statusKeaktifan,
            'is_active'                    => $isActiveMember,
            'is_aktif'                     => $isActiveMember,
            'active_months_count'          => $activeMonthsCount,
            'passive_months_count'         => $passiveMonthsCount,
            'consecutive_inactive_months'  => $consecutiveInactive,
            'opening_balance'              => $initialBalance,
            'closing_balance'              => $saldoAkhirBulanSebelumnya,
            'total_deposit'                => round($grandTotalDeposits, 2),
            'total_withdrawal'             => round($grandTotalWithdrawals, 2),
            'total_interest'               => round($grandTotalJasa, 2),
            'cycles'                       => $cycles,
            // Ekstensi data lengkap untuk kebutuhan UI, PDF, & API backward compatibility
            'member'                       => [
                'id'                   => $member->id,
                'member_number'        => $member->member_number,
                'buku_putih_no'        => $bukuPutihNo,
                'name'                 => $member->name,
                'nik'                  => $member->nik,
                'phone'                => $member->phone,
                'address'              => $member->address ?? $member->alamat ?? '-',
                'status'               => $member->status,
                'status_label'         => $statusLabel,
                'status_badge'         => $statusLabel,
                'badge_status'         => $statusLabel,
                'badge_color'          => $isActiveMember ? 'green' : 'red',
                'membership_status'    => $statusLabel,
                'status_keaktifan'     => $statusKeaktifan,
                'is_active'            => $isActiveMember,
                'is_aktif'             => $isActiveMember,
                'active_months_count'  => $activeMonthsCount,
                'passive_months_count' => $passiveMonthsCount,
                'consecutive_inactive_months' => $consecutiveInactive,
                'has_buku_putih'       => (bool) ($member->has_buku_putih || $member->daily_savings > 0),
                'daily_savings'        => (float) ($member->daily_savings ?? 0.0),
            ],
            'period_info'                  => [
                'id'           => $periodInfo['period_id'],
                'period_name'  => $periodInfo['period_name'],
                'start_year'   => $periodInfo['start_year'],
                'end_year'     => $periodInfo['end_year'],
                'period_label' => $periodInfo['period_label'],
                'label'        => $periodInfo['period_label'],
            ],
            'summary'                      => $summary,
            'monthly_records'              => $cycles,
            'months'                       => $cycles,
            'all_transactions'             => $allFlatTransactions,
        ];
    }

    /**
     * Evaluasi Status Keaktifan Anggota Buku Putih (Simpanan Harian)
     * Aturan Bisnis:
     * 1. Jika anggota PERNAH melakukan transaksi kas (KM/KK) minimal 1 kali di dalam periode berjalan ini -> "AKTIF".
     * 2. Status "TIDAK AKTIF (>6 Bln Pasif)" HANYA jika anggota TIDAK PERNAH menabung/bertransaksi sama sekali di periode ini.
     * 3. Transaksi bunga memorial (BM) JANGAN dianggap sebagai setoran kas anggota.
     */
    public function evaluateMembershipStatus(array $monthlyCycles, bool $isExplicitlyInactive = false): array
    {
        $consecutiveInactive = 0;
        $maxConsecutiveInactive = 0;
        $hasCashTransactionInPeriod = false;
        $activeMonthsCount = 0;

        foreach ($monthlyCycles as $cycle) {
            // Cek apakah di bulan ini ada transaksi kas riil (setoran KM / penarikan KK)
            $hasCash = collect($cycle['transactions'] ?? $cycle['rows'] ?? [])->contains(function ($t) {
                return in_array($t['type'] ?? '', ['KM', 'KK', 'deposit', 'withdrawal']) && empty($t['is_memorial']);
            });

            if ($hasCash) {
                $hasCashTransactionInPeriod = true;
                $activeMonthsCount++;
                $consecutiveInactive = 0; // Reset ke 0 saat ada transaksi
            } else {
                $consecutiveInactive++;
                if ($consecutiveInactive > $maxConsecutiveInactive) {
                    $maxConsecutiveInactive = $consecutiveInactive;
                }
            }
        }

        // Evaluasi Status:
        // Jika anggota secara eksplisit dinonaktifkan pengurus, status TIDAK AKTIF
        if ($isExplicitlyInactive) {
            $isActive = false;
            $statusKeaktifan = 'TIDAK AKTIF (Dinonaktifkan)';
            $statusLabel = 'TIDAK AKTIF';
        } elseif ($hasCashTransactionInPeriod || $activeMonthsCount > 0) {
            $isActive = true;
            $statusKeaktifan = 'AKTIF';
            $statusLabel = 'AKTIF';
        } else {
            $isActive = false;
            $statusKeaktifan = 'TIDAK AKTIF (>6 Bln Pasif)';
            $statusLabel = 'TIDAK AKTIF';
        }

        return [
            'status_label'                    => $statusLabel,
            'membership_status'               => $statusLabel,
            'status_keaktifan'                => $statusKeaktifan,
            'is_active'                       => $isActive,
            'has_ever_transacted_in_period'   => $hasCashTransactionInPeriod,
            'active_months_count'             => $activeMonthsCount,
            'passive_months_count'            => count($monthlyCycles) - $activeMonthsCount,
            'consecutive_inactive_months'     => $consecutiveInactive,
            'max_consecutive_inactive_months' => $maxConsecutiveInactive,
        ];
    }

    /**
     * Cetak Lembar Mutasi Buku Tabungan Harian (Buku Putih) CUM Pelita format PDF
     */
    public function exportPdf(int $memberId, ?int $periodId = null, ?int $fiscalYear = null, ?int $year = null)
    {
        $data = $this->getMemberBukuPutihLedger($memberId, $periodId, $fiscalYear, $year);

        $viewName = view()->exists('reports.buku_putih_ledger_pdf')
            ? 'reports.buku_putih_ledger_pdf'
            : (view()->exists('buku_putih_ledger_pdf') ? 'buku_putih_ledger_pdf' : 'exports.buku_putih_ledger_pdf');

        $pdf = Pdf::loadView($viewName, [
            'member_id'        => $data['member_id'],
            'period'           => $data['period'],
            'opening_balance'  => $data['opening_balance'],
            'closing_balance'  => $data['closing_balance'],
            'total_deposit'    => $data['total_deposit'],
            'total_withdrawal' => $data['total_withdrawal'],
            'total_interest'   => $data['total_interest'],
            'cycles'           => $data['cycles'],
            'member'           => $data['member'],
            'period_info'      => $data['period_info'],
            'summary'          => $data['summary'],
            'monthly_records'  => $data['monthly_records'],
            'months'           => $data['months'],
            'all_transactions' => $data['all_transactions'],
        ])
        ->setPaper('a4', 'portrait')
        ->setOption('isHtml5ParserEnabled', true)
        ->setOption('isRemoteEnabled', false);

        $cleanName = preg_replace('/[^A-Za-z0-9_-]/', '_', $data['member']['name']);
        $fileName  = "Buku_Putih_Ledger_{$data['member']['buku_putih_no']}_{$cleanName}_{$data['period_info']['start_year']}_{$data['period_info']['end_year']}.pdf";

        return $pdf->download($fileName);
    }
}