<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\DividendService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Laravel\Sanctum\PersonalAccessToken;

class DividendController extends Controller
{
    protected DividendService $dividendService;

    public function __construct(DividendService $dividendService)
    {
        $this->dividendService = $dividendService;
    }

    /**
     * Validasi autentikasi untuk download / export (Sanctum session, Bearer header, atau query token)
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
            $accessToken = PersonalAccessToken::findToken($token);
            if ($accessToken) {
                return true;
            }
        }

        return false;
    }

    /**
     * Pratinjau Kalkulasi Pembagian Deviden Buku Biru
     * Endpoint: GET /api/manager/dividends/preview
     */
    public function preview(Request $request): JsonResponse
    {
        try {
            $month      = (int) ($request->input('month') ?? $request->input('bulan') ?? now()->month);
            $year       = (int) ($request->input('year') ?? $request->input('tahun') ?? now()->year);
            $percentage = (float) ($request->input('percentage') ?? $request->input('persen') ?? DividendService::DEFAULT_PERCENTAGE);

            if ($percentage < 0 || $percentage > 100) {
                return response()->json([
                    'success' => false,
                    'status'  => 'error',
                    'message' => 'Persentase deviden harus berada di antara 0% dan 100%.',
                ], 422);
            }

            $memberId = $request->input('member_id') ?? $request->input('id');
            if (!$memberId && $request->filled('member_number')) {
                $memberId = \App\Models\Member::where('member_number', $request->input('member_number'))->value('id');
            }
            $memberId = $memberId ? (int) $memberId : null;

            $cacheKey = $memberId 
                ? "dividend_preview_member_{$memberId}_{$month}_{$year}_" . round($percentage, 2)
                : "dividend_preview_global_{$month}_{$year}_" . round($percentage, 2);

            $data = Cache::remember($cacheKey, 300, function () use ($month, $year, $percentage, $memberId) {
                return $this->dividendService->preview($month, $year, $percentage, $memberId);
            });

            return response()->json([
                'success' => true,
                'status'  => 'success',
                'message' => 'Preview kalkulasi deviden Buku Biru berhasil dimuat.',
                'data'    => $data,
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'status'  => 'error',
                'message' => 'Gagal memuat preview deviden: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Status Pembagian SHU & Deviden 12 Bulan (Juni s/d Mei)
     * Endpoint: GET /api/shu/monthly-status?year=2026
     */
    public function monthlyStatus(Request $request): JsonResponse
    {
        try {
            $fiscalYear = (int) ($request->query('year') ?? $request->query('fiscal_year') ?? $request->query('tahun') ?? now()->year);
            $startYear  = $fiscalYear - 1;
            $endYear    = $fiscalYear;

            $shortMonthNames = [
                6 => 'Jun', 7 => 'Jul', 8 => 'Agu', 9 => 'Sep', 10 => 'Okt', 11 => 'Nov', 12 => 'Des',
                1 => 'Jan', 2 => 'Feb', 3 => 'Mar', 4 => 'Apr', 5 => 'Mei'
            ];

            // 12 Bulan Buku: Juni (startYear) s/d Mei (endYear)
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

            $savedBenchmarks = \App\Models\MonthlyCooperativeBenchmark::where('fiscal_year', $fiscalYear)
                ->get()
                ->keyBy('month');

            $monthsData = [];
            $totalDistributedMonths = 0;
            $totalPendingMonths = 0;
            $totalDistributedAmount = 0.0;
            $totalShuNet = 0.0;

            foreach ($fiscalMonthsOrder as $fm) {
                $m = $fm['m'];
                $y = $fm['y'];
                $dates = $this->dividendService->resolveDates($m, $y);
                $saved = $savedBenchmarks->get($m);

                // Cek status distribusi di transactions atau shu_distributions
                $targetYm = $dates['target_ym'];
                $isDistributed = $this->dividendService->isAlreadyDistributed($targetYm, $m, $y);

                // Ambil laba bersih
                $autoProfit = $this->dividendService->calculateNetProfit($m, $y, false);
                $autoShu    = (float) ($autoProfit['shu_bersih'] ?? 0.0);
                $isManual   = ($saved && $saved->net_income !== null);
                $netIncome  = $isManual ? (float) $saved->net_income : $autoShu;
                $divPercent = $saved ? (float) $saved->dividend_allocation_percent : DividendService::DEFAULT_PERCENTAGE;

                // Total Saham Koperasi
                $totalShares = ($saved && $saved->total_coop_shares !== null)
                    ? (float) $saved->total_coop_shares
                    : $this->dividendService->getHistoricalCoopSharesAtDate($dates['cutoff_date'], $m);

                $lembarKoperasi = round($totalShares / 1000.0, 2);
                $danaDev25 = round($netIncome * ($divPercent / 100.0), 2);
                $hargaDevPerLembar = ($lembarKoperasi > 0 && $danaDev25 > 0) ? ($danaDev25 / $lembarKoperasi) : 0.0;

                // Info distribusi jika ada
                if ($isDistributed) {
                    $totalDistributedMonths++;
                    $totalDistributedAmount += $danaDev25;

                    $distTrx = \App\Models\Transaction::where('book_type', 'BUKU_BIRU')
                        ->where(function ($q) use ($targetYm, $m, $y) {
                            $q->where('receipt_number', 'like', 'DIV-' . $targetYm . '%')
                              ->orWhere('receipt_number', 'like', 'BM-DIV-' . $targetYm . '%')
                              ->orWhere(function ($sub) use ($m, $y) {
                                  $sub->where('category', 'bunga_saham')
                                      ->whereMonth('transaction_date', $m)
                                      ->whereYear('transaction_date', $y);
                              });
                        })
                        ->latest('id')
                        ->first();

                    $voucherNo = $distTrx ? ($distTrx->receipt_number ?: $distTrx->transaction_number) : $dates['default_voucher'];
                    $distributedAt = $distTrx ? $distTrx->created_at?->toIso8601String() : null;
                } else {
                    $totalPendingMonths++;
                    $voucherNo = $dates['default_voucher'];
                    $distributedAt = null;
                }

                $totalShuNet += $netIncome;

                $monthsData[] = [
                    'id'                          => $saved?->id,
                    'fiscal_year'                 => $fiscalYear,
                    'calendar_year'               => $y,
                    'month'                       => $m,
                    'month_name'                  => $shortMonthNames[$m],
                    'month_label'                 => \Carbon\Carbon::createFromDate($y, $m, 1)->locale('id')->isoFormat('MMMM YYYY'),
                    'cycle_start_date'            => $dates['start_date'],
                    'cycle_end_date'              => $dates['cutoff_date'],
                    'execution_date'              => $dates['execution_date'],
                    'net_profit'                  => round($netIncome, 2),
                    'net_income'                  => round($netIncome, 2),
                    'shu_25_percent'              => round($danaDev25, 2),
                    'dividend_pool'               => round($danaDev25, 2),
                    'percentage'                  => round($divPercent, 2),
                    'dividend_allocation_percent' => round($divPercent, 2),
                    'total_coop_shares'           => round($totalShares, 2),
                    'lembar_saham'                => $lembarKoperasi,
                    'total_lembar_koperasi'       => $lembarKoperasi,
                    'share_price'                 => round($hargaDevPerLembar, 6),
                    'harga_deviden_per_lembar'    => round($hargaDevPerLembar, 6),
                    'harga_saham_display'         => (int) round($hargaDevPerLembar),
                    'is_distributed'              => $isDistributed,
                    'status'                      => $isDistributed ? 'distributed' : 'pending',
                    'status_label'                => $isDistributed ? 'Selesai Dibagikan' : 'Belum Diproses',
                    'voucher_no'                  => $voucherNo,
                    'distributed_at'              => $distributedAt,
                    'is_manual_override'          => $isManual,
                    'notes'                       => $saved?->notes,
                ];
            }

            return response()->json([
                'success' => true,
                'status'  => 'success',
                'message' => "Status SHU bulanan tahun buku {$fiscalYear} berhasil dimuat.",
                'data'    => [
                    'fiscal_year'              => $fiscalYear,
                    'fiscal_period_label'      => "Juni {$startYear} - Mei {$endYear}",
                    'total_net_profit'         => round($totalShuNet, 2),
                    'total_distributed_amount' => round($totalDistributedAmount, 2),
                    'total_distributed_months' => $totalDistributedMonths,
                    'total_pending_months'     => $totalPendingMonths,
                    'months'                   => $monthsData,
                    'benchmarks'               => $monthsData,
                ],
            ], 200);
        } catch (\Exception $e) {
            Log::error('Gagal memuat status SHU bulanan: ' . $e->getMessage(), [
                'trace' => $e->getTraceAsString(),
            ]);
            return response()->json([
                'success' => false,
                'status'  => 'error',
                'message' => 'Gagal memuat status SHU bulanan: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Eksekusi Pembagian Deviden Buku Biru & Pencatatan Jurnal Bukti Memorial
     * Endpoint: POST /api/manager/dividends/distribute & POST /api/shu/distribute
     */
    public function distribute(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'voucher_no' => 'nullable|string|max:100',
            'month'      => 'nullable',
            'year'       => 'nullable',
            'fiscal_year'=> 'nullable',
            'net_profit' => 'nullable|numeric|min:0',
            'net_income' => 'nullable|numeric|min:0',
            'percentage' => 'nullable|numeric|min:0|max:100',
            'notes'      => 'nullable|string|max:255',
        ], [
            'percentage.min' => 'Persentase deviden minimal 0%.',
            'percentage.max' => 'Persentase deviden maksimal 100%.',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'status'  => 'error',
                'message' => $validator->errors()->first(),
                'errors'  => $validator->errors(),
            ], 422);
        }

        try {
            $rawMonth   = $request->input('month') ?? $request->input('bulan');
            $month      = $rawMonth ? $this->dividendService->parseMonth($rawMonth) : now()->month;
            $year       = (int) ($request->input('year') ?? $request->input('tahun') ?? now()->year);
            $fiscalYear = (int) ($request->input('fiscal_year') ?? (($month >= 6) ? ($year + 1) : $year));
            $percentage = (float) ($request->input('percentage') ?? $request->input('persen') ?? DividendService::DEFAULT_PERCENTAGE);
            
            $voucherNo  = trim((string) $request->input('voucher_no'));
            if (empty($voucherNo)) {
                $voucherNo = "BM-DIV-{$year}" . str_pad((string)$month, 2, '0', STR_PAD_LEFT);
            }
            
            $notes      = $request->input('notes');
            $user       = $request->user();
            $userId     = $user ? $user->id : null;

            // Jika ada input net_profit / net_income dari dialog, simpan/perbarui MonthlyCooperativeBenchmark
            if ($request->has('net_profit') || $request->has('net_income')) {
                $inputProfit = (float) ($request->input('net_profit') ?? $request->input('net_income') ?? 0);
                $calendarYear = ($month >= 6) ? ($fiscalYear - 1) : $fiscalYear;
                $dates = $this->dividendService->resolveDates($month, $calendarYear);
                
                \App\Models\MonthlyCooperativeBenchmark::updateOrCreate(
                    [
                        'fiscal_year' => $fiscalYear,
                        'month'       => $month,
                    ],
                    [
                        'cycle_start_date'            => $dates['start_date'],
                        'cycle_end_date'              => $dates['cutoff_date'],
                        'net_income'                  => $inputProfit,
                        'dividend_allocation_percent' => $percentage,
                        'updated_by'                  => $userId,
                    ]
                );
                \Illuminate\Support\Facades\Cache::flush();
            }

            $result = $this->dividendService->distribute($month, $year, $percentage, $voucherNo, $userId, $notes);

            return response()->json([
                'success' => true,
                'status'  => 'success',
                'message' => $result['message'],
                'data'    => $result,
            ], 200);
        } catch (\InvalidArgumentException $e) {
            return response()->json([
                'success' => false,
                'status'  => 'error',
                'message' => $e->getMessage(),
            ], 422);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'status'  => 'error',
                'message' => 'Gagal mendistribusikan deviden: ' . $e->getMessage(),
            ], 400);
        }
    }

    /**
     * Lembar Buku Saham & Rekapitulasi 1 Tahun Buku Anggota (Individual Statement)
     * Endpoint: GET /api/manager/members/{id}/dividend-statement
     */
    public function memberStatement(Request $request, $id): JsonResponse
    {
        try {
            $fiscalYear = $request->has('fiscal_year') ? (int) $request->input('fiscal_year') : null;
            $month      = $request->has('month') ? (int) $request->input('month') : null;
            $year       = $request->has('year') ? (int) $request->input('year') : null;

            $memberId   = (int) $id;
            $cacheKey   = "dividend_statement_{$memberId}_fy" . ($fiscalYear ?? 'null') . "_m" . ($month ?? 'null') . "_y" . ($year ?? 'null');

            $data = Cache::remember($cacheKey, 300, function () use ($memberId, $fiscalYear, $month, $year) {
                return $this->dividendService->getMemberDividendStatement($memberId, $fiscalYear, $month, $year);
            });

            return response()->json([
                'success' => true,
                'status'  => 'success',
                'message' => 'Lembar Buku Saham dan Deviden anggota berhasil dimuat.',
                'data'    => $data,
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'status'  => 'error',
                'message' => 'Gagal memuat Lembar Buku Saham anggota: ' . $e->getMessage(),
            ], 404);
        }
    }

    /**
     * Ekspor Cetak PDF Lembar Buku Saham & Deviden Anggota (Individual Statement PDF)
     * Endpoint: GET /api/manager/members/{id}/dividend-statement/export-pdf
     */
    public function exportMemberStatementPdf(Request $request, $id)
    {
        ini_set('memory_limit', '1024M');
        set_time_limit(300);

        if (!$this->validateExportAuth($request)) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthorized: Token autentikasi tidak valid atau telah kadaluarsa.'
            ], 401);
        }

        try {
            $fiscalYear = $request->has('fiscal_year') ? (int) $request->input('fiscal_year') : null;
            $month      = $request->has('month') ? (int) $request->input('month') : null;
            $year       = $request->has('year') ? (int) $request->input('year') : null;

            return $this->dividendService->exportMemberStatementPdf((int) $id, $fiscalYear, $month, $year);
        } catch (\Exception $e) {
            Log::error('Gagal export PDF Lembar Saham Anggota: ' . $e->getMessage(), [
                'file'  => $e->getFile(),
                'line'  => $e->getLine(),
                'trace' => $e->getTraceAsString(),
            ]);
            return response()->json([
                'success' => false,
                'message' => 'Gagal mencetak Lembar Saham Anggota PDF: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Ekspor Cetak PDF Laporan Pembagian Deviden Buku Biru (Kolektif)
     * Endpoint: GET /api/manager/dividends/export-pdf
     */
    public function exportPdf(Request $request)
    {
        ini_set('memory_limit', '1024M');
        set_time_limit(300);

        if (!$this->validateExportAuth($request)) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthorized: Token autentikasi tidak valid atau telah kadaluarsa.'
            ], 401);
        }

        try {
            $month      = (int) ($request->input('month') ?? $request->input('bulan') ?? now()->month);
            $year       = (int) ($request->input('year') ?? $request->input('tahun') ?? now()->year);
            $percentage = (float) ($request->input('percentage') ?? $request->input('persen') ?? DividendService::DEFAULT_PERCENTAGE);

            return $this->dividendService->exportPdf($month, $year, $percentage);
        } catch (\Exception $e) {
            Log::error('Gagal export PDF Laporan Deviden: ' . $e->getMessage(), [
                'file'  => $e->getFile(),
                'line'  => $e->getLine(),
                'trace' => $e->getTraceAsString(),
            ]);
            return response()->json([
                'success' => false,
                'message' => 'Gagal mencetak Laporan Deviden PDF: ' . $e->getMessage(),
            ], 500);
        }
    }
}
