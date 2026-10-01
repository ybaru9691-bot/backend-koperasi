<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Member;
use App\Models\Period;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class TransactionImportTest extends TestCase
{
    use RefreshDatabase;

    public function test_transaction_import_updates_member_balances_correctly()
    {
        // 1. Create admin user
        $admin = User::create([
            'name' => 'Admin Import',
            'email' => 'admin.import@koperasi.com',
            'password' => bcrypt('123456'),
            'role' => 'admin',
            'nik' => '1122334455667788',
        ]);
        Sanctum::actingAs($admin);

        // 2. Create active period
        Period::create([
            'period_name' => 'Agustus 2026',
            'start_date'  => '2026-08-01',
            'end_date'    => '2026-08-31',
            'status'      => 'open',
            'is_locked'   => false,
            'is_active'   => true,
        ]);

        // 3. Create members
        $member1 = Member::create([
            'member_number' => 'PELITA-202608-0001',
            'nik' => '1234567890123456',
            'name' => 'Alice Member',
            'email' => 'alice@koperasi.com',
            'phone' => '081234567891',
            'principal_savings' => 200000.00,
            'mandatory_savings' => 20000.00,
            'voluntary_savings' => 10000.00,
            'status' => 'active',
        ]);

        $member2 = Member::create([
            'member_number' => 'PELITA-202608-0002',
            'nik' => '1234567890123457',
            'name' => 'Bob Member',
            'email' => 'bob@koperasi.com',
            'phone' => '081234567892',
            'principal_savings' => 200000.00,
            'mandatory_savings' => 20000.00,
            'voluntary_savings' => 10000.00,
            'status' => 'active',
        ]);

        // 4. Create cash account
        Account::forceCreate([
            'account_number' => 'KAS-101',
            'account_name' => 'Kas Koperasi',
            'account_type' => 'kas',
            'category' => 'asset',
            'balance' => 0.00,
        ]);

        // 5. Build transaction array payload
        $payload = [
            'transactions' => [
                [
                    'member_id' => $member1->id,
                    'amount' => 50000,
                    'transaction_date' => '2026-08-15',
                    'description' => 'Setoran Simpanan Pokok',
                    'account_code' => '2020_pokok',
                ],
                [
                    'member_id' => $member1->id,
                    'amount' => 25000,
                    'transaction_date' => '2026-08-15',
                    'description' => 'Setoran Simpanan Wajib',
                    'account_code' => '2020_wajib',
                ],
                [
                    'member_id' => $member2->id,
                    'amount' => 100000,
                    'transaction_date' => '2026-08-15',
                    'description' => 'Setoran Tabungan Harian (Sukarela)',
                    'account_code' => '2020_sukarela',
                ]
            ]
        ];

        // 6. Call endpoint
        $response = $this->postJson('/api/transactions/import', $payload);
        $response->assertStatus(200);
        $response->assertJson([
            'success' => true,
        ]);

        // 7. Verify balances updated properly
        $member1->refresh();
        $member2->refresh();

        $this->assertEquals(250000.00, (float) $member1->principal_savings); // 200k + 50k
        $this->assertEquals(45000.00, (float) $member1->mandatory_savings);  // 20k + 25k
        $this->assertEquals(110000.00, (float) $member2->voluntary_savings); // 10k + 100k
    }

    public function test_transaction_import_db_transaction_rollback_on_failure()
    {
        // 1. Create admin user
        $admin = User::create([
            'name' => 'Admin Import Rollback',
            'email' => 'admin.import.rb@koperasi.com',
            'password' => bcrypt('123456'),
            'role' => 'admin',
            'nik' => '1122334455667789',
        ]);
        Sanctum::actingAs($admin);

        // 2. Create active period
        Period::create([
            'period_name' => 'Agustus 2026',
            'start_date'  => '2026-08-01',
            'end_date'    => '2026-08-31',
            'status'      => 'open',
            'is_locked'   => false,
            'is_active'   => true,
        ]);

        // 3. Create member
        $member = Member::create([
            'member_number' => 'PELITA-202608-0003',
            'nik' => '1234567890123458',
            'name' => 'Charlie Member',
            'email' => 'charlie@koperasi.com',
            'phone' => '081234567893',
            'principal_savings' => 200000.00,
            'mandatory_savings' => 20000.00,
            'voluntary_savings' => 10000.00,
            'status' => 'active',
        ]);

        // 4. Create cash account
        Account::forceCreate([
            'account_number' => 'KAS-101',
            'account_name' => 'Kas Koperasi',
            'account_type' => 'kas',
            'category' => 'asset',
            'balance' => 0.00,
        ]);

        // 5. Payload with one valid row and one invalid row (non-existent member_id)
        $payload = [
            'transactions' => [
                [
                    'member_id' => $member->id,
                    'amount' => 50000,
                    'transaction_date' => '2026-08-15',
                    'description' => 'Setoran Simpanan Pokok',
                    'account_code' => '2020_pokok',
                ],
                [
                    'member_id' => 999999, // Invalid
                    'amount' => 25000,
                    'transaction_date' => '2026-08-15',
                    'description' => 'Setoran Simpanan Wajib',
                    'account_code' => '2020_wajib',
                ]
            ]
        ];

        // 6. Call endpoint -> should fail
        $response = $this->postJson('/api/transactions/import', $payload);
        $response->assertStatus(400);
        $response->assertJson([
            'success' => false,
            'failed_row_index' => 2,
        ]);

        // 7. Verify balance of member was NOT updated (rolled back)
        $member->refresh();
        $this->assertEquals(200000.00, (float) $member->principal_savings);
    }

    public function test_import_initial_members_creates_members_and_balances()
    {
        // 1. Create admin user
        $admin = User::create([
            'name' => 'Admin Initial Import',
            'email' => 'admin.initial@koperasi.com',
            'password' => bcrypt('123456'),
            'role' => 'admin',
            'nik' => '1122334455667799',
        ]);
        Sanctum::actingAs($admin);

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

        // 4. Payload for initial members import
        $payload = [
            'members' => [
                [
                    'name' => 'David Imported',
                    'nik' => '9876543210123456',
                    'phone' => '08987654321',
                    'principal_savings' => 200000,
                    'mandatory_savings' => 20000,
                    'voluntary_savings' => 10000,
                    'daily_savings' => 50000,
                ]
            ]
        ];

        // 5. Call import-initial endpoint
        $response = $this->postJson('/api/members/import-initial', $payload);
        $response->assertStatus(200);
        $response->assertJson([
            'success' => true,
        ]);

        // 6. Verify database records
        $member = Member::where('nik', '9876543210123456')->first();
        $this->assertNotNull($member);
        $this->assertEquals('David Imported', $member->name);
        $this->assertEquals(200000.00, (float) $member->principal_savings);
        $this->assertEquals(20000.00, (float) $member->mandatory_savings);
        $this->assertEquals(10000.00, (float) $member->voluntary_savings);
        $this->assertEquals(50000.00, (float) $member->daily_savings);
        $this->assertTrue($member->has_buku_biru);
        $this->assertTrue($member->has_buku_putih);
    }
}
