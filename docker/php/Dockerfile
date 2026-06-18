FROM php:8.3-fpm-alpine

# Install system dependencies, git, bash, and nginx
RUN apk add --no-cache \
    postgresql-dev \
    libzip-dev \
    zip \
    unzip \
    git \
    bash \
    nginx

# Install PHP extensions for PostgreSQL, Zip, and Opcache
RUN docker-php-ext-install pdo pdo_pgsql zip opcache

# Copy Composer from the official image
COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

# Prepare Nginx run directories
RUN mkdir -p /run/nginx

WORKDIR /srv/picky_rss

# Copy Nginx production configuration
COPY docker/nginx/nginx-prod.conf /etc/nginx/http.d/default.conf

# Copy project files into the image
COPY . /srv/picky_rss

# Set Symfony production environment and allow Composer to run as root
ENV APP_ENV=prod
ENV COMPOSER_ALLOW_SUPERUSER=1

# Install composer production dependencies
RUN composer install --no-dev --optimize-autoloader --no-interaction

# Set correct permissions for the var directory
RUN mkdir -p var && chown -R www-data:www-data var

# Make the entrypoint script executable
RUN chmod +x /srv/picky_rss/docker/php/docker-entrypoint.sh

# Expose port 80 for incoming webhook traffic via Nginx
EXPOSE 80

# Set the default entrypoint
ENTRYPOINT ["/srv/picky_rss/docker/php/docker-entrypoint.sh"]
