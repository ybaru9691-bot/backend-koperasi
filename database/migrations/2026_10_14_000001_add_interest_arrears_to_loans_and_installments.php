<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (Schema::hasTable('loans')) {
            Schema::table('loans', function (Blueprint $table) {
                if (!Schema::hasColumn('loans', 'interest_arrears')) {
                    $table->decimal('interest_arrears', 15, 2)->default(0.00)->after('remaining_principal');
                }
            });
        }

        if (Schema::hasTable('loan_installments')) {
            Schema::table('loan_installments', function (Blueprint $table) {
                if (!Schema::hasColumn('loan_installments', 'scheduled_interest')) {
                    $table->decimal('scheduled_interest', 15, 2)->nullable()->default(0.00)->after('interest_amount');
                }
                if (!Schema::hasColumn('loan_installments', 'arrears_interest_billed')) {
                    $table->decimal('arrears_interest_billed', 15, 2)->default(0.00)->after('scheduled_interest');
                }
                if (!Schema::hasColumn('loan_installments', 'unpaid_interest')) {
                    $table->decimal('unpaid_interest', 15, 2)->default(0.00)->after('arrears_interest_billed');
                }
            });

            // Pastikan kolom status dapat menampung 'partial' dan 'partially_paid'
            try {
                DB::statement("ALTER TABLE loan_installments MODIFY COLUMN status VARCHAR(50) NOT NULL DEFAULT 'unpaid'");
            } catch (\Throwable $e) {
                // Abaikan jika database engine SQLite atau tidak mendukung MODIFY COLUMN
            }
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::hasTable('loans')) {
            Schema::table('loans', function (Blueprint $table) {
                if (Schema::hasColumn('loans', 'interest_arrears')) {
                    $table->dropColumn('interest_arrears');
                }
            });
        }

        if (Schema::hasTable('loan_installments')) {
            Schema::table('loan_installments', function (Blueprint $table) {
                $colsToDrop = [];
                if (Schema::hasColumn('loan_installments', 'scheduled_interest')) {
                    $colsToDrop[] = 'scheduled_interest';
                }
                if (Schema::hasColumn('loan_installments', 'arrears_interest_billed')) {
                    $colsToDrop[] = 'arrears_interest_billed';
                }
                if (Schema::hasColumn('loan_installments', 'unpaid_interest')) {
                    $colsToDrop[] = 'unpaid_interest';
                }
                if (!empty($colsToDrop)) {
                    $table->dropColumn($colsToDrop);
                }
            });
        }
    }
};
