<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use App\Models\Machine;

class AdmsHandshakeTest extends TestCase
{
    use RefreshDatabase;

    public function test_valid_sn_returns_config_string(): void
    {
        $machine = Machine::create([
            'serial_number' => 'TEST001',
            'name' => 'Test Machine',
            'location' => 'Test Location',
            'status' => 'offline',
        ]);

        $response = $this->get('/iclock/cdata?SN=TEST001');

        $response->assertStatus(200);
        $response->assertSee('TEST001');
        $this->assertNotNull($machine->fresh()->last_seen_at);
        $this->assertEquals('online', $machine->fresh()->status);
    }

    public function test_invalid_sn_returns_unauthorized(): void
    {
        $response = $this->get('/iclock/cdata?SN=INVALID');

        $response->assertStatus(200);
        $response->assertSee('Unauthorized');
    }

    public function test_missing_sn_returns_unauthorized(): void
    {
        $response = $this->get('/iclock/cdata');

        $response->assertStatus(200);
        $response->assertSee('Unauthorized');
    }
}
