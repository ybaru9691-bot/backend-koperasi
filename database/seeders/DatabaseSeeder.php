<?php

namespace Database\Seeders;

use App\Models\Account;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        // 1. Akun Admin Default (NIK: 1234567890123456, PIN: 123456)
        $admin = User::updateOrCreate(
            ['nik' => '1234567890123456'],
            [
                'name'     => 'Admin Koperasi Pelita',
                'email'    => 'admin@koperasi.com',
                'role'     => 'admin',
                'password' => Hash::make('123456'), // PIN 6 digit
            ]
        );

        // 2. Akun Manager / Ketua Default (NIK: 1122334455667788, PIN: 123456)
        $manager = User::updateOrCreate(
            ['nik' => '1122334455667788'],
            [
                'name'     => 'Manager Koperasi Pelita',
                'email'    => 'manager@koperasi.com',
                'role'     => 'manager',
                'password' => Hash::make('123456'), // PIN 6 digit
            ]
        );

        // 3. Master Akun Kas Koperasi (Default Kas Aset)
        Account::firstOrCreate(
            ['account_number' => 'KAS-101'],
            [
                'account_name' => 'Kas Koperasi',
                'account_type' => 'kas',
                'category'     => 'asset',
                'balance'      => 0.00,
            ]
        );

        // 4. Seed Chart of Accounts (COA) CUM Pelita
        $this->call(ChartOfAccountSeeder::class);
    }
}