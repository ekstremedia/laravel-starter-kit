#!/bin/bash
set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
ENV_FILE="$ROOT_DIR/.env"
EXAMPLE_FILE="$ROOT_DIR/.env.example"

if [ ! -f "$ENV_FILE" ]; then
    cp "$EXAMPLE_FILE" "$ENV_FILE"
fi

prompt() {
    local label="$1"
    local default="$2"
    local value

    read -r -p "$label [$default]: " value
    echo "${value:-$default}"
}

set_env() {
    local key="$1"
    local value="$2"

    php -r '
        $file = $argv[1];
        $key = $argv[2];
        $value = $argv[3];
        $contents = file_get_contents($file);
        $line = $key."=".$value;

        if (preg_match("/^".preg_quote($key, "/")."=.*/m", $contents)) {
            $contents = preg_replace("/^".preg_quote($key, "/")."=.*/m", $line, $contents);
        } else {
            $contents .= PHP_EOL.$line.PHP_EOL;
        }

        file_put_contents($file, $contents);
    ' "$ENV_FILE" "$key" "$value"
}

app_name="$(prompt "App name" "Laravel Starter Kit")"
# Default to localhost + a published host port so a fresh clone runs with zero
# /etc/hosts edits and APP_URL stays consistent with the port docker publishes.
# Enter a custom hostname (e.g. http://my-app.test) only if you'll add it to
# /etc/hosts yourself.
app_host_port="$(prompt "App host port" "8120")"
app_url="$(prompt "App URL" "http://localhost:${app_host_port}")"
db_database="$(prompt "Database name" "starter")"
db_username="$(prompt "Database username" "starter")"
db_password="$(prompt "Database password" "secret")"
admin_first_name="$(prompt "Seeded admin first name" "Admin")"
admin_last_name="$(prompt "Seeded admin last name" "User")"
admin_email="$(prompt "Seeded admin email" "admin@example.test")"
admin_password="$(prompt "Seeded admin password" "password")"
easy_login_enabled="$(prompt "Enable local easy login (true/false)" "false")"

app_host="$(php -r '
    $url = $argv[1];
    $host = parse_url($url, PHP_URL_HOST);
    echo $host ?: $url;
' "$app_url")"

# Derive a valid email domain for MAIL_FROM_ADDRESS. A single-label host like
# `localhost` (or an IP) is not an RFC-valid email domain — Spatie Backup
# rejects it on boot ("hello@localhost is not a valid email address") and the
# app crash-loops. Fall back to example.test unless the host looks like a real
# dotted domain.
mail_domain="$app_host"
mail_domain="${mail_domain%%/*}"
case "$mail_domain" in
    localhost|127.0.0.1|::1) mail_domain="example.test" ;;
    *.*) : ;;
    *) mail_domain="example.test" ;;
esac
storage_key="$(echo "$app_host" | tr '.-' '_' )_settings"

set_env "APP_NAME" "\"$app_name\""
set_env "APP_URL" "$app_url"
set_env "APP_HOST_PORT" "$app_host_port"
set_env "VITE_APP_NAME" "\"\${APP_NAME}\""
set_env "VITE_DEV_SERVER_HOST" "$app_host"
set_env "VITE_APP_STORAGE_KEY" "$storage_key"
set_env "BROADCAST_CONNECTION" "reverb"
set_env "DB_DATABASE" "$db_database"
set_env "DB_USERNAME" "$db_username"
set_env "DB_PASSWORD" "$db_password"
set_env "MAIL_FROM_ADDRESS" "\"hello@$mail_domain\""
set_env "MAIL_FROM_NAME" "\"\${APP_NAME}\""
set_env "STARTER_ADMIN_FIRST_NAME" "$admin_first_name"
set_env "STARTER_ADMIN_LAST_NAME" "$admin_last_name"
set_env "STARTER_ADMIN_EMAIL" "$admin_email"
set_env "STARTER_ADMIN_PASSWORD" "$admin_password"
set_env "DEV_EASY_LOGIN_ENABLED" "$easy_login_enabled"
set_env "REVERB_HOST" "127.0.0.1"
set_env "REVERB_SERVER_HOST" "0.0.0.0"
set_env "REVERB_SERVER_PORT" "8080"
set_env "REVERB_HOST_PORT" "8080"
set_env "VITE_REVERB_HOST" "$app_host"

echo ""
echo "Starter environment updated in $ENV_FILE"
echo "Next steps:"
case "$app_host" in
    localhost|127.0.0.1|::1) ;;
    *) echo "  - Add '$app_host' to /etc/hosts (custom hostname)" ;;
esac
echo "  - Run 'make build'"
echo "  - Visit $app_url"
