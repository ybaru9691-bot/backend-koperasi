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
    Schema::table('loans', function (Blueprint $table) {
        if (!Schema::hasColumn('loans', 'interest_arrears')) {
            $table->decimal('interest_arrears', 15, 2)->default(0)->after('remaining_amount');
        }
        // Jika ada kolom lain yang ditambahkan di migrasi ini, bungkus dengan if yang sama:
        // if (!Schema::hasColumn('loans', 'nama_kolom_lain')) { ... }
    });
}

public function down(): void
{
    Schema::table('users', function (Blueprint $table) {
        $table->dropColumn('is_active');
    });
    }
};
