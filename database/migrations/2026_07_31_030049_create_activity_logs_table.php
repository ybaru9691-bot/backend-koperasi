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
        Schema::create('activity_logs', function (Blueprint $table) {
            $table->id();
            
            // Actor: Siapa yang melakukan aksi (User/Admin/Teller)
            $table->foreignId('user_id')->nullable()->constrained('users')->onDelete('set null');
            
            // Action & Context
            $table->string('action', 100); // Contoh: 'CREATE_LOAN', 'APPROVE_LOAN', 'CLOSE_PERIOD', 'LOGIN'
            $table->string('description')->nullable(); // Penjelasan singkat aksi
            
            // Polymorphic relation untuk menunjuk ke tabel/model mana yang diubah (misal: App\Models\Loan ID 5)
            $table->nullableMorphs('subject'); 
            
            // Data Changes (Audit Detail)
            $table->json('properties')->nullable(); // Menyimpan data sebelum & sesudah (old values vs new values)
            
            // Client Metadata
            $table->string('ip_address', 45)->nullable();
            $table->string('user_agent')->nullable();
            
            $table->timestamp('created_at')->useCurrent(); // Hanya butuh created_at (log tidak boleh di-update)
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('activity_logs');
    }
};