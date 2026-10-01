<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('initial_account_balances', function (Blueprint $table) {
            $table->id();
            $table->date('cutoff_date')->default('2026-05-01');
            $table->string('account_code', 50);
            $table->decimal('debit', 15, 2)->default(0.00);
            $table->decimal('credit', 15, 2)->default(0.00);
            $table->timestamps();

            $table->unique(['cutoff_date', 'account_code'], 'uniq_initial_balance_cutoff_account');
            $table->index('account_code');
            $table->index('cutoff_date');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('initial_account_balances');
    }
};
