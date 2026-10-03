<?php declare(strict_types=1);

namespace Resp3\Laravel\Tests\Support;

use Closure;
use Resp3\Laravel\Client\ServerCapabilities;
use Resp3\PushMessage;

/**
 * The push and capability members of Resp3ClientInterface for test
 * doubles. Reports a Valkey 9.1.2 server.
 */
trait ClientStubDefaults
{
    public ?Closure $pushListener = null;

    /** @return list<PushMessage> */
    public function drainPushes(): array
    {
        return [];
    }

    /** @param (Closure(PushMessage): void)|null $listener */
    public function setPushListener(?Closure $listener): void
    {
        $this->pushListener = $listener;
    }

    public function capabilities(): ServerCapabilities
    {
        return ServerCapabilities::fromHello([
            'server' => 'valkey',
            'version' => '9.1.2',
            'id' => 1,
            'mode' => 'standalone',
        ]);
    }
}
