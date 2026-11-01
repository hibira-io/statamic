<?php

namespace Hibira\Statamic\Http\Middleware;

use Closure;
use Hibira\Statamic\Realtime;
use Illuminate\Broadcasting\BroadcastServiceProvider;
use Illuminate\Http\Request;
use Statamic\Statamic;
use Symfony\Component\HttpFoundation\Response;

/**
 * With BROADCAST_CONNECTION=hibira, Statamic turns on its control panel
 * Echo client but only knows how to configure Pusher and Reverb. This fills
 * in the Hibira host, so addons built on Statamic.$echo work as they are.
 */
class PointControlPanelEchoAtHibira
{
    public function __construct(protected Realtime $realtime) {}

    public function handle(Request $request, Closure $next): Response
    {
        if (config('hibira-statamic.control_panel_echo')
            && config('broadcasting.default') === 'hibira'
            && $this->realtime->configured()
            && $this->statamicBroadcastingEnabled($request)) {
            $options = $this->realtime->clientOptions();

            Statamic::provideToScript(['broadcasting' => [
                'enabled' => true,
                'endpoint' => config('broadcasting.auth_endpoint', url('/broadcasting/auth')),
                'connection' => 'hibira',
                'options' => [
                    'broadcaster' => 'pusher',
                    'key' => $options['key'],
                    'cluster' => 'hkg',
                    'wsHost' => $options['host'],
                    'wsPort' => $options['port'],
                    'wssPort' => $options['port'],
                    'forceTLS' => $options['tls'],
                    'enabledTransports' => ['ws', 'wss'],
                ],
            ]]);
        }

        return $next($request);
    }

    /**
     * Statamic turns its Echo client on only when the app has broadcasting
     * set up (routes/channels.php). Left off otherwise, so the control panel
     * doesn't open a connection nobody uses.
     */
    protected function statamicBroadcastingEnabled(Request $request): bool
    {
        $broadcasting = Statamic::jsonVariables($request)['broadcasting'] ?? [];

        if (is_array($broadcasting) && ! empty($broadcasting['enabled'])) {
            return true;
        }

        // Statamic checks once, while booting, and may miss a provider that
        // loads after it. Ask again now.
        return array_key_exists(BroadcastServiceProvider::class, app()->getLoadedProviders());
    }
}
