<?php

namespace App\Console\Commands;

use App\Models\Member;
use App\Models\Transaction;
use Illuminate\Console\Command;

class RecalculateMemberBalances extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'member:recalculate {member_id} {--principal=0} {--mandatory=0} {--voluntary=0} {--daily=0}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Recalculate and update member balances based on approved transactions history';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $memberId = $this->argument('member_id');
        $member = Member::find($memberId);

        if (!$member) {
            $this->error("Member with ID {$memberId} not found!");
            return Command::FAILURE;
        }

        $basePrincipal = (float) $this->option('principal');
        $baseMandatory = (float) $this->option('mandatory');
        $baseVoluntary = (float) $this->option('voluntary');
        $baseDaily     = (float) $this->option('daily');

        $this->info("Recalculating balances for {$member->name} (ID: {$member->id})...");

        // Fetch all approved transactions
        $transactions = Transaction::where('member_id', $member->id)
            ->where('status', 'approved')
            ->orderBy('id', 'asc')
            ->get();

        $principal = $basePrincipal;
        $mandatory = $baseMandatory;
        $voluntary = $baseVoluntary;
        $daily     = $baseDaily;

        foreach ($transactions as $trx) {
            $amount = (float) $trx->amount;
            $type = strtolower($trx->type);
            $desc = strtolower($trx->description ?? '');
            $bookType = $trx->book_type;

            $isDeposit = in_array($type, ['deposit', 'in', 'kas_masuk']);
            $isWithdrawal = in_array($type, ['withdrawal', 'out', 'kas_keluar']);

            // Determine classification: daily vs blue book items
            $isHarian = ($bookType === 'BUKU_PUTIH' || str_contains($desc, 'harian') || str_contains($desc, 'putih'));

            if ($isHarian) {
                if ($isDeposit) {
                    $daily += $amount;
                } elseif ($isWithdrawal) {
                    $daily -= $amount;
                }
            } else {
                if (str_contains($desc, 'pokok')) {
                    if ($isDeposit) {
                        $principal += $amount;
                    } elseif ($isWithdrawal) {
                        $principal -= $amount;
                    }
                } elseif (str_contains($desc, 'wajib')) {
                    if ($isDeposit) {
                        $mandatory += $amount;
                    } elseif ($isWithdrawal) {
                        $mandatory -= $amount;
                    }
                } else {
                    if ($isDeposit) {
                        $voluntary += $amount;
                    } elseif ($isWithdrawal) {
                        $voluntary -= $amount;
                    }
                }
            }
        }

        $this->info("\nRecalculation Results:");
        $this->info("- Principal Savings: {$principal}");
        $this->info("- Mandatory Savings: {$mandatory}");
        $this->info("- Voluntary Savings: {$voluntary}");
        $this->info("- Daily Savings:     {$daily}");

        // Save to database
        $member->principal_savings = $principal;
        $member->mandatory_savings = $mandatory;
        $member->voluntary_savings = $voluntary;
        $member->daily_savings = $daily;
        $member->save();

        $this->info("\nDatabase successfully synchronized!");

        return Command::SUCCESS;
    }
}
