#!/usr/bin/env bash
# Boot the 6-node Valkey cluster used by the cluster test suite.
#
#   tests/cluster/setup.sh
#
# Idempotent: re-running on a healthy cluster is a no-op. Exits non-zero
# (and prints the cluster-init log) when the cluster does not reach
# cluster_state:ok with all 16384 slots on every node in time. Needs only
# docker on the host; valkey-cli runs inside the node containers.
set -euo pipefail

cd "$(dirname "$0")"

nodes=(1:7100 2:7101 3:7102 4:7103 5:7104 6:7105)

# True when every node reports cluster_state:ok and 16384 slots ok.
cluster_ok() {
    local entry n port info
    for entry in "${nodes[@]}"; do
        n=${entry%%:*}
        port=${entry##*:}
        info=$(docker exec "r3-cluster-$n" valkey-cli -p "$port" cluster info 2>/dev/null | tr -d '\r') || return 1
        grep -q '^cluster_state:ok$' <<<"$info" || return 1
        grep -q '^cluster_slots_ok:16384$' <<<"$info" || return 1
    done
}

fail() {
    echo "$1" >&2
    docker compose logs --no-color cluster-init 2>&1 | tail -30 >&2 || true
    for entry in "${nodes[@]}"; do
        n=${entry%%:*}
        port=${entry##*:}
        echo "--- r3-cluster-$n" >&2
        docker exec "r3-cluster-$n" valkey-cli -p "$port" cluster info 2>&1 \
            | grep -E '^cluster_(state|slots_ok|known_nodes)' >&2 || true
    done
    exit 1
}

echo "Booting 6-node Valkey cluster on 127.0.0.1:7100-7105..."
docker compose up -d --wait valkey-1 valkey-2 valkey-3 valkey-4 valkey-5 valkey-6

if cluster_ok; then
    echo "Cluster already up: 16384 slots assigned, cluster_state:ok on all nodes."
    exit 0
fi

# cluster-init is a one-shot service that runs `valkey-cli --cluster create`.
docker compose up -d cluster-init

echo "Waiting for cluster-init to finish..."
init_status=""
for _ in $(seq 1 60); do
    id=$(docker compose ps -aq cluster-init)
    init_status=$(docker inspect -f '{{.State.Status}}' "$id" 2>/dev/null || echo unknown)
    if [ "$init_status" = "exited" ]; then
        break
    fi
    sleep 1
done
if [ "$init_status" != "exited" ]; then
    fail "cluster-init did not finish within 60s (status: $init_status)"
fi
init_code=$(docker inspect -f '{{.State.ExitCode}}' "$(docker compose ps -aq cluster-init)")
if [ "$init_code" != "0" ]; then
    fail "cluster-init exited with code $init_code"
fi

# --cluster create returns once the config epochs agree; every node still
# has to see full slot coverage before it stops answering CLUSTERDOWN.
echo "Waiting for cluster_state:ok on all nodes..."
for _ in $(seq 1 30); do
    if cluster_ok; then
        echo "Cluster up: 16384 slots assigned across 3 masters + 3 replicas."
        exit 0
    fi
    sleep 1
done

fail "Cluster did not reach cluster_state:ok on all nodes within 30s"
