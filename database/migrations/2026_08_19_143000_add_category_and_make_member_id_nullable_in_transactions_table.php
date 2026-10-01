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
        Schema::table('transactions', function (Blueprint $table) {
            if (!Schema::hasColumn('transactions', 'category')) {
                $table->string('category', 100)->nullable()->after('type');
            }
        });

        // Make member_id nullable for general operational / non-member transactions
        if (Schema::hasTable('transactions') && Schema::hasColumn('transactions', 'member_id')) {
            try {
                Schema::table('transactions', function (Blueprint $table) {
                    $table->foreignId('member_id')->nullable()->change();
                });
            } catch (\Throwable $e) {
                // SQLite or DB specific fallback
            }
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            if (Schema::hasColumn('transactions', 'category')) {
                $table->dropColumn('category');
            }
        });
    }
};