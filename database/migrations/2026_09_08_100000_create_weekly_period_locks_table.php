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
        if (!Schema::hasTable('weekly_period_locks')) {
            Schema::create('weekly_period_locks', function (Blueprint $table) {
                $table->id();
                $table->unsignedTinyInteger('month'); // 1 - 12
                $table->unsignedSmallInteger('year'); // e.g. 2026
                $table->string('week', 10); // 'M1', 'M2', 'M3', 'M4', 'M5'
                $table->date('start_date')->nullable();
                $table->date('end_date')->nullable();
                $table->boolean('is_locked')->default(false);
                $table->string('status', 20)->default('active'); // 'active' or 'locked'
                $table->foreignId('locked_by')->nullable()->constrained('users')->onDelete('set null');
                $table->timestamp('locked_at')->nullable();
                $table->text('notes')->nullable();
                $table->timestamps();

                $table->unique(['year', 'month', 'week'], 'weekly_period_unique');
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('weekly_period_locks');
    }
};
