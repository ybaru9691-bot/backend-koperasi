<?php

namespace Tests\Feature;

use App\Models\ChartOfAccount;
use App\Models\JournalDetail;
use App\Models\JournalEntry;
use App\Models\Member;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class TabelarisReportTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(\Database\Seeders\ChartOfAccountSeeder::class);
    }

    public function test_tabelaris_api_structure_and_column_mapping(): void
    {
        $admin = User::create([
            'name'     => 'Admin Tabelaris',
            'email'    => 'admin.tabelaris@koperasi.com',
            'password' => bcrypt('password123'),
            'role'     => 'admin',
            'nik'      => '1234567890123456',
        ]);

        $member = Member::create([
            'member_number'     => '001.001',
            'name'              => 'Bpk. Johanes Sitompul',
            'nik'               => '3201010101010001',
            'phone'             => '081234567890',
            'status'            => 'active',
            'principal_savings' => 100000.00,
            'mandatory_savings' => 50000.00,
            'voluntary_savings' => 500000.00,
            'daily_savings'     => 200000.00,
        ]);

        Sanctum::actingAs($admin);

        $coaKas     = ChartOfAccount::where('account_code', '1000')->first();
        $coaPiutang = ChartOfAccount::where('account_code', '1024')->first();
        $coaSaham   = ChartOfAccount::where('account_code', '2020')->first();
        $coaHarian  = ChartOfAccount::where('account_code', '2021')->first();
        $coaDiakonia= ChartOfAccount::where('account_code', '2022')->first();
        $coaJasa    = ChartOfAccount::where('account_code', '4180')->first();
        $coaDenda   = ChartOfAccount::where('account_code', '4182')->first();
        $coaProvisi = ChartOfAccount::where('account_code', '4170')->first();
        $coaBiaya   = ChartOfAccount::where('account_code', '7100')->first();

        // 1. Transaksi KM 1: Setoran Kas Masuk Campuran (SP: 100k, SW: 50k, Jasa: 50k) = Rp 200.000
        $t1 = Transaction::create([
            'transaction_number' => 'KM-20260901-0001',
            'receipt_number'     => 'KM-001',
            'member_id'          => $member->id,
            'type'               => 'deposit',
            'amount'             => 200000.00,
            'transaction_date'   => '2026-09-01',
            'description'        => 'Setoran Simpanan Pokok, Wajib & Jasa Pinjaman',
            'status'             => 'approved',
        ]);
        $j1 = $t1->journalEntry ?: JournalEntry::where('transaction_id', $t1->id)->first();
        if ($j1) {
            $j1->details()->delete();
        } else {
            $j1 = JournalEntry::create([
                'transaction_id' => $t1->id,
                'entry_date'     => '2026-09-01',
                'voucher_number' => 'KM-001-A',
                'description'    => $t1->description,
            ]);
        }
        JournalDetail::create([
            'journal_entry_id' => $j1->id,
            'account_id'       => $coaKas->id,
            'debit'            => 200000.00,
            'credit'           => 0.00,
        ]);
        JournalDetail::create([
            'journal_entry_id' => $j1->id,
            'account_id'       => $coaSaham->id,
            'debit'            => 0.00,
            'credit'           => 100000.00,
            'description'      => 'Simpanan Pokok (SP)',
        ]);
        JournalDetail::create([
            'journal_entry_id' => $j1->id,
            'account_id'       => $coaSaham->id,
            'debit'            => 0.00,
            'credit'           => 50000.00,
            'description'      => 'Simpanan Wajib (SW)',
        ]);
        JournalDetail::create([
            'journal_entry_id' => $j1->id,
            'account_id'       => $coaJasa->id,
            'debit'            => 0.00,
            'credit'           => 50000.00,
            'description'      => 'Jasa Pinjaman',
        ]);

        // 2. Transaksi KM 2: Provisi Rp 150.000 & Denda Rp 25.000 = Rp 175.000
        $t2 = Transaction::create([
            'transaction_number' => 'KM-20260901-0002',
            'receipt_number'     => 'KM-002',
            'member_id'          => $member->id,
            'type'               => 'deposit',
            'amount'             => 175000.00,
            'transaction_date'   => '2026-09-01',
            'description'        => 'Provisi Pinjaman & Denda Keterlambatan',
            'status'             => 'approved',
        ]);
        $j2 = $t2->journalEntry ?: JournalEntry::where('transaction_id', $t2->id)->first();
        if ($j2) {
            $j2->details()->delete();
        } else {
            $j2 = JournalEntry::create([
                'transaction_id' => $t2->id,
                'entry_date'     => '2026-09-01',
                'voucher_number' => 'KM-002-A',
                'description'    => $t2->description,
            ]);
        }
        JournalDetail::create([
            'journal_entry_id' => $j2->id,
            'account_id'       => $coaKas->id,
            'debit'            => 175000.00,
            'credit'           => 0.00,
        ]);
        JournalDetail::create([
            'journal_entry_id' => $j2->id,
            'account_id'       => $coaProvisi->id,
            'debit'            => 0.00,
            'credit'           => 150000.00,
            'description'      => 'Provisi Pinjaman',
        ]);
        JournalDetail::create([
            'journal_entry_id' => $j2->id,
            'account_id'       => $coaDenda->id,
            'debit'            => 0.00,
            'credit'           => 25000.00,
            'description'      => 'Denda Keterlambatan',
        ]);

        // 3. Transaksi KK 1: Pencairan Pinjaman (Piutang) = Rp 1.000.000
        $t3 = Transaction::create([
            'transaction_number' => 'KK-20260902-0001',
            'receipt_number'     => 'KK-001',
            'member_id'          => $member->id,
            'type'               => 'withdrawal',
            'amount'             => 1000000.00,
            'transaction_date'   => '2026-09-02',
            'description'        => 'Pencairan Pinjaman Baru',
            'status'             => 'approved',
        ]);
        $j3 = $t3->journalEntry ?: JournalEntry::where('transaction_id', $t3->id)->first();
        if ($j3) {
            $j3->details()->delete();
        } else {
            $j3 = JournalEntry::create([
                'transaction_id' => $t3->id,
                'entry_date'     => '2026-09-02',
                'voucher_number' => 'KK-001-A',
                'description'    => $t3->description,
            ]);
        }
        JournalDetail::create([
            'journal_entry_id' => $j3->id,
            'account_id'       => $coaPiutang->id,
            'debit'            => 1000000.00,
            'credit'           => 0.00,
            'description'      => 'Pencairan Pinjaman Anggota',
        ]);
        JournalDetail::create([
            'journal_entry_id' => $j3->id,
            'account_id'       => $coaKas->id,
            'debit'            => 0.00,
            'credit'           => 1000000.00,
        ]);

        // 4. Transaksi KK 2: Biaya ATK = Rp 75.000
        $t4 = Transaction::create([
            'transaction_number' => 'KK-20260902-0002',
            'receipt_number'     => 'KK-002',
            'member_id'          => null,
            'type'               => 'withdrawal',
            'amount'             => 75000.00,
            'transaction_date'   => '2026-09-02',
            'description'        => 'Pembelian Kertas dan ATK Kantor',
            'status'             => 'approved',
        ]);
        $j4 = $t4->journalEntry ?: JournalEntry::where('transaction_id', $t4->id)->first();
        if ($j4) {
            $j4->details()->delete();
        } else {
            $j4 = JournalEntry::create([
                'transaction_id' => $t4->id,
                'entry_date'     => '2026-09-02',
                'voucher_number' => 'KK-002-A',
                'description'    => $t4->description,
            ]);
        }
        JournalDetail::create([
            'journal_entry_id' => $j4->id,
            'account_id'       => $coaBiaya->id,
            'debit'            => 75000.00,
            'credit'           => 0.00,
            'description'      => 'Biaya ATK',
        ]);
        JournalDetail::create([
            'journal_entry_id' => $j4->id,
            'account_id'       => $coaKas->id,
            'debit'            => 0.00,
            'credit'           => 75000.00,
        ]);

        // Uji Endpoint GET /api/v1/tabelaris
        $res = $this->getJson('/api/v1/tabelaris?start_date=2026-09-01&end_date=2026-09-07');
        $res->assertStatus(200);
        $res->assertJson(['success' => true]);

        $data = $res->json('data');

        // 1. Verifikasi Jumlah Kolom Definisi Metadata (29 Kolom)
        $columns = $data['columns'];
        $this->assertCount(29, $columns, 'Jurnal Tabelaris wajib memiliki tepat 29 kolom!');

        // 2. Verifikasi Data Rows
        $rows = $data['rows'];
        $this->assertCount(4, $rows);

        // Row 1 (KM-001):
        $r1 = $rows[0];
        $this->assertEquals('KM-001', $r1['no_bukti']);
        $this->assertEquals('001.001', $r1['nba']);
        $this->assertEquals(200000.00, (float) $r1['kas_debet']);
        $this->assertEquals(0.00, (float) $r1['kas_kredit']);
        $this->assertEquals(100000.00, (float) $r1['simpanan_sp']);
        $this->assertEquals(50000.00, (float) $r1['simpanan_sw']);
        $this->assertEquals(50000.00, (float) $r1['jasa_pinjaman']);

        // Row 2 (KM-002):
        $r2 = $rows[1];
        $this->assertEquals(175000.00, (float) $r2['kas_debet']);
        $this->assertEquals(150000.00, (float) $r2['provisi_pinjaman']);
        $this->assertEquals(250000.00 - 225000.00, (float) $r2['denda_penalti']);

        // Row 3 (KK-001):
        $r3 = $rows[2];
        $this->assertEquals(1000000.00, (float) $r3['kas_kredit']);
        $this->assertEquals(1000000.00, (float) $r3['piutang']);

        // Row 4 (KK-002):
        $r4 = $rows[3];
        $this->assertEquals(75000.00, (float) $r4['kas_kredit']);
        $this->assertEquals(75000.00, (float) $r4['biaya']);

        // 3. Verifikasi Ringkasan Total & Keseimbangan
        $summary = $data['summary'];
        $this->assertEquals(375000.00, (float) $summary['total_kas_debet']);
        $this->assertEquals(1075000.00, (float) $summary['total_kas_kredit']);

        $this->assertEquals(1075000.00, (float) $summary['total_pengeluaran']);
        $this->assertEquals(375000.00, (float) $summary['total_pemasukan']);

        // Grand Total Debet = Kas Debet (375k) + Pengeluaran (1075k) = 1.450.000
        // Grand Total Kredit = Kas Kredit (1075k) + Pemasukan (375k) = 1.450.000
        $this->assertEquals(1450000.00, (float) $summary['grand_total_debet']);
        $this->assertEquals(1450000.00, (float) $summary['grand_total_kredit']);
        $this->assertTrue($summary['is_balanced'], 'Jurnal Tabelaris harus seimbang (Total Debet == Total Kredit)');
    }

    public function test_tabelaris_export_excel_native(): void
    {
        $admin = User::create([
            'name'     => 'Admin Export Tabelaris',
            'email'    => 'export.tabelaris@koperasi.com',
            'password' => bcrypt('password123'),
            'role'     => 'admin',
            'nik'      => '9988776655443322',
        ]);

        $token = $admin->createToken('excel-token')->plainTextToken;

        // Uji Export Native .xlsx via endpoint /api/v1/tabelaris/export-excel
        $response = $this->get("/api/v1/tabelaris/export-excel?token={$token}&period=2026-09-M1");
        $response->assertStatus(200);
        $response->assertHeader('Content-Type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        $this->assertStringContainsString('.xlsx', $response->headers->get('Content-Disposition'));
    }

    public function test_tabelaris_export_pdf(): void
    {
        $admin = User::create([
            'name'     => 'Admin Export PDF Tabelaris',
            'email'    => 'pdf.tabelaris@koperasi.com',
            'password' => bcrypt('password123'),
            'role'     => 'admin',
            'nik'      => '9988776655443333',
        ]);

        $token = $admin->createToken('pdf-token')->plainTextToken;

        // Uji Export PDF via endpoint /api/v1/tabelaris/export-pdf
        $response = $this->get("/api/v1/tabelaris/export-pdf?token={$token}&period=2026-09-M1");
        $response->assertStatus(200);
        $response->assertHeader('Content-Type', 'application/pdf');
        $this->assertStringContainsString('.pdf', $response->headers->get('Content-Disposition'));
    }
}
