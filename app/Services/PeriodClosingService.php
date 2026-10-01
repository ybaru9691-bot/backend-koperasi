<?php

namespace App\Services;

use App\Models\AccountingPeriod;
use App\Models\Member;
use App\Models\Period;
use App\Models\Transaction;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\HttpKernel\Exception\HttpException;

class PeriodClosingService
{
    public const LOCKED_MESSAGE = 'Periode akuntansi telah ditutup dan dikunci. Transaksi pada tanggal ini tidak dapat diubah.';

    /**
     * Memeriksa apakah suatu tanggal transaksi berada di dalam periode akuntansi yang berstatus LOCKED atau CLOSED.
     *
     * @param string|\DateTimeInterface|null $date
     * @return bool
     */
    public static function isDateLocked($date): bool
    {
        if (empty($date)) {
            return false;
        }

        try {
            $dateStr = $date instanceof \DateTimeInterface
                ? $date->format('Y-m-d')
                : Carbon::parse((string) $date)->format('Y-m-d');
        } catch (\Throwable $e) {
            $dateStr = substr((string) $date, 0, 10);
        }

        // 1. Cek tabel accounting_periods (Tutup Buku / Periode Akuntansi Kunci)
        if (Schema::hasTable('accounting_periods')) {
            $isLockedAccounting = DB::table('accounting_periods')
                ->where(function ($q) {
                    $q->where('is_locked', true)
                      ->orWhereIn(DB::raw('UPPER(status)'), ['LOCKED', 'CLOSED', 'TUTUP', 'DITUTUP']);
                })
                ->whereDate('start_date', '<=', $dateStr)
                ->whereDate('end_date', '>=', $dateStr)
                ->exists();

            if ($isLockedAccounting) {
                return true;
            }
        }

        // 2. Cek tabel periods (Tutup Buku / Periode Akuntansi Kunci)
        if (Schema::hasTable('periods')) {
            $isLockedPeriod = DB::table('periods')
                ->where(function ($q) {
                    $q->where('is_locked', true)
                      ->orWhereIn(DB::raw('LOWER(status)'), ['closed', 'locked', 'tutup', 'ditutup']);
                })
                ->whereDate('start_date', '<=', $dateStr)
                ->whereDate('end_date', '>=', $dateStr)
                ->exists();

            if ($isLockedPeriod) {
                return true;
            }
        }

        return false;
    }

    /**
     * Mengambil detail periode yang terkunci untuk tanggal tertentu.
     *
     * @param string|\DateTimeInterface|null $date
     * @return object|null
     */
    public static function getLockedPeriod($date): ?object
    {
        if (empty($date)) {
            return null;
        }

        try {
            $dateStr = $date instanceof \DateTimeInterface
                ? $date->format('Y-m-d')
                : Carbon::parse((string) $date)->format('Y-m-d');
        } catch (\Throwable $e) {
            $dateStr = substr((string) $date, 0, 10);
        }

        if (Schema::hasTable('accounting_periods')) {
            $period = DB::table('accounting_periods')
                ->where(function ($q) {
                    $q->where('is_locked', true)
                      ->orWhereIn(DB::raw('UPPER(status)'), ['LOCKED', 'CLOSED', 'TUTUP', 'DITUTUP']);
                })
                ->whereDate('start_date', '<=', $dateStr)
                ->whereDate('end_date', '>=', $dateStr)
                ->first();

            if ($period) {
                return $period;
            }
        }

        if (Schema::hasTable('periods')) {
            $period = DB::table('periods')
                ->where(function ($q) {
                    $q->where('is_locked', true)
                      ->orWhereIn(DB::raw('LOWER(status)'), ['closed', 'locked', 'tutup', 'ditutup']);
                })
                ->whereDate('start_date', '<=', $dateStr)
                ->whereDate('end_date', '>=', $dateStr)
                ->first();

            if ($period) {
                return $period;
            }
        }

        return null;
    }

