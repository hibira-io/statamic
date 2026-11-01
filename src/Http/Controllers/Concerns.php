<?php

namespace Hibira\Statamic\Http\Controllers;

use Hibira\Statamic\Channels;
use Statamic\Contracts\Auth\User as UserContract;
use Statamic\Contracts\Entries\Entry as EntryContract;
use Statamic\Facades\Entry;
use Statamic\Facades\User;

trait Concerns
{
    protected function user(): UserContract
    {
        $user = User::current();
        abort_unless($user instanceof UserContract, 403);

        return $user;
    }

    /** The entry, if the current user may view it. */
    protected function viewableEntry(string $id): EntryContract
    {
        abort_unless(Channels::validId($id), 404);

        $entry = Entry::find($id);
        abort_unless($entry instanceof EntryContract, 404);
        abort_unless($this->user()->can('view', $entry), 403);

        return $entry;
    }
}
