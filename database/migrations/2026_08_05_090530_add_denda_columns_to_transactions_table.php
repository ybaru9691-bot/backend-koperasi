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
            if (!Schema::hasColumn('transactions', 'denda')) {
                $table->decimal('denda', 15, 2)->default(0.00)->after('amount');
            }
            if (!Schema::hasColumn('transactions', 'denda_reason')) {
                $table->string('denda_reason')->nullable()->after('denda');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            $columns = [];
            if (Schema::hasColumn('transactions', 'denda_reason')) {
                $columns[] = 'denda_reason';
            }
            if (!empty($columns)) {
                $table->dropColumn($columns);
            }
        });
    }
};
