<?php

namespace Tests\Feature;

use App\Models\InitialAccountBalance;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class InitialBalanceControllerTest extends TestCase
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

    public function test_get_initial_balances_default()
    {
        Sanctum::actingAs($this->adminUser);

        $response = $this->getJson('/api/initial-balances');

        $response->assertStatus(200)
            ->assertJson([
                'success' => true,
                'status'  => 'success',
            ])
            ->assertJsonStructure([
                'data' => [
                    'cutoff_date',
                    'total_debit',
                    'total_credit',
                    'items',
                ]
            ]);
    }

    public function test_get_initial_balances_with_month_year_week_query()
    {
        Sanctum::actingAs($this->adminUser);

        $response = $this->getJson('/api/initial-balances?month=9&year=2026&week=Semua');

        $response->assertStatus(200)
            ->assertJson([
                'success' => true,
                'status'  => 'success',
            ]);

        $data = $response->json('data');
        $this->assertArrayHasKey('items', $data);
        $this->assertCount(17, $data['items']);
    }

    public function test_store_and_retrieve_initial_balances()
    {
        Sanctum::actingAs($this->adminUser);

        $payload = [
            'cutoff_date' => '2026-05-01',
            'balances'    => [
                ['account_code' => '1000', 'debit' => 10000000, 'credit' => 0],
                ['account_code' => '2020', 'debit' => 0, 'credit' => 10000000],
            ]
        ];

        $storeResponse = $this->postJson('/api/initial-balances', $payload);
        $storeResponse->assertStatus(200)
            ->assertJson([
                'success' => true,
                'status'  => 'success',
            ]);

        $getResponse = $this->getJson('/api/initial-balances?cutoff_date=2026-05-01');
        $getResponse->assertStatus(200);

        $kasItem = collect($getResponse->json('data.items'))->firstWhere('account_code', '1000');
        $this->assertEquals(10000000, $kasItem['debit']);
    }
}
