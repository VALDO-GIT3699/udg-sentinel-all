FROM php:8.2-fpm-alpine

LABEL maintainer="Coordinación de Tecnologías de la Información — UDG"
LABEL description="UDG Sentinel — PHP 8.2 FPM"

# ── Dependencias del sistema ──────────────────
RUN apk add --no-cache \
    git \
    curl \
    libpng-dev \
    libjpeg-turbo-dev \
    libwebp-dev \
    libzip-dev \
    zip \
    unzip \
    postgresql-dev \
    oniguruma-dev \
    icu-dev \
    freetype-dev \
    linux-headers \
    $PHPIZE_DEPS

# ── Extensiones PHP ───────────────────────────
RUN docker-php-ext-configure gd --with-freetype --with-jpeg --with-webp \
    && docker-php-ext-install \
        pdo \
        pdo_pgsql \
        pgsql \
        mbstring \
        exif \
        pcntl \
        bcmath \
        gd \
        zip \
        intl \
        opcache

# ── Redis extension (phpredis) ────────────────
RUN pecl install redis \
    && docker-php-ext-enable redis

# ── Composer ─────────────────────────────────
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

# ── Node.js 22 (para build de assets) ────────
RUN apk add --no-cache nodejs npm

# ── Configuración PHP para producción ────────
COPY php.ini /usr/local/etc/php/conf.d/custom.ini

# ── Usuario no-root ───────────────────────────
RUN addgroup -g 1000 -S www && \
    adduser -u 1000 -S www -G www

USER www

WORKDIR /var/www/html

EXPOSE 9000
