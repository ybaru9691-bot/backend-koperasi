<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class MemberMigrationTemplateTest extends TestCase
{
    use RefreshDatabase;

    protected User $adminUser;

    protected function setUp(): void
    {
        parent::setUp();
        $this->adminUser = User::factory()->create([
            'role' => 'admin',
        ]);
    }

    public function test_download_migration_template_xlsx_success(): void
    {
        Sanctum::actingAs($this->adminUser);

        $response = $this->get('/api/members/migration/template');

        $response->assertStatus(200);
        $response->assertHeader('content-type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        $this->assertStringContainsString('Template_Migrasi_Saldo_Anggota.xlsx', $response->headers->get('content-disposition'));
    }

    public function test_download_migration_template_csv_success(): void
    {
        Sanctum::actingAs($this->adminUser);

        $response = $this->get('/api/members/migration/template?format=csv');

        $response->assertStatus(200);
        $this->assertStringContainsString('text/csv', $response->headers->get('content-type'));
        $this->assertStringContainsString('Template_Migrasi_Saldo_Anggota.csv', $response->headers->get('content-disposition'));

        $content = $response->streamedContent();
        $this->assertStringContainsString('member_number,nik,name,buku_putih_no,phone_number,status,principal_savings,mandatory_savings,voluntary_savings,daily_savings', $content);
        $this->assertStringContainsString('0001,1403094112820007,Basalina Hutauruk,2021-0017,081234567890,Active,200000,4080000,12020000,744486', $content);
        $this->assertStringContainsString('0002,1403094112820008,Binsar Pandiangan,2021-0018,081234567891,Active,200000,3500000,8500000,500000', $content);
    }

    public function test_admin_alias_route_works(): void
    {
        Sanctum::actingAs($this->adminUser);

        $response = $this->get('/api/admin/members/migration/template?format=csv');

        $response->assertStatus(200);
        $this->assertStringContainsString('Template_Migrasi_Saldo_Anggota.csv', $response->headers->get('content-disposition'));
    }
}