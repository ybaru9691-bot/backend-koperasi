<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Member;
use App\Models\Period;
use App\Models\User;
use App\Models\Loan;
use App\Models\JournalEntry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class MemberImportTest extends TestCase
{
    use RefreshDatabase;

    public function test_import_initial_members_success()
    {
        // 1. Create admin user
        $admin = User::create([
            'name' => 'Admin Member Import',
            'email' => 'admin.mimport@koperasi.com',
            'password' => bcrypt('123456'),
            'role' => 'admin',
            'nik' => '1122334455667700',
        ]);
        Sanctum::actingAs($admin);
        $this->seed(\Database\Seeders\ChartOfAccountSeeder::class);

        // 2. Create active period
        Period::create([
            'period_name' => 'Agustus 2026',
            'start_date'  => '2026-08-01',
            'end_date'    => '2026-08-31',
            'status'      => 'open',
            'is_locked'   => false,
            'is_active'   => true,
        ]);

        // 3. Create cash account
        Account::forceCreate([
            'account_number' => 'KAS-101',
            'account_name' => 'Kas Koperasi',
            'account_type' => 'kas',
            'category' => 'asset',
            'balance' => 0.00,
        ]);

        // 4. Payload for import
        $payload = [
            'members' => [
                [
                    'member_number' => 'member_number',
                    'nik' => 'nik',
                    'name' => 'name',
                ],
                [
                    'member_number' => 'PELITA-001',
                    'nik' => '1234567890123456',
                    'name' => 'John Import',
                    'buku_putih_no' => '2021-0001',
                    'phone_number' => '081234567801',
                    'status' => 'active',
                    'principal_savings' => 200000,
                    'mandatory_savings' => 20000,
                    'voluntary_savings' => 10000,
                    'daily_savings' => 50000,
                    'outstanding_loan' => 500000,
                ],
                [
                    0 => 'PELITA-002',
                    1 => '1234567890123455',
                    2 => 'Alice Numerical',
                    3 => '2021-0017', // buku_putih_no
                    4 => '081234567802',
                    5 => 'active',
                    6 => 100000,
                    7 => 10000,
                    8 => 5000,
                    9 => 20000,
                    10 => 0,
                ],
                [
                    0 => 'PELITA-003',
                    1 => '1234567890123454',
                    2 => 'Bob Empty BP',
                    3 => '-', // buku_putih_no empty/dash -> should be null in db
                    4 => '081234567803',
                    5 => 'active',
                    6 => 50000,
                    7 => 10000,
                    8 => 100000,
                    9 => 0,
                    10 => 0,
                ]
            ]
        ];

        // 5. Call route
        $response = $this->postJson('/api/members/import-initial', $payload);
        $response->assertStatus(200);
        $response->assertJson([
            'success' => true,
            'data' => [
                'total_imported' => 3,
                'total_amount' => 1075000, // 780000 + 135000 + 160000
            ]
        ]);

        // 6. Verify database records
        $member = Member::where('nik', '1234567890123456')->first();
        $this->assertNotNull($member);
        $this->assertEquals('1234567890123456@pelita.local', $member->email);
        $this->assertEquals('2021-0001', $member->buku_putih_no);
        $this->assertEquals(200000, $member->principal_savings);
        $this->assertEquals(20000, $member->mandatory_savings);
        $this->assertEquals(10000, $member->voluntary_savings);
        $this->assertEquals(50000, $member->daily_savings);

        // Verify loan was created
        $loan = Loan::where('member_id', $member->id)->first();
        $this->assertNotNull($loan);
        $this->assertEquals(500000, $loan->amount);
        $this->assertEquals('active', $loan->status);

        // Verify Member 2 records
        $member2 = Member::where('nik', '1234567890123455')->first();
        $this->assertNotNull($member2);
        $this->assertEquals('1234567890123455@pelita.local', $member2->email);
        $this->assertEquals('2021-0017', $member2->buku_putih_no);
        $this->assertEquals(5000, $member2->principal_savings);
        $this->assertEquals(100000, $member2->mandatory_savings);
        $this->assertEquals(10000, $member2->voluntary_savings);
        $this->assertEquals(20000, $member2->daily_savings);

        // Verify Member 3 records (dash/0 turns to null)
        $member3 = Member::where('nik', '1234567890123454')->first();
        $this->assertNotNull($member3);
        $this->assertNull($member3->buku_putih_no);

        // Verify journal entries were generated for initial balances
        $journals = JournalEntry::all();
        $this->assertGreaterThan(0, $journals->count());
    }

    public function test_import_initial_members_rollback_on_failure()
    {
        $admin = User::create([
            'name' => 'Admin Member Import rb',
            'email' => 'admin.mimport.rb@koperasi.com',
            'password' => bcrypt('123456'),
            'role' => 'admin',
            'nik' => '1122334455667709',
        ]);
        Sanctum::actingAs($admin);
        $this->seed(\Database\Seeders\ChartOfAccountSeeder::class);

        Period::create([
            'period_name' => 'Agustus 2026',
            'start_date'  => '2026-08-01',
            'end_date'    => '2026-08-31',
            'status'      => 'open',
            'is_locked'   => false,
            'is_active'   => true,
        ]);

        Account::forceCreate([
            'account_number' => 'KAS-101',
            'account_name' => 'Kas Koperasi',
            'account_type' => 'kas',
            'category' => 'asset',
            'balance' => 0.00,
        ]);

        // Second row is invalid because NIK is missing
        $payload = [
            'members' => [
                [
                    'member_number' => 'PELITA-002',
                    'nik' => '1234567890123459',
                    'name' => 'Valid Member',
                    'email' => 'valid@koperasi.com',
                    'phone_number' => '081234567802',
                    'status' => 'active',
                    'principal_savings' => 200000,
                ],
                [
                    'member_number' => 'PELITA-003',
                    'nik' => '1234567890123460',
                    'name' => null, // Invalid: Name is required
                ]
            ]
        ];

        $response = $this->postJson('/api/members/import-initial', $payload);
        $response->assertStatus(400);
        $response->assertJson([
            'success' => false,
            'failed_row_index' => 2,
        ]);

        // Verify first member was NOT created due to rollback
        $this->assertFalse(Member::where('nik', '1234567890123459')->exists());
    }

    public function test_import_pure_buku_putih_and_data_ango_format()
    {
        $admin = User::create([
            'name' => 'Admin Data Ango',
            'email' => 'admin.ango@koperasi.com',
            'password' => bcrypt('123456'),
            'role' => 'admin',
            'nik' => '1122334455667788',
        ]);
        Sanctum::actingAs($admin);
        $this->seed(\Database\Seeders\ChartOfAccountSeeder::class);

        Period::create([
            'period_name' => 'September 2026',
            'start_date'  => '2026-09-01',
            'end_date'    => '2026-09-30',
            'status'      => 'open',
            'is_locked'   => false,
            'is_active'   => true,
        ]);

        Account::forceCreate([
            'account_number' => 'KAS-101',
            'account_name' => 'Kas Koperasi',
            'account_type' => 'kas',
            'category' => 'asset',
            'balance' => 0.00,
        ]);

        // Simulating Data ango1.xlsx format:
        // 0: member_number, 1: nik, 2: name, 3: buku_putih_no, 4: phone_number, 5: status,
        // 6: mandatory_savings, 7: voluntary_savings, 8: principal_savings, 9: daily_savings
        // Notice NO loan column (10 is omitted).
        // Row 1: Regular member with scientific notation NIK
        // Row 2: Pure Buku Putih member (no member_number, no nik, but has daily_savings and buku_putih_no)
        $payload = [
            'members' => [
                [
                    0 => '0001',
                    1 => '1.40309411282e+15', // scientific NIK
                    2 => 'Basalina Hutauruk',
                    3 => '2021-0017',
                    4 => '081234567890',
                    5 => 'Active',
                    6 => 4080000, // mandatory
                    7 => 12020000, // voluntary
                    8 => 200000, // principal
                    9 => 744486, // daily
                ],
                [
                    0 => '', // no member_number
                    1 => '', // no nik
                    2 => 'Pure Buku Putih Member',
                    3 => '2021-0099',
                    4 => '081234567899',
                    5 => 'Active',
                    6 => 500000, // should NOT create shares because it's pure buku putih
                    7 => 200000,
                    8 => 100000,
                    9 => 350000, // daily savings
                ]
            ]
        ];

        $response = $this->postJson('/api/members/import-initial', $payload);
        $response->assertStatus(200);

        // Verify member 1
        $member1 = Member::where('member_number', '0001')->first();
        $this->assertNotNull($member1);
        $this->assertEquals('1403094112820000', $member1->nik);
        $this->assertEquals(200000, $member1->principal_savings);
        $this->assertEquals(4080000, $member1->mandatory_savings);
        $this->assertEquals(12020000, $member1->voluntary_savings);
        $this->assertEquals(744486, $member1->daily_savings);
        $this->assertTrue((bool)$member1->has_buku_biru);
        $this->assertTrue((bool)$member1->has_buku_putih);

        // Verify member 2 (Pure Buku Putih)
        $member2 = Member::where('name', 'Pure Buku Putih Member')->first();
        $this->assertNotNull($member2);
        $this->assertStringStartsWith('TEMP', $member2->nik);
        $this->assertEquals(0, $member2->principal_savings);
        $this->assertEquals(0, $member2->mandatory_savings);
        $this->assertEquals(0, $member2->voluntary_savings);
        $this->assertEquals(350000, $member2->daily_savings);
        $this->assertEquals('2021-0099', $member2->buku_putih_no);
        $this->assertFalse((bool)$member2->has_buku_biru);
        $this->assertTrue((bool)$member2->has_buku_putih);
    }
}
