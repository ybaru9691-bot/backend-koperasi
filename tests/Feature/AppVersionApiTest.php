<?php

namespace Tests\Feature;

use Tests\TestCase;

class AppVersionApiTest extends TestCase
{
    public function test_get_app_version_returns_correct_json_structure(): void
    {
        $response = $this->getJson('/api/app-version');

        $response->assertStatus(200)
            ->assertJsonStructure([
                'success',
                'message',
                'data' => [
                    'latest_version',
                    'min_version',
                    'force_update',
                    'download_url',
                    'release_notes',
                ],
            ]);

        $this->assertTrue($response->json('success'));
        $this->assertEquals('1.0.1', $response->json('data.latest_version'));
        $this->assertFalse($response->json('data.force_update'));
    }

    public function test_force_update_is_true_when_current_version_below_min(): void
    {
        $response = $this->getJson('/api/app-version?current_version=0.9.9');

        $response->assertStatus(200);
        $this->assertTrue($response->json('data.force_update'));
    }
}
