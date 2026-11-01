<?php

namespace Hibira\Statamic\Listeners;

use Hibira\Statamic\Channels;
use Hibira\Statamic\Realtime;
use Statamic\Events\EntrySaved;
use Statamic\Facades\User;

/**
 * Tells everyone with the entry open that it was saved, so nobody keeps
 * editing an old version without knowing.
 *
 * Saves from the console (imports, scheduled jobs) are left out, so a bulk
 * import doesn't send one event per entry.
 */
class AnnounceEntrySaved
{
    public function __construct(protected Realtime $realtime) {}

    public function handle(EntrySaved $event): void
    {
        if (! config('hibira-statamic.presence') || (app()->runningInConsole() && ! app()->runningUnitTests())) {
            return;
        }

        $channel = Channels::entry((string) $event->entry->id());
        if ($channel === null) {
            return;
        }

        $user = User::current();

        $this->realtime->trigger($channel, 'saved', [
            'by' => $user ? ['id' => (string) $user->id(), 'name' => (string) ($user->name() ?: $user->email())] : null,
            'at' => now()->toIso8601String(),
        ]);
    }
}
