<?php

namespace Hibira\Statamic\Tests;

use Illuminate\Broadcasting\BroadcastServiceProvider;

class ControlPanelEchoTest extends TestCase
{
    protected function getPackageProviders($app)
    {
        return array_merge(parent::getPackageProviders($app), [BroadcastServiceProvider::class]);
    }

    protected function getEnvironmentSetUp($app)
    {
        parent::getEnvironmentSetUp($app);

        $app['config']->set('broadcasting.default', 'hibira');
    }

    public function test_statamics_own_echo_is_pointed_at_hibira(): void
    {
        $html = $this->actingAs($this->superUser())->get('/cp/dashboard')->assertOk()->getContent();

        preg_match('/var StatamicConfig = (\{.*?\});?\s*\n/s', $html, $match);
        $this->assertNotEmpty($match, 'StatamicConfig not found on the dashboard');
        $broadcasting = json_decode($match[1], true)['broadcasting'];

        $this->assertTrue($broadcasting['enabled']);
        $this->assertSame('pusher', $broadcasting['options']['broadcaster']);
        $this->assertSame('ws.hibira.io', $broadcasting['options']['wsHost']);
        $this->assertSame(443, $broadcasting['options']['wssPort']);
        $this->assertSame('test-key', $broadcasting['options']['key']);
    }
}
