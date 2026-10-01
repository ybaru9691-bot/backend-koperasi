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
        if (Schema::hasTable('loans')) {
            Schema::table('loans', function (Blueprint $table) {
                if (!Schema::hasColumn('loans', 'tenor_months')) {
                    $table->integer('tenor_months')->nullable();
                }
                if (!Schema::hasColumn('loans', 'remaining_principal')) {
                    $table->decimal('remaining_principal', 15, 2)->nullable();
                }
                if (!Schema::hasColumn('loans', 'interest_method')) {
                    $table->string('interest_method')->default('declining_balance');
                }
            });
        }

        if (Schema::hasTable('loan_installments')) {
            Schema::table('loan_installments', function (Blueprint $table) {
                if (!Schema::hasColumn('loan_installments', 'receipt_number')) {
                    $table->string('receipt_number')->nullable();
                }
                if (!Schema::hasColumn('loan_installments', 'beginning_balance')) {
                    $table->decimal('beginning_balance', 15, 2)->default(0);
                }
                if (!Schema::hasColumn('loan_installments', 'ending_balance')) {
                    $table->decimal('ending_balance', 15, 2)->default(0);
                }
                if (!Schema::hasColumn('loan_installments', 'penalty_fee')) {
                    $table->decimal('penalty_fee', 15, 2)->default(0);
                }
                if (!Schema::hasColumn('loan_installments', 'paid_by')) {
                    $table->integer('paid_by')->nullable();
                }
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::hasTable('loans')) {
            Schema::table('loans', function (Blueprint $table) {
                $table->dropColumn(['tenor_months', 'remaining_principal', 'interest_method']);
            });
        }
        
        if (Schema::hasTable('loan_installments')) {
            Schema::table('loan_installments', function (Blueprint $table) {
                $table->dropColumn(['receipt_number', 'beginning_balance', 'ending_balance', 'penalty_fee', 'paid_by']);
            });
        }
    }
};
