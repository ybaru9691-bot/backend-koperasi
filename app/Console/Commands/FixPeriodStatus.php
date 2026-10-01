<?php

namespace App\Console\Commands;

use App\Models\AccountingPeriod;
use App\Models\Period;
use Illuminate\Console\Command;

class FixPeriodStatus extends Command
{
    protected $signature = 'koperasi:fix-period';
    protected $description = 'Fix and activate running accounting period Juni 2026 - Mei 2027';

    public function handle(): int
    {
        $this->info('Cleaning duplicate periods and fixing active period...');

        // 1. Deduplikasi tabel accounting_periods
        $accPeriodNames = AccountingPeriod::select('period_name')->distinct()->pluck('period_name');
        foreach ($accPeriodNames as $pName) {
            $records = AccountingPeriod::where('period_name', $pName)->orderBy('id', 'desc')->get();
            if ($records->count() > 1) {
                // Pertahankan record pertama (terbaru), hapus sisanya
                $keep = $records->first();
                AccountingPeriod::where('period_name', $pName)->where('id', '!=', $keep->id)->delete();
                $this->line("Deduplicated AccountingPeriod: {$pName} (Kept ID: {$keep->id})");
            }
        }

        // 2. Deduplikasi tabel periods
        $periodNames = Period::select('period_name')->distinct()->pluck('period_name');
        foreach ($periodNames as $pName) {
            $records = Period::where('period_name', $pName)->orderBy('id', 'desc')->get();
            if ($records->count() > 1) {
                $keep = $records->first();
                Period::where('period_name', $pName)->where('id', '!=', $keep->id)->delete();
                $this->line("Deduplicated Period: {$pName} (Kept ID: {$keep->id})");
            }
        }

        // 3. Kunci semua periode selain Juni 2026 - Mei 2027
        Period::where('period_name', '!=', 'Juni 2026 - Mei 2027')->update([
            'status'    => 'closed',
            'is_locked' => true,
            'is_active' => false,
        ]);

        AccountingPeriod::where('period_name', '!=', 'Juni 2026 - Mei 2027')->update([
            'status'    => 'LOCKED',
            'is_locked' => true,
            'is_active' => false,
        ]);

        // 4. Set periode Juni 2026 - Mei 2027 sebagai OPEN, is_locked = 0, is_active = 1
        $openPeriod = Period::where('period_name', 'Juni 2026 - Mei 2027')
            ->orWhere(function ($q) {
                $q->where('start_date', '>=', '2026-06-01')
                  ->where('end_date', '<=', '2027-05-31');
            })
            ->latest('id')
            ->first();

        if (!$openPeriod) {
            $openPeriod = Period::create([
                'period_name' => 'Juni 2026 - Mei 2027',
                'start_date'  => '2026-06-01',
                'end_date'    => '2027-05-31',
                'status'      => 'open',
                'is_locked'   => false,
                'is_active'   => true,
                'notes'       => 'Tahun Buku Berjalan 2026 - 2027',
            ]);
        } else {
            $openPeriod->update([
                'period_name' => 'Juni 2026 - Mei 2027',
                'status'      => 'open',
                'is_locked'   => false,
                'is_active'   => true,
                'closed_at'   => null,
                'closed_by'   => null,
            ]);
        }

        $accPeriod = AccountingPeriod::where('period_name', 'Juni 2026 - Mei 2027')
            ->orWhere(function ($q) {
                $q->where('start_date', '>=', '2026-06-01')
                  ->where('end_date', '<=', '2027-05-31');
            })
            ->latest('id')
            ->first();

        if (!$accPeriod) {
            $accPeriod = AccountingPeriod::create([
                'period_name' => 'Juni 2026 - Mei 2027',
                'start_date'  => '2026-06-01',
                'end_date'    => '2027-05-31',
                'status'      => 'OPEN',
                'is_locked'   => false,
                'is_active'   => true,
                'notes'       => 'Tahun Buku Berjalan 2026 - 2027',
            ]);
        } else {
            $accPeriod->update([
                'period_name' => 'Juni 2026 - Mei 2027',
                'status'      => 'OPEN',
                'is_locked'   => false,
                'is_active'   => true,
                'closed_at'   => null,
                'closed_by'   => null,
            ]);
        }

        $this->info("SUCCESS: Period '{$openPeriod->period_name}' (ID: {$openPeriod->id}) is now OPEN (is_locked=0, is_active=1).");
        return 0;
    }
}