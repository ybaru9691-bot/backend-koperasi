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
        Schema::create('jobs', function (Blueprint $table) {
            $table->id();// id unik untuk setiap pekerjaan
            $table->string('queue')->index();// nama antrian tempat pekerjaan akan dijalankan
            $table->longText('payload');// data pekerjaan yg akan dirun,biasanya dalam bentuk json
            $table->unsignedTinyInteger('attempts');// jumlah kali pekerjaan telah dicoba
            $table->unsignedInteger('reserved_at')->nullable();// tanggal pekerjaan di-reserve
            $table->unsignedInteger('available_at');// tanggal pekerjaan tersedia
            $table->unsignedInteger('created_at');// tanggal pekerjaan dibuat
        });

        Schema::create('job_batches', function (Blueprint $table) {
            $table->string('id')->primary();// id unik untuk batch pekerjaan
            $table->string('name');// nama batch
            $table->integer('total_jobs');// jumlah total pekerjaan dalam batch
            $table->integer('pending_jobs');// jumlah kerja yg menunggu
            $table->integer('failed_jobs');//jumlah kerja yg gagal
            $table->longText('failed_job_ids');//daftar id kerja gagal dalam batch
            $table->mediumText('options')->nullable();//opsi tambahan
            $table->integer('cancelled_at')->nullable();//tanggal batch dibatalkan
            $table->integer('created_at');// tangggal batch dibuat
            $table->integer('finished_at')->nullable();// tanggal batch selesai
        });

        Schema::create('failed_jobs', function (Blueprint $table) {
            $table->id();
            $table->string('uuid')->unique();
            $table->text('connection');
            $table->text('queue');
            $table->longText('payload');
            $table->longText('exception');
            $table->timestamp('failed_at')->useCurrent();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('jobs');
        Schema::dropIfExists('job_batches');
        Schema::dropIfExists('failed_jobs');
    }
};
