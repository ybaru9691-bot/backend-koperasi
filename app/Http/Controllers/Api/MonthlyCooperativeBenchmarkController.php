<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;   
use App\Models\MonthlyCooperativeBenchmark;
use App\Services\DividendService;
use App\Services\PeriodLockService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;

class MonthlyCooperativeBenchmarkController extends Controller
{
    protected DividendService $dividendService;

    public function __construct(DividendService $dividendService)
    {
        $this->dividendService = $dividendService;
    }
    public function index(Request $request): JsonResponse
    {
        try {
            $fiscalYear = (int) ($request->query('fiscal_year') ?? $request->query('year') ?? $request->query('tahun') ?? now()->year);
            $startYear  = $fiscalYear - 1;
            $endYear    = $fiscalYear;

            $shortMonthNames = [
                6 => 'Jun', 7 => 'Jul', 8 => 'AUG', 9 => 'Sep', 10 => 'OKT', 11 => 'NOV', 12 => 'DES',
                1 => 'Jan', 2 => 'FEB', 3 => 'MAR', 4 => 'APR', 5 => 'MEI'
            ];

            // Ambil semua benchmark tersimpan untuk fiscal_year ini
            $savedBenchmarks = MonthlyCooperativeBenchmark::where('fiscal_year', $fiscalYear)
                ->get()
                ->keyBy('month');

            $monthsData = [];
            $totalNetIncome = 0.0;
            $totalDividends = 0.0;

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

            foreach ($fiscalMonthsOrder as $idx => $fm) {
                $m = $fm['m'];
                $y = $fm['y'];
                $dates = $this->dividendService->resolveDates($m, $y);
                $saved = $savedBenchmarks->get($m);

                // Auto-calculated: query laba bersih riil dari transaksi kas siklus 21–20
                $autoProfit = $this->dividendService->calculateNetProfit($m, $y, false);
                $autoShu    = (float) $autoProfit['shu_bersih'];
                $isManual   = ($saved && $saved->net_income !== null);

                // Prioritas 1: input manual manajer | Prioritas 2: query riil | Fallback: 0
                $netIncome = $isManual ? (float) $saved->net_income : $autoShu;

                $divPercent = $saved ? (float) $saved->dividend_allocation_percent : DividendService::DEFAULT_PERCENTAGE;

                // Total Saham Koperasi
                $totalShares = ($saved && $saved->total_coop_shares !== null)
                    ? (float) $saved->total_coop_shares
                    : $this->dividendService->getHistoricalCoopSharesAtDate($dates['cutoff_date'], $m);

                $lembarKoperasi = round($totalShares / 1000.0, 2);
                $danaDev25 = round($netIncome * ($divPercent / 100.0), 2);
                $hargaDevPerLembar = $lembarKoperasi > 0 ? ($danaDev25 / $lembarKoperasi) : 0.0;
                $rateDisplay = (int) round($hargaDevPerLembar);

                $isLocked = $saved ? (bool) $saved->is_locked : PeriodLockService::isLocked($dates['execution_date']);

                $totalNetIncome += $netIncome;
                $totalDividends += $danaDev25;

                $monthsData[] = [
                    'id'                          => $saved?->id,
                    'fiscal_year'                 => $fiscalYear,
                    'calendar_year'               => $y,
                    'month'                       => $m,
                    'month_name'                  => $shortMonthNames[$m],
                    'month_label'                 => Carbon::createFromDate($y, $m, 1)->locale('id')->isoFormat('MMMM YYYY'),
                    'cycle_start_date'            => $dates['start_date'],
                    'cycle_end_date'              => $dates['cutoff_date'],
                    'execution_date'              => $dates['execution_date'],
                    // Pemetaan Nama Baku Laporan Koperasi
                    'total_saham'                 => round($totalShares, 2),
                    'shu_setelah_biaya'           => round($netIncome, 2),
                    'shu_deviden_pool'            => round($danaDev25, 2),
                    'lembar_saham'                => $lembarKoperasi,
                    'harga_saham_deviden'         => round($hargaDevPerLembar, 6),
                    // Alias Kompatibilitas Legacy & Frontend
                    'net_income'                  => round($netIncome, 2),
                    'net_income_formatted'        => number_format($netIncome, 0, ',', '.'),
                    'auto_calculated_net_income'  => round($autoShu, 2),
                    'is_manual_override'          => $isManual,
                    'dividend_allocation_percent' => round($divPercent, 2),
                    'total_coop_shares'           => round($totalShares, 2),
                    'total_coop_shares_formatted' => number_format($totalShares, 0, ',', '.'),
                    'jumlah_lembar_koperasi'      => $lembarKoperasi,
                    'dana_deviden'                => round($danaDev25, 2),
                    'harga_deviden_per_lembar'    => round($hargaDevPerLembar, 6),
                    'harga_saham_display'         => $rateDisplay,
                    'is_locked'                   => $isLocked,
                    'notes'                       => $saved?->notes,
                    'updated_at'                  => $saved?->updated_at?->toIso8601String(),
                ];
            }

            return response()->json([
                'success' => true,
                'status'  => 'success',
                'message' => "Parameter acuan SHU koperasi tahun buku {$fiscalYear} berhasil dimuat.",
                'data'    => [
                    'fiscal_year'         => $fiscalYear,
                    'fiscal_period_label' => "Juni {$startYear} - Mei {$endYear}",
                    'total_net_income'    => round($totalNetIncome, 2),
                    'total_dividends'     => round($totalDividends, 2),
                    'benchmarks'          => $monthsData,
                    'months'              => $monthsData,
                ],
            ], 200);
        } catch (\Exception $e) {
            Log::error('Gagal mengambil parameter acuan SHU koperasi: ' . $e->getMessage(), [
                'trace' => $e->getTraceAsString(),
            ]);
            return response()->json([
                'success' => false,
                'status'  => 'error',
                'message' => 'Gagal mengambil parameter acuan SHU: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Simpan atau Update Parameter Acuan SHU Bulanan (Single atau Batch)
     * Endpoint: POST /api/manager/coop-benchmarks/update
     */
    public function update(Request $request): JsonResponse
    {
        $user = $request->user();
        $userId = $user ? $user->id : null;

        $validator = Validator::make($request->all(), [
            'fiscal_year'                 => 'required|integer|min:2000|max:2100',
            'month'                       => 'nullable|integer|min:1|max:12',
            'net_income'                  => 'nullable|numeric|min:0',
            'dividend_allocation_percent' => 'nullable|numeric|min:0|max:100',
            'total_coop_shares'           => 'nullable|numeric|min:0',
            'notes'                       => 'nullable|string|max:500',
            'benchmarks'                  => 'nullable|array',
            'benchmarks.*.month'          => 'required_with:benchmarks|integer|min:1|max:12',
            'benchmarks.*.net_income'     => 'nullable|numeric|min:0',
            'benchmarks.*.dividend_allocation_percent' => 'nullable|numeric|min:0|max:100',
            'benchmarks.*.total_coop_shares'           => 'nullable|numeric|min:0',
            'benchmarks.*.notes'                       => 'nullable|string|max:500',
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
            $fiscalYear = (int) $request->input('fiscal_year');
            $updatedRecords = [];

            DB::transaction(function () use ($request, $fiscalYear, $userId, &$updatedRecords) {
                $benchmarksInput = $request->input('benchmarks');

                if (is_array($benchmarksInput) && !empty($benchmarksInput)) {
                    // Batch Update
                    foreach ($benchmarksInput as $item) {
                        $m = (int) $item['month'];
                        $calendarYear = ($m >= 6) ? ($fiscalYear - 1) : $fiscalYear;
                        $dates = $this->dividendService->resolveDates($m, $calendarYear);

                        $existing = MonthlyCooperativeBenchmark::where('fiscal_year', $fiscalYear)->where('month', $m)->first();

                        $record = MonthlyCooperativeBenchmark::updateOrCreate(
                            [
                                'fiscal_year' => $fiscalYear,
                                'month'       => $m,
                            ],
                            [
                                'cycle_start_date'            => $dates['start_date'],
                                'cycle_end_date'              => $dates['cutoff_date'],
                                'net_income'                  => isset($item['net_income']) && $item['net_income'] !== '' ? (float) $item['net_income'] : null,
                                'dividend_allocation_percent' => isset($item['dividend_allocation_percent']) ? (float) $item['dividend_allocation_percent'] : 25.00,
                                'total_coop_shares'           => isset($item['total_coop_shares']) && $item['total_coop_shares'] !== '' ? (float) $item['total_coop_shares'] : null,
                                'notes'                       => $item['notes'] ?? null,
                                'updated_by'                  => $userId,
                                'created_by'                  => $existing ? $existing->created_by : $userId,
                            ]
                        );
                        $updatedRecords[] = $record;
                    }
                } else {
                    // Single Month Update
                    $m = (int) $request->input('month');
                    if ($m < 1 || $m > 12) {
                        throw new \InvalidArgumentException('Parameter bulan (month) wajib diisi antara 1 dan 12.');
                    }

                    $calendarYear = ($m >= 6) ? ($fiscalYear - 1) : $fiscalYear;
                    $dates = $this->dividendService->resolveDates($m, $calendarYear);

                    $existing = MonthlyCooperativeBenchmark::where('fiscal_year', $fiscalYear)->where('month', $m)->first();

                    $record = MonthlyCooperativeBenchmark::updateOrCreate(
                        [
                            'fiscal_year' => $fiscalYear,
                            'month'       => $m,
                        ],
                        [
                            'cycle_start_date'            => $dates['start_date'],
                            'cycle_end_date'              => $dates['cutoff_date'],
                            'net_income'                  => $request->has('net_income') && $request->input('net_income') !== '' && $request->input('net_income') !== null ? (float) $request->input('net_income') : null,
                            'dividend_allocation_percent' => (float) ($request->input('dividend_allocation_percent') ?? 25.00),
                            'total_coop_shares'           => $request->has('total_coop_shares') && $request->input('total_coop_shares') !== '' && $request->input('total_coop_shares') !== null ? (float) $request->input('total_coop_shares') : null,
                            'notes'                       => $request->input('notes'),
                            'updated_by'                  => $userId,
                            'created_by'                  => $existing ? $existing->created_by : $userId,
                        ]
                    );
                    $updatedRecords[] = $record;
                }

                Cache::flush();
            });

            return response()->json([
                'success' => true,
                'status'  => 'success',
                'message' => 'Parameter acuan SHU bulanan koperasi berhasil disimpan.',
                'data'    => [
                    'fiscal_year'     => $fiscalYear,
                    'updated_count'   => count($updatedRecords),
                    'updated_records' => $updatedRecords,
                ],
            ], 200);
        } catch (\InvalidArgumentException $e) {
            return response()->json([
                'success' => false,
                'status'  => 'error',
                'message' => $e->getMessage(),
            ], 422);
        } catch (\Exception $e) {
            Log::error('Gagal menyimpan parameter acuan SHU: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'status'  => 'error',
                'message' => 'Gagal menyimpan parameter acuan SHU: ' . $e->getMessage(),
            ], 500);
        }
    }
}