    /**
     * Memvalidasi durasi periode: Wajib tepat 12 bulan (1 tahun buku, 350 - 370 hari).
     *
     * @param string|\DateTimeInterface $startDate
     * @param string|\DateTimeInterface $endDate
     * @return bool
     */
    public static function validatePeriodDuration($startDate, $endDate): bool
    {
        try {
            $sDate = $startDate instanceof Carbon ? $startDate : Carbon::parse($startDate);
            $eDate = $endDate instanceof Carbon ? $endDate : Carbon::parse($endDate);

            if ($eDate->lt($sDate)) {
                return false;
            }

            $diffInDays = $sDate->diffInDays($eDate) + 1;
            $diffInMonths = $sDate->diffInMonths($eDate);

            // Tepat 12 bulan: 350 s/d 370 hari atau 11 s/d 12 bulan
            return ($diffInDays >= 350 && $diffInDays <= 370) || ($diffInMonths >= 11 && $diffInMonths <= 12);
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * Eksekusi Rollover Saldo Simpanan Anggota (Akun Riil / Buku Besar) ke Periode Baru.
     * Saldo akhir periode cut-off lama dialihkan sebagai saldo awal yang berkesinambungan di periode baru.
     *
     * @param Period|AccountingPeriod $closedPeriod
     * @param Period|AccountingPeriod $newPeriod
     * @param User|null $user
     * @return array
     */
    public static function rolloverMemberSavings($closedPeriod, $newPeriod, ?User $user = null): array
    {
        $endDateStr = $closedPeriod->end_date instanceof Carbon
            ? $closedPeriod->end_date->toDateString()
            : substr((string)$closedPeriod->end_date, 0, 10);

        $newStartDateStr = $newPeriod->start_date instanceof Carbon
            ? $newPeriod->start_date->toDateString()
            : substr((string)$newPeriod->start_date, 0, 10);

        $members = Member::where('status', '!=', 'resigned')->get();
        $processedCount = 0;
        $totalRolloverShares = 0.0;

        Transaction::withoutPeriodLock(function () use (
            $members,
            $endDateStr,
            $newStartDateStr,
            $closedPeriod,
            $newPeriod,
            $user,
            &$processedCount,
            &$totalRolloverShares
        ) {
            foreach ($members as $member) {
                $spEnd = $member->getSavingsBalanceAt('SP', $endDateStr);
                $swEnd = $member->getSavingsBalanceAt('SW', $endDateStr);
                $ssEnd = $member->getSavingsBalanceAt('SS', $endDateStr);
                $totalSaham = round($spEnd + $swEnd + $ssEnd, 2);

                // Pastikan saldo anggota di tabel members tetap sinkron dan tidak kembali ke 0
                $member->update([
                    'principal_savings' => $spEnd,
                    'mandatory_savings' => $swEnd,
                    'voluntary_savings' => $ssEnd,
                ]);

                $totalRolloverShares += $totalSaham;
                $processedCount++;
            }
        });

        Log::info("[PeriodClosingService] Rollover simpanan selesai untuk {$processedCount} anggota. Total Saham Rollover: Rp " . number_format($totalRolloverShares, 2));

        return [
            'total_members_processed' => $processedCount,
            'total_rollover_shares'   => $totalRolloverShares,
            'closed_period_end'       => $endDateStr,
            'new_period_start'        => $newStartDateStr,
        ];
    }

    /**
     * Memvalidasi tanggal transaksi. Mengembalikan JsonResponse 403 jika periode terkunci.
     *
     * @param string|\DateTimeInterface|null $date
     * @param string|null $customMessage
     * @param int $statusCode
     * @return JsonResponse|null
     */
    public static function validateDate($date, ?string $customMessage = null, int $statusCode = 403): ?JsonResponse
    {
        if (self::isDateLocked($date)) {
            return response()->json([
                'status'  => 'error',
                'success' => false,
                'message' => $customMessage ?? self::LOCKED_MESSAGE,
                'error'   => 'LOCKED_PERIOD',
            ], $statusCode);
        }

        return null;
    }

    /**
     * Memvalidasi tanggal transaksi. Lempar HttpException 403 jika periode terkunci.
     *
     * @param string|\DateTimeInterface|null $date
     * @param string|null $customMessage
     * @throws HttpException
     */
    public static function validateOrFail($date, ?string $customMessage = null): void
    {
        if (self::isDateLocked($date)) {
            abort(403, $customMessage ?? self::LOCKED_MESSAGE);
        }
    }
}