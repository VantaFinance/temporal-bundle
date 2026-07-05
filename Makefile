RR_VERSION ?= rr:^2025
RR_CMD := ./rr serve -c tests/App/.rr.temporal.yaml

TEMPORAL_VERSION ?= temporal:^1.4-fairness@dev
TEMPORAL_CMD := ./temporal server start-dev

fix-code-style:
	@vendor/bin/php-cs-fixer fix --allow-risky=yes --verbose --using-cache=no

lint-code-style:
	@vendor/bin/php-cs-fixer fix --allow-risky=yes --dry-run --stop-on-violation --diff --using-cache=no

analysis-code:
	@php -d memory_limit=-1 vendor/bin/phpstan analyse -c phpstan.neon --memory-limit=-1

get-deps:
	./vendor/bin/dload get --force --no-ansi --no-interaction ${RR_VERSION} ${TEMPORAL_VERSION}

clear-vendor:
	rm -rf composer.lock vendor vendor-bin/tools/vendor

testing:
	./vendor/bin/phpunit

start-temporal:
	@echo "Starting Temporal..."
	@./temporal server start-dev > var/log/temporal.log 2>&1 &
	@echo "Temporal started"

stop-temporal:
	@echo "Stopping Temporal..."
	@pkill -f "$(TEMPORAL_CMD)" && echo "Temporal stopped" || echo "Temporal not running"


start-rr:
	@echo "Starting RoadRunner..."
	@rm -rf var/cache && nohup $(RR_CMD) > var/log/rr.log 2>&1 &
	@echo "RoadRunner started"

stop-rr:
	@echo "Stopping RoadRunner..."
	@pkill -f "$(RR_CMD)" && echo "RoadRunner stopped" || echo "RoadRunner not running"
