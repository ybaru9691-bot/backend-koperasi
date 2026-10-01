<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\WeeklyPeriodLock;
use App\Services\WorksheetReportService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AccountingController extends Controller
{
    protected WorksheetReportService $worksheetService;

    public function __construct(WorksheetReportService $worksheetService)
    {
        $this->worksheetService = $worksheetService;
    }

    /**
     * Get Neraca Lajur (Worksheet) spesifik rentang tanggal pekan (M1 - M5) beserta status penguncian
     * Endpoint: GET /api/accounting/worksheet
     */
    public function worksheet(Request $request): JsonResponse
    {
        try {
            $month = (int) $request->get('month', date('n'));
            $year  = (int) $request->get('year', date('Y'));
            $week  = $request->get('week') ?? $request->get('minggu');
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

            $startDate = Carbon::create($year, $month, 1)->startOfDay();
            $endDate   = Carbon::create($year, $month, 1)->endOfMonth()->endOfDay();

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

            // Tentukan status penguncian mingguan (Safe query dengan fallback is_locked = false)
            $isLocked = false;
            $lockRecord = null;
            try {
                if (\Illuminate\Support\Facades\Schema::hasTable('weekly_period_locks')) {
                    if ($weekFormatted) {
                        $lockRecord = WeeklyPeriodLock::where('year', $year)
                            ->where('month', $month)
                            ->where('week', $weekFormatted)
                            ->first();
                    } else {
                        $startInfo = WeeklyPeriodLock::getWeekInfoForDate($resolvedStart);
                        $endInfo   = WeeklyPeriodLock::getWeekInfoForDate($resolvedEnd);
                        if ($startInfo['week'] === $endInfo['week'] && $startInfo['year'] === $endInfo['year'] && $startInfo['month'] === $endInfo['month']) {
                            $weekFormatted = $startInfo['week'];
                            $lockRecord = WeeklyPeriodLock::where('year', $startInfo['year'])
                                ->where('month', $startInfo['month'])
                                ->where('week', $startInfo['week'])
                                ->first();
                        }
                    }

                    if ($lockRecord && $lockRecord->is_locked) {
                        $isLocked = true;
                    }
                }
            } catch (\Throwable $lockEx) {
                $isLocked = false;
                $lockRecord = null;
            }

            $statusStr = $isLocked ? 'locked' : 'active';

            // Generate Worksheet Report 10-Column / 12-Column
            $worksheetData = $this->worksheetService->generateWorksheet($resolvedStart, $resolvedEnd, $periodLabel);

            $formattedLockedAt = null;
            if ($isLocked && $lockRecord && $lockRecord->locked_at) {
                $formattedLockedAt = Carbon::parse($lockRecord->locked_at)->format('Y-m-d H:i:s');
            }

            $responseData = array_merge($worksheetData, [
                'status'         => $statusStr,
                'is_locked'      => $isLocked,
                'month'          => $month,
                'year'           => $year,
                'week'           => $weekFormatted,
                'start_date'     => $resolvedStart,
                'end_date'       => $resolvedEnd,
                'locked_at'      => $formattedLockedAt,
                'locked_by'      => ($isLocked && $lockRecord) ? $lockRecord->locked_by : null,
                'locked_by_name' => ($isLocked && $lockRecord && $lockRecord->locker) ? $lockRecord->locker->name : null,
                'lock_notes'     => ($isLocked && $lockRecord) ? $lockRecord->notes : null,
            ]);

            return response()->json([
                'success' => true,
                'status'  => 'success',
                'message' => 'Data Neraca Lajur berhasil dimuat',
                'data'    => $responseData,
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

    /**
     * Lock Weekly Accounting Period (M1 - M5)
     * Endpoint: POST /api/accounting/lock-week
     */
    public function lockWeek(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'month' => 'required|integer|between:1,12',
            'year'  => 'required|integer|between:2000,2100',
            'week'  => 'required',
            'notes' => 'nullable|string|max:500',
        ]);

        try {
            $month = (int) $validated['month'];
            $year  = (int) $validated['year'];
            $weekRaw = (string) $validated['week'];

            if (preg_match('/(\d)/', $weekRaw, $m)) {
                $weekNum = (int) $m[1];
            } else {
                $weekNum = 1;
            }
            $weekFormatted = 'M' . $weekNum;

            [$sDate, $eDate, $label] = WeeklyPeriodLock::resolveDateRange($year, $month, $weekNum);

            $user = $request->user();
            $userId = $user ? $user->id : null;

            $lock = WeeklyPeriodLock::updateOrCreate(
                [
                    'year'  => $year,
                    'month' => $month,
                    'week'  => $weekFormatted,
                ],
                [
                    'start_date' => $sDate,
                    'end_date'   => $eDate,
                    'is_locked'  => true,
                    'status'     => 'locked',
                    'locked_by'  => $userId,
                    'locked_at'  => now(),
                    'notes'      => $validated['notes'] ?? "Penguncian evaluasi pekan {$weekFormatted} ({$label})",
                ]
            );

            $freshLock = $lock->fresh(['locker']);
            $responseData = $freshLock->toArray();
            if ($freshLock->locked_at) {
                $responseData['locked_at'] = Carbon::parse($freshLock->locked_at)->format('Y-m-d H:i:s');
            }

            return response()->json([
                'success' => true,
                'status'  => 'success',
                'message' => "Pekan {$weekFormatted} periode " . sprintf('%02d/%04d', $month, $year) . " berhasil dikunci.",
                'data'    => $responseData,
            ], 200);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'status'  => 'error',
                'message' => 'Gagal mengunci periode pekan: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Unlock Weekly Accounting Period (M1 - M5)
     * Endpoint: POST /api/accounting/unlock-week
     */
    public function unlockWeek(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'month' => 'required|integer|between:1,12',
            'year'  => 'required|integer|between:2000,2100',
            'week'  => 'required',
            'notes' => 'nullable|string|max:500',
        ]);

        try {
            $month = (int) $validated['month'];
            $year  = (int) $validated['year'];
            $weekRaw = $validated['week'];

            if (preg_match('/(\d)/', $weekRaw, $m)) {
                $weekNum = (int) $m[1];
            } else {
                $weekNum = 1;
            }
            $weekFormatted = 'M' . $weekNum;

            [$sDate, $eDate, $label] = WeeklyPeriodLock::resolveDateRange($year, $month, $weekNum);

            $lock = WeeklyPeriodLock::updateOrCreate(
                [
                    'year'  => $year,
                    'month' => $month,
                    'week'  => $weekFormatted,
                ],
                [
                    'start_date' => $sDate,
                    'end_date'   => $eDate,
                    'is_locked'  => false,
                    'status'     => 'active',
                    'locked_by'  => null,
                    'locked_at'  => null,
                    'notes'      => $validated['notes'] ?? "Pembukaan kunci pekan {$weekFormatted} ({$label})",
                ]
            );

            return response()->json([
                'success' => true,
                'status'  => 'success',
                'message' => "Kunci pekan {$weekFormatted} periode " . sprintf('%02d/%04d', $month, $year) . " berhasil dibuka.",
                'data'    => $lock->fresh(),
            ], 200);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'status'  => 'error',
                'message' => 'Gagal membuka kunci periode pekan: ' . $e->getMessage(),
            ], 500);
        }
    }
}
