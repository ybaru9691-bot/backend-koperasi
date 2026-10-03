<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Baca dan import SQL dump
        $sqlFile = database_path('../db_koperasi.sql');
        
        if (!file_exists($sqlFile)) {
            throw new Exception("File db_koperasi.sql tidak ditemukan di " . $sqlFile);
        }

        // Baca seluruh file
        $sql = file_get_contents($sqlFile);
        
        // Split by statements dan execute
        // Hapus comments dan line breaks
        $sql = preg_replace('/^--.*$/m', '', $sql);
        $sql = preg_replace('/^\/\*.*?\*\/$/ms', '', $sql);
        
        // Split queries
        $statements = array_filter(
            array_map('trim', explode(';', $sql)),
            fn($stmt) => !empty($stmt) && $stmt !== ''
        );

        // Execute setiap statement
        foreach ($statements as $statement) {
            try {
                DB::statement($statement);
            } catch (\Exception $e) {
                // Ignore "table already exists" errors
                if (strpos($e->getMessage(), 'already exists') === false) {
                    throw $e;
                }
            }
        }
    }

    public function down(): void
    {
        // Tidak ada rollback untuk import data
    }
};
