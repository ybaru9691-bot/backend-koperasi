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
        Schema::create('loan_installments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('loan_id')->constrained('loans')->onDelete('cascade');
            $table->foreignId('paid_by_member_id')->constrained('members')->onDelete('cascade');
            $table->foreignId('received_by_user_id')->nullable()->constrained('users')->onDelete('set null'); // Teller/Admin penerima

            $table->integer('installment_number'); // Angsuran ke- (1, 2, 3, dst.)
            $table->decimal('principal_amount', 15, 2); // Nominal Pokok
            $table->decimal('interest_amount', 15, 2);  // Nominal Bunga / Jasa
            $table->decimal('penalty_amount', 15, 2)->default(0.00); // Denda keterlambatan (jika ada)
            $table->decimal('total_amount', 15, 2);     // Total yang harus dibayar (Pokok + Bunga + Denda)

            $table->date('due_date'); // Tanggal Jatuh Tempo Pembayaran
            $table->date('paid_at')->nullable(); // Tanggal Realisasi Pembayaran

            $table->enum('status', ['unpaid', 'paid', 'late', 'partially_paid'])->default('unpaid');
            $table->text('notes')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('loan_installments');
    }
};