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
        Schema::table('members', function (Blueprint $table) {
            if (!Schema::hasColumn('members', 'has_buku_biru')) {
                $table->boolean('has_buku_biru')->default(true)->after('user_id');
            }
            if (!Schema::hasColumn('members', 'has_buku_putih')) {
                $table->boolean('has_buku_putih')->default(false)->after('has_buku_biru');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('members', function (Blueprint $table) {
            if (Schema::hasColumn('members', 'has_buku_biru')) {
                $table->dropColumn('has_buku_biru');
            }
            if (Schema::hasColumn('members', 'has_buku_putih')) {
                $table->dropColumn('has_buku_putih');
            }
        });
    }
};
