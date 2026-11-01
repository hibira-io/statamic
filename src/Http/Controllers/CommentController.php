<?php

namespace Hibira\Statamic\Http\Controllers;

use Hibira\Statamic\Channels;
use Hibira\Statamic\Comments\CommentStore;
use Hibira\Statamic\Members;
use Hibira\Statamic\Realtime;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

class CommentController extends Controller
{
    use Concerns;

    public function __construct(protected CommentStore $comments, protected Realtime $realtime) {}

    public function index(string $entry): JsonResponse
    {
        $this->viewableEntry($entry);

        return response()->json(['comments' => $this->comments->all($entry)]);
    }

    public function store(Request $request, string $entry): JsonResponse
    {
        $this->viewableEntry($entry);

        $data = $request->validate([
            'body' => ['required', 'string', 'max:'.(int) config('hibira-statamic.comments.max_length', 2000)],
        ]);

        $body = trim(str_replace("\r\n", "\n", $data['body']));
        abort_if($body === '', 422);

        $comment = $this->comments->add($entry, Members::info($this->user()), $body);
        $this->broadcast($request, $entry, 'added', $comment);

        return response()->json(['comment' => $comment], 201);
    }

    public function resolve(Request $request, string $entry, string $comment): JsonResponse
    {
        $this->viewableEntry($entry);

        $resolved = $request->boolean('resolved', true);
        $user = Members::info($this->user());

        $changed = $this->comments->resolve(
            $entry,
            $comment,
            $resolved ? ['id' => $user['id'], 'name' => $user['name']] : null,
        );
        abort_if($changed === null, 404);

        $this->broadcast($request, $entry, 'updated', $changed);

        return response()->json(['comment' => $changed]);
    }

    public function destroy(Request $request, string $entry, string $comment): JsonResponse
    {
        $this->viewableEntry($entry);

        $found = $this->comments->find($entry, $comment);
        abort_if($found === null, 404);

        $user = $this->user();
        abort_unless($user->isSuper() || (string) ($found['author']['id'] ?? '') === (string) $user->id(), 403);

        $this->comments->delete($entry, $comment);
        $this->broadcast($request, $entry, 'deleted', ['id' => $comment]);

        return response()->json(['deleted' => true]);
    }

    /** @param  array<string, mixed>  $comment */
    protected function broadcast(Request $request, string $entry, string $action, array $comment): void
    {
        $this->realtime->trigger(
            (string) Channels::entry($entry),
            'comment',
            ['action' => $action, 'comment' => $comment],
            Realtime::socketId($request->header('X-Socket-ID')),
        );
    }
}
