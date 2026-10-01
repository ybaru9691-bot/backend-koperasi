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
        Schema::create('loans', function (Blueprint $table) {
            $table->id();
            $table->string('loan_code', 50)->unique(); // Nomor Kontrak Pinjaman (misal: LND-202607-001)
            $table->foreignId('member_id')->constrained('members')->onDelete('cascade');
            
            // Perhitungan Pinjaman
            $table->decimal('amount', 15, 2); // Plafon/Nominal Pinjaman Utama
            $table->decimal('interest_rate', 5, 2); // Bunga/Jasa Pinjaman (%)
            $table->integer('duration_months'); // Tenor/Jangka Waktu (Bulan)
            $table->decimal('monthly_installment', 15, 2); // Estimasi Angsuran Pokok + Bunga per Bulan
            $table->decimal('remaining_amount', 15, 2); // Sisa Pokok Pinjaman
            $table->string('purpose', 255)->nullable();
            $table->string('collateral', 255)->nullable();
            
            // Persetujuan & Audit
            $table->foreignId('approved_by')->nullable()->constrained('users')->onDelete('set null'); // Manager/Ketua
            $table->enum('status', ['pending', 'pending_admin', 'pending_manager', 'approved', 'rejected', 'active', 'paid_off', 'completed', 'defaulted'])->default('pending_admin');
            
            // Tanggal Penting
            $table->date('application_date'); // Tanggal Pengajuan
            $table->timestamp('approved_at')->nullable(); // Tanggal Disetujui
            $table->date('disbursement_date')->nullable(); // Tanggal Pencairan Dana
            $table->date('due_date')->nullable(); // Tanggal Jatuh Tempo Akhir
            
            $table->text('notes')->nullable(); // Catatan Tambahan (Keperluan/Agunan)
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('loans');
    }
};