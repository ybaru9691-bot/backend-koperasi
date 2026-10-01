<?php

namespace App\Services;

use App\Models\Account;
use App\Models\ChartOfAccount;
use App\Models\Loan;
use App\Models\LoanInstallment;
use App\Models\Member;
use App\Models\Transaction;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class SavingsBalanceService
{
    /**
     * Mengambil ringkasan saldo terpadu koperasi (Single Source of Truth).
     * Terintegrasi langsung dengan Master Saldo Awal COA (initial_account_balances)
     * dan Mutasi Operasional Riil (Mengecualikan Voucher Migrasi KM-IMP).
     *
     * Digunakan bersama oleh Dashboard Admin, Dashboard Manajer, dan Laporan.
     *
     * @return array
     */
    public function getCoopSavingsSummary(): array
    {
        // 1. Total Anggota Aktif
        $totalMembers = Member::where('status', 'active')->count() ?? 0;

        // Ambil agregasi simpanan anggota dari tabel members
        $memberSavings = Member::where('status', 'active')
            ->selectRaw('
                SUM(COALESCE(principal_savings, 0)) as total_principal,
                SUM(COALESCE(mandatory_savings, 0)) as total_mandatory,
                SUM(COALESCE(voluntary_savings, 0)) as total_voluntary,
                SUM(COALESCE(daily_savings, 0)) as total_daily,
                SUM(COALESCE(principal_savings, 0) + COALESCE(mandatory_savings, 0)) as total_saham_tetap,
                SUM(COALESCE(principal_savings, 0) + COALESCE(mandatory_savings, 0) + COALESCE(voluntary_savings, 0)) as total_saham
            ')->first();

        $totalPrincipal  = (float) ($memberSavings->total_principal ?? 0);
        $totalMandatory  = (float) ($memberSavings->total_mandatory ?? 0);
        $totalSahamTetap = (float) ($memberSavings->total_saham_tetap ?? ($totalPrincipal + $totalMandatory));
        $memberVoluntary = (float) ($memberSavings->total_voluntary ?? 0);
        $memberDaily     = (float) ($memberSavings->total_daily ?? 0);

        // 2. Ambil Master Saldo Awal COA (Cut-Off 01 Mei 2026) dari initial_account_balances
        $savedBalances = collect();
        if (Schema::hasTable('initial_account_balances')) {
            $targetCutoff = DB::table('initial_account_balances')->max('cutoff_date');
            if ($targetCutoff) {
                $cutoffDate = date('Y-m-d', strtotime($targetCutoff));
                $savedBalances = DB::table('initial_account_balances')
                    ->where('cutoff_date', $cutoffDate)
                    ->get()
                    ->keyBy('account_code');
            }
        }

        $hasMasterInitial = $savedBalances->isNotEmpty();

        $initKas1000   = (float) ($savedBalances->get('1000')->debit ?? 0.0);
        $initBank1010  = (float) ($savedBalances->get('1010')->debit ?? 0.0);
        $initPiut1024  = (float) ($savedBalances->get('1024')->debit ?? 0.0);
        $initTanah1700 = (float) ($savedBalances->get('1700')->debit ?? 0.0);
        $initInv1741   = (float) ($savedBalances->get('1741')->debit ?? 0.0);
        $initAlat1743  = (float) ($savedBalances->get('1743')->debit ?? 0.0);
        $initBb2020    = (float) ($savedBalances->get('2020')->credit ?? ($totalSahamTetap + $memberVoluntary));
        $initBp2021    = (float) ($savedBalances->get('2021')->credit ?? $memberDaily);
        $initSos2034   = (float) ($savedBalances->get('2034')->credit ?? 0.0);
        $initDuka2038  = (float) ($savedBalances->get('2038')->credit ?? 0.0);

        // 3. Mutasi Jurnal Operasional Riil (Non-KM-IMP) per COA
        $targetCodes = ['1000', '1010', '1024', '1700', '1741', '1743', '2020', '2021', '2034', '2038'];
        $coaMutations = collect();
        if (Schema::hasTable('journal_details') && Schema::hasTable('journal_entries')) {
            $coaMutations = DB::table('journal_details')
                ->join('chart_of_accounts', 'journal_details.account_id', '=', 'chart_of_accounts.id')
                ->join('journal_entries', 'journal_details.journal_entry_id', '=', 'journal_entries.id')
                ->whereIn('chart_of_accounts.account_code', $targetCodes)
                ->where(function ($q) {
                    $q->whereNull('journal_entries.voucher_number')
                      ->orWhere(function ($vn) {
                          $vn->where('journal_entries.voucher_number', 'not like', 'KM-IMP%')
                             ->where('journal_entries.voucher_number', 'not like', 'KM-IMP-P%');
                      });
                })
                ->selectRaw('
                    chart_of_accounts.account_code,
                    chart_of_accounts.normal_balance,
                    COALESCE(SUM(journal_details.debit), 0) as total_debit,
                    COALESCE(SUM(journal_details.credit), 0) as total_credit
                ')
                ->groupBy('chart_of_accounts.account_code', 'chart_of_accounts.normal_balance')
                ->get()
                ->keyBy('account_code');
        }

        // Helper hitung saldo akhir per COA (Saldo Awal + Mutasi Normal)
        $calcBalance = function (string $code, float $initBalance, string $normal = 'DEBIT') use ($coaMutations) {
            $row = $coaMutations->get($code);
            $debit  = (float) ($row->total_debit ?? 0);
            $credit = (float) ($row->total_credit ?? 0);
            if (strtoupper($normal) === 'DEBIT') {
                return max(0.0, round($initBalance + ($debit - $credit), 2));
            } else {
                return max(0.0, round($initBalance + ($credit - $debit), 2));
            }
        };

        // 4. Perhitungan Saldo Riil Terpadu
        $kasLaci  = $calcBalance('1000', $initKas1000, 'DEBIT');
        $kasBank  = $calcBalance('1010', $initBank1010, 'DEBIT');
        $totalKasBank = round($kasLaci + $kasBank, 2);

        // Piutang Pinjaman (COA 1024)
        if ($hasMasterInitial) {
            $totalPiutang = $calcBalance('1024', $initPiut1024, 'DEBIT');
        } else {
            // Fallback module loans untuk unit test
            $totalLoans = (float) (Loan::whereIn('status', ['approved', 'disbursed', 'active'])->sum('amount') ?? 0);
            $totalPaidPrincipal = (float) (LoanInstallment::where('status', 'paid')->sum('principal_amount') ?? 0);
            $totalPiutang = max(0.0, $totalLoans - $totalPaidPrincipal);
        }

        // Simpanan Saham Buku Biru (COA 2020)
        $sahamEkuitas = $calcBalance('2020', $initBb2020, 'CREDIT');
        $voluntarySavings = $hasMasterInitial
            ? max(0.0, round($sahamEkuitas - $totalSahamTetap, 2))
            : $memberVoluntary;

        // Tabungan Harian Buku Putih (COA 2021)
        $totalTabunganHarian = $hasMasterInitial
            ? $calcBalance('2021', $initBp2021, 'CREDIT')
            : $memberDaily;

        // Dana Duka & Sosial (COA 2034 + 2038)
        $danaSosial = $calcBalance('2034', $initSos2034, 'CREDIT');
        $danaDuka   = $calcBalance('2038', $initDuka2038, 'CREDIT');
        $danaDukaSosial = round($danaSosial + $danaDuka, 2);

        // Aset Tetap Koperasi (1700 + 1741 + 1743)
        $asetTetap = $calcBalance('1700', $initTanah1700, 'DEBIT')
                   + $calcBalance('1741', $initInv1741, 'DEBIT')
                   + $calcBalance('1743', $initAlat1743, 'DEBIT');

        // Total Aset & Total Simpanan
        $totalAset    = round($totalKasBank + $totalPiutang + $asetTetap, 2);
        $totalSavings = round($sahamEkuitas + $totalTabunganHarian, 2);

        // 5. Mutasi Kas Riil Operasional (Non-KM-IMP) Bulan & Tahun Berjalan
        $currentMonth = (int) now()->month;
        $currentYear  = (int) now()->year;

        $nonMigrationTrxFilter = function ($q) {
            $q->where(function ($sub) {
                $sub->whereNull('receipt_number')
                    ->orWhere(function ($rn) {
                        $rn->where('receipt_number', 'not like', 'KM-IMP%')
                           ->where('receipt_number', 'not like', 'KM-IMP-P%');
                    });
            })->where(function ($sub) {
                $sub->whereNull('description')
                    ->orWhere(function ($desc) {
                        $desc->where('description', 'not like', '%Saldo Awal%')
                             ->where('description', 'not like', '%saldo awal%');
                    });
            });
        };

        $currentMonthSummary = Transaction::where('status', 'approved')
            ->whereMonth('transaction_date', $currentMonth)
            ->whereYear('transaction_date', $currentYear)
            ->where($nonMigrationTrxFilter)
            ->selectRaw("
                SUM(CASE WHEN type IN ('deposit', 'in', 'kas_masuk', 'KM') THEN amount ELSE 0 END) as km_month,
                SUM(CASE WHEN type IN ('withdrawal', 'out', 'kas_keluar', 'KK') THEN amount ELSE 0 END) as kk_month
            ")->first();

        $totalKMMonth = (float) ($currentMonthSummary->km_month ?? 0);
        $totalKKMonth = (float) ($currentMonthSummary->kk_month ?? 0);

        $currentYearSummary = Transaction::where('status', 'approved')
            ->whereYear('transaction_date', $currentYear)
            ->where($nonMigrationTrxFilter)
            ->selectRaw("
                SUM(CASE WHEN type IN ('deposit', 'in', 'kas_masuk', 'KM') THEN amount ELSE 0 END) as km_year,
                SUM(CASE WHEN type IN ('withdrawal', 'out', 'kas_keluar', 'KK') THEN amount ELSE 0 END) as kk_year
            ")->first();

        $totalKMYear = (float) ($currentYearSummary->km_year ?? 0);
        $totalKKYear = (float) ($currentYearSummary->kk_year ?? 0);

        $driver = DB::connection()->getDriverName();
        $monthExpr = ($driver === 'sqlite')
            ? "CAST(strftime('%m', transaction_date) AS INTEGER) as month_num"
            : "MONTH(transaction_date) as month_num";

        // 6. Cash Flow Trends Operasional Riil (Render fl_chart)
        $monthlyTrendData = DB::table('transactions')
            ->select(
                DB::raw($monthExpr),
                DB::raw("SUM(CASE WHEN type IN ('KM', 'deposit', 'in', 'kas_masuk') THEN amount ELSE 0 END) as total_km"),
                DB::raw("SUM(CASE WHEN type IN ('KK', 'withdrawal', 'out', 'kas_keluar') THEN amount ELSE 0 END) as total_kk")
            )
            ->whereYear('transaction_date', $currentYear)
            ->where('status', 'approved')
            ->where($nonMigrationTrxFilter)
            ->groupBy('month_num')
            ->get()
            ->keyBy('month_num');

        $monthsMap = [
            1 => 'Jan', 2 => 'Feb', 3 => 'Mar', 4 => 'Apr', 5 => 'Mei', 6 => 'Jun',
            7 => 'Jul', 8 => 'Agu', 9 => 'Sep', 10 => 'Okt', 11 => 'Nov', 12 => 'Des'
        ];

        $cashFlowTrends = [];
        for ($m = 1; $m <= 12; $m++) {
            $dbRow = $monthlyTrendData->get($m);
            $kmVal = (float) ($dbRow->total_km ?? 0);
            $kkVal = (float) ($dbRow->total_kk ?? 0);
            $cashFlowTrends[] = [
                'month'      => $monthsMap[$m],
                'month_num'  => $m,
                'cash_in'    => $kmVal,
                'cash_out'   => $kkVal,
                'kas_masuk'  => $kmVal,
                'kas_keluar' => $kkVal,
                'net_flow'   => $kmVal - $kkVal,
            ];
        }

        return [
            'total_members'                 => $totalMembers,
            'total_aset'                    => $totalAset,
            'total_kas_bank'                => $totalKasBank,
            'total_cash_bank'               => $totalKasBank,
            'kas_laci'                      => $kasLaci,
            'kas_bank'                      => $kasBank,
            'saldo_kas'                     => $kasLaci,
            'total_kas'                     => $totalKasBank,
            'permanent_capital'             => $totalSahamTetap,
            'total_saham_tetap'             => $totalSahamTetap,
            'saham_tetap'                   => $totalSahamTetap,
            'saham_ekuitas'                 => $sahamEkuitas,
            'total_saham_buku_biru'         => $sahamEkuitas,
            'total_principal'               => $totalPrincipal,
            'total_mandatory'               => $totalMandatory,
            'voluntary_savings'             => $voluntarySavings,
            'simpanan_sukarela'             => $voluntarySavings,
            'total_simpanan_sukarela'       => $voluntarySavings,
            'total_voluntary'               => $voluntarySavings,
            'daily_savings'                 => $totalTabunganHarian,
            'tabungan_harian'               => $totalTabunganHarian,
            'total_tabungan_harian'         => $totalTabunganHarian,
            'total_daily_savings'           => $totalTabunganHarian,
            'total_daily'                   => $totalTabunganHarian,
            'simpanan_bisa_ditarik'         => $totalTabunganHarian,
            'dana_duka_sosial'              => $danaDukaSosial,
            'cadangan_dana_duka'            => $danaDukaSosial,
            'total_savings'                 => $totalSavings,
            'total_loans'                   => $totalPiutang,
            'total_piutang'                 => $totalPiutang,
            'total_kas_masuk'               => $totalKMMonth,
            'total_kas_keluar'              => $totalKKMonth,
            'total_cash_in'                 => $totalKMYear,
            'total_cash_out'                => $totalKKYear,
            'total_kas_masuk_current_month'  => $totalKMMonth,
            'total_kas_keluar_current_month' => $totalKKMonth,
            'total_deposits'                => $totalKMYear,
            'total_withdrawals'             => $totalKKYear,
            'cash_flow_trends'              => $cashFlowTrends,
        ];
    }
}
