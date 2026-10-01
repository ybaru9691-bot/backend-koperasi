<?php

namespace App\Console\Commands;

use App\Models\Member;
use App\Models\Transaction;
use Illuminate\Console\Command;
use Carbon\Carbon;

class CheckInactiveMembers extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'members:check-inactive';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Deactivate members with no transactions in the last 6 months (180 days)';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $this->info("Checking active members with no transactions in the last 180 days...");

        $sixMonthsAgo = Carbon::now()->subDays(180);
        $activeMembers = Member::where('status', 'active')->get();
        $deactivatedCount = 0;

        foreach ($activeMembers as $member) {
            // Find the latest approved transaction
            $latestTransaction = Transaction::where('member_id', $member->id)
                ->where('status', 'approved')
                ->latest('transaction_date')
                ->latest('id')
                ->first();

            if ($latestTransaction) {
                $lastDate = Carbon::parse($latestTransaction->transaction_date);
                if ($lastDate->lt($sixMonthsAgo)) {
                    $member->status = 'inactive';
                    $member->save();
                    $deactivatedCount++;
                    $this->info("Deactivated member: {$member->name} (ID: {$member->id}), last transaction date: {$lastDate->toDateString()}");
                }
            } else {
                // No transactions at all, check registration date
                $regDate = $member->created_at ? Carbon::parse($member->created_at) : null;
                if ($regDate && $regDate->lt($sixMonthsAgo)) {
                    $member->status = 'inactive';
                    $member->save();
                    $deactivatedCount++;
                    $this->info("Deactivated legacy member (no transactions): {$member->name} (ID: {$member->id}), registered at: {$regDate->toDateString()}");
                }
            }
        }

        $this->info("Check complete. Total deactivated members: {$deactivatedCount}");

        return Command::SUCCESS;
    }
}
