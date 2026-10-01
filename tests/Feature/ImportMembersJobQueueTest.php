<?php

namespace Tests\Feature;

use App\Jobs\ImportMembersJob;
use App\Models\Account;
use App\Models\Member;
use App\Models\Period;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\TestCase;

class ImportMembersJobQueueTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(\Database\Seeders\ChartOfAccountSeeder::class);

        Period::create([
            'period_name' => 'September 2026',
            'start_date'  => '2026-09-01',
            'end_date'    => '2026-09-30',
            'status'      => 'open',
            'is_locked'   => false,
            'is_active'   => true,
        ]);

        Account::firstOrCreate(
            ['account_number' => 'KAS-101'],
            [
                'account_name' => 'Kas Koperasi',
                'account_type' => 'kas',
                'category'     => 'asset',
                'balance'      => 0.00,
            ]
        );
    }

    public function test_upload_excel_dispatches_import_members_job(): void
    {
        Queue::fake();
        Storage::fake('local');

        $admin = User::create([
            'name'     => 'Admin Queue',
            'email'    => 'admin.queue@koperasi.com',
            'password' => bcrypt('123456'),
            'role'     => 'admin',
            'nik'      => '1122334455667788',
        ]);
        Sanctum::actingAs($admin);

        $file = UploadedFile::fake()->create('members_data.xlsx', 100);

        $res = $this->postJson('/api/members/import-initial', [
            'file' => $file,
        ]);

        $res->assertStatus(200);
        $res->assertJson([
            'success' => true,
            'message' => 'File berhasil diunggah dan sedang diproses di antrean latar belakang.',
            'data'    => [
                'queued' => true,
            ]
        ]);

        Queue::assertPushed(ImportMembersJob::class);
    }

    public function test_import_members_job_execution_processes_excel_data_and_cleans_file(): void
    {
        Storage::fake('local');

        $admin = User::create([
            'name'     => 'Admin Worker',
            'email'    => 'admin.worker@koperasi.com',
            'password' => bcrypt('123456'),
            'role'     => 'admin',
            'nik'      => '1122334455667799',
        ]);

        // Buat file Excel fisik di temporary storage
        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        
        // Header & Data Excel with buku_putih_no in Column 3
        $sheet->fromArray([
            ['member_number', 'nik', 'name', 'buku_putih_no', 'phone_number', 'status', 'mandatory_savings', 'voluntary_savings', 'principal_savings', 'daily_savings', 'outstanding_loan'],
            ['001.101', '3201010101010101', 'Bpk. Sabar Marpaung', '2021-0017', '081234567101', 'active', 50000, 100000, 200000, 50000, 1000000],
            ['001.102', '3201010101010102', 'Ibu Tiurma Simanjuntak', '-', '081234567102', 'active', 50000, 200000, 200000, 0, 0],
        ]);

        $tempPath = storage_path('framework/testing/test_import_' . uniqid() . '.xlsx');
        if (!is_dir(dirname($tempPath))) {
            @mkdir(dirname($tempPath), 0777, true);
        }
        $writer = new Xlsx($spreadsheet);
        $writer->save($tempPath);

        $this->assertFileExists($tempPath);

        // Eksekusi Job secara langsung
        $job = new ImportMembersJob($tempPath, $admin->id);
        $job->handle();

        // Verifikasi Member berhasil diimpor
        $m1 = Member::where('nik', '3201010101010101')->first();
        $this->assertNotNull($m1);
        $this->assertEquals('Bpk. Sabar Marpaung', $m1->name);
        $this->assertEquals('2021-0017', $m1->buku_putih_no);
        $this->assertEquals('3201010101010101@pelita.local', $m1->email);
        $this->assertEquals(200000, (float) $m1->principal_savings);
        $this->assertEquals(50000, (float) $m1->mandatory_savings);
        $this->assertEquals(100000, (float) $m1->voluntary_savings);
        $this->assertEquals(50000, (float) $m1->daily_savings);

        // Verifikasi Member 2 (dash in buku_putih_no becomes null)
        $m2 = Member::where('nik', '3201010101010102')->first();
        $this->assertNotNull($m2);
        $this->assertEquals('Ibu Tiurma Simanjuntak', $m2->name);
        $this->assertNull($m2->buku_putih_no);

        // Verifikasi transaksi dibuat dengan format TRX-IMP dan deskripsi Saldo Awal Buku Putih
        $trxs = Transaction::whereIn('member_id', [$m1->id, $m2->id])->get();
        $this->assertGreaterThan(0, $trxs->count());
        foreach ($trxs as $trx) {
            $this->assertStringStartsWith('TRX-IMP-', $trx->transaction_number);
        }

        $trxPutih = Transaction::where('member_id', $m1->id)->where('book_type', 'BUKU_PUTIH')->first();
        $this->assertNotNull($trxPutih);
        $this->assertEquals("Saldo Awal Buku Putih - {$m1->name}", $trxPutih->description);
        $this->assertEquals(50000, (float) $trxPutih->amount);

        // Verifikasi file temporary sudah dihapus
        $this->assertFileDoesNotExist($tempPath);
    }

    public function test_import_members_handles_duplicate_and_dummy_nik_gracefully(): void
    {
        Storage::fake('local');

        $admin = User::create([
            'name'     => 'Admin Duplicate Nik',
            'email'    => 'admin.dupnik@koperasi.com',
            'password' => bcrypt('123456'),
            'role'     => 'admin',
            'nik'      => '1122334455667711',
        ]);

        // Pre-create member 1 with specific NIK
        Member::create([
            'member_number'     => '001.001',
            'nik'               => '3201999999999999',
            'name'              => 'Existing Member',
            'email'             => 'exist@pelita.local',
            'phone'             => '081234567001',
            'status'            => 'active',
            'principal_savings' => 100000,
            'password'          => bcrypt('123456'),
        ]);

        // Create spreadsheet where member 2 has the SAME dummy NIK as member 1, and member 3 has empty NIK
        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->fromArray([
            ['member_number', 'nik', 'name', 'email', 'phone_number', 'status', 'mandatory_savings', 'voluntary_savings', 'principal_savings', 'daily_savings', 'outstanding_loan'],
            ['001.002', '3201999999999999', 'Member With Duplicate Dummy NIK', 'dup@pelita.local', '081234567002', 'active', 50000, 0, 200000, 0, 0],
            ['001.003', '', 'Member With Empty NIK', '', '081234567003', 'active', 50000, 0, 150000, 0, 0],
        ]);

        $tempPath = storage_path('framework/testing/test_import_dup_' . uniqid() . '.xlsx');
        $writer = new Xlsx($spreadsheet);
        $writer->save($tempPath);

        $job = new ImportMembersJob($tempPath, $admin->id);
        $job->handle();

        // Verifikasi semua 3 anggota terdaftar dengan member_number masing-masing
        $this->assertEquals(3, Member::count());

        $m2 = Member::where('member_number', '001.002')->first();
        $this->assertNotNull($m2);
        $this->assertEquals('Member With Duplicate Dummy NIK', $m2->name);
        $this->assertStringStartsWith('TEMP', $m2->nik);
        $this->assertEquals(200000, (float) $m2->principal_savings);

        $m3 = Member::where('member_number', '001.003')->first();
        $this->assertNotNull($m3);
        $this->assertEquals('Member With Empty NIK', $m3->name);
        $this->assertStringStartsWith('TEMP', $m3->nik);
        $this->assertEquals(150000, (float) $m3->principal_savings);
    }
}

