<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ChartOfAccount;
use App\Models\InitialAccountBalance;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class InitialBalanceController extends Controller
{
    /**
     * Daftar 17 Akun Baku Neraca Saldo Awal Cut-Off KSP CUM Pelita
     */
    protected function getInitialBalanceAccountsDefinition(): array
    {
        return [
            // DEBET (6 Akun Aset)
            '1000' => ['name' => 'Kas', 'type' => 'ASSET', 'category' => 'Kas & Bank', 'normal' => 'DEBIT'],
            '1010' => ['name' => 'BRI', 'type' => 'ASSET', 'category' => 'Kas & Bank', 'normal' => 'DEBIT'],
            '1024' => ['name' => 'Piutang', 'type' => 'ASSET', 'category' => 'Piutang Pinjaman', 'normal' => 'DEBIT'],
            '1700' => ['name' => 'Inventaris Tanah', 'type' => 'ASSET', 'category' => 'Aset Tetap', 'normal' => 'DEBIT'],
            '1741' => ['name' => 'Inventaris Perl.Kantor', 'type' => 'ASSET', 'category' => 'Aset Tetap', 'normal' => 'DEBIT'],
            '1743' => ['name' => 'Inventaris Kendaraan Kantor', 'type' => 'ASSET', 'category' => 'Aset Tetap', 'normal' => 'DEBIT'],

            // KREDIT (11 Akun Kewajiban, Kontra Aset & Ekuitas)
            '2020' => ['name' => 'Sw,Ss,Sp - Buku Biru', 'type' => 'LIABILITY', 'category' => 'Simpanan Saham', 'normal' => 'CREDIT'],
            '2021' => ['name' => 'Simp Harian - Buku Putih', 'type' => 'LIABILITY', 'category' => 'Simpanan Harian', 'normal' => 'CREDIT'],
            '2022' => ['name' => 'Simp Diakonia', 'type' => 'LIABILITY', 'category' => 'Simpanan', 'normal' => 'CREDIT'],
            '2032' => ['name' => 'Asuransi Investasi', 'type' => 'LIABILITY', 'category' => 'Dana-dana', 'normal' => 'CREDIT'],
            '2033' => ['name' => 'Dana Pendidikan/Pelatihan', 'type' => 'LIABILITY', 'category' => 'Dana-dana', 'normal' => 'CREDIT'],
            '2034' => ['name' => 'Dana Sosial', 'type' => 'LIABILITY', 'category' => 'Dana-dana', 'normal' => 'CREDIT'],
            '2038' => ['name' => 'Dana Duka', 'type' => 'LIABILITY', 'category' => 'Dana-dana', 'normal' => 'CREDIT'],
            '3101' => ['name' => 'Akumulasi Peny.Perl Kantor', 'type' => 'ASSET', 'category' => 'Akumulasi Penyusutan', 'normal' => 'CREDIT'],
            '3102' => ['name' => 'Akumulasi Penyusutan Kend Kantor', 'type' => 'ASSET', 'category' => 'Akumulasi Penyusutan', 'normal' => 'CREDIT'],
            '3010' => ['name' => 'Cadangan Modal Koperasi', 'type' => 'EQUITY', 'category' => 'Modal & Cadangan', 'normal' => 'CREDIT'],
            '3020' => ['name' => 'Ekuitas / Modal Koperasi', 'type' => 'EQUITY', 'category' => 'Modal & Cadangan', 'normal' => 'CREDIT'],
        ];
    }

    /**
     * Menampilkan daftar 17 COA Neraca Saldo Awal dan nilai Cut-Off
     * GET /api/initial-balances?cutoff_date=2026-05-01
     */
    public function index(Request $request): JsonResponse
    {
        try {
            $cutoffDate = $request->input('cutoff_date')
                ?? $request->input('date')
                ?? $request->input('cut_off_date')
                ?? '2026-05-01';

            // 1. Sinkronisasi master akun ke tabel chart_of_accounts jika belum ada
            $definedAccounts = $this->getInitialBalanceAccountsDefinition();
            foreach ($definedAccounts as $code => $info) {
                ChartOfAccount::firstOrCreate(
                    ['account_code' => $code],
                    [
                        'account_name'   => $info['name'],
                        'account_type'   => $info['type'],
                        'normal_balance' => $info['normal'],
                        'is_active'      => true,
                    ]
                );
            }

            // 2. Ambil data saldo awal tersimpan dari initial_account_balances
            $savedBalances = collect();
            if (\Illuminate\Support\Facades\Schema::hasTable('initial_account_balances')) {
                $savedBalances = InitialAccountBalance::where('cutoff_date', $cutoffDate)
                    ->get()
                    ->keyBy('account_code');

                if ($savedBalances->isEmpty()) {
                    // Fallback ke cutoff_date terbaru yang tersedia jika tidak ditemukan pada tanggal spesifik
                    $latestCutoff = InitialAccountBalance::max('cutoff_date');
                    if ($latestCutoff) {
                        $savedBalances = InitialAccountBalance::where('cutoff_date', $latestCutoff)
                            ->get()
                            ->keyBy('account_code');
                    }
                }
            }

            $isSaved = $savedBalances->isNotEmpty();
            $items = [];
            $totalDebit = 0.0;
            $totalCredit = 0.0;

            // 3. Render 17 akun baku dengan memprioritaskan nama asli di database chart_of_accounts
            foreach ($definedAccounts as $code => $info) {
                $saved = $savedBalances->get($code);
                $debit = $saved ? (float) $saved->debit : 0.0;
                $credit = $saved ? (float) $saved->credit : 0.0;

                $totalDebit += $debit;
                $totalCredit += $credit;

                // Prioritaskan nama akun dari database tabel chart_of_accounts
                $coa = ChartOfAccount::where('account_code', $code)->first();
                $accountName = ($coa && !empty($coa->account_name)) ? $coa->account_name : $info['name'];
                $normalBalance = ($coa && !empty($coa->normal_balance)) ? strtoupper((string) $coa->normal_balance) : $info['normal'];
                $accountType = ($coa && !empty($coa->account_type)) ? strtoupper((string) $coa->account_type) : $info['type'];

                // Nominal amount untuk sinkronisasi form Flutter
                $amount = ($normalBalance === 'DEBIT') ? $debit : $credit;
                if ($amount == 0.0 && ($debit > 0 || $credit > 0)) {
                    $amount = max($debit, $credit);
                }

                $items[] = [
                    'account_code'   => $code,
                    'code'           => $code,
                    'account_name'   => $accountName,
                    'name'           => $accountName,
                    'category'       => $info['category'],
                    'account_type'   => $accountType,
                    'normal_balance' => $normalBalance,
                    'normal'         => $normalBalance,
                    'amount'         => round($amount, 2),
                    'debit'          => round($debit, 2),
                    'credit'         => round($credit, 2),
                ];
            }

            $totalDebit = round($totalDebit, 2);
            $totalCredit = round($totalCredit, 2);
            $diff = round(abs($totalDebit - $totalCredit), 2);
            $isBalanced = ($diff < 1.0);

            return response()->json([
                'success' => true,
                'status'  => 'success',
                'message' => 'Daftar 17 akun saldo awal cut-off berhasil diambil',
                'data'    => [
                    'cutoff_date'   => $cutoffDate,
                    'total_debit'   => $totalDebit,
                    'total_credit'  => $totalCredit,
                    'difference'    => $diff,
                    'is_balanced'   => $isBalanced,
                    'is_saved'      => $isSaved,
                    'total_accounts'=> count($items),
                    'items'         => $items,
                    'accounts'      => $items,
                    'balances'      => $items,
                ],
            ], 200);

        } catch (\Throwable $e) {
            return response()->json([
                'success' => false,
                'status'  => 'error',
                'message' => 'Gagal memuat saldo awal: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Menyimpan atau memperbarui Saldo Awal Cut-Off
     * Mendukung format Flutter ('items' dengan 'amount') dan Web ('balances' dengan 'debit'/'credit')
     * POST /api/initial-balances
     */
    public function store(Request $request): JsonResponse
    {
        // 1. Validasi Input Dasar
        $validator = Validator::make($request->all(), [
            'cutoff_date' => 'required|date',
            'items'       => 'required_without:balances|array|min:1',
            'balances'    => 'required_without:items|array|min:1',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'status'  => 'error',
                'message' => 'Validasi input saldo awal gagal',
                'errors'  => $validator->errors(),
            ], 422);
        }

        $cutoffDate = date('Y-m-d', strtotime($request->input('cutoff_date')));
        $rawList = $request->input('items') ?? $request->input('balances') ?? [];

        // 2. Normalisasi Payload (Konversi items [amount + normal_balance] atau balances [debit/credit])
        $normalizedBalances = [];
        $totalDebit = 0.0;
        $totalCredit = 0.0;

        foreach ($rawList as $row) {
            $code = trim((string) ($row['account_code'] ?? $row['code'] ?? ''));
            if (empty($code)) {
                continue;
            }

            $normal = strtoupper(trim((string) ($row['normal_balance'] ?? $row['normal'] ?? '')));

            if (isset($row['debit']) || isset($row['credit'])) {
                $debit = round((float) ($row['debit'] ?? 0), 2);
                $credit = round((float) ($row['credit'] ?? 0), 2);
            } else {
                $amount = round((float) ($row['amount'] ?? 0), 2);
                if (empty($normal)) {
                    $coa = ChartOfAccount::where('account_code', $code)->first();
                    $normal = $coa ? strtoupper((string) $coa->normal_balance) : 'DEBIT';
                }

                if ($normal === 'DEBIT') {
                    $debit = $amount;
                    $credit = 0.0;
                } else {
                    $credit = $amount;
                    $debit = 0.0;
                }
            }

            $totalDebit += $debit;
            $totalCredit += $credit;

            $normalizedBalances[] = [
                'account_code' => $code,
                'debit'        => $debit,
                'credit'       => $credit,
            ];
        }

        $totalDebit = round($totalDebit, 2);
        $totalCredit = round($totalCredit, 2);
        $diff = round(abs($totalDebit - $totalCredit), 2);

        // 3. Pengecekan Keseimbangan (Balance Guard): Wajib Seimbang
        if ($diff >= 1.0) {
            return response()->json([
                'success' => false,
                'status'  => 'error',
                'message' => 'Total Debet dan Kredit belum seimbang!',
                'data'    => [
                    'cutoff_date'  => $cutoffDate,
                    'total_debit'  => $totalDebit,
                    'total_credit' => $totalCredit,
                    'difference'   => $diff,
                    'is_balanced'  => false,
                ]
            ], 422);
        }

        // 4. Simpan atau perbarui data saldo awal menggunakan updateOrCreate di dalam transaksi database
        DB::beginTransaction();
        try {
            $savedCount = 0;
            foreach ($normalizedBalances as $item) {
                InitialAccountBalance::updateOrCreate(
                    [
                        'cutoff_date'  => $cutoffDate,
                        'account_code' => $item['account_code'],
                    ],
                    [
                        'debit'  => $item['debit'],
                        'credit' => $item['credit'],
                    ]
                );
                $savedCount++;
            }

            DB::commit();

            return response()->json([
                'success' => true,
                'status'  => 'success',
                'message' => 'Saldo Awal Cut-Off Pembukuan berhasil disimpan & dijurnal otomatis!',
                'data'    => [
                    'cutoff_date'   => $cutoffDate,
                    'total_debit'   => $totalDebit,
                    'total_credit'  => $totalCredit,
                    'difference'    => $diff,
                    'is_balanced'   => true,
                    'saved_count'   => $savedCount,
                ]
            ], 200);

        } catch (\Throwable $e) {
            DB::rollBack();
            return response()->json([
                'success' => false,
                'status'  => 'error',
                'message' => 'Gagal menyimpan saldo awal: ' . $e->getMessage(),
            ], 500);
        }
    }
}