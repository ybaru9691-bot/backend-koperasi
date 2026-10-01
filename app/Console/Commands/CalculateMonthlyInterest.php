<?php

namespace App\Console\Commands;

use App\Services\BukuPutihInterestService;
use Illuminate\Console\Command;

class CalculateMonthlyInterest extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'members:calculate-interest {--month= : Bulan kalkulasi (1-12)} {--year= : Tahun kalkulasi}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Calculate and apply 0.6% monthly interest for active members with Buku Putih (Daily Savings)';

    /**
     * Execute the console command.
     */
    public function handle(BukuPutihInterestService $service)
    {
        $month = (int) ($this->option('month') ?: now()->month);
        $year  = (int) ($this->option('year') ?: now()->year);

        $this->info("Starting monthly interest calculation (0.6%) for Buku Putih (Month: {$month}, Year: {$year})...");

        try {
            $result = $service->distribute($month, $year);

            if ($result['processed_count'] > 0) {
                $this->info("Success: Distributed total Rp " . number_format($result['total_interest'], 0, ',', '.') . " to {$result['processed_count']} members.");
                $this->info("Memorial Journal Voucher: " . $result['voucher_number']);
            } else {
                $this->warn($result['message']);
            }

            return Command::SUCCESS;
        } catch (\Exception $e) {
            $this->error("Failed to distribute interest: " . $e->getMessage());
            return Command::FAILURE;
        }
    }
}
