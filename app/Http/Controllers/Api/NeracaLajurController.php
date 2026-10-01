<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\WeeklyPeriodLock;
use App\Services\WorksheetReportService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class NeracaLajurController extends Controller
{
    protected WorksheetReportService $worksheetService;

    public function __construct(WorksheetReportService $worksheetService)
    {
        $this->worksheetService = $worksheetService;
    }
    public function index(Request $request): JsonResponse
    {
        try {
            $month  = (int) $request->get('month', date('n'));
            $year   = (int) $request->get('year', date('Y'));
            $week   = $request->get('week') ?? $request->get('minggu');
            $period = $request->get('period');

            if ($period && (!$request->has('month') || !$request->has('year'))) {
                if (preg_match('/(\d{4})[-_\s](\d{1,2})/', $period, $myMatch)) {
                    $year  = (int) $myMatch[1];
                    $month = (int) $myMatch[2];
                }
                if (!$week && preg_match('/[MmWw](\d)/i', $period, $wMatch)) {
                    $week = 'M' . $wMatch[1];
                }
            }

            $weekUpper = $week ? strtoupper(trim((string) $week)) : null;
            $weekFormatted = null;

            if ($weekUpper && preg_match('/[Mm]?([1-5])/', $weekUpper, $wm)) {
                $weekNum = (int) $wm[1];
                [$resolvedStart, $resolvedEnd, $periodLabel, $weekFormatted] = WeeklyPeriodLock::resolveDateRange($year, $month, $weekNum);
            } elseif ($request->filled('start_date') && $request->filled('end_date')) {
                $resolvedStart = Carbon::parse($request->get('start_date'))->toDateString();
                $resolvedEnd   = Carbon::parse($request->get('end_date'))->toDateString();
                $periodLabel   = sprintf('%04d-%02d (%s s/d %s)', $year, $month, date('d/m/Y', strtotime($resolvedStart)), date('d/m/Y', strtotime($resolvedEnd)));
            } else {
                $daysInMonth   = (int) date('t', strtotime(sprintf('%04d-%02d-01', $year, $month)));
                $resolvedStart = sprintf('%04d-%02d-01', $year, $month);
                $resolvedEnd   = sprintf('%04d-%02d-%02d', $year, $month, $daysInMonth);
                $periodLabel   = sprintf('%04d-%02d (Bulanan)', $year, $month);
            }

            // Generate Worksheet 10 Kolom
            $worksheetData = $this->worksheetService->generateWorksheet($resolvedStart, $resolvedEnd, $periodLabel);

            return response()->json([
                'success' => true,
                'status'  => 'success',
                'message' => 'Data Neraca Lajur berhasil dimuat',
                'data'    => array_merge($worksheetData, [
                    'month'      => $month,
                    'year'       => $year,
                    'week'       => $weekFormatted,
                    'start_date' => $resolvedStart,
                    'end_date'   => $resolvedEnd,
                ]),
            ], 200);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'status'  => 'error',
                'message' => 'Gagal memuat Neraca Lajur: ' . $e->getMessage(),
                'data'    => null,
            ], 500);
        }
    }
}
