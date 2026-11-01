<?php

namespace Hibira\Statamic\Http\Middleware;

use Closure;
use Hibira\Statamic\Channels;
use Hibira\Statamic\LivePreview;
use Hibira\Statamic\Realtime;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Vite;
use Symfony\Component\HttpFoundation\Response;

/**
 * Adds a small script to live preview pages opened outside the control
 * panel (on a phone, in another browser), which reloads the page when the
 * entry changes.
 */
class InjectPreviewReload
{
    public function __construct(protected Realtime $realtime) {}

    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        $token = $request->query('token');

        if (! config('hibira-statamic.live_preview')
            || ! is_string($token) || $token === ''
            || ! $response->headers->has('X-Statamic-Live-Preview')
            || $request->headers->get('Sec-Fetch-Dest') === 'iframe'
            || ! str_contains((string) $response->headers->get('Content-Type'), 'text/html')
            || ! $this->realtime->configured()) {
            return $response;
        }

        $html = $response->getContent();
        if (! is_string($html) || ($at = strripos($html, '</body>')) === false) {
            return $response;
        }

        LivePreview::watch($token);

        $response->setContent(substr_replace($html, $this->script($token), $at, 0));

        return $response;
    }

    protected function script(string $token): string
    {
        $options = $this->realtime->clientOptions();
        $url = sprintf(
            '%s://%s:%d/app/%s?protocol=7&client=hibira-statamic&version=0.1.0&flash=false',
            $options['tls'] ? 'wss' : 'ws',
            $options['host'],
            $options['port'],
            rawurlencode($options['key']),
        );

        $config = json_encode(
            ['url' => $url, 'channel' => Channels::preview($token)],
            JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT,
        );

        $source = (string) file_get_contents(__DIR__.'/../../../dist/preview.js');
        $nonce = method_exists(Vite::getFacadeRoot(), 'cspNonce') ? Vite::cspNonce() : null;

        return '<script'.($nonce ? ' nonce="'.e($nonce).'"' : '').'>'.trim($source).';HibiraStatamicPreview('.$config.');</script>';
    }
}
