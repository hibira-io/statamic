<?php

namespace Hibira\Statamic\Http\Controllers;

use Hibira\Statamic\Channels;
use Hibira\Statamic\Members;
use Hibira\Statamic\Realtime;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Routing\Controller;

/** Signs subscriptions to an entry's presence channel for people who may view the entry. */
class AuthController extends Controller
{
    use Concerns;

    public function __invoke(Request $request, Realtime $realtime): Response
    {
        $socketId = Realtime::socketId($request->input('socket_id'));
        $channel = (string) $request->input('channel_name');
        $entryId = Channels::entryId($channel);

        abort_unless($socketId !== null && $entryId !== null, 403);
        abort_unless($realtime->configured(), 503);

        $this->viewableEntry($entryId);
        $member = Members::info($user = $this->user());

        return response(
            $realtime->authorizePresence($channel, $socketId, $member['id'], array_diff_key($member, ['id' => true])),
            200,
            ['Content-Type' => 'application/json'],
        );
    }
}
