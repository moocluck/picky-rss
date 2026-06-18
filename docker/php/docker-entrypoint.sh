#!/bin/sh
set -e

# Run database migrations on container startup
echo "Checking and running database migrations..."
php bin/console doctrine:migrations:migrate --no-interaction --allow-no-migration || true

# Start Telegram bot polling in the background if mode is polling
if [ "$TELEGRAM_MODE" = "polling" ]; then
    echo "Starting Nutgram bot polling in background..."
    php bin/console nutgram:run &
fi

# Start feed update cron loop in the background
CRON_INTERVAL=${CRON_INTERVAL:-900} # Default to 15 minutes (900 seconds)
echo "Starting feed update cron loop in background (interval: ${CRON_INTERVAL}s)..."
(
    while true; do
        sleep "$CRON_INTERVAL"
        echo "Running background cron: app:update-feeds..."
        php bin/console app:update-feeds || true
    done
) &

# Start PHP-FPM in the foreground (keeps the container running)
echo "Starting PHP-FPM..."
exec php-fpm
