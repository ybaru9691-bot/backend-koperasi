<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('members', function (Blueprint $table) {
            $table->id();
            
            // Relasi User / Otentikasi (Nullable)
            $table->foreignId('user_id')->nullable()->constrained('users')->onDelete('set null');        
            // 1. Data Identitas Anggota
            $table->string('member_number', 30)->unique()->nullable(); // Nomor Anggota / Register
            $table->string('nik', 16)->unique();                       // NIK (16 digit)
            $table->string('name');                                     // Nama Lengkap
            $table->string('email')->nullable();                        // Email (Nullable)
            $table->string('password')->nullable();                     // Password (Nullable)
            $table->string('pin_code', 6)->nullable();                  // PIN Transaksi (Nullable)
            $table->string('place_of_birth')->nullable();               // Tempat Lahir
            $table->date('date_of_birth')->nullable();                  // Tanggal Lahir
            $table->string('gender', 20)->nullable();                   // Jenis Kelamin
            $table->string('phone', 20)->nullable();                    // No Handphone / WA
            $table->string('occupation')->nullable();                  // Pekerjaan
            $table->string('education')->nullable();                   // Pendidikan Terakhir
            $table->string('family_status')->nullable();               // Status Keluarga
            $table->string('church_sector')->nullable();               // Sektor / Wijk Gereja
            $table->text('address')->nullable();                        // Alamat Lengkap
            
            // 2. Data Ahli Waris
            $table->string('heir_name')->nullable();                   // Nama Ahli Waris
            $table->string('heir_relationship')->nullable();           // Hubungan Ahli Waris
            $table->string('heir_place_of_birth')->nullable();         // Tempat Lahir Ahli Waris
            $table->date('heir_date_of_birth')->nullable();            // Tanggal Lahir Ahli Waris
            $table->text('heir_address')->nullable();                  // Alamat Ahli Waris
            
            // 3. Rincian Setoran Awal Keuangan
            $table->decimal('registration_fee', 15, 2)->default(0.00); // Uang Pangkal / Pendaftaran
            $table->decimal('principal_savings', 15, 2)->default(0.00); // Simpanan Pokok
            $table->decimal('mandatory_savings', 15, 2)->default(0.00); // Simpanan Wajib
            $table->decimal('voluntary_savings', 15, 2)->default(0.00); // Simpanan Sukarela
            $table->decimal('social_fund', 15, 2)->default(0.00);       // Dana Duka / Sosial
            $table->decimal('grief_fund', 15, 2)->default(0.00);        // Dana Duka
            
            // Status & Timestamps
            $table->enum('status', ['active', 'inactive', 'suspended'])->default('active');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('members');
    }
};