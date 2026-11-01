<?php

namespace Hibira\Statamic\Tests;

use Hibira\Statamic\Channels;
use Hibira\Statamic\Http\Middleware\InjectPreviewReload;
use Hibira\Statamic\LivePreview;
use Illuminate\Support\Facades\Route;

class LivePreviewTest extends TestCase
{
    protected function defineRoutes($router)
    {
        $router->middleware('web')->get('/preview-page', fn () => response('<html><body><h1>Draft</h1></body></html>')
            ->header('X-Statamic-Live-Preview', '1')
            ->header('Content-Type', 'text/html; charset=UTF-8'));

        $router->middleware('web')->get('/normal-page', fn () => response('<html><body>Live</body></html>'));
    }

    public function test_preview_pages_opened_outside_the_control_panel_get_the_reload_script(): void
    {
        $response = $this->get('/preview-page?live-preview=x&token=tok123', ['Sec-Fetch-Dest' => 'document']);

        $response->assertOk();
        $html = $response->getContent();
        $this->assertStringContainsString('HibiraStatamicPreview({', $html);
        $this->assertStringContainsString(Channels::preview('tok123'), $html);
        $this->assertStringContainsString('wss:\/\/ws.hibira.io:443\/app\/test-key', str_replace('/', '\/', $html));
        $this->assertLessThan(strpos($html, '</body>'), strpos($html, '<script>'));
        $this->assertTrue(LivePreview::watched('tok123'));
    }

    public function test_the_control_panels_own_preview_iframe_is_left_alone(): void
    {
        $html = $this->get('/preview-page?token=tok123', ['Sec-Fetch-Dest' => 'iframe'])->getContent();

        $this->assertStringNotContainsString('HibiraStatamicPreview', $html);
        $this->assertFalse(LivePreview::watched('tok123'));
    }

    public function test_other_pages_are_left_alone(): void
    {
        $this->assertStringNotContainsString('HibiraStatamicPreview', $this->get('/normal-page?token=tok123')->getContent());
        $this->assertStringNotContainsString('HibiraStatamicPreview', $this->get('/preview-page')->getContent());

        config(['hibira-statamic.live_preview' => false]);
        $this->assertStringNotContainsString('HibiraStatamicPreview', $this->get('/preview-page?token=tok123')->getContent());
    }

    public function test_preview_channels_are_unguessable_and_stable(): void
    {
        $this->assertSame(Channels::preview('a'), Channels::preview('a'));
        $this->assertNotSame(Channels::preview('a'), Channels::preview('b'));
        $this->assertMatchesRegularExpression('/\Ahibira-statamic\.preview\.[a-f0-9]{40}\z/', Channels::preview('a'));
    }

    public function test_updating_the_live_preview_reloads_watching_pages_and_keeps_the_link(): void
    {
        $this->entry('post-1');
        $user = $this->superUser();

        $first = $this->actingAs($user)->postJson('/cp/collections/blog/entries/post-1/preview', ['preview' => ['title' => 'Draft']]);
        $first->assertOk();
        $token = $first->json('token');

        // Nobody opened the link yet, so nothing is sent.
        $this->assertSame([], $this->realtime->sent('refresh'));
        $this->actingAs($user)->getJson('/cp/hibira/entries/post-1/preview-link')
            ->assertOk()
            ->assertJsonPath('url', $first->json('url'));

        LivePreview::watch($token);

        $this->actingAs($user)
            ->postJson('/cp/collections/blog/entries/post-1/preview', ['token' => $token, 'preview' => ['title' => 'Draft 2']])
            ->assertOk();

        $sent = $this->realtime->sent('refresh');
        $this->assertCount(1, $sent);
        $this->assertSame(Channels::preview($token), $sent[0]['channel']);
    }

    public function test_the_preview_link_is_per_user(): void
    {
        $this->entry('post-1');

        $this->actingAs($this->superUser('ada'))->getJson('/cp/hibira/entries/post-1/preview-link')
            ->assertOk()
            ->assertJsonPath('url', null);
    }
}
