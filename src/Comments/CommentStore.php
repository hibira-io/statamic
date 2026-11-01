<?php

namespace Hibira\Statamic\Comments;

use Hibira\Statamic\Channels;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Str;
use InvalidArgumentException;
use RuntimeException;

/**
 * Comments on entries, kept as one JSON file per entry.
 *
 * Writes take an exclusive lock on the file, so two people commenting at
 * the same moment don't overwrite each other.
 */
class CommentStore
{
    public function __construct(protected Filesystem $files, protected string $path) {}

    /** @return list<array<string, mixed>> oldest first */
    public function all(string $entryId): array
    {
        $file = $this->file($entryId);

        if (! $this->files->exists($file)) {
            return [];
        }

        return $this->decode((string) $this->files->sharedGet($file));
    }

    public function count(string $entryId, bool $includeResolved = false): int
    {
        return count(array_filter(
            $this->all($entryId),
            fn (array $comment) => $includeResolved || $comment['resolved_at'] === null,
        ));
    }

    /**
     * @param  array<string, mixed>  $author
     * @return array<string, mixed>
     */
    public function add(string $entryId, array $author, string $body): array
    {
        $comment = [
            'id' => strtolower((string) Str::ulid()),
            'entry' => $entryId,
            'body' => $body,
            'author' => $author,
            'created_at' => now()->toIso8601String(),
            'resolved_at' => null,
            'resolved_by' => null,
        ];

        $this->change($entryId, function (array $comments) use ($comment) {
            $comments[] = $comment;

            return $comments;
        });

        return $comment;
    }

    /** @return array<string, mixed>|null */
    public function find(string $entryId, string $commentId): ?array
    {
        foreach ($this->all($entryId) as $comment) {
            if ($comment['id'] === $commentId) {
                return $comment;
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>|null  $by  who resolved it, or null to reopen
     * @return array<string, mixed>|null the changed comment
     */
    public function resolve(string $entryId, string $commentId, ?array $by): ?array
    {
        $changed = null;

        $this->change($entryId, function (array $comments) use ($commentId, $by, &$changed) {
            foreach ($comments as $i => $comment) {
                if ($comment['id'] === $commentId) {
                    $comments[$i]['resolved_at'] = $by ? now()->toIso8601String() : null;
                    $comments[$i]['resolved_by'] = $by;
                    $changed = $comments[$i];
                }
            }

            return $comments;
        });

        return $changed;
    }

    public function delete(string $entryId, string $commentId): bool
    {
        $deleted = false;

        $this->change($entryId, function (array $comments) use ($commentId, &$deleted) {
            $kept = array_values(array_filter($comments, fn (array $c) => $c['id'] !== $commentId));
            $deleted = count($kept) !== count($comments);

            return $kept;
        });

        return $deleted;
    }

    /** Remove every comment of an entry. */
    public function forget(string $entryId): void
    {
        $this->files->delete($this->file($entryId));
    }

    /** @param  callable(list<array<string, mixed>>): list<array<string, mixed>>  $callback */
    protected function change(string $entryId, callable $callback): void
    {
        $file = $this->file($entryId);
        $this->files->ensureDirectoryExists(dirname($file));

        $handle = fopen($file, 'c+');
        if ($handle === false) {
            throw new RuntimeException("Unable to open {$file}.");
        }

        try {
            flock($handle, LOCK_EX);
            $comments = $callback($this->decode((string) stream_get_contents($handle)));

            ftruncate($handle, 0);
            rewind($handle);
            fwrite($handle, json_encode($comments, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)."\n");
            fflush($handle);
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }

        if ($comments === []) {
            $this->files->delete($file);
        }
    }

    /** @return list<array<string, mixed>> */
    protected function decode(string $json): array
    {
        $comments = json_decode($json, true);

        return is_array($comments) ? array_values(array_filter($comments, 'is_array')) : [];
    }

    protected function file(string $entryId): string
    {
        if (! Channels::validId($entryId)) {
            throw new InvalidArgumentException('Invalid entry ID.');
        }

        return rtrim($this->path, '/\\').DIRECTORY_SEPARATOR.$entryId.'.json';
    }
}
