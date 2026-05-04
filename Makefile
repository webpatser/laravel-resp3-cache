.PHONY: help test test-unit test-feature cluster-up cluster-down cluster-test bench

EXT_RESP3 ?= /Users/christoph/Development/Github/php-resp3/modules/resp3.so
PHP       := php -d extension=$(EXT_RESP3)

help:
	@echo "Targets:"
	@echo "  test              Run unit + feature tests (single-node)"
	@echo "  test-unit         Unit tests only"
	@echo "  test-feature      Feature tests against single-node Redis on 127.0.0.1:6379"
	@echo "  cluster-up        Boot the 6-node Valkey cluster on 127.0.0.1:7100-7105 (port 7000 collides with macOS AirPlay Receiver)"
	@echo "  cluster-down      Tear it down"
	@echo "  cluster-test      cluster-up + cluster suite + cluster-down"
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

bench:
	$(PHP) bench/laravel_cache_many.php
	@if nc -z 127.0.0.1 7100 2>/dev/null; then \
		$(PHP) bench/cluster_cache_many.php; \
	else \
		echo "(skipping cluster bench: no cluster on :7000)"; \
	fi
