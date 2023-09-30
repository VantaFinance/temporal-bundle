ARG PHP_VERSION=8.2
ARG PHP_INSTALLER_VERSION=2.1
ARG ROADRUNNER_VERSION=2023.2

FROM ghcr.io/roadrunner-server/roadrunner:${ROADRUNNER_VERSION} AS roadrunner
FROM mlocati/php-extension-installer:${PHP_INSTALLER_VERSION} as installer
FROM php:${PHP_VERSION}-cli-buster AS temporal_php

COPY --from=roadrunner /usr/bin/rr /usr/local/bin/rr

RUN --mount=type=bind,from=installer,source=/usr/bin/install-php-extensions,target=/usr/local/bin/install-php-extensions \
     install-php-extensions opcache zip intl pcntl sockets protobuf grpc

WORKDIR /app

CMD rr serve -c .rr.temporal.yaml