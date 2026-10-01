<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('announcements', function (Blueprint $table) {
            $table->id();
            $table->string('title');
            $table->text('content');
            $table->string('category')->default('INFORMASI'); // PENTING, INFORMASI, PROMO
            $table->string('author_name')->nullable(); // Nama pembuat
            $table->string('author_role')->nullable(); // e.g. Pendeta, Manajer Keuangan
            $table->unsignedBigInteger('created_by')->nullable(); // user ID pembuat
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('announcements');
    }
};
