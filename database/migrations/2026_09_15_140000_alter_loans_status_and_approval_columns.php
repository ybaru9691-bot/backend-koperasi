<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (Schema::hasTable('loans')) {
            Schema::table('loans', function (Blueprint $table) {
                // Tambahkan kolom audit approval jika belum ada
                if (!Schema::hasColumn('loans', 'manager_approved_at')) {
                    $table->timestamp('manager_approved_at')->nullable()->after('approved_at');
                }
                if (!Schema::hasColumn('loans', 'manager_approved_by')) {
                    $table->foreignId('manager_approved_by')->nullable()->constrained('users')->onDelete('set null')->after('approved_by');
                }
                if (!Schema::hasColumn('loans', 'admin_verified_at')) {
                    $table->timestamp('admin_verified_at')->nullable()->after('manager_approved_at');
                }
                if (!Schema::hasColumn('loans', 'admin_verified_by')) {
                    $table->foreignId('admin_verified_by')->nullable()->constrained('users')->onDelete('set null')->after('manager_approved_by');
                }
                if (!Schema::hasColumn('loans', 'disbursed_at')) {
                    $table->timestamp('disbursed_at')->nullable()->after('disbursement_date');
                }
                if (!Schema::hasColumn('loans', 'disbursed_by')) {
                    $table->foreignId('disbursed_by')->nullable()->constrained('users')->onDelete('set null')->after('admin_verified_by');
                }
            });

            // Ubah tipe kolom status menjadi string agar mendukung WAITING_MANAGER_APPROVAL, APPROVED_BY_MANAGER, dsb.
            if (DB::getDriverName() === 'mysql') {
                DB::statement("ALTER TABLE loans MODIFY status VARCHAR(50) NOT NULL DEFAULT 'pending_admin'");
            }
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::hasTable('loans')) {
            Schema::table('loans', function (Blueprint $table) {
                $columns = ['manager_approved_at', 'manager_approved_by', 'admin_verified_at', 'admin_verified_by', 'disbursed_at', 'disbursed_by'];
                foreach ($columns as $col) {
                    if (Schema::hasColumn('loans', $col)) {
                        $table->dropColumn($col);
                    }
                }
            });
        }
    }
};