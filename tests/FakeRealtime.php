<?php

namespace Hibira\Statamic\Tests;

use Hibira\Laravel\Hibira;
use Hibira\Statamic\Realtime;

/** Records events instead of sending them. */
class FakeRealtime extends Realtime
{
    /** @var list<array{channel: string, event: string, data: array<string, mixed>, except: ?string}> */
    public array $sent = [];

    public function __construct(Hibira $hibira, public bool $isConfigured = true)
    {
        parent::__construct($hibira);
    }

    public function configured(): bool
    {
        return $this->isConfigured;
    }

    public function trigger(string $channel, string $event, array $data, ?string $exceptSocket = null): void
    {
        if ($this->isConfigured) {
            $this->sent[] = ['channel' => $channel, 'event' => $event, 'data' => $data, 'except' => $exceptSocket];
        }
    }

    /** @return list<array{channel: string, event: string, data: array<string, mixed>, except: ?string}> */
    public function sent(string $event): array
    {
        return array_values(array_filter($this->sent, fn ($sent) => $sent['event'] === $event));
    }
}
