# syntax=docker/dockerfile:1

# ---- Stage 1: install PHP dependencies with Composer ----
FROM php:8.4-alpine AS vendor

# Composer needs git + unzip to fetch the dev-master VCS dependency.
RUN apk add --no-cache git unzip

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

ENV COMPOSER_ALLOW_SUPERUSER=1
ENV COMPOSER_HOME=/tmp/composer

WORKDIR /build

# Copy only what Composer needs first so this layer caches across source changes.
COPY composer.json composer.lock ./
RUN composer install --no-dev --no-interaction --no-progress --no-scripts --prefer-dist

# ---- Stage 2: runtime image ----
FROM php:8.4-alpine

# tini gives us a proper init (signal handling, zombie reaping) as PID 1.
RUN apk add --no-cache tini

# Pandoc (>= 3.9, the version this tool targets). Alpine packages pandoc in the
# edge/community repository; the stable repos do not carry it. This is the same
# install strategy the project has always used (the older Dockerfile pulled it
# from edge), kept on Alpine to keep the image small. Pinning to an exact pandoc
# version is not possible via apk here, edge tracks the current release, which is
# 3.9.x and satisfies the requirement.
RUN apk add --no-cache pandoc-cli \
        --repository=https://dl-cdn.alpinelinux.org/alpine/edge/community \
    && pandoc --version | head -n1

# Application code lives in /opt/app; /app is reserved for the user's mounted volume.
WORKDIR /opt/app
COPY --from=vendor /build/vendor ./vendor
COPY composer.json composer.lock convert.php ./
COPY app ./app

RUN ln -s /opt/app/convert.php /usr/local/bin/convert.php

COPY docker/docker-entrypoint.sh /usr/local/bin/docker-entrypoint.sh
RUN chmod +x /usr/local/bin/docker-entrypoint.sh

# The user mounts their working directory here (docker run -v "$PWD:/app").
WORKDIR /app

ENTRYPOINT ["/sbin/tini", "--", "/usr/local/bin/docker-entrypoint.sh"]
CMD ["--help"]
