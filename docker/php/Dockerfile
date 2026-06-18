FROM php:8.3-fpm-alpine

# Установка системных зависимостей и инструментов
RUN apk add --no-cache \
    postgresql-dev \
    libzip-dev \
    zip \
    unzip \
    git \
    bash

# Установка расширений PHP для PostgreSQL, Zip и Opcache
RUN docker-php-ext-install pdo pdo_pgsql zip opcache

# Копируем Composer из официального образа
COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

WORKDIR /srv/picky_rss
