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
        if (\Illuminate\Support\Facades\DB::getDriverName() !== 'sqlite') {
            \Illuminate\Support\Facades\DB::statement("ALTER TABLE transactions MODIFY COLUMN payment_method VARCHAR(20) NOT NULL DEFAULT 'cash'");
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Untuk rollback, bisa diubah kembali ke ENUM jika diperlukan
        // \Illuminate\Support\Facades\DB::statement("ALTER TABLE transactions MODIFY COLUMN payment_method ENUM('cash') NOT NULL DEFAULT 'cash'");
    }
};
