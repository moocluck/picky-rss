#!/bin/sh
set -e

# Wait for DB to become available
echo "Waiting for database to be ready..."
php -r '
$dbUrl = getenv("DATABASE_URL");
if (!$dbUrl) {
    $dbUrl = "";
    foreach ([".env", ".env.local"] as $file) {
        if (file_exists($file)) {
            foreach (file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
                $line = trim($line);
                if (empty($line) || strpos($line, "#") === 0) continue;
                $parts = explode("=", $line, 2);
                if (count($parts) === 2 && trim($parts[0]) === "DATABASE_URL") {
                    $dbUrl = trim($parts[1], "\" \t\r\n" . chr(39));
                }
            }
        }
    }
}
if (!$dbUrl) {
    echo "DATABASE_URL not found, skipping wait.\n";
    exit(0);
}
$url = parse_url($dbUrl);
$host = $url["host"] ?? "db";
$port = $url["port"] ?? 5432;
echo "Checking connection to $host:$port...\n";
for ($i = 0; $i < 30; $i++) {
    $fp = @fsockopen($host, $port, $errno, $errstr, 2);
    if ($fp) {
        fclose($fp);
        echo "Database is ready!\n";
        exit(0);
    }
    sleep(1);
}
echo "Database connection failed.\n";
' || true

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

# Start Nginx in the background (to handle webhooks if enabled)
echo "Starting Nginx..."
nginx &

# Start PHP-FPM in the foreground (keeps the container running)
echo "Starting PHP-FPM..."
exec php-fpm
