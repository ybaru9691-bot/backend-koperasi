<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('transactions', function (Blueprint $table) {
            $table->id();
            
            // Nomor Transaksi / Nota (Unik)
            $table->string('transaction_number', 50)->unique();
            
            // Foreign Keys
            $table->foreignId('member_id')->constrained('members')->onDelete('cascade');
            $table->foreignId('account_id')->nullable()->constrained('accounts')->onDelete('cascade');
            $table->foreignId('operator_id')->nullable()->constrained('users')->onDelete('set null');
            $table->foreignId('approved_by')->nullable()->constrained('users')->onDelete('set null');

            // Detail Transaksi & Nominal
            $table->enum('type', ['deposit', 'withdrawal', 'transfer', 'fee', 'interest', 'in', 'out'])->default('deposit');
            $table->decimal('amount', 15, 2);
            $table->decimal('beginning_balance', 15, 2)->default(0.00);
            $table->decimal('ending_balance', 15, 2)->default(0.00);
            
            // Pembayaran & Status
            $table->enum('payment_method', ['cash', 'transfer'])->default('cash');
            $table->date('transaction_date');
            $table->text('description')->nullable();
            $table->enum('status', ['pending', 'approved', 'rejected', 'cancelled'])->default('approved');
            $table->timestamp('approved_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('transactions');
    }
};