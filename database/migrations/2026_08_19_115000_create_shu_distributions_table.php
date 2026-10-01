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
        if (!Schema::hasTable('shu_distributions')) {
            Schema::create('shu_distributions', function (Blueprint $table) {
                $table->id();
                $table->foreignId('period_id')->nullable()->constrained('periods')->onDelete('cascade');
                $table->string('period_name', 100)->nullable();
                $table->foreignId('member_id')->constrained('members')->onDelete('cascade');
                $table->string('member_name', 100)->nullable();
                $table->string('member_number', 50)->nullable();
                $table->decimal('simpanan_pokok', 15, 2)->default(0.00);
                $table->decimal('simpanan_wajib', 15, 2)->default(0.00);
                $table->decimal('total_saham', 15, 2)->default(0.00);
                $table->decimal('jasa_saham', 15, 2)->default(0.00);
                $table->decimal('deviden', 15, 2)->default(0.00);
                $table->decimal('gross_shu', 15, 2)->default(0.00);
                $table->decimal('potongan_duka', 15, 2)->default(0.00);
                $table->decimal('potongan_wajib', 15, 2)->default(0.00);
                $table->decimal('total_potongan', 15, 2)->default(0.00);
                $table->decimal('net_shu', 15, 2)->default(0.00);
                $table->string('status', 30)->default('distributed');
                $table->timestamp('distributed_at')->nullable();
                $table->text('notes')->nullable();
                $table->timestamps();
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('shu_distributions');
    }
};