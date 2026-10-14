<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('loans', function (Blueprint $table) {
            if (!Schema::hasColumn('loans', 'interest_arrears')) {
                $table->decimal('interest_arrears', 15, 2)->default(0)->after('remaining_amount');
            }
        });

        Schema::table('loan_installments', function (Blueprint $table) {
            if (!Schema::hasColumn('loan_installments', 'scheduled_interest')) {
                $table->decimal('scheduled_interest', 15, 2)->nullable()->after('interest_amount');
            }
            if (!Schema::hasColumn('loan_installments', 'arrears_interest_billed')) {
                $table->decimal('arrears_interest_billed', 15, 2)->default(0)->after('scheduled_interest');
            }
            if (!Schema::hasColumn('loan_installments', 'unpaid_interest')) {
                $table->decimal('unpaid_interest', 15, 2)->default(0)->after('arrears_interest_billed');
            }
        });
    }

    public function down(): void
    {
        Schema::table('loan_installments', function (Blueprint $table) {
            $columnsToDrop = [];
            foreach (['scheduled_interest', 'arrears_interest_billed', 'unpaid_interest'] as $col) {
                if (Schema::hasColumn('loan_installments', $col)) {
                    $columnsToDrop[] = $col;
                }
            }
            if (!empty($columnsToDrop)) {
                $table->dropColumn($columnsToDrop);
            }
        });

        Schema::table('loans', function (Blueprint $table) {
            if (Schema::hasColumn('loans', 'interest_arrears')) {
                $table->dropColumn('interest_arrears');
            }
        });
    }
};