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
        if (Schema::hasTable('transactions') && Schema::hasColumn('transactions', 'book_type')) {
            Schema::table('transactions', function (Blueprint $table) {
                $table->string('book_type', 50)->nullable()->default(null)->change();
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::hasTable('transactions') && Schema::hasColumn('transactions', 'book_type')) {
            Schema::table('transactions', function (Blueprint $table) {
                $table->string('book_type', 50)->nullable()->default('BUKU_BIRU')->change();
            });
        }
    }
};
