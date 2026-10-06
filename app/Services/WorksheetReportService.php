<?php

namespace App\Services;

use App\Models\ChartOfAccount;
use App\Models\JournalDetail;
use App\Models\InitialAccountBalance;
use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class WorksheetReportService
{
    /**
     * Generate Neraca Lajur (Worksheet Report / 10-Column & 12-Column Trial Balance)
     * Formulasi 100% identik dengan Master Excel Koperasi CUM Pelita:
     * 
     * 1. Saldo Awal (Initial Balance): initial_debit & initial_credit
     * 2. Penyesuaian / Mutasi: adjustment_debit & adjustment_credit
     * 3. Percobaan (Trial Balance):
     *    - trial_debit = initial_debit + adjustment_debit
     *    - trial_credit = initial_credit + adjustment_credit
     * 4. Pemisahan Rugi Laba & Neraca:
     *    - Harta (1xxx / ASSET): neraca_debit = trial_debit - trial_credit
     *    - Kewajiban (2xxx) & Ekuitas (3xxx): neraca_credit = trial_credit - trial_debit
     *    - Pendapatan (4xxx / REVENUE): rugi_laba_credit = trial_credit - trial_debit
     *    - Beban (5xxx / 7xxx / EXPENSE): rugi_laba_debit = trial_debit - trial_credit
     * 5. Ikhtisar R/L & Laba Periode (9900):
     *    - SHU / Net Income = Total Rugi Laba (Kredit) - Total Rugi Laba (Debit)
     *    - Masuk ke baris 9900: Debit di Rugi Laba & Kredit di Neraca
     * 
     * @param string|null $startDate Format: Y-m-d
     * @param string|null $endDate   Format: Y-m-d
     * @param string|null $periodLabel Label deskriptif periode
     * @return array
     */
    public function generateWorksheet(?string $startDate = null, ?string $endDate = null, ?string $periodLabel = null): array
    {
        if ($startDate) {
            $startDate = date('Y-m-d', strtotime($startDate));
        }
        if ($endDate) {
            $endDate = date('Y-m-d', strtotime($endDate));
        }

        // Cache hasil Worksheet untuk periode lampau (6 jam) atau periode berjalan (10 menit)
        $isPast = $endDate && ($endDate < date('Y-m-d'));
        $ttl = $isPast ? now()->addHours(6) : now()->addMinutes(10);
        $cacheVersion = Cache::get('worksheet_cache_version', '1');
        $cacheKey = "worksheet_{$startDate}_{$endDate}_{$cacheVersion}";

        return Cache::remember($cacheKey, $ttl, function () use ($startDate, $endDate, $periodLabel) {
            return $this->computeWorksheet($startDate, $endDate, $periodLabel);
        });
    }

    /**
     * Hitung Worksheet / Neraca Lajur
     */
    protected function computeWorksheet(?string $startDate = null, ?string $endDate = null, ?string $periodLabel = null): array
    {
        // 1. Ambil seluruh daftar COA terurut berdasarkan account_code
        $coaList = ChartOfAccount::select(['id', 'account_code', 'account_name', 'account_type', 'normal_balance', 'is_active'])
            ->orderBy('account_code')
            ->get();

        // 2. Integrasi Saldo Awal Cut-Off (initial_account_balances) secara langsung
        $cutoffDate = '2026-05-01';
        $savedBalances = collect();

        $targetCutoff = InitialAccountBalance::when($startDate, function ($q) use ($startDate) {
            $q->where('cutoff_date', '<=', $startDate);
        })->max('cutoff_date');

        if (!$targetCutoff) {
            $targetCutoff = InitialAccountBalance::max('cutoff_date');
        }

        if ($targetCutoff) {
            $cutoffDate = date('Y-m-d', strtotime($targetCutoff));
            $savedBalances = InitialAccountBalance::where('cutoff_date', $cutoffDate)
                ->get()
                ->keyBy('account_code');
        }

        // Helper filter untuk mengecualikan transaksi migrasi saldo awal pembantu anggota (KM-IMP-...)
        // agar tidak mendobelkan saldo kas dan simpanan pada Neraca Lajur induk
        $filterNonMigration = function ($q) {
            $q->where(function ($sub) {
                $sub->whereNull('transactions.id')
                    ->orWhere(function ($t) {
                        $t->whereNotIn('transactions.type', ['migration', 'import', 'migrasi'])
                          ->where(function ($rn) {
                              $rn->whereNull('transactions.receipt_number')
                                 ->orWhere('transactions.receipt_number', 'not like', 'KM-IMP%');
                          });
                    });
            })
            ->where(function ($sub) {
                $sub->whereNull('journal_entries.voucher_number')
                    ->orWhere('journal_entries.voucher_number', 'not like', 'KM-IMP%');
            });
        };

        // 3. Query Agregasi Mutasi Historis Sebelum startDate (Murni Transaksi Operasional)
        // a. Mutasi dari Cut-Off Date s/d < startDate (jika startDate > cutoffDate)
        $priorFromCutoff = collect();
        if ($startDate && $savedBalances->isNotEmpty() && $startDate > $cutoffDate) {
            $priorFromCutoff = JournalDetail::query()
                ->join('journal_entries', 'journal_details.journal_entry_id', '=', 'journal_entries.id')
                ->leftJoin('transactions', 'journal_entries.transaction_id', '=', 'transactions.id')
                ->whereDate('journal_entries.entry_date', '>=', $cutoffDate)
                ->whereDate('journal_entries.entry_date', '<', $startDate)
                ->where($filterNonMigration)
                ->select(
                    'journal_details.account_id',
                    DB::raw('COALESCE(SUM(journal_details.debit), 0) as total_debit'),
                    DB::raw('COALESCE(SUM(journal_details.credit), 0) as total_credit')
                )
                ->groupBy('journal_details.account_id')
                ->get()
                ->keyBy('account_id');
        }

        // b. Mutasi keseluruhan sebelum startDate (fallback untuk akun non-cutoff atau filter < cutoffDate)
        $priorBeforeStart = collect();
        if ($startDate) {
            $priorBeforeStart = JournalDetail::query()
                ->join('journal_entries', 'journal_details.journal_entry_id', '=', 'journal_entries.id')
                ->leftJoin('transactions', 'journal_entries.transaction_id', '=', 'transactions.id')
                ->whereDate('journal_entries.entry_date', '<', $startDate)
                ->where($filterNonMigration)
                ->select(
                    'journal_details.account_id',
                    DB::raw('COALESCE(SUM(journal_details.debit), 0) as total_debit'),
                    DB::raw('COALESCE(SUM(journal_details.credit), 0) as total_credit')
                )
                ->groupBy('journal_details.account_id')
                ->get()
                ->keyBy('account_id');
        }

        // 4. Hitung Mutasi Penyesuaian Periode (Antara startDate dan endDate) - HANYA Mutasi Operasional Riil
        $mutasiQuery = JournalDetail::query()
            ->join('journal_entries', 'journal_details.journal_entry_id', '=', 'journal_entries.id')
            ->leftJoin('transactions', 'journal_entries.transaction_id', '=', 'transactions.id');

        if ($startDate && $endDate) {
            $mutasiQuery->whereDate('journal_entries.entry_date', '>=', $startDate)
                        ->whereDate('journal_entries.entry_date', '<=', $endDate);
        } elseif ($startDate) {
            $mutasiQuery->whereDate('journal_entries.entry_date', '>=', $startDate);
        } elseif ($endDate) {
            $mutasiQuery->whereDate('journal_entries.entry_date', '<=', $endDate);
        }

        $mutasiQuery->where($filterNonMigration);

        $mutasi = $mutasiQuery
            ->select(
                'journal_details.account_id',
                DB::raw('COALESCE(SUM(journal_details.debit), 0) as total_debit'),
                DB::raw('COALESCE(SUM(journal_details.credit), 0) as total_credit')
            )
            ->groupBy('journal_details.account_id')
            ->get()
            ->keyBy('account_id');

        $accounts = [];

        // Inisialisasi akumulator subtotal
        $subtotal = [
            'nsa_debit'          => 0.0,
            'nsa_credit'         => 0.0,
            'adj_debit'          => 0.0,
            'adj_credit'         => 0.0,
            'trial_debit'        => 0.0,
            'trial_credit'       => 0.0,
            'lr_debit'           => 0.0,
            'lr_credit'          => 0.0,
            'neraca_debit'       => 0.0,
            'neraca_credit'      => 0.0,

            // Alias akumulator
            'initial_debit'      => 0.0,
            'initial_credit'     => 0.0,
            'adjustment_debit'   => 0.0,
            'adjustment_credit'  => 0.0,
            'rugi_laba_debit'    => 0.0,
            'rugi_laba_credit'   => 0.0,
        ];

        foreach ($coaList as $coa) {
            $codePrefix = substr((string) $coa->account_code, 0, 1);
            $accType    = strtoupper((string) $coa->account_type);

            // Identifikasi Akun Nominal (Pendapatan 4xxx & Beban 5xxx-7xxx)
            $isNominalAccount = in_array($codePrefix, ['4', '5', '6', '7']) 
                || in_array($accType, ['REVENUE', 'EXPENSE', 'INCOME', 'BIAYA', 'BEBAN', 'PENDAPATAN']);

            // --- 1. RUMUS NERACA SALDO AWAL (NSA) BERSIH (NET BALANCE) MURNI MASTER SALDO AWAL ---
            $initDebit  = 0.0;
            $initCredit = 0.0;

            if ($isNominalAccount) {
                // Akun Nominal Pendapatan & Beban (4xxx, 5xxx, 6xxx, 7xxx): Wajib 0 di awal setiap periode
                $initDebit  = 0.0;
                $initCredit = 0.0;
            } else {
                // Ambil saldo awal cut-off resmi dari Master Saldo Awal Koperasi (initial_account_balances)
                $cutoffRec    = $savedBalances->get($coa->account_code);
                $cutOffDebit  = (float) ($cutoffRec->debit ?? 0);
                $cutOffCredit = (float) ($cutoffRec->credit ?? 0);

                // Hitung mutasi historis operasional sebelum startDate
                $histDebit  = 0.0;
                $histCredit = 0.0;

                if ($startDate) {
                    if ($savedBalances->isNotEmpty() && $startDate > $cutoffDate) {
                        // Mutasi historis operasional kumulatif dari cutoff_date s/d < startDate
                        $histDebit  = (float) ($priorFromCutoff[$coa->id]->total_debit ?? 0);
                        $histCredit = (float) ($priorFromCutoff[$coa->id]->total_credit ?? 0);
                    } elseif ($savedBalances->isEmpty() || $startDate < $cutoffDate) {
                        // Fallback jika tidak ada record cut-off atau filter tanggal < cutoffDate
                        $histDebit  = (float) ($priorBeforeStart[$coa->id]->total_debit ?? 0);
                        $histCredit = (float) ($priorBeforeStart[$coa->id]->total_credit ?? 0);
                        if ($startDate < $cutoffDate) {
                            $cutOffDebit  = 0.0;
                            $cutOffCredit = 0.0;
                        }
                    }
                }

                // Kalkulasi Saldo Awal Bersih (NSA) sesuai sifat normal akun
                if ($codePrefix === '1' || ($coa->normal_balance === 'DEBIT' && !in_array($codePrefix, ['2', '3']))) {
                    // Akun Aset / Harta (1xxx)
                    $netAwal = ($cutOffDebit - $cutOffCredit) + ($histDebit - $histCredit);
                    $initDebit  = $netAwal >= 0 ? $netAwal : 0.0;
                    $initCredit = $netAwal < 0 ? abs($netAwal) : 0.0;
                } else {
                    // Akun Kewajiban, Ekuitas, & Akumulasi (2xxx, 3xxx)
                    $netAwal = ($cutOffCredit - $cutOffDebit) + ($histCredit - $histDebit);
                    $initCredit = $netAwal >= 0 ? $netAwal : 0.0;
                    $initDebit  = $netAwal < 0 ? abs($netAwal) : 0.0;
                }
            }

            // --- 2. RUMUS PENYESUAIAN / MUTASI PERIODE (ADJ) ---
            $adjDebit  = (float) ($mutasi[$coa->id]->total_debit ?? 0);
            $adjCredit = (float) ($mutasi[$coa->id]->total_credit ?? 0);

            // Skip akun tanpa mutasi dan tanpa saldo awal sama sekali
            if ($initDebit == 0 && $initCredit == 0 && $adjDebit == 0 && $adjCredit == 0) {
                continue;
            }

            // --- 3. RUMUS NERACA PERCOBAAN (TRIAL BALANCE / TB) ---
            $trialDebit  = (float) round($initDebit + $adjDebit, 2);
            $trialCredit = (float) round($initCredit + $adjCredit, 2);

            // --- 4. PEMISAHAN RUGI LABA (LR) VS NERACA ---
            $lrDebit  = 0.0;
            $lrCredit = 0.0;
            $nDebit   = 0.0;
            $nCredit  = 0.0;

            // a. Akun Harta (1xxx / ASSET):
            if ($codePrefix === '1' || $accType === 'ASSET') {
                $netAsset = $trialDebit - $trialCredit;
                if ($netAsset >= 0) {
                    $nDebit = $netAsset;
                } else {
                    $nCredit = abs($netAsset);
                }
            }
            // b. Akun Kewajiban (2xxx / LIABILITY) & Ekuitas / Akumulasi (3xxx / EQUITY):
            elseif (in_array($codePrefix, ['2', '3']) || in_array($accType, ['LIABILITY', 'EQUITY'])) {
                $netLiab = $trialCredit - $trialDebit;
                if ($netLiab >= 0) {
                    $nCredit = $netLiab;
                } else {
                    $nDebit = abs($netLiab);
                }
            }
            // c. Akun Pendapatan (4xxx / REVENUE):
            elseif ($codePrefix === '4' || $accType === 'REVENUE') {
                $netRev = $trialCredit - $trialDebit;
                if ($netRev >= 0) {
                    $lrCredit = $netRev;
                } else {
                    $lrDebit = abs($netRev);
                }
            }
            // d. Akun Beban (5xxx / 6xxx / 7xxx / EXPENSE):
            elseif (in_array($codePrefix, ['5', '6', '7']) || $accType === 'EXPENSE') {
                $netExp = $trialDebit - $trialCredit;
                if ($netExp >= 0) {
                    $lrDebit = $netExp;
                } else {
                    $lrCredit = abs($netExp);
                }
            }

            // Akumulasi Subtotal
            $subtotal['nsa_debit']          += $initDebit;
            $subtotal['nsa_credit']         += $initCredit;
            $subtotal['adj_debit']          += $adjDebit;
            $subtotal['adj_credit']         += $adjCredit;
            $subtotal['trial_debit']        += $trialDebit;
            $subtotal['trial_credit']       += $trialCredit;
            $subtotal['lr_debit']           += $lrDebit;
            $subtotal['lr_credit']          += $lrCredit;
            $subtotal['neraca_debit']       += $nDebit;
            $subtotal['neraca_credit']      += $nCredit;

            $subtotal['initial_debit']      += $initDebit;
            $subtotal['initial_credit']     += $initCredit;
            $subtotal['adjustment_debit']   += $adjDebit;
            $subtotal['adjustment_credit']  += $adjCredit;
            $subtotal['rugi_laba_debit']    += $lrDebit;
            $subtotal['rugi_laba_credit']   += $lrCredit;

            $accounts[] = [
                'account_code'       => $coa->account_code,
                'account_name'       => $coa->account_name,
                'account_type'       => $coa->account_type,
                'normal_balance'     => $coa->normal_balance,

                // 10 Kolom Baku Neraca Lajur (Worksheet)
                'nsa_debit'          => $initDebit,
                'nsa_credit'         => $initCredit,
                'adj_debit'          => $adjDebit,
                'adj_credit'         => $adjCredit,
                'trial_debit'        => $trialDebit,
                'trial_credit'       => $trialCredit,
                'lr_debit'           => $lrDebit,
                'lr_credit'          => $lrCredit,
                'neraca_debit'       => $nDebit,
                'neraca_credit'      => $nCredit,

                // Format Kolom Alias Kompatibilitas Excel & Flutter
                'initial_debit'      => $initDebit,
                'initial_credit'     => $initCredit,
                'awal_debit'         => $initDebit,
                'awal_credit'        => $initCredit,

                'adjustment_debit'   => $adjDebit,
                'adjustment_credit'  => $adjCredit,
                'mutasi_debit'       => $adjDebit,
                'mutasi_credit'      => $adjCredit,

                'percobaan_debit'    => $trialDebit,
                'percobaan_credit'   => $trialCredit,

                'rugi_laba_debit'    => $lrDebit,
                'rugi_laba_credit'   => $lrCredit,
            ];
        }

        // --- 5. RUMUS IKHTISAR RUGI LABA & LABA PERIODE (SHU BERJALAN) ---
        // Net Income / SHU = Total Rugi Laba (Kredit) - Total Rugi Laba (Debit)
        $netIncome = round($subtotal['lr_credit'] - $subtotal['lr_debit'], 2);

        $ikhtisarLrDebit  = $netIncome >= 0 ? $netIncome : 0.0;
        $ikhtisarLrCredit = $netIncome < 0 ? abs($netIncome) : 0.0;

        $neracaLabaDebit  = $netIncome < 0 ? abs($netIncome) : 0.0;
        $neracaLabaCredit = $netIncome >= 0 ? $netIncome : 0.0;

        // Tambahkan baris sistem 9900 Ikhtisar R/L & Laba Periode Berjalan
        $ikhtisarRow = [
            'account_code'       => '9900',
            'account_name'       => 'Ikhtisar R/L & Laba Periode (SHU)',
            'account_type'       => 'EQUITY',
            'normal_balance'     => 'CREDIT',
            'is_ikhtisar_rl'     => true,
            'is_system_row'      => true,

            'nsa_debit'          => 0.0,
            'nsa_credit'         => 0.0,
            'adj_debit'          => 0.0,
            'adj_credit'         => 0.0,
            'trial_debit'        => 0.0,
            'trial_credit'       => 0.0,
            'lr_debit'           => $ikhtisarLrDebit,
            'lr_credit'          => $ikhtisarLrCredit,
            'neraca_debit'       => $neracaLabaDebit,
            'neraca_credit'      => $neracaLabaCredit,

            'initial_debit'      => 0.0,
            'initial_credit'     => 0.0,
            'awal_debit'         => 0.0,
            'awal_credit'        => 0.0,
            'adjustment_debit'   => 0.0,
            'adjustment_credit'  => 0.0,
            'mutasi_debit'       => 0.0,
            'mutasi_credit'      => 0.0,
            'percobaan_debit'    => 0.0,
            'percobaan_credit'   => 0.0,
            'rugi_laba_debit'    => $ikhtisarLrDebit,
            'rugi_laba_credit'   => $ikhtisarLrCredit,
        ];

        $accountsWithIkhtisar = $accounts;
        $accountsWithIkhtisar[] = $ikhtisarRow;

        // Grand Total Akhir yang Balance Sempurna
        $totalLrDebit     = round($subtotal['lr_debit'] + $ikhtisarLrDebit, 2);
        $totalLrCredit    = round($subtotal['lr_credit'] + $ikhtisarLrCredit, 2);
        $totalNeracaDebit = round($subtotal['neraca_debit'] + $neracaLabaDebit, 2);
        $totalNeracaCredit = round($subtotal['neraca_credit'] + $neracaLabaCredit, 2);

        $isBalanced = abs($subtotal['trial_debit'] - $subtotal['trial_credit']) < 0.05
            || (abs($subtotal['adj_debit'] - $subtotal['adj_credit']) < 0.05 && abs($totalLrDebit - $totalLrCredit) < 0.05);

        $grandTotal = [
            'total_nsa_debit'          => round($subtotal['nsa_debit'], 2),
            'total_nsa_credit'         => round($subtotal['nsa_credit'], 2),
            'total_initial_debit'      => round($subtotal['nsa_debit'], 2),
            'total_initial_credit'     => round($subtotal['nsa_credit'], 2),
            'total_awal_debit'         => round($subtotal['nsa_debit'], 2),
            'total_awal_credit'        => round($subtotal['nsa_credit'], 2),

            'total_adj_debit'          => round($subtotal['adj_debit'], 2),
            'total_adj_credit'         => round($subtotal['adj_credit'], 2),
            'total_adjustment_debit'   => round($subtotal['adj_debit'], 2),
            'total_adjustment_credit'  => round($subtotal['adj_credit'], 2),
            'total_mutasi_debit'       => round($subtotal['adj_debit'], 2),
            'total_mutasi_credit'      => round($subtotal['adj_credit'], 2),

            'total_trial_debit'        => round($subtotal['trial_debit'], 2),
            'total_trial_credit'       => round($subtotal['trial_credit'], 2),
            'total_percobaan_debit'    => round($subtotal['trial_debit'], 2),
            'total_percobaan_credit'   => round($subtotal['trial_credit'], 2),

            'subtotal_lr_debit'        => round($subtotal['lr_debit'], 2),
            'subtotal_lr_credit'       => round($subtotal['lr_credit'], 2),
            'subtotal_rugi_laba_debit'  => round($subtotal['lr_debit'], 2),
            'subtotal_rugi_laba_credit' => round($subtotal['lr_credit'], 2),

            'subtotal_neraca_debit'     => round($subtotal['neraca_debit'], 2),
            'subtotal_neraca_credit'    => round($subtotal['neraca_credit'], 2),

            'total_lr_debit'           => $totalLrDebit,
            'total_lr_credit'          => $totalLrCredit,
            'total_rugi_laba_debit'    => $totalLrDebit,
            'total_rugi_laba_credit'   => $totalLrCredit,

            'total_neraca_debit'       => $totalNeracaDebit,
            'total_neraca_credit'      => $totalNeracaCredit,

            'net_income'               => $netIncome,
            'shu_berjalan'             => $netIncome,
            'laba_rugi_bersih'         => $netIncome,
            'is_balanced'              => $isBalanced,
        ];

        return [
            'period' => [
                'start_date'   => $startDate,
                'end_date'     => $endDate,
                'period_label' => $periodLabel,
            ],
            'accounts'          => $accountsWithIkhtisar,
            'pure_accounts'     => $accounts,
            'ikhtisar_rl_row'   => $ikhtisarRow,
            'subtotal'          => $subtotal,
            'summary'           => $grandTotal,
        ];
    }

    /**
     * Resolve start_date and end_date from flexible inputs (dates, month/year, period, week M1-M5).
     *
     * Siklus Mingguan Koperasi (M1 - M5):
     * - M1: Tanggal 01 s/d 07
     * - M2: Tanggal 08 s/d 14
     * - M3: Tanggal 15 s/d 21
     * - M4: Tanggal 22 s/d 28
     * - M5: Tanggal 29 s/d Akhir Bulan
     *
     * @param string|null $startDate
     * @param string|null $endDate
     * @param string|null $period
     * @param int|string|null $month
     * @param int|string|null $year
     * @param int|string|null $week
     * @return array{0: ?string, 1: ?string, 2: string} [startDate, endDate, periodLabel]
     */
    public static function resolvePeriodDates(?string $startDate = null, ?string $endDate = null, ?string $period = null, $month = null, $year = null, $week = null): array
    {
        // 1. Jika tanggal awal dan akhir diberikan langsung secara eksplisit
        if ($startDate && $endDate) {
            $sDate = date('Y-m-d', strtotime($startDate));
            $eDate = date('Y-m-d', strtotime($endDate));
            return [$sDate, $eDate, "{$sDate} s/d {$eDate}"];
        }

        // 2. Ekstrak week/minggu dari string period jika ada (misal: '2026-09-M1', '2026-09 M1', 'September 2026 M1')
        if ($period && !$week) {
            if (preg_match('/[MmWw](\d)/i', $period, $wMatch)) {
                $week = (int) $wMatch[1];
            }
            if (preg_match('/(\d{4})[-_\s](\d{1,2})/', $period, $myMatch)) {
                $year  = (int) $myMatch[1];
                $month = (int) $myMatch[2];
            }
        }

        // 3. Ekstrak month dan year dari nama bulan bahasa indonesia / inggris jika period berupa teks ("September 2026 M1")
        if ($period && (!$month || !$year)) {
            $monthNames = [
                'januari' => 1, 'january' => 1, 'jan' => 1,
                'februari' => 2, 'february' => 2, 'feb' => 2,
                'maret' => 3, 'march' => 3, 'mar' => 3,
                'april' => 4, 'apr' => 4,
                'mei' => 5, 'may' => 5,
                'juni' => 6, 'june' => 6, 'jun' => 6,
                'juli' => 7, 'july' => 7, 'jul' => 7,
                'agustus' => 8, 'august' => 8, 'agu' => 8, 'aug' => 8,
                'september' => 9, 'sep' => 9, 'sept' => 9,
                'oktober' => 10, 'october' => 10, 'okt' => 10, 'oct' => 10,
                'november' => 11, 'nov' => 11,
                'desember' => 12, 'december' => 12, 'des' => 12, 'dec' => 12,
            ];
            $lowerP = strtolower($period);
            foreach ($monthNames as $name => $mNum) {
                if (str_contains($lowerP, $name)) {
                    $month = $mNum;
                    break;
                }
            }
            if (preg_match('/\b(20\d{2})\b/', $period, $yMatch)) {
                $year = (int) $yMatch[1];
            }
        }

        // 4. Standarisasi week jika berupa string "M1", "M2", dll.
        if ($week) {
            if (is_string($week) && preg_match('/(\d)/', $week, $wm)) {
                $week = (int) $wm[1];
            } else {
                $week = (int) $week;
            }
        }

        // 5. Jika ada month & year
        if ($month && $year) {
            $y = (int) $year;
            $m = (int) $month;
            $daysInMonth = (int) date('t', strtotime(sprintf('%04d-%02d-01', $y, $m)));

            if ($week) {
                switch ($week) {
                    case 1:
                        $sDate = sprintf('%04d-%02d-01', $y, $m);
                        $eDate = sprintf('%04d-%02d-07', $y, $m);
                        $label = sprintf('%04d-%02d M1 (01 s/d 07)', $y, $m);
                        break;
                    case 2:
                        $sDate = sprintf('%04d-%02d-08', $y, $m);
                        $eDate = sprintf('%04d-%02d-14', $y, $m);
                        $label = sprintf('%04d-%02d M2 (08 s/d 14)', $y, $m);
                        break;
                    case 3:
                        $sDate = sprintf('%04d-%02d-15', $y, $m);
                        $eDate = sprintf('%04d-%02d-21', $y, $m);
                        $label = sprintf('%04d-%02d M3 (15 s/d 21)', $y, $m);
                        break;
                    case 4:
                        $sDate = sprintf('%04d-%02d-22', $y, $m);
                        $eDate = sprintf('%04d-%02d-28', $y, $m);
                        $label = sprintf('%04d-%02d M4 (22 s/d 28)', $y, $m);
                        break;
                    case 5:
                    default:
                        $sDate = sprintf('%04d-%02d-29', $y, $m);
                        $eDate = sprintf('%04d-%02d-%02d', $y, $m, $daysInMonth);
                        $label = sprintf('%04d-%02d M5 (29 s/d %02d)', $y, $m, $daysInMonth);
                        break;
                }
                return [$sDate, $eDate, $label];
            }

            // Jika bulanan penuh
            $sDate = sprintf('%04d-%02d-01', $y, $m);
            $eDate = sprintf('%04d-%02d-%02d', $y, $m, $daysInMonth);
            $label = sprintf('%04d-%02d (Bulanan)', $y, $m);
            return [$sDate, $eDate, $label];
        }

        // 6. Jika period hanya YYYY-MM
        if ($period && preg_match('/^\d{4}-\d{2}$/', $period)) {
            $sDate = $period . '-01';
            $eDate = date('Y-m-t', strtotime($sDate));
            return [$sDate, $eDate, $period];
        }

        // 7. Default: periode akuntansi aktif (OPEN) atau bulan berjalan
        $activePeriod = \App\Models\AccountingPeriod::where('status', 'OPEN')->first();
        if ($activePeriod) {
            $sDate = $activePeriod->start_date ? date('Y-m-d', strtotime($activePeriod->start_date)) : date('Y-m-01');
            $eDate = $activePeriod->end_date ? date('Y-m-d', strtotime($activePeriod->end_date)) : date('Y-m-t');
            $label = $activePeriod->period_name ?? date('Y-m');
            return [$sDate, $eDate, $label];
        }

        $sDate = date('Y-m-01');
        $eDate = date('Y-m-t');
        return [$sDate, $eDate, date('Y-m')];
    }
}
