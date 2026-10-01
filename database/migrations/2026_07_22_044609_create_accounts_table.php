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
        Schema::create('accounts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('member_id')->nullable()->constrained('members')->onDelete('cascade'); 
            $table->string('account_number', 30)->unique(); // Nomor Rekening (misal: SP-001, SW-001, KAS-01)
            $table->string('account_name'); // Nama Akun / Simpanan (Simpanan Pokok, Simpanan Wajib, Kas Utama)
            $table->enum('account_type', ['pokok', 'wajib', 'sukarela', 'kas', 'saham_penyerta'])->default('sukarela');
            $table->enum('category', ['asset', 'liability', 'equity', 'revenue', 'expense']); // Kategori Akuntansi
            $table->decimal('balance', 15, 2)->default(0.00); // Saldo akun/rekening
            $table->enum('status', ['active', 'closed', 'frozen'])->default('active');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('accounts');
    }
};