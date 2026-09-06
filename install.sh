#!/usr/bin/env bash
#
# S2 Panel - one-shot Ubuntu bootstrap.
#
# Takes a bare Ubuntu server to "open https://<domain>/install and finish
# the wizard": checks/installs every OS-level dependency (PHP 8.3 + required
# extensions, Composer, Node 20+, MySQL, nginx, certbot), pulls the panel's
# own code, builds it, opens the panel's own database (a step the in-app
# installer deliberately does NOT do - see app/Modules/Install - it only
# tests a connection someone already typed in), points nginx at public/, and
# requests a Let's Encrypt certificate. Everything else - Steam API key,
# each Swiftly plugin's database connection, the owner's SteamID, which
# modules are on - stays the in-app wizard's job; this script only gets far
# enough for that wizard to be reachable at all.
#
# Usage:
#   sudo bash <(curl -fsSL https://raw.githubusercontent.com/candaysa/S2_Panel/main/install.sh)
#
# That form (not "curl | bash") is deliberate: it leaves the real terminal on
# stdin, so the domain/email prompts below actually work interactively. Pass
# them up front instead - e.g. for unattended/scripted runs - with:
#   sudo bash <(curl -fsSL .../install.sh) --domain panel.example.com --email you@example.com --yes
#   sudo ./install.sh --domain panel.example.com --email you@example.com
#
# Safe to re-run: every step checks what's already there before changing
# anything (installed packages, an existing .env, an existing database) -
# a failed or interrupted run can just be started again.

set -euo pipefail

# ---------------------------------------------------------------- defaults

REPO_URL="https://github.com/candaysa/S2_Panel.git"
BRANCH="main"
INSTALL_DIR="/var/www/s2panel"
DOMAIN=""
EMAIL=""
DB_NAME="s2_panel"
DB_USER="s2panel"
SKIP_SSL=0
ASSUME_YES=0
PHP_VERSION="8.3"

# ------------------------------------------------------------------ output

is_tty() { [ -t 1 ]; }
if is_tty; then
    C_RESET=$'\033[0m'; C_BOLD=$'\033[1m'; C_GREEN=$'\033[32m'; C_YELLOW=$'\033[33m'; C_RED=$'\033[31m'; C_BLUE=$'\033[34m'
else
    C_RESET=""; C_BOLD=""; C_GREEN=""; C_YELLOW=""; C_RED=""; C_BLUE=""
fi

step()  { printf '\n%s%s==>%s %s%s\n' "$C_BOLD" "$C_BLUE" "$C_RESET" "$C_BOLD" "$1$C_RESET"; }
ok()    { printf '%s  ok:%s %s\n' "$C_GREEN" "$C_RESET" "$1"; }
warn()  { printf '%s  warn:%s %s\n' "$C_YELLOW" "$C_RESET" "$1"; }
die()   { printf '%s  error:%s %s\n' "$C_RED" "$C_RESET" "$1" >&2; exit 1; }

# -------------------------------------------------------------- arg parsing

while [ $# -gt 0 ]; do
    case "$1" in
        --domain) DOMAIN="$2"; shift 2 ;;
        --email) EMAIL="$2"; shift 2 ;;
        --dir) INSTALL_DIR="$2"; shift 2 ;;
        --repo) REPO_URL="$2"; shift 2 ;;
        --branch) BRANCH="$2"; shift 2 ;;
        --db-name) DB_NAME="$2"; shift 2 ;;
        --db-user) DB_USER="$2"; shift 2 ;;
        --skip-ssl) SKIP_SSL=1; shift ;;
        --yes|-y) ASSUME_YES=1; shift ;;
        --help|-h)
            sed -n '2,27p' "$0" | sed 's/^# \{0,1\}//'
            exit 0
            ;;
        *) die "unknown option: $1 (see --help)" ;;
    esac
done

[ "$(id -u)" -eq 0 ] || die "run as root (sudo ./install.sh ...)"

. /etc/os-release 2>/dev/null || die "cannot read /etc/os-release - this script only supports Ubuntu"
[ "${ID:-}" = "ubuntu" ] || warn "this script is written for Ubuntu; detected '${ID:-unknown}' - continuing anyway, apt-based steps may fail"

if [ -z "$DOMAIN" ] && [ "$ASSUME_YES" -eq 0 ]; then
    read -rp "Domain the panel will be served from (e.g. panel.example.com): " DOMAIN
fi
[ -n "$DOMAIN" ] || die "--domain is required"

if [ "$SKIP_SSL" -eq 0 ] && [ -z "$EMAIL" ] && [ "$ASSUME_YES" -eq 0 ]; then
    read -rp "Email for Let's Encrypt renewal notices: " EMAIL
fi
if [ "$SKIP_SSL" -eq 0 ] && [ -z "$EMAIL" ]; then
    warn "no --email given and --yes was set - disabling automatic SSL (use --skip-ssl to silence this)"
    SKIP_SSL=1
fi

DB_PASS="$(openssl rand -base64 24 | tr -dc 'A-Za-z0-9' | head -c 32)"

step "About to set up S2 Panel"
cat <<SUMMARY
  domain       : $DOMAIN
  install dir  : $INSTALL_DIR
  repo/branch  : $REPO_URL @ $BRANCH
  panel DB     : $DB_NAME (user: $DB_USER, password generated)
  SSL          : $([ "$SKIP_SSL" -eq 1 ] && echo "skipped" || echo "Let's Encrypt via certbot ($EMAIL)")
SUMMARY

if [ "$ASSUME_YES" -eq 0 ]; then
    read -rp "Continue? [Y/n] " reply
    case "$reply" in [nN]*) die "cancelled" ;; esac
fi

# --------------------------------------------------------- helper: set_env
#
# Idempotent KEY=VALUE writer for .env - replaces an existing line, appends
# a new one otherwise. Values are quoted only when they contain whitespace,
# matching what .env.example itself does (compare APP_NAME="S2 Panel" next
# to APP_ENV=local).
set_env() {
    local key="$1" value="$2" file="$INSTALL_DIR/.env"
    local escaped
    case "$value" in
        *[[:space:]]*) value="\"${value}\"" ;;
    esac
    escaped=$(printf '%s' "$value" | sed -e 's/[\/&]/\\&/g')
    if grep -q "^${key}=" "$file" 2>/dev/null; then
        sed -i "s/^${key}=.*/${key}=${escaped}/" "$file"
    else
        printf '%s=%s\n' "$key" "$value" >> "$file"
    fi
}

# ------------------------------------------------------------ 1. OS packages

step "Installing base packages"
export DEBIAN_FRONTEND=noninteractive
apt-get update -qq
apt-get install -y -qq software-properties-common ca-certificates curl git unzip gnupg lsb-release >/dev/null
ok "curl, git, unzip present"

step "Installing PHP $PHP_VERSION"
if ! apt-cache show "php${PHP_VERSION}-fpm" >/dev/null 2>&1; then
    warn "php${PHP_VERSION} not in the default repos for this Ubuntu release - adding ppa:ondrej/php"
    add-apt-repository -y ppa:ondrej/php >/dev/null
    apt-get update -qq
fi
PHP_PACKAGES="php${PHP_VERSION}-fpm php${PHP_VERSION}-cli php${PHP_VERSION}-common php${PHP_VERSION}-mysql \
php${PHP_VERSION}-mbstring php${PHP_VERSION}-xml php${PHP_VERSION}-curl php${PHP_VERSION}-zip \
php${PHP_VERSION}-bcmath php${PHP_VERSION}-intl php${PHP_VERSION}-gd"
# shellcheck disable=SC2086
apt-get install -y -qq $PHP_PACKAGES >/dev/null
ok "PHP ${PHP_VERSION} + required extensions installed ($(php -v | head -n1))"

step "Installing Composer"
if ! command -v composer >/dev/null 2>&1; then
    php_installer_sig="$(curl -fsSL https://composer.github.io/installer.sig)"
    curl -fsSL https://getcomposer.org/installer -o /tmp/composer-setup.php
    php_actual_sig="$(php -r "echo hash_file('sha384', '/tmp/composer-setup.php');")"
    [ "$php_installer_sig" = "$php_actual_sig" ] || die "composer installer signature mismatch - aborting"
    php /tmp/composer-setup.php --install-dir=/usr/local/bin --filename=composer >/dev/null
    rm -f /tmp/composer-setup.php
    ok "Composer installed ($(composer --version))"
else
    ok "Composer already present ($(composer --version))"
fi

step "Installing Node.js 20+"
node_major="$(command -v node >/dev/null 2>&1 && node -v | sed 's/^v//' | cut -d. -f1 || echo 0)"
if [ "$node_major" -lt 20 ]; then
    curl -fsSL https://deb.nodesource.com/setup_20.x | bash - >/dev/null 2>&1
    apt-get install -y -qq nodejs >/dev/null
    ok "Node.js installed ($(node -v))"
else
    ok "Node.js already present ($(node -v))"
fi

step "Installing MySQL"
MYSQL_LOCAL=0
if command -v mysql >/dev/null 2>&1 && systemctl is-active --quiet mysql 2>/dev/null; then
    ok "MySQL already running locally - reusing it"
    MYSQL_LOCAL=1
elif systemctl is-active --quiet mariadb 2>/dev/null; then
    ok "MariaDB already running locally - reusing it"
    MYSQL_LOCAL=1
else
    apt-get install -y -qq mysql-server >/dev/null
    systemctl enable --now mysql >/dev/null
    ok "MySQL server installed and started"
    MYSQL_LOCAL=1
fi

step "Installing nginx"
if ! command -v nginx >/dev/null 2>&1; then
    apt-get install -y -qq nginx >/dev/null
fi
systemctl enable --now nginx >/dev/null
ok "nginx present ($(nginx -v 2>&1))"

# ------------------------------------------------------------ 2. panel database
#
# The in-app wizard (app/Modules/Install/App/Http/Controllers/InstallController.php)
# only tests a database connection already typed into it - creating the
# panel's own schema and a dedicated user is deliberately left out of that
# controller (it never runs raw DDL against credentials a browser submitted).
# That's this script's job instead, once, with root MySQL access it already
# has on the box it just provisioned.

step "Creating the panel's own database"
run_mysql() {
    if [ "$MYSQL_LOCAL" -eq 1 ] && mysql -u root -e 'SELECT 1' >/dev/null 2>&1; then
        mysql -u root "$@"
    else
        mysql "$@"
    fi
}
if ! run_mysql -e 'SELECT 1' >/dev/null 2>&1; then
    die "cannot reach MySQL as root (unix_socket auth failed) - create ${DB_NAME}/${DB_USER} manually and re-run with --skip-ssl once .env is set"
fi
run_mysql <<SQL
CREATE DATABASE IF NOT EXISTS \`${DB_NAME}\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER IF NOT EXISTS '${DB_USER}'@'127.0.0.1' IDENTIFIED BY '${DB_PASS}';
ALTER USER '${DB_USER}'@'127.0.0.1' IDENTIFIED BY '${DB_PASS}';
GRANT ALL PRIVILEGES ON \`${DB_NAME}\`.* TO '${DB_USER}'@'127.0.0.1';
FLUSH PRIVILEGES;
SQL
ok "database '${DB_NAME}' and user '${DB_USER}' ready"

# ------------------------------------------------------------ 3. the code

step "Fetching S2 Panel"
if [ -d "$INSTALL_DIR/.git" ]; then
    git -C "$INSTALL_DIR" fetch origin "$BRANCH" --quiet
    git -C "$INSTALL_DIR" checkout "$BRANCH" --quiet
    git -C "$INSTALL_DIR" pull origin "$BRANCH" --quiet
    ok "existing checkout at $INSTALL_DIR updated"
else
    mkdir -p "$(dirname "$INSTALL_DIR")"
    git clone --branch "$BRANCH" --depth 1 "$REPO_URL" "$INSTALL_DIR" --quiet
    ok "cloned into $INSTALL_DIR"
fi
cd "$INSTALL_DIR"

step "Installing PHP dependencies (composer install)"
# Runs as root, same as the clone above - the tree is only handed to
# www-data at the very end (see "Fixing ownership and permissions"). Doing
# that here instead would make composer create vendor/ as www-data inside a
# directory git just created as root, which fails outright: www-data has no
# write access to it yet.
composer install --no-dev --optimize-autoloader --no-interaction --quiet
ok "vendor/ ready"

step "Building frontend assets (npm)"
npm ci --silent
npm run build --silent
ok "public/build ready"

# ------------------------------------------------------------ 4. .env

step "Writing .env"
if [ ! -f .env ]; then
    cp .env.example .env
fi
# Always http here: nginx is still HTTP-only at this point regardless of
# --skip-ssl - certbot (below) is what actually adds the 443 listener, and
# only on success does APP_URL get upgraded. Writing https up front would
# leave APP_URL claiming a scheme the vhost doesn't serve whenever --skip-ssl
# was passed, or certbot failed (e.g. DNS for the domain isn't live yet).
set_env APP_URL "http://$DOMAIN"
set_env APP_ENV production
set_env APP_DEBUG false
set_env DB_CONNECTION panel
set_env DB_HOST 127.0.0.1
set_env DB_PORT 3306
set_env DB_DATABASE "$DB_NAME"
set_env DB_USERNAME "$DB_USER"
set_env DB_PASSWORD "$DB_PASS"
# The Steam/RCON/module wizard writes every other DB_*/STEAM_* value itself
# once it can reach the panel database above - see InstallController.

if ! grep -q '^APP_KEY=.\+' .env; then
    php artisan key:generate --force --quiet
fi
ok ".env ready"

step "Running migrations"
php artisan migrate --force --no-interaction
ok "panel tables created"

step "Fixing ownership and permissions"
chown -R www-data:www-data "$INSTALL_DIR"
find storage bootstrap/cache -type d -exec chmod 775 {} \;
find storage bootstrap/cache -type f -exec chmod 664 {} \;
ok "www-data owns $INSTALL_DIR"

# ------------------------------------------------------------ 5. nginx + SSL

step "Configuring nginx"
NGINX_CONF="/etc/nginx/sites-available/${DOMAIN}.conf"
cat > "$NGINX_CONF" <<NGINX
server {
    listen 80;
    listen [::]:80;
    server_name ${DOMAIN};

    root ${INSTALL_DIR}/public;
    index index.php;
    charset utf-8;

    add_header X-Frame-Options "SAMEORIGIN";
    add_header X-Content-Type-Options "nosniff";

    location / {
        try_files \$uri \$uri/ /index.php?\$query_string;
    }

    location = /favicon.ico { access_log off; log_not_found off; }
    location = /robots.txt  { access_log off; log_not_found off; }

    error_page 404 /index.php;

    location ~ \.php\$ {
        fastcgi_pass unix:/run/php/php${PHP_VERSION}-fpm.sock;
        fastcgi_param SCRIPT_FILENAME \$realpath_root\$fastcgi_script_name;
        include fastcgi_params;
    }

    location ~ /\.(?!well-known).* {
        deny all;
    }
}
NGINX
ln -sf "$NGINX_CONF" "/etc/nginx/sites-enabled/${DOMAIN}.conf"
nginx -t
systemctl reload nginx
ok "nginx vhost for $DOMAIN live (HTTP)"

if command -v ufw >/dev/null 2>&1 && ufw status | grep -q "Status: active"; then
    ufw allow 'Nginx Full' >/dev/null || true
    ufw allow OpenSSH >/dev/null || true
    ok "ufw: opened 80/443"
fi

if [ "$SKIP_SSL" -eq 0 ]; then
    step "Requesting a Let's Encrypt certificate"
    apt-get install -y -qq certbot python3-certbot-nginx >/dev/null
    if certbot --nginx -d "$DOMAIN" --non-interactive --agree-tos -m "$EMAIL" --redirect; then
        ok "HTTPS ready - certbot rewrote the vhost and will auto-renew"
        set_env APP_URL "https://$DOMAIN"
    else
        warn "certbot failed (DNS for $DOMAIN not pointed at this server yet?) - panel is reachable over HTTP for now; re-run: certbot --nginx -d $DOMAIN"
    fi
fi

systemctl enable --now "php${PHP_VERSION}-fpm" >/dev/null

# ------------------------------------------------------------------ done

FINAL_URL="$(grep '^APP_URL=' .env | cut -d= -f2- | tr -d '"')"

step "Done"
cat <<DONE

  S2 Panel is up. Open ${C_BOLD}${FINAL_URL}/install${C_RESET} to finish setup:
  language, each Swiftly plugin's database connection, your Steam API key
  and SteamID64, and which modules to enable.

  Panel database (already in .env, saved here for your records):
    database : ${DB_NAME}
    user     : ${DB_USER}
    password : ${DB_PASS}

  Once the wizard is done, consider (see README.md):
    - php artisan config:cache && php artisan route:cache && php artisan view:cache
    - a cron entry for 'php artisan schedule:run' if you enable Stats/Health
    - a queue worker (or QUEUE_CONNECTION=sync) if you enable Webhooks

DONE
