<?php

namespace Hibira\Statamic;

use Hibira\Laravel\Hibira;
use Throwable;

/**
 * Talks to Hibira for the addon: sends events and signs channel
 * subscriptions.
 *
 * A failed send is reported but never fails the request. Comments are
 * stored first, and open pages fetch them again when they reconnect.
 */
class Realtime
{
    public function __construct(protected Hibira $hibira) {}

    /** Whether the app ID, key and secret are set. */
    public function configured(): bool
    {
        return $this->hibira->missingRealtimeCredentials() === [];
    }

    /** @param  array<string, mixed>  $data */
    public function trigger(string $channel, string $event, array $data, ?string $exceptSocket = null): void
    {
        if (! $this->configured()) {
            return;
        }

        try {
            $this->hibira->realtime()->trigger(
                $channel,
                $event,
                $data,
                $exceptSocket ? ['socket_id' => $exceptSocket] : [],
            );
        } catch (Throwable $e) {
            report($e);
        }
    }

    /** @param  array<string, mixed>  $info */
    public function authorizePresence(string $channel, string $socketId, string $userId, array $info): string
    {
        return $this->hibira->realtime()->authorizePresenceChannel($channel, $socketId, $userId, $info);
    }

    /**
     * What the browser needs to connect: the public key and WebSocket host.
     *
     * @return array{key: string, host: string, port: int, tls: bool}
     */
    public function clientOptions(): array
    {
        $config = config('hibira');
        $tls = ($config['scheme'] ?? 'https') !== 'http';

        return [
            'key' => (string) ($config['key'] ?? ''),
            'host' => (string) ($config['host'] ?? 'ws.hibira.io'),
            'port' => (int) ($config['port'] ?? ($tls ? 443 : 80)),
            'tls' => $tls,
        ];
    }

    /** A socket ID sent by the browser, or null when it is missing or malformed. */
    public static function socketId(?string $value): ?string
    {
        return is_string($value) && preg_match('/\A\d+\.\d+\z/', $value) ? $value : null;
    }
}
