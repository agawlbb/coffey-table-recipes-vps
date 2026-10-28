FROM php:8.4-cli-bookworm
RUN apt-get update && apt-get install -y --no-install-recommends libcurl4-openssl-dev libonig-dev ca-certificates \
    && docker-php-ext-install pdo_mysql curl mbstring pcntl \
    && rm -rf /var/lib/apt/lists/*
RUN groupadd --gid 1000 ctr-backend \
    && useradd --uid 1000 --gid 1000 --no-create-home --shell /usr/sbin/nologin ctr-backend
WORKDIR /app
USER ctr-backend
CMD ["php", "server.php", "start"]
