<?php

namespace App\Services;

use Carbon\Carbon;
use Illuminate\Http\JsonResponse;

class PeriodLockService
{
    /**
     * Mengecek apakah tanggal tertentu berada di dalam periode akuntansi yang berstatus LOCKED / CLOSED
     *
     * @param string|Carbon|\DateTimeInterface|null $date
     * @return bool
     */
    public static function isLocked($date): bool
    {
        return PeriodClosingService::isDateLocked($date);
    }

    /**
     * Memvalidasi tanggal transaksi, jika berada di periode terkunci kembalikan JSON Response HTTP 403
     *
     * @param string|Carbon|\DateTimeInterface|null $date
     * @param string|null $customMessage
     * @param int $statusCode
     * @return JsonResponse|null
     */
    public static function validateDate($date, ?string $customMessage = null, int $statusCode = 403): ?JsonResponse
    {
        return PeriodClosingService::validateDate($date, $customMessage, $statusCode);
    }
}
