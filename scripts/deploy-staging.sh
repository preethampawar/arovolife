#!/usr/bin/env bash
# ─────────────────────────────────────────────────────────────────────────────
#  arovolife — staging post-deploy script
#
#  Wired up as the Cloudways "Deploy Hook" for the Karonix Wellness app
#  (slug: ahdhesuhty). Cloudways pulls the repo into public_html/, then
#  invokes this script. We rebuild dependencies, run migrations, refresh
#  caches, and restart the queue worker.
#
#  Run on the server like this:
#
#      bash /home/master/applications/ahdhesuhty/public_html/scripts/deploy-staging.sh
#
#  Exit codes:
#      0  success
#      1  any step failed (Cloudways will surface the failure in its UI)
#
#  Safe to re-run. Every step is idempotent.
# ─────────────────────────────────────────────────────────────────────────────

set -euo pipefail

# ── Configuration ────────────────────────────────────────────────────────────

# The Laravel project lives one level under public_html (we keep the repo
# rooted at public_html so /docs, /scripts, etc. ship alongside the app).
REPO_ROOT="${REPO_ROOT:-/home/master/applications/ahdhesuhty/public_html}"
APP_ROOT="${APP_ROOT:-${REPO_ROOT}/app}"
LOG_FILE="${APP_ROOT}/storage/logs/deploy.log"
HEALTH_URL="${HEALTH_URL:-https://phplaravel-1611779-6390605.cloudwaysapps.com/}"

# ── Logging helpers ──────────────────────────────────────────────────────────

ts()   { date -u +'%Y-%m-%dT%H:%M:%SZ'; }
log()  { printf '[%s] %s\n'  "$(ts)" "$*"  | tee -a "$LOG_FILE"; }
fail() { printf '[%s] ✘ %s\n' "$(ts)" "$*" | tee -a "$LOG_FILE" >&2; exit 1; }
step() { printf '\n[%s] ── %s ──\n' "$(ts)" "$*" | tee -a "$LOG_FILE"; }

# Make sure the log file is writable before we use `tee` everywhere.
mkdir -p "$(dirname "$LOG_FILE")"
touch "$LOG_FILE" 2>/dev/null || true

# ── Pre-flight ───────────────────────────────────────────────────────────────

step "Pre-flight"
[[ -d "$APP_ROOT" ]] || fail "APP_ROOT not found: $APP_ROOT"
[[ -f "$APP_ROOT/artisan" ]] || fail "artisan not found at $APP_ROOT/artisan — wrong APP_ROOT?"
[[ -f "$APP_ROOT/.env" ]]    || fail ".env missing at $APP_ROOT/.env — populate it before deploying"

cd "$APP_ROOT"

CURRENT_SHA="$(git -C "$REPO_ROOT" rev-parse --short HEAD 2>/dev/null || echo 'unknown')"
log "Deploying commit ${CURRENT_SHA} from $(git -C "$REPO_ROOT" rev-parse --abbrev-ref HEAD 2>/dev/null || echo '?')"

# Confirm APP_ENV is staging (or production) — refuse to deploy with local/testing.
APP_ENV_VALUE="$(grep -E '^APP_ENV=' .env | head -n1 | cut -d'=' -f2- | tr -d '"' | tr -d "'")"
case "$APP_ENV_VALUE" in
    staging|production) log "APP_ENV=${APP_ENV_VALUE}";;
    *) fail "Refusing to deploy: APP_ENV='${APP_ENV_VALUE}' is not staging or production";;
esac

# ── Maintenance mode (briefly, only while migrations run) ────────────────────
# We don't enable it for the whole deploy — composer/npm steps don't change
# behaviour visible to a user mid-request. Only `migrate` is the risk window.

# ── PHP dependencies ─────────────────────────────────────────────────────────

step "composer install"
composer install \
    --no-dev \
    --optimize-autoloader \
    --no-interaction \
    --prefer-dist 2>&1 | tee -a "$LOG_FILE" \
    || fail "composer install failed"

# ── Frontend build ───────────────────────────────────────────────────────────

