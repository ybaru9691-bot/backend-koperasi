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
        if (Schema::hasTable('periods')) {
            Schema::table('periods', function (Blueprint $table) {
                if (!Schema::hasColumn('periods', 'is_locked')) {
                    $table->boolean('is_locked')->default(false)->after('status');
                }
                if (!Schema::hasColumn('periods', 'is_active')) {
                    $table->boolean('is_active')->default(false)->after('is_locked');
                }
            });
        }

        if (Schema::hasTable('accounting_periods')) {
            Schema::table('accounting_periods', function (Blueprint $table) {
                if (!Schema::hasColumn('accounting_periods', 'is_locked')) {
                    $table->boolean('is_locked')->default(false)->after('status');
                }
                if (!Schema::hasColumn('accounting_periods', 'is_active')) {
                    $table->boolean('is_active')->default(false)->after('is_locked');
                }
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::hasTable('periods')) {
            Schema::table('periods', function (Blueprint $table) {
                if (Schema::hasColumn('periods', 'is_active')) {
                    $table->dropColumn('is_active');
                }
                if (Schema::hasColumn('periods', 'is_locked')) {
                    $table->dropColumn('is_locked');
                }
            });
        }

        if (Schema::hasTable('accounting_periods')) {
            Schema::table('accounting_periods', function (Blueprint $table) {
                if (Schema::hasColumn('accounting_periods', 'is_active')) {
                    $table->dropColumn('is_active');
                }
                if (Schema::hasColumn('accounting_periods', 'is_locked')) {
                    $table->dropColumn('is_locked');
                }
            });
        }
    }
};