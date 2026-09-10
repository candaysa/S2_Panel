#!/usr/bin/env bash
#
# S2 Panel - one-shot Ubuntu bootstrap.
#
# Takes a bare Ubuntu server to "open https://<domain>/install and finish
# the wizard": checks/installs every OS-level dependency (PHP 8.3 + required
# extensions, Composer, Node 20+, nginx, certbot), pulls the panel's own
# code, builds it, points nginx at public/, and requests a Let's Encrypt
# certificate.
#
# It creates no database. The panel keeps its tables in the database your
# CS2 plugins already use - you type that one into the wizard, and the
# wizard creates the panel's tables there. Until then the panel runs its
# sessions and cache on files, which is what lets the wizard load on a
# server where the panel has no database yet. Everything else - Steam API
# key, the owner's SteamID, which modules are on - is the wizard's too; this
# script only gets far enough for that wizard to be reachable at all.
#
# Usage:
#   curl -fsSL https://raw.githubusercontent.com/candaysa/S2_Panel/main/install.sh | sudo bash
#   sudo ./install.sh
#
# It asks for the domain and the Let's Encrypt email as its first two steps,
# so nothing has to be worked out in advance. Every answer can also be given
# up front as a flag, which is what an unattended run (cron, CI, an image
# build) has to do - there is nothing to ask on a machine with no terminal.
#
# Options:
#   --domain NAME    hostname the panel is served from (asked for if absent)
#   --email  ADDR    address for Let's Encrypt renewal notices (asked for if
#                    absent; leave blank at the prompt to install without SSL)
#   --dir    PATH    install directory (default: /var/www/s2panel)
#   --repo   URL     git remote to clone from
#   --branch NAME    branch to check out (default: main)
#   --skip-ssl       leave the vhost on plain HTTP (no certbot run)
#   --yes, -y        non-interactive: never prompt, accept the defaults
#
# Safe to re-run: every step checks what's already there before changing
# anything (installed packages, an existing checkout, an existing .env) - a
# failed or interrupted run can just be started again, and a re-run over an
# installed panel updates it without touching its database or sessions.

set -euo pipefail

# ---------------------------------------------------------------- defaults

REPO_URL="https://github.com/candaysa/S2_Panel.git"
BRANCH="main"
INSTALL_DIR="/var/www/s2panel"
DOMAIN=""
EMAIL=""
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

# ------------------------------------------------------------------ prompts
#
# Questions have to be read from the terminal, not from stdin. The documented
# way to run this is `curl ... | sudo bash`, where stdin IS the script - a
# plain `read` there does not wait for the operator, it swallows the script's
# own next line and runs on with garbage. Talking to /dev/tty directly is
# what lets the one-liner ask anything at all.
#
# No controlling terminal (cron, CI, a container build) means there is nobody
# to ask: ask() fails, and each caller falls back to the flag it needs.

# Opening it is the only honest test: under cron the /dev/tty node still
# exists and passes -r/-w, then fails with ENXIO the moment it is opened,
# which would otherwise spray "No such device or address" over an unattended
# run before falling back.
have_tty() { { : > /dev/tty; } 2>/dev/null; }

# ask VARNAME "prompt text"
ask() {
    local __var="$1" __prompt="$2" __reply=""

    have_tty || return 1
    printf '%s' "$__prompt" > /dev/tty 2>/dev/null || return 1
    IFS= read -r __reply < /dev/tty 2>/dev/null || return 1
    printf -v "$__var" '%s' "$__reply"
}

say_tty() { have_tty && printf '%s\n' "$1" > /dev/tty 2>/dev/null; return 0; }

# People paste "https://panel.example.com/" - take the hostname out of it
# rather than writing that straight into an nginx server_name.
normalise_domain() {
    printf '%s' "$1" | tr -d '[:space:]' | sed -E 's#^[A-Za-z]+://##; s#/.*$##; s#:[0-9]+$##'
}

valid_domain() {
    printf '%s' "$1" | grep -Eq '^[A-Za-z0-9]([A-Za-z0-9-]*[A-Za-z0-9])?(\.[A-Za-z0-9]([A-Za-z0-9-]*[A-Za-z0-9])?)+$'
}

valid_email() {
    printf '%s' "$1" | grep -Eq '^[^[:space:]@]+@[^[:space:]@]+\.[^[:space:]@]+$'
}

# -------------------------------------------------------------- arg parsing

while [ $# -gt 0 ]; do
    case "$1" in
        --domain) DOMAIN="$2"; shift 2 ;;
        --email) EMAIL="$2"; shift 2 ;;
        --dir) INSTALL_DIR="$2"; shift 2 ;;
        --repo) REPO_URL="$2"; shift 2 ;;
        --branch) BRANCH="$2"; shift 2 ;;
        # Gone, not ignored: anyone still passing them expects a database to
        # be made, and silently not making one would surface much later as
        # a wizard asking for something they thought the script had done.
        --db-name|--db-user)
            die "$1 is no longer used - the script creates no database; the install wizard asks for your CS2 plugins' database and puts the panel's tables there"
            ;;
        --skip-ssl) SKIP_SSL=1; shift ;;
        --yes|-y) ASSUME_YES=1; shift ;;
        --help|-h)
            sed -n '2,41p' "$0" | sed 's/^# \{0,1\}//'
            exit 0
            ;;
        *) die "unknown option: $1 (see --help)" ;;
    esac
done

[ "$(id -u)" -eq 0 ] || die "run as root (sudo ./install.sh ...)"

. /etc/os-release 2>/dev/null || die "cannot read /etc/os-release - this script only supports Ubuntu"
[ "${ID:-}" = "ubuntu" ] || warn "this script is written for Ubuntu; detected '${ID:-unknown}' - continuing anyway, apt-based steps may fail"

step "Where should the panel live?"

if [ -n "$DOMAIN" ]; then
    DOMAIN="$(normalise_domain "$DOMAIN")"
    valid_domain "$DOMAIN" || die "--domain '$DOMAIN' is not a hostname (expected something like panel.example.com)"
elif [ "$ASSUME_YES" -eq 1 ]; then
    die "--domain is required with --yes (there is no sensible default for it)"
else
    while :; do
        ask DOMAIN "  Domain the panel will be served from (e.g. panel.example.com): " \
            || die "--domain is required (no terminal available to ask on)"
        DOMAIN="$(normalise_domain "$DOMAIN")"

        if [ -n "$DOMAIN" ] && valid_domain "$DOMAIN"; then
            break
        fi

        say_tty "  that is not a hostname - just the name, no http:// and no path"
    done
fi
ok "domain: $DOMAIN"

# DNS is worth checking before certbot finds out the hard way: a domain that
# does not point here yet is the single most common reason the SSL step
# fails, and it is far cheaper to say so now than after the install.
if [ "$SKIP_SSL" -eq 0 ] && command -v getent >/dev/null 2>&1; then
    if ! getent hosts "$DOMAIN" >/dev/null 2>&1; then
        warn "$DOMAIN does not resolve yet - certbot will fail unless its DNS record exists and points at this server"
    fi
fi

if [ "$SKIP_SSL" -eq 0 ] && [ -z "$EMAIL" ] && [ "$ASSUME_YES" -eq 0 ]; then
    while :; do
        ask EMAIL "  Email for Let's Encrypt renewal notices (blank to install without SSL): " || { EMAIL=""; break; }
        [ -z "$EMAIL" ] && break
        valid_email "$EMAIL" && break
        say_tty "  that is not an email address - or leave it blank to skip SSL"
    done
elif [ -n "$EMAIL" ] && ! valid_email "$EMAIL"; then
    die "--email '$EMAIL' is not an email address"
fi

if [ "$SKIP_SSL" -eq 0 ] && [ -z "$EMAIL" ]; then
    warn "no email given - installing without automatic SSL; the vhost stays on plain HTTP"
    SKIP_SSL=1
fi

step "About to set up S2 Panel"
cat <<SUMMARY
  domain       : $DOMAIN
  install dir  : $INSTALL_DIR
  repo/branch  : $REPO_URL @ $BRANCH
  database     : none created here - the install wizard asks for your CS2
                 plugins' database and creates the panel's tables in it
  SSL          : $([ "$SKIP_SSL" -eq 1 ] && echo "skipped" || echo "Let's Encrypt via certbot ($EMAIL)")
SUMMARY

if [ "$ASSUME_YES" -eq 0 ]; then
    # Same reason as the prompts above: with `curl | bash` a bare read would
    # consume the script instead of the answer. No terminal at all means the
    # operator cannot confirm, so take the flags they did pass and go.
    if ask reply "Continue? [Y/n] "; then
        case "$reply" in [nN]*) die "cancelled" ;; esac
    fi
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

# No MySQL server step: the panel does not get a database of its own any
# more. It lives in the database your CS2 plugins already use, wherever
# that is - this box or another - and only needs PHP's pdo_mysql (in the
# PHP step above) to reach it.

step "Installing nginx"
if ! command -v nginx >/dev/null 2>&1; then
    apt-get install -y -qq nginx >/dev/null
fi
systemctl enable --now nginx >/dev/null
ok "nginx present ($(nginx -v 2>&1))"

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

# Re-running this over a panel that is already installed is the update path,
# and that panel has a database, sessions in it, and settings nobody should
# lose. So the pre-install arrangement below only applies to an install that
# has not finished its wizard yet.
ALREADY_INSTALLED=0
if grep -qE '^INSTALLED=(true|1)$' .env; then
    ALREADY_INSTALLED=1
fi

if [ "$ALREADY_INSTALLED" -eq 0 ]; then
    # No database exists for the panel until the wizard creates its tables
    # in the one you give it, so until then sessions and cache run on files.
    # Finishing the wizard moves both to the database.
    set_env SESSION_DRIVER file
    set_env CACHE_STORE file
fi
# DB_* is deliberately not written here. The wizard's database step is
# where it gets chosen, and the only place that can prove it works.

if ! grep -q '^APP_KEY=.\+' .env; then
    php artisan key:generate --force --quiet
fi
ok ".env ready"

if [ "$ALREADY_INSTALLED" -eq 1 ]; then
    # Updating an installed panel: bring its schema forward. A fresh install
    # has nothing to migrate into yet - the wizard does that once it has a
    # database.
    step "Running migrations"
    php artisan migrate --force --no-interaction
    ok "panel tables up to date"
fi

step "Fixing ownership and permissions"
chown -R www-data:www-data "$INSTALL_DIR"
find storage bootstrap/cache -type d -exec chmod 775 {} \;
find storage bootstrap/cache -type f -exec chmod 664 {} \;
ok "www-data owns $INSTALL_DIR"

step "Scheduling background tasks"
# Health checks, RCON verification, the server activity chart and Steam
# profile warming all hang off Laravel's scheduler, so it is set up here
# rather than left as a README step - and as www-data, never root. A root
# cron run creates storage/ files (the log first of all) that php-fpm then
# cannot write, after which any request that needs to log an error fails
# outright. Left to a README line, that is exactly how it tends to get added.
if [ "$INSTALL_DIR" = "/var/www/s2panel" ]; then
    CRON_FILE="/etc/cron.d/s2panel"
else
    # cron.d names may only hold [A-Za-z0-9_-]; one file per install dir so
    # two panels on one box do not overwrite each other's schedule.
    CRON_FILE="/etc/cron.d/s2panel-$(printf '%s' "$INSTALL_DIR" | tr -c 'A-Za-z0-9' '-' | sed 's/^-*//; s/-*$//')"
fi
cat > "$CRON_FILE" <<CRON
# S2 Panel scheduler for $INSTALL_DIR - written by install.sh. Runs as the
# web server user: a root run leaves storage/ files php-fpm cannot write.
* * * * * www-data cd $INSTALL_DIR && php artisan schedule:run >> /dev/null 2>&1
CRON
chmod 644 "$CRON_FILE"
ok "scheduler runs every minute as www-data ($CRON_FILE)"

if crontab -l 2>/dev/null | grep -F "$INSTALL_DIR" | grep -q "schedule:run"; then
    warn "root's own crontab also runs schedule:run for $INSTALL_DIR - remove that line (sudo crontab -e): it now runs twice, and the root copy is what leaves files php-fpm cannot write"
fi

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
  language, the database your CS2 plugins use (the panel creates its own
  tables in it), your Steam API key and the owner's Steam profile link.

  If you turn on the Webhooks module later, it also needs a queue worker
  (or QUEUE_CONNECTION=sync in .env) - see README.md.

DONE
