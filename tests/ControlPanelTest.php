<?php

namespace Hibira\Statamic\Tests;

use Hibira\Statamic\Http\Controllers\ScriptController;
use Statamic\Statamic;

class ControlPanelTest extends TestCase
{
    use FakesRoles;

    public function test_the_script_is_served_from_the_package(): void
    {
        $response = $this->get('/cp/hibira/cp.js');

        $response->assertOk();
        $this->assertStringContainsString('javascript', $response->headers->get('Content-Type'));
        $this->assertStringContainsString('hibira-st', $response->getContent());

        $this->get('/cp/hibira/cp.js', ['If-None-Match' => '"'.ScriptController::version().'"'])->assertStatus(304);
    }

    public function test_the_script_is_added_to_the_control_panel(): void
    {
        $scripts = Statamic::availableExternalScripts(request());

        $this->assertNotEmpty(array_filter($scripts, fn ($url) => str_contains($url, '/cp/hibira/cp.js?v=')));
    }

    public function test_config_needs_a_control_panel_user(): void
    {
        $this->getJson('/cp/hibira/config')->assertStatus(401);
    }

    public function test_config_has_what_the_browser_needs(): void
    {
        $this->actingAs($this->superUser())
            ->getJson('/cp/hibira/config')
            ->assertOk()
            ->assertJsonPath('enabled', true)
            ->assertJsonPath('connection.key', 'test-key')
            ->assertJsonPath('connection.host', 'ws.hibira.io')
            ->assertJsonPath('connection.tls', true)
            ->assertJsonPath('user.id', 'super')
            ->assertJsonPath('user.name', 'Ada Admin')
            ->assertJsonPath('user.super', true)
            ->assertJsonPath('features.comments', true)
            ->assertJsonPath('strings.comments', 'Comments')
            ->assertJsonPath('routes.comments', url('/cp/hibira/entries/__ENTRY__/comments'));
    }

    public function test_config_is_off_without_credentials(): void
    {
        $this->realtime->isConfigured = false;

        $this->actingAs($this->superUser())
            ->getJson('/cp/hibira/config')
            ->assertOk()
            ->assertExactJson(['enabled' => false]);
    }

    public function test_strings_follow_the_users_language(): void
    {
        $this->app->setLocale('zh_TW');

        $this->actingAs($this->superUser())
            ->getJson('/cp/hibira/config')
            ->assertJsonPath('strings.comments', '留言');
    }

    public function test_presence_subscriptions_are_signed_for_people_who_may_view_the_entry(): void
    {
        $this->entry('post-1');

        $response = $this->actingAs($this->superUser())->postJson('/cp/hibira/auth', [
            'socket_id' => '123.456',
            'channel_name' => 'presence-hibira-statamic.entry.post-1',
        ]);

        $response->assertOk();
        $data = $response->json();
        $channelData = json_decode($data['channel_data'], true);

        $this->assertSame('super', $channelData['user_id']);
        $this->assertSame('Ada Admin', $channelData['user_info']['name']);
        $this->assertSame('AA', $channelData['user_info']['initials']);

        $expected = 'test-key:'.hash_hmac('sha256', '123.456:presence-hibira-statamic.entry.post-1:'.$data['channel_data'], 'test-secret');
        $this->assertSame($expected, $data['auth']);
    }

    public function test_other_channels_and_missing_entries_are_refused(): void
    {
        $this->entry('post-1');
        $user = $this->superUser();

        $this->actingAs($user)->postJson('/cp/hibira/auth', [
            'socket_id' => '123.456',
            'channel_name' => 'presence-something-else',
        ])->assertForbidden();

        $this->actingAs($user)->postJson('/cp/hibira/auth', [
            'socket_id' => 'nope',
            'channel_name' => 'presence-hibira-statamic.entry.post-1',
        ])->assertForbidden();

        $this->actingAs($user)->postJson('/cp/hibira/auth', [
            'socket_id' => '123.456',
            'channel_name' => 'presence-hibira-statamic.entry.missing',
        ])->assertNotFound();
    }

    public function test_people_who_cant_view_the_entry_cant_join(): void
    {
        $this->setTestRoles(['author' => ['access cp']]);
        $this->entry('post-1');

        $user = $this->plainUser();
        $user->assignRole('author')->save();

        $this->actingAs($user)->postJson('/cp/hibira/auth', [
            'socket_id' => '123.456',
            'channel_name' => 'presence-hibira-statamic.entry.post-1',
        ])->assertForbidden();

        $this->actingAs($user)->getJson('/cp/hibira/entries/post-1/comments')->assertForbidden();
    }

    public function test_saving_an_entry_tells_the_people_who_have_it_open(): void
    {
        $entry = $this->entry('post-1');
        $this->realtime->sent = [];

        $this->actingAs($this->superUser());
        $entry->set('title', 'Changed')->save();

        $sent = $this->realtime->sent('saved');
        $this->assertCount(1, $sent);
        $this->assertSame('presence-hibira-statamic.entry.post-1', $sent[0]['channel']);
        $this->assertSame(['id' => 'super', 'name' => 'Ada Admin'], $sent[0]['data']['by']);
    }

    public function test_saved_warnings_can_be_turned_off(): void
    {
        config(['hibira-statamic.presence' => false]);
        $this->entry('post-1');

        $this->assertSame([], $this->realtime->sent('saved'));
    }
}
