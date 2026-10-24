ARG PHP_VERSION=8.4
ARG PHP_INSTALLER_VERSION=2.11
# RoadRunner 3 for temporal/sdk >= 2.20; 2025.1 for temporal/sdk 2.16 - 2.19
ARG ROADRUNNER_VERSION=3.0

FROM ghcr.io/roadrunner-server/roadrunner:${ROADRUNNER_VERSION} AS roadrunner
FROM mlocati/php-extension-installer:${PHP_INSTALLER_VERSION} AS installer
FROM php:${PHP_VERSION}-cli-bookworm AS temporal_php

COPY --from=roadrunner /usr/bin/rr /usr/local/bin/rr

RUN --mount=type=bind,from=installer,source=/usr/bin/install-php-extensions,target=/usr/local/bin/install-php-extensions \
     install-php-extensions opcache zip intl pcntl sockets protobuf grpc

WORKDIR /app

CMD rr serve -c .rr.temporal.yaml