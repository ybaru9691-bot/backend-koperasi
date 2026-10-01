<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\ChartOfAccount;

class ChartOfAccountSeeder extends Seeder
{
    /**
     * Seed Data Resmi Chart of Accounts (COA) CUM Pelita HKBP Dame
     */
    public function run(): void
    {
        $accounts = [
          
            // ASET (ASSET) — Normal Balance: DEBIT       
            ['account_code' => '1000', 'account_name' => 'Kas',                         'account_type' => 'ASSET', 'normal_balance' => 'DEBIT'],
            ['account_code' => '1010', 'account_name' => 'BRI',                         'account_type' => 'ASSET', 'normal_balance' => 'DEBIT'],
            ['account_code' => '1024', 'account_name' => 'Piutang',                     'account_type' => 'ASSET', 'normal_balance' => 'DEBIT'],
            ['account_code' => '1700', 'account_name' => 'Inventaris Tanah',            'account_type' => 'ASSET', 'normal_balance' => 'DEBIT'],
            ['account_code' => '1741', 'account_name' => 'Inventaris Perl.Kantor',      'account_type' => 'ASSET', 'normal_balance' => 'DEBIT'],
            ['account_code' => '1743', 'account_name' => 'Inventaris Kendaraan Kantor', 'account_type' => 'ASSET', 'normal_balance' => 'DEBIT'],

          
            // KEWAJIBAN (LIABILITY) — Normal Balance: CREDIT       
            ['account_code' => '2020', 'account_name' => 'Sw,Ss,Sp - Buku Biru',        'account_type' => 'LIABILITY', 'normal_balance' => 'CREDIT'],
            ['account_code' => '2021', 'account_name' => 'Simp Harian - Buku Putih',    'account_type' => 'LIABILITY', 'normal_balance' => 'CREDIT'],
            ['account_code' => '2022', 'account_name' => 'Simp Diakonia',               'account_type' => 'LIABILITY', 'normal_balance' => 'CREDIT'],
            ['account_code' => '2032', 'account_name' => 'Asuransi Investasi',          'account_type' => 'LIABILITY', 'normal_balance' => 'CREDIT'],
            ['account_code' => '2033', 'account_name' => 'Dana Pendidikan/Pelatihan',   'account_type' => 'LIABILITY', 'normal_balance' => 'CREDIT'],
            ['account_code' => '2034', 'account_name' => 'Dana Sosial',                 'account_type' => 'LIABILITY', 'normal_balance' => 'CREDIT'],
            ['account_code' => '2038', 'account_name' => 'Dana Duka',                   'account_type' => 'LIABILITY', 'normal_balance' => 'CREDIT'],

          
            // AKUMULASI PENYUSUTAN (CONTRA-ASSET) — Normal Balance: CREDIT        
            ['account_code' => '3101', 'account_name' => 'Akumulasi Peny.Perl Kantor',       'account_type' => 'ASSET', 'normal_balance' => 'CREDIT'],
            ['account_code' => '3102', 'account_name' => 'Akumulasi Penyusutan Kend Kantor', 'account_type' => 'ASSET', 'normal_balance' => 'CREDIT'],

            // EKUITAS & MODAL (EQUITY) — Normal Balance: CREDIT
            ['account_code' => '3010', 'account_name' => 'Cadangan Modal Koperasi',          'account_type' => 'EQUITY', 'normal_balance' => 'CREDIT'],
            ['account_code' => '3020', 'account_name' => 'Ekuitas / Modal Koperasi',         'account_type' => 'EQUITY', 'normal_balance' => 'CREDIT'],

          
            // PENDAPATAN (REVENUE) — Normal Balance: CREDIT          
            ['account_code' => '4170', 'account_name' => 'Provisi Pinjaman',                 'account_type' => 'REVENUE', 'normal_balance' => 'CREDIT'],
            ['account_code' => '4180', 'account_name' => 'Jasa Pinjaman',                    'account_type' => 'REVENUE', 'normal_balance' => 'CREDIT'],
            ['account_code' => '4181', 'account_name' => 'Jasa Bank',                        'account_type' => 'REVENUE', 'normal_balance' => 'CREDIT'],
            ['account_code' => '4182', 'account_name' => 'Denda Keterlambatan Angsuran',     'account_type' => 'REVENUE', 'normal_balance' => 'CREDIT'],
            ['account_code' => '4183', 'account_name' => 'Finalty Tabungan',                 'account_type' => 'REVENUE', 'normal_balance' => 'CREDIT'],
            ['account_code' => '4184', 'account_name' => 'Denda Deviden',                    'account_type' => 'REVENUE', 'normal_balance' => 'CREDIT'],
            ['account_code' => '4191', 'account_name' => 'Uang Pangkal',                     'account_type' => 'REVENUE', 'normal_balance' => 'CREDIT'],
            ['account_code' => '4192', 'account_name' => 'Pendapatan Lain-lain',             'account_type' => 'REVENUE', 'normal_balance' => 'CREDIT'],
            ['account_code' => '4193', 'account_name' => 'Pend Fotocopy',                    'account_type' => 'REVENUE', 'normal_balance' => 'CREDIT'],
            ['account_code' => '4194', 'account_name' => 'Pend Sembako',                     'account_type' => 'REVENUE', 'normal_balance' => 'CREDIT'],

          
            // BEBAN OPERASIONAL (EXPENSE) — Normal Balance: DEBIT
            ['account_code' => '7100', 'account_name' => 'ATK',                              'account_type' => 'EXPENSE', 'normal_balance' => 'DEBIT'],
            ['account_code' => '7101', 'account_name' => 'Komunikasi',                       'account_type' => 'EXPENSE', 'normal_balance' => 'DEBIT'],
            ['account_code' => '7102', 'account_name' => 'B Perbaikan Perl.Komputer',        'account_type' => 'EXPENSE', 'normal_balance' => 'DEBIT'],
            ['account_code' => '7103', 'account_name' => 'B Perbaikan Kendaraan',            'account_type' => 'EXPENSE', 'normal_balance' => 'DEBIT'],
            ['account_code' => '7104', 'account_name' => 'B Penyusutan Perl Kantor',         'account_type' => 'EXPENSE', 'normal_balance' => 'DEBIT'],
            ['account_code' => '7105', 'account_name' => 'B Penyusutan Kendaraan Kantor',    'account_type' => 'EXPENSE', 'normal_balance' => 'DEBIT'],
            ['account_code' => '7108', 'account_name' => 'B Wifi',                          'account_type' => 'EXPENSE', 'normal_balance' => 'DEBIT'],
            ['account_code' => '7109', 'account_name' => 'Listrik CUM',                      'account_type' => 'EXPENSE', 'normal_balance' => 'DEBIT'],
            ['account_code' => '7110', 'account_name' => 'Gaji Karyawan',                    'account_type' => 'EXPENSE', 'normal_balance' => 'DEBIT'],
            ['account_code' => '7111', 'account_name' => 'Perjalanan Dinas Tamu',            'account_type' => 'EXPENSE', 'normal_balance' => 'DEBIT'],
            ['account_code' => '7112', 'account_name' => 'Perjalanan Dinas Karyawan',        'account_type' => 'EXPENSE', 'normal_balance' => 'DEBIT'],
            ['account_code' => '7114', 'account_name' => 'Minyak Inventaris',                'account_type' => 'EXPENSE', 'normal_balance' => 'DEBIT'],
            ['account_code' => '7115', 'account_name' => 'Transport Petugas',                'account_type' => 'EXPENSE', 'normal_balance' => 'DEBIT'],
            ['account_code' => '7116', 'account_name' => 'Lembur Karyawan',                  'account_type' => 'EXPENSE', 'normal_balance' => 'DEBIT'],
            ['account_code' => '7117', 'account_name' => 'Bonus Kerja Karyawan',             'account_type' => 'EXPENSE', 'normal_balance' => 'DEBIT'],
            ['account_code' => '7120', 'account_name' => 'B Cetak Rek Koran',                'account_type' => 'EXPENSE', 'normal_balance' => 'DEBIT'],
            ['account_code' => '7121', 'account_name' => 'B Perbaikan Pemb.Kantor',          'account_type' => 'EXPENSE', 'normal_balance' => 'DEBIT'],
            ['account_code' => '7122', 'account_name' => 'B Insentive Rapat',                'account_type' => 'EXPENSE', 'normal_balance' => 'DEBIT'],
            ['account_code' => '7123', 'account_name' => 'Tunjangan Rumah',                  'account_type' => 'EXPENSE', 'normal_balance' => 'DEBIT'],
            ['account_code' => '7131', 'account_name' => 'Pajak Inventaris',                 'account_type' => 'EXPENSE', 'normal_balance' => 'DEBIT'],
            ['account_code' => '7145', 'account_name' => 'Jasa Simpanan',                    'account_type' => 'EXPENSE', 'normal_balance' => 'DEBIT'],
            ['account_code' => '7147', 'account_name' => 'Biaya Tamu',                       'account_type' => 'EXPENSE', 'normal_balance' => 'DEBIT'],
            ['account_code' => '7160', 'account_name' => 'Honor Pengurus',                   'account_type' => 'EXPENSE', 'normal_balance' => 'DEBIT'],
            ['account_code' => '7161', 'account_name' => 'B Rapat Pengurus & Manager',       'account_type' => 'EXPENSE', 'normal_balance' => 'DEBIT'],
            ['account_code' => '7167', 'account_name' => 'BPJS Kesehatan',                   'account_type' => 'EXPENSE', 'normal_balance' => 'DEBIT'],
            ['account_code' => '7168', 'account_name' => 'BPJS Tenaga Kerja',                'account_type' => 'EXPENSE', 'normal_balance' => 'DEBIT'],
            ['account_code' => '7169', 'account_name' => 'Sumbangan',                        'account_type' => 'EXPENSE', 'normal_balance' => 'DEBIT'],
            ['account_code' => '7170', 'account_name' => 'Konsumsi Kantor',                  'account_type' => 'EXPENSE', 'normal_balance' => 'DEBIT'],
            ['account_code' => '7171', 'account_name' => 'Biaya Promosi & Parsel',           'account_type' => 'EXPENSE', 'normal_balance' => 'DEBIT'],
        ];

        foreach ($accounts as $account) {
            ChartOfAccount::updateOrCreate(
                ['account_code' => $account['account_code']],
                array_merge($account, ['is_active' => true])
            );
        }

        $this->command->info('✅ ' . count($accounts) . ' akun COA CUM Pelita berhasil di-seed!');
    }
}
