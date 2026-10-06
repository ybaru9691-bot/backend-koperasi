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
        // 1. Indeks untuk tabel transactions (mempercepat filter tanggal, status, dan tipe transaksi)
        Schema::table('transactions', function (Blueprint $table) {
            $table->index(['status', 'transaction_date'], 'idx_trx_status_date');
            $table->index(['member_id', 'status', 'transaction_date'], 'idx_trx_member_status_date');
            $table->index(['status', 'type'], 'idx_trx_status_type');
        });

        // 2. Indeks untuk tabel members (mempercepat lookup status anggota aktif)
        Schema::table('members', function (Blueprint $table) {
            $table->index('status', 'idx_members_status');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            $table->dropIndex('idx_trx_status_date');
            $table->dropIndex('idx_trx_member_status_date');
            $table->dropIndex('idx_trx_status_type');
        });

        Schema::table('members', function (Blueprint $table) {
            $table->dropIndex('idx_members_status');
        });
    }
};