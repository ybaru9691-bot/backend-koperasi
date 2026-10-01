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
        Schema::table('transactions', function (Blueprint $table) {
            $table->index(['account_id', 'transaction_date']);
            $table->index(['transaction_date', 'type']);
            $table->index(['member_id', 'created_at']);
        });

        Schema::table('journal_details', function (Blueprint $table) {
            $table->index(['account_id', 'journal_entry_id']);
        });

        Schema::table('journal_entries', function (Blueprint $table) {
            $table->index(['entry_date', 'id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            $table->dropIndex(['account_id', 'transaction_date']);
            $table->dropIndex(['transaction_date', 'type']);
            $table->dropIndex(['member_id', 'created_at']);
        });

        Schema::table('journal_details', function (Blueprint $table) {
            $table->dropIndex(['account_id', 'journal_entry_id']);
        });

        Schema::table('journal_entries', function (Blueprint $table) {
            $table->dropIndex(['entry_date', 'id']);
        });
    }
};
