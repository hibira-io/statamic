<?php

namespace Hibira\Statamic;

use Illuminate\Support\Facades\Cache;

/**
 * Live preview on other devices.
 *
 * Statamic reuses one token for an editor's live preview, so a preview
 * link opened elsewhere shows the latest changes when reloaded. A page
 * opened outside the control panel subscribes to the token's channel, and
 * every preview update tells it to reload.
 */
class LivePreview
{
    protected const MINUTES = 120;

    public static function remember(string $userId, string $entryId, string $url): void
    {
        Cache::put(static::linkKey($userId, $entryId), $url, now()->addMinutes(static::MINUTES));
    }

    public static function link(string $userId, string $entryId): ?string
    {
        $url = Cache::get(static::linkKey($userId, $entryId));

        return is_string($url) ? $url : null;
    }

    /** A preview page with this token is open outside the control panel. */
    public static function watch(string $token): void
    {
        Cache::put(static::watchKey($token), true, now()->addMinutes(static::MINUTES));
    }

    public static function watched(string $token): bool
    {
        return (bool) Cache::get(static::watchKey($token));
    }

    /** Reload preview pages of this token, if any were opened outside the control panel. */
    public static function refresh(string $token): void
    {
        if (static::watched($token)) {
            app(Realtime::class)->trigger(Channels::preview($token), 'refresh', ['at' => now()->getTimestampMs()]);
        }
    }

    protected static function linkKey(string $userId, string $entryId): string
    {
        return 'hibira-statamic.preview-link.'.sha1($userId.'|'.$entryId);
    }

    protected static function watchKey(string $token): string
    {
        return 'hibira-statamic.preview-watched.'.sha1($token);
    }
}
