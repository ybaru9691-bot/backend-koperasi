<?php

namespace Tests\Feature;

use App\Models\ChartOfAccount;
use App\Models\JournalDetail;
use App\Models\JournalEntry;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class LedgerExportTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(\Database\Seeders\ChartOfAccountSeeder::class);
    }

    public function test_export_ledger_excel_with_sanctum_auth(): void
    {
        $admin = User::create([
            'name'     => 'Admin Export',
            'email'    => 'admin.export@koperasi.com',
            'password' => bcrypt('123456'),
            'role'     => 'admin',
            'nik'      => '1122334455667799',
        ]);
        Sanctum::actingAs($admin);

        $coaKas = ChartOfAccount::where('account_code', '1000')->first();
        $entry = JournalEntry::create([
            'voucher_number' => 'JV-20260901-0001',
            'entry_date'     => '2026-09-01',
            'description'    => 'Setoran Awal Kas',
        ]);
        JournalDetail::create([
            'journal_entry_id' => $entry->id,
            'account_id'       => $coaKas->id,
            'debit'            => 500000,
            'credit'           => 0,
            'description'      => 'Setoran Kas',
        ]);

        $res = $this->get('/api/ledger/export-excel?account_code=1000');
        $res->assertStatus(200);
        $res->assertHeader('Content-Type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    }

    public function test_export_ledger_pdf_with_sanctum_auth(): void
    {
        $admin = User::create([
            'name'     => 'Admin Export PDF',
            'email'    => 'admin.pdf@koperasi.com',
            'password' => bcrypt('123456'),
            'role'     => 'admin',
            'nik'      => '1122334455667788',
        ]);
        Sanctum::actingAs($admin);

        $res = $this->get('/api/ledger/export-pdf?account_code=1000');
        $res->assertStatus(200);
        $res->assertHeader('Content-Type', 'application/pdf');
    }

    public function test_export_ledger_with_token_query_param(): void
    {
        $admin = User::create([
            'name'     => 'Admin Token',
            'email'    => 'admin.token@koperasi.com',
            'password' => bcrypt('123456'),
            'role'     => 'admin',
            'nik'      => '1122334455667777',
        ]);

        $tokenResult = $admin->createToken('test_export_token');
        $plainTextToken = $tokenResult->plainTextToken;

        $res = $this->get("/api/reports/journal-ledger/export/excel?account_code=1000&token={$plainTextToken}");
        $res->assertStatus(200);
        $res->assertHeader('Content-Type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    }

    public function test_export_ledger_unauthorized_without_token(): void
    {
        $res = $this->get('/api/ledger/export-excel?account_code=1000');
        $res->assertStatus(401);
        $res->assertJson([
            'success' => false,
        ]);
    }
}
