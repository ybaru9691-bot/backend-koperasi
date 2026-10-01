<?php

namespace App\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class WeeklyPeriodLock extends Model
{
    use HasFactory;

    protected $table = 'weekly_period_locks';

    protected $fillable = [
        'month',
        'year',
        'week',
        'start_date',
        'end_date',
        'is_locked',
        'status',
        'locked_by',
        'locked_at',
        'notes',
    ];

    protected $casts = [
        'month'      => 'integer',
        'year'       => 'integer',
        'is_locked'  => 'boolean',
        'start_date' => 'date',
        'end_date'   => 'date',
        'locked_at'  => 'datetime',
    ];

    public function locker()
    {
        return $this->belongsTo(User::class, 'locked_by');
    }

    /**
     * Hitung rentang tanggal pekan (M1 - M5)
     * 
     * M1: Tanggal 1 s/d 7
     * M2: Tanggal 8 s/d 14
     * M3: Tanggal 15 s/d 21
     * M4: Tanggal 22 s/d 28
     * M5: Tanggal 29 s/d Akhir Bulan (28/29/30/31)
     *
     * @param int $year
     * @param int $month
     * @param string|int $week
     * @return array [$startDate, $endDate, $label, $weekFormatted]
     */
    public static function resolveDateRange(int $year, int $month, $week): array
    {
        $weekNum = 1;
        if (is_string($week) && preg_match('/(\d)/', $week, $m)) {
            $weekNum = (int) $m[1];
        } elseif (is_numeric($week)) {
            $weekNum = (int) $week;
        }

        if ($weekNum < 1) {
            $weekNum = 1;
        }
        if ($weekNum > 5) {
            $weekNum = 5;
        }

        $weekFormatted = 'M' . $weekNum;
        $daysInMonth = (int) date('t', strtotime(sprintf('%04d-%02d-01', $year, $month)));

        switch ($weekNum) {
            case 1:
                $sDate = sprintf('%04d-%02d-01', $year, $month);
                $eDate = sprintf('%04d-%02d-07', $year, $month);
                $label = sprintf('%04d-%02d M1 (01 s/d 07)', $year, $month);
                break;
            case 2:
                $sDate = sprintf('%04d-%02d-08', $year, $month);
                $eDate = sprintf('%04d-%02d-14', $year, $month);
                $label = sprintf('%04d-%02d M2 (08 s/d 14)', $year, $month);
                break;
            case 3:
                $sDate = sprintf('%04d-%02d-15', $year, $month);
                $eDate = sprintf('%04d-%02d-21', $year, $month);
                $label = sprintf('%04d-%02d M3 (15 s/d 21)', $year, $month);
                break;
            case 4:
                $sDate = sprintf('%04d-%02d-22', $year, $month);
                $eDate = sprintf('%04d-%02d-28', $year, $month);
                $label = sprintf('%04d-%02d M4 (22 s/d 28)', $year, $month);
                break;
            case 5:
            default:
                $sDate = sprintf('%04d-%02d-29', $year, $month);
                $eDate = sprintf('%04d-%02d-%02d', $year, $month, $daysInMonth);
                $label = sprintf('%04d-%02d M5 (29 s/d %02d)', $year, $month, $daysInMonth);
                break;
        }

        return [$sDate, $eDate, $label, $weekFormatted];
    }

    /**
     * Dapatkan informasi pekan untuk tanggal tertentu
     *
     * @param string|Carbon $date
     * @return array
     */
    public static function getWeekInfoForDate($date): array
    {
        $carbonDate = $date instanceof Carbon ? $date : Carbon::parse($date);
        $year  = (int) $carbonDate->year;
        $month = (int) $carbonDate->month;
        $day   = (int) $carbonDate->day;

        if ($day <= 7) {
            $weekNum = 1;
        } elseif ($day <= 14) {
            $weekNum = 2;
        } elseif ($day <= 21) {
            $weekNum = 3;
        } elseif ($day <= 28) {
            $weekNum = 4;
        } else {
            $weekNum = 5;
        }

        [$sDate, $eDate, $label, $weekFormatted] = self::resolveDateRange($year, $month, $weekNum);

        return [
            'year'           => $year,
            'month'          => $month,
            'day'            => $day,
            'week'           => $weekFormatted,
            'week_number'    => $weekNum,
            'start_date'     => $sDate,
            'end_date'       => $eDate,
            'period_label'   => $label,
        ];
    }

    /**
     * Cek apakah tanggal transaksi berada pada pekan yang terkunci
     *
     * @param string|Carbon $date
     * @return bool
     */
    public static function isDateLocked($date): bool
    {
        try {
            $info = self::getWeekInfoForDate($date);

            return self::where('year', $info['year'])
                ->where('month', $info['month'])
                ->where('week', $info['week'])
                ->where('is_locked', true)
                ->exists();
        } catch (\Exception $e) {
            return false;
        }
    }
}
