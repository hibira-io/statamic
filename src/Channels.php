<?php

namespace Hibira\Statamic;

/** Channel names used by the addon. */
class Channels
{
    public const ENTRY_PREFIX = 'presence-hibira-statamic.entry.';

    public const PREVIEW_PREFIX = 'hibira-statamic.preview.';

    /** The presence channel of an entry, or null when its ID can't be used in a channel name. */
    public static function entry(string $id): ?string
    {
        return static::validId($id) ? static::ENTRY_PREFIX.$id : null;
    }

    /** The entry ID in an entry channel name, or null for any other channel. */
    public static function entryId(string $channel): ?string
    {
        if (! str_starts_with($channel, static::ENTRY_PREFIX)) {
            return null;
        }

        $id = substr($channel, strlen(static::ENTRY_PREFIX));

        return static::validId($id) ? $id : null;
    }

    /**
     * The public channel a live preview link listens on.
     *
     * Named after a keyed hash of the preview token, so only someone who
     * has the link can find it. Its events carry no content.
     */
    public static function preview(string $token): string
    {
        return static::PREVIEW_PREFIX.substr(hash_hmac('sha256', $token, static::key()), 0, 40);
    }

    public static function validId(string $id): bool
    {
        return (bool) preg_match('/\A[A-Za-z0-9_\-]{1,100}\z/', $id);
    }

    protected static function key(): string
    {
        return (string) (config('hibira.secret') ?: config('app.key'));
    }
}
