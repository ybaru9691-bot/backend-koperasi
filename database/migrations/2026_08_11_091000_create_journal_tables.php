<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Tabel Header Jurnal
        Schema::create('journal_entries', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('transaction_id')->nullable();  // FK ke transactions (nullable untuk jurnal manual)
            $table->date('entry_date');
            $table->string('voucher_number', 30)->unique();            // Nomor Voucher Jurnal (JV-YYYYMMDD-XXXX)
            $table->text('description')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();      // User ID pembuat
            $table->timestamps();

            $table->index('transaction_id');
            $table->index('entry_date');
        });

        // Tabel Detail Jurnal (Double Entry: Debit & Kredit)
        Schema::create('journal_details', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('journal_entry_id');
            $table->unsignedBigInteger('account_id');                  // FK ke chart_of_accounts
            $table->decimal('debit', 15, 2)->default(0);
            $table->decimal('credit', 15, 2)->default(0);
            $table->string('description')->nullable();                 // Keterangan per baris
            $table->timestamps();

            $table->foreign('journal_entry_id')
                  ->references('id')
                  ->on('journal_entries')
                  ->onDelete('cascade');

            $table->foreign('account_id')
                  ->references('id')
                  ->on('chart_of_accounts')
                  ->onDelete('restrict');

            $table->index('journal_entry_id');
            $table->index('account_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('journal_details');
        Schema::dropIfExists('journal_entries');
    }
};
