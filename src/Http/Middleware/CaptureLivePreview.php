<?php

namespace Hibira\Statamic\Http\Middleware;

use Closure;
use Hibira\Statamic\LivePreview;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Statamic\Facades\User;
use Symfony\Component\HttpFoundation\Response;

/**
 * Watches Statamic's live preview requests in the control panel: keeps the
 * editor's preview link for "open on another device" and reloads preview
 * pages opened elsewhere.
 */
class CaptureLivePreview
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        if (! config('hibira-statamic.live_preview') || ! $response instanceof JsonResponse || ! $response->isSuccessful()) {
            return $response;
        }

        $name = (string) optional($request->route())->getName();
        if (! str_ends_with($name, 'collections.entries.preview.edit')) {
            return $response;
        }

        $data = $response->getData(true);
        $token = $data['token'] ?? null;
        $url = $data['url'] ?? null;
        $entry = $request->route('entry');
        $entryId = is_object($entry) && method_exists($entry, 'id') ? (string) $entry->id() : (is_string($entry) ? $entry : null);

        if (! is_string($token) || ! is_string($url)) {
            return $response;
        }

        if ($entryId !== null && ($user = User::current())) {
            LivePreview::remember((string) $user->id(), $entryId, $url);
        }

        LivePreview::refresh($token);

        return $response;
    }
}
