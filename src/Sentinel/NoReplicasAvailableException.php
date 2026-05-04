<?php declare(strict_types=1);

namespace Resp3\Laravel\Sentinel;

use RuntimeException;

/**
 * Thrown by Resp3SentinelReplicaPool when every known replica connection has
 * failed and a fresh discovery query also returned an empty list. Caught by
 * Resp3SentinelConnection so reads silently fall back to the master client.
 */
final class NoReplicasAvailableException extends RuntimeException
{
}
