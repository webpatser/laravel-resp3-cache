#!/usr/bin/env bash
# Boot the 6-node Valkey cluster used by the cluster test suite.
#
#   tests/cluster/setup.sh
#
# Idempotent: re-running on a healthy cluster is a no-op.
set -euo pipefail

cd "$(dirname "$0")"

echo "Booting 6-node Valkey cluster on 127.0.0.1:7100-7105..."
docker compose up -d --wait

# cluster-init is a one-shot service; wait for it to exit successfully.
echo "Waiting for cluster-init to finish..."
for _ in $(seq 1 30); do
    state=$(docker inspect -f '{{.State.Status}}' tests-cluster-init-1 2>/dev/null \
            || docker inspect -f '{{.State.Status}}' "$(docker compose ps -q cluster-init)" 2>/dev/null \
            || echo "unknown")
    if [ "$state" = "exited" ]; then
        break
    fi
    sleep 1
done

# Sanity check: all 16384 slots assigned.
slots_ok=$(valkey-cli -p 7100 cluster info 2>/dev/null | grep cluster_slots_ok | tr -d '\r' | cut -d: -f2)
if [ "$slots_ok" != "16384" ]; then
    echo "Cluster slots not fully assigned: cluster_slots_ok=$slots_ok"
    exit 1
fi

echo "Cluster up: 16384 slots assigned across 3 masters + 3 replicas."
