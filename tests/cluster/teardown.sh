#!/usr/bin/env bash
# Tear down the local Valkey cluster started by setup.sh.
set -euo pipefail
cd "$(dirname "$0")"
docker compose down -v