step "npm ci && npm run build"
if [[ -f package-lock.json ]]; then
    npm ci --silent 2>&1 | tee -a "$LOG_FILE" || fail "npm ci failed"
else
    npm install --silent 2>&1 | tee -a "$LOG_FILE" || fail "npm install failed"
fi
npm run build 2>&1 | tee -a "$LOG_FILE" || fail "vite build failed"

# ── Storage symlink (one-time, but cheap to re-run) ──────────────────────────

step "storage:link"
php artisan storage:link 2>&1 | tee -a "$LOG_FILE" || true   # already-linked is not fatal

# ── Migrations ───────────────────────────────────────────────────────────────

step "Migrations"
log "Pretend run (showing what would change):"
php artisan migrate --pretend 2>&1 | tee -a "$LOG_FILE" || true

log "Applying migrations:"
php artisan down --render="errors::503" --retry=15 2>&1 | tee -a "$LOG_FILE" || true
trap 'php artisan up >/dev/null 2>&1 || true' EXIT
php artisan migrate --force --no-interaction 2>&1 | tee -a "$LOG_FILE" \
    || fail "migrate failed — site is in maintenance mode; investigate before clearing"
php artisan up 2>&1 | tee -a "$LOG_FILE" || true
trap - EXIT

# ── Idempotent production seeder ─────────────────────────────────────────────
# Only inserts missing rows; never overwrites admin-edited values.

step "ProductionSeeder"
php artisan db:seed --class=ProductionSeeder --force --no-interaction 2>&1 | tee -a "$LOG_FILE" \
    || fail "ProductionSeeder failed"

# ── Cache rebuilds ───────────────────────────────────────────────────────────

step "Refresh caches"
php artisan config:clear 2>&1 | tee -a "$LOG_FILE"
php artisan route:clear  2>&1 | tee -a "$LOG_FILE"
php artisan view:clear   2>&1 | tee -a "$LOG_FILE"
php artisan event:clear  2>&1 | tee -a "$LOG_FILE" || true

php artisan config:cache 2>&1 | tee -a "$LOG_FILE" || fail "config:cache failed (likely env() outside config/)"
php artisan route:cache  2>&1 | tee -a "$LOG_FILE" || fail "route:cache failed"
php artisan view:cache   2>&1 | tee -a "$LOG_FILE"
php artisan event:cache  2>&1 | tee -a "$LOG_FILE" || true

# Bytecode caches Laravel doesn't manage itself
if command -v php >/dev/null && php -v 2>/dev/null | grep -q 'OPcache'; then
    log "OPcache present; relying on Cloudways' apache reload to flush"
fi

# ── Permissions ──────────────────────────────────────────────────────────────

step "Fix ownership / permissions"
# Cloudways php-fpm runs as the master user; web reads happen via www-data.
# We want both to read; only master writes.
chmod -R ug+rwX storage bootstrap/cache 2>&1 | tee -a "$LOG_FILE" || true
chmod -R o+rX  storage bootstrap/cache  2>&1 | tee -a "$LOG_FILE" || true
# Drop any stale framework views from the previous deploy.
find storage/framework/views -name '*.php' -mmin +60 -delete 2>/dev/null || true

# ── Restart queue workers ────────────────────────────────────────────────────

step "Restart queue workers"
php artisan queue:restart 2>&1 | tee -a "$LOG_FILE" || true

# ── Smoke test ───────────────────────────────────────────────────────────────

step "Smoke test"
HTTP_CODE="$(curl -o /dev/null -s -w '%{http_code}' --max-time 15 "$HEALTH_URL" || echo 'curl_failed')"
log "GET ${HEALTH_URL} → HTTP ${HTTP_CODE}"
case "$HTTP_CODE" in
    200|301|302) log "Smoke test passed" ;;
    *)           fail "Smoke test failed (HTTP ${HTTP_CODE}). Site likely broken; check storage/logs/laravel.log" ;;
esac

# ── Summary ──────────────────────────────────────────────────────────────────

step "Done"
log "✓ Deploy of ${CURRENT_SHA} complete."
log "  - Tail logs: tail -F ${APP_ROOT}/storage/logs/laravel.log"
log "  - Tail this script's log: tail -F ${LOG_FILE}"
