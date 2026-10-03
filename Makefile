.PHONY: help test test-unit test-feature cluster-up cluster-down cluster-test sentinel-up sentinel-down sentinel-test bench

EXT_RESP3 ?= /Users/christoph/Development/Github/php-resp3/modules/resp3.so
PHP       := php -d extension=$(EXT_RESP3)
RESP3_TEST_HOST ?= 127.0.0.1
RESP3_TEST_PORT ?= 6379
export RESP3_TEST_HOST RESP3_TEST_PORT

help:
	@echo "Targets:"
	@echo "  test              Run unit + feature tests (single-node)"
	@echo "  test-unit         Unit tests only"
	@echo "  test-feature      Feature tests against single-node Redis on $(RESP3_TEST_HOST):$(RESP3_TEST_PORT)"
	@echo "  cluster-up        Boot the 6-node Valkey cluster on 127.0.0.1:7100-7105 (port 7000 collides with macOS AirPlay Receiver)"
	@echo "  cluster-down      Tear it down"
	@echo "  cluster-test      cluster-up + cluster suite + cluster-down"
	@echo "  sentinel-up       Boot the Sentinel-managed Valkey on 127.0.0.1:6500 (master) + 26500-26502 (sentinels)"
	@echo "  sentinel-down     Tear it down"
	@echo "  sentinel-test     sentinel-up + sentinel suite + sentinel-down"
	@echo "  bench             Run cache::many bench against single-node and cluster"

test: test-unit test-feature

test-unit:
	vendor/bin/phpunit --testsuite=Unit

test-feature:
	$(PHP) vendor/bin/phpunit --testsuite=Feature

cluster-up:
	tests/cluster/setup.sh

cluster-down:
	tests/cluster/teardown.sh

cluster-test: cluster-up
	-$(PHP) vendor/bin/phpunit tests/Feature/ClusterTest.php tests/Feature/ClusterReplicaTest.php
	@$(MAKE) cluster-down

sentinel-up:
	tests/cluster/sentinel-setup.sh

sentinel-down:
	tests/cluster/sentinel-teardown.sh

sentinel-test: sentinel-up
	-$(PHP) vendor/bin/phpunit tests/Feature/SentinelTest.php
	@$(MAKE) sentinel-down

bench:
	$(PHP) bench/laravel_cache_many.php
	@if nc -z 127.0.0.1 7100 2>/dev/null; then \
		$(PHP) bench/cluster_cache_many.php; \
	else \
		echo "(skipping cluster bench: no cluster on :7000)"; \
	fi
