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
        Schema::create('periods', function (Blueprint $table) {
            $table->id();
            $table->string('period_name', 50); // Contoh: "Januari 2026" atau "Tahun Buku 2026"
            $table->date('start_date');        // Tanggal Mulai Periode
            $table->date('end_date');          // Tanggal Selesai Periode
            
            // Status Periode: open (bisa input transaksi), closed (dikunci/tutup buku)
            $table->enum('status', ['open', 'closed'])->default('open');
            
            // Audit Trail Tutup Buku
            $table->foreignId('closed_by')->nullable()->constrained('users')->onDelete('set null');
            $table->timestamp('closed_at')->nullable();
            
            $table->text('notes')->nullable(); // Catatan tambahan (misal: Laporan SHU disahkan)
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('periods');
    }
};