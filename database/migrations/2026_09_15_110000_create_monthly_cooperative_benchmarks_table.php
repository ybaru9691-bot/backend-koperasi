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
        Schema::create('monthly_cooperative_benchmarks', function (Blueprint $table) {
            $table->id();
            $table->unsignedSmallInteger('fiscal_year')->index(); // Tahun buku, misal: 2026
            $table->unsignedTinyInteger('month')->index(); // Bulan 1-12
            $table->date('cycle_start_date')->nullable(); // Tanggal 21 bulan sebelumnya
            $table->date('cycle_end_date')->nullable(); // Tanggal 20 bulan berjalan
            $table->decimal('net_income', 15, 2)->nullable(); // SHU Setelah Beban Koperasi (manual input / override)
            $table->decimal('dividend_allocation_percent', 5, 2)->default(25.00); // Alokasi dividen %, default 25.00
            $table->decimal('total_coop_shares', 15, 2)->nullable(); // Total Saham Koperasi (nullable, auto-calc fallback)
            $table->boolean('is_locked')->default(false); // True jika periode sudah ditutup / dikunci
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['fiscal_year', 'month'], 'uniq_benchmark_fiscal_year_month');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('monthly_cooperative_benchmarks');
    }
};
