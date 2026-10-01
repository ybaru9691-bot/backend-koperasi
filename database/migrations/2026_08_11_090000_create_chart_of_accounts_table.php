<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('chart_of_accounts', function (Blueprint $table) {
            $table->id();
            $table->string('account_code', 10)->unique();        // Kode Perkiraan (e.g. 1000, 4180, 7110)
            $table->string('account_name');                       // Nama Perkiraan
            $table->string('account_type', 20);                   // ASSET, LIABILITY, EQUITY, REVENUE, EXPENSE
            $table->string('normal_balance', 10);                 // DEBIT atau CREDIT
            $table->string('parent_code', 10)->nullable();        // Kode induk (untuk sub-akun)
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('chart_of_accounts');
    }
};
