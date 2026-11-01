<?php

namespace Hibira\Statamic;

use Statamic\Contracts\Auth\User;

/** How a control panel user is shown to the others. */
class Members
{
    public const COLORS = ['#2563eb', '#db2777', '#16a34a', '#ea580c', '#7c3aed', '#0891b2', '#ca8a04', '#dc2626'];

    /** @return array{id: string, name: string, initials: string, avatar: ?string, color: string} */
    public static function info(User $user): array
    {
        $id = (string) $user->id();
        $name = trim((string) ($user->name() ?: $user->email()));

        $avatar = null;
        try {
            $avatar = $user->avatar() ?: null;
        } catch (\Throwable) {
            // Some user repositories can't build an avatar URL.
        }

        return [
            'id' => $id,
            'name' => $name,
            'initials' => static::initials($user, $name),
            'avatar' => is_string($avatar) ? $avatar : null,
            'color' => static::COLORS[crc32($id) % count(static::COLORS)],
        ];
    }

    protected static function initials(User $user, string $name): string
    {
        try {
            $initials = (string) $user->initials();
        } catch (\Throwable) {
            $initials = '';
        }

        if ($initials === '') {
            $initials = mb_strtoupper(mb_substr($name, 0, 1));
        }

        return mb_substr($initials, 0, 2);
    }
}
