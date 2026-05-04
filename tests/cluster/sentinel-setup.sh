#!/usr/bin/env bash
# Boot the 1 master + 2 replicas + 3 sentinels Valkey topology used by
# the Sentinel test suite.
#
#   tests/cluster/sentinel-setup.sh
#
# Idempotent. Waits for sentinels to know the master before returning.
set -euo pipefail

cd "$(dirname "$0")"

echo "Booting Sentinel-managed Valkey on 127.0.0.1:6500-6502 + 26500-26502..."
docker compose -f sentinel-compose.yml up -d --wait

# Wait for sentinels to know about the master and quorum is satisfied.
for _ in $(seq 1 20); do
    addr=$(valkey-cli -p 26500 sentinel get-master-addr-by-name mymaster 2>/dev/null | head -2 | tr '\n' ' ' || true)
    if [ -n "$addr" ]; then
        echo "Sentinel reports master: $addr"
        exit 0
    fi
    sleep 1
done

echo "Sentinels did not report a master in time" >&2
exit 1
