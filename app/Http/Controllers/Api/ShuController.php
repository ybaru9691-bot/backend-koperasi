<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\DividendService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;

class ShuController extends Controller
{
    protected DividendService $dividendService;
    public function __construct(DividendService $dividendService)
    {
        $this->dividendService = $dividendService;
    }
    /**
     * Endpoint Input / Tutup Buku Nilai SHU Bulanan Koperasi
     * Endpoint: POST /api/shu/monthly-record
     */
    public function monthlyRecord(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'year'                        => 'required',
            'month'                       => 'required',
            'net_profit'                  => 'required|numeric|min:0',
            'dividend_allocation_percent' => 'nullable|numeric|min:0|max:100',
            'total_shares_capital'        => 'nullable|numeric|min:0',
            'notes'                       => 'nullable|string|max:500',
        ], [
            'year.required'       => 'Tahun buku (year / fiscal_year) wajib diisi.',
            'month.required'      => 'Bulan (month) wajib diisi (angka 1-12 atau nama/singkatan bulan misal AUG).',
            'net_profit.required' => 'Nominal keuntungan bersih / SHU setelah dikurangi biaya (net_profit) wajib diisi.',
            'net_profit.numeric'  => 'Nominal keuntungan bersih harus berupa angka.',
            'net_profit.min'      => 'Nominal keuntungan bersih tidak boleh negatif.',
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
            $rawMonth = $request->input('month');
            $month = $this->dividendService->parseMonth($rawMonth);

            $rawYear = (int) ($request->input('fiscal_year') ?? $request->input('year'));
            if ($rawYear < 2000 || $rawYear > 2100) {
                throw new \InvalidArgumentException('Tahun harus berada di antara 2000 dan 2100.');
            }

            // Jika parameter tahun berupa tahun kalender berjalan dan bulan >= 6,
            // tahun buku (fiscal_year) yang menaunginya adalah year + 1 (contoh: Agu 2025 -> Tahun Buku 2026)
            // Namun jika user langsung memasukkan fiscal_year (misal 2026), gunakan fiscal_year tersebut
            $fiscalYear = $rawYear;
            if ($request->has('calendar_year')) {
                $calendarYear = (int) $request->input('calendar_year');
                $fiscalYear = ($month >= 6) ? ($calendarYear + 1) : $calendarYear;
            }

            $netProfit = (float) $request->input('net_profit');
            $percentage = (float) ($request->input('dividend_allocation_percent') ?? $request->input('percentage') ?? DividendService::DEFAULT_PERCENTAGE);
            $totalShares = $request->has('total_shares_capital') && $request->input('total_shares_capital') !== null && $request->input('total_shares_capital') !== ''
                ? (float) $request->input('total_shares_capital')
                : ($request->has('total_coop_shares') ? (float) $request->input('total_coop_shares') : null);

            $notes = $request->input('notes');
            $user = $request->user();
            $userId = $user ? $user->id : null;
            $result = $this->dividendService->recordMonthlyShu(
                $fiscalYear,
                $month,
                $netProfit,
                $totalShares,
                $percentage,
                $userId,
                $notes
            );

            return response()->json([
                'success' => true,
                'status'  => 'success',
                'message' => "Nilai SHU bulanan periode {$result['record']['month_label']} (Tahun Buku {$fiscalYear}) berhasil dicatat.",
                'data'    => $result,
            ], 200);
        } catch (\InvalidArgumentException $e) {
            return response()->json([
                'success' => false,
                'status'  => 'error',
                'message' => $e->getMessage(),
            ], 422);
        } catch (\Exception $e) {
            Log::error('Gagal mencatat SHU bulanan: ' . $e->getMessage(), [
                'trace' => $e->getTraceAsString(),
            ]);
            return response()->json([
                'success' => false,
                'status'  => 'error',
                'message' => 'Gagal mencatat SHU bulanan: ' . $e->getMessage(),
            ], 500);
        }
    }
    public function distribute(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'year'       => 'required',
            'month'      => 'required',
            'percentage' => 'nullable|numeric|min:0|max:100',
            'voucher_no' => 'nullable|string|max:100',
            'notes'      => 'nullable|string|max:255',
        ], [
            'year.required'  => 'Tahun buku (year / fiscal_year) wajib diisi.',
            'month.required' => 'Bulan (month) wajib diisi.',
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
            $rawMonth = $request->input('month');
            $month = $this->dividendService->parseMonth($rawMonth);

            $rawYear = (int) ($request->input('fiscal_year') ?? $request->input('year'));
            $calendarYear = ($month >= 6) ? ($rawYear - 1) : $rawYear;
            if ($request->has('calendar_year')) {
                $calendarYear = (int) $request->input('calendar_year');
            }

            $percentage = (float) ($request->input('percentage') ?? $request->input('persen') ?? DividendService::DEFAULT_PERCENTAGE);
            
            // Format default voucher: BM-DIV-YYYYMM
            $dates = $this->dividendService->resolveDates($month, $calendarYear);
            $voucherNo = trim((string)($request->input('voucher_no') ?? $dates['default_voucher']));

            if (empty($voucherNo)) {
                $voucherNo = $dates['default_voucher'];
            }

            $notes = $request->input('notes');
            $user = $request->user();
            $userId = $user ? $user->id : null;

            // Eksekusi pembagian
            $distResult = $this->dividendService->distribute($month, $calendarYear, $percentage, $voucherNo, $userId, $notes);

            // Ambil rekap 12 bulan terkini
            $fiscalYear = ($month >= 6) ? ($calendarYear + 1) : $calendarYear;
            $recap = $this->dividendService->getShuRecap12Months($fiscalYear);
            return response()->json([
                'success' => true,
                'status'  => 'success',
                'message' => $distResult['message'],
                'data'    => [
                    'distribution'    => $distResult,
                    'recap_12_months' => $recap,
                ],
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
                'message' => 'Gagal mendistribusikan deviden SHU: ' . $e->getMessage(),
            ], 400);
        }
    }
    /**
     * Endpoint Rekap Status Pembagian SHU 12 Bulan (Juni s/d Mei)
     * Endpoint: GET /api/shu/summary atau GET /api/shu/monthly-records
     */
    public function summary(Request $request): JsonResponse
    {
        try {
            $fiscalYear = (int) ($request->query('fiscal_year') ?? $request->query('year') ?? now()->year);
            $recap = $this->dividendService->getShuRecap12Months($fiscalYear);

            return response()->json([
                'success' => true,
                'status'  => 'success',
                'message' => "Rekapitulasi status SHU 12 bulan tahun buku {$fiscalYear} berhasil dimuat.",
                'data'    => $recap,
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'status'  => 'error',
                'message' => 'Gagal memuat rekap SHU: ' . $e->getMessage(),
            ], 500);
        }
    }
    /**
     * Pratinjau Pembagian Deviden SHU Bulanan
     * Endpoint: GET /api/shu/preview
     */
    public function preview(Request $request): JsonResponse
    {
        try {
            $rawMonth = $request->input('month') ?? now()->month;
            $month = $this->dividendService->parseMonth($rawMonth);

            $rawYear = (int) ($request->input('fiscal_year') ?? $request->input('year') ?? now()->year);
            $calendarYear = ($month >= 6) ? ($rawYear - 1) : $rawYear;
            if ($request->has('calendar_year')) {
                $calendarYear = (int) $request->input('calendar_year');
            }
            $percentage = (float) ($request->input('percentage') ?? DividendService::DEFAULT_PERCENTAGE);
            $memberId = $request->input('member_id') ?? $request->input('id');
            if (!$memberId && $request->filled('member_number')) {
                $memberId = \App\Models\Member::where('member_number', $request->input('member_number'))->value('id');
            }
            $memberId = $memberId ? (int) $memberId : null;

            $cacheKey = $memberId 
                ? "dividend_preview_member_{$memberId}_{$month}_{$calendarYear}_" . round($percentage, 2)
                : "dividend_preview_global_{$month}_{$calendarYear}_" . round($percentage, 2);

            $data = Cache::remember($cacheKey, 300, function () use ($month, $calendarYear, $percentage, $memberId) {
                return $this->dividendService->preview($month, $calendarYear, $percentage, $memberId);
            });

            return response()->json([
                'success' => true,
                'status'  => 'success',
                'message' => 'Pratinjau pembagian SHU deviden berhasil dimuat.',
                'data'    => $data,
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'status'  => 'error',
                'message' => 'Gagal memuat pratinjau SHU: ' . $e->getMessage(),
            ], 500);
        }
    }
}
