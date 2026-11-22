#!/usr/bin/env bash
# End-to-end test of the release zip on real servers, in a real browser:
#
#   WordPress (latest, default block theme) with BMLT Minutes (from wordpress.org) and this plugin's zip, served by
#     ├─ Apache + PHP-FPM
#     └─ nginx + PHP-FPM
#   minutes with uploaded PDF, DOCX and CSV files, a Google Docs link and password-protected minutes, in two committees
#   and several months, on a published page with [bmlt_minutes_by_month]
#   headless Chrome as a visitor, on a desktop and on a phone (bin/browser-check.py)
#
# Then the page's HTML (no protected document's address) and uninstall (nothing left behind).
#
# Usage: bin/e2e.sh <work-dir>      (the directory is created; KEEP=1 leaves the servers' files behind)
#
# Environment (defaults suit Ubuntu with apache2, nginx, php-fpm and google-chrome installed):
#   DB_HOST=127.0.0.1 DB_PORT=3306 DB_ADMIN_USER=root DB_ADMIN_PASS=
#   APACHE=apache2  APACHE_MODULES=/usr/lib/apache2/modules  NGINX=nginx  PHP_FPM=php-fpm8.3  CHROME=google-chrome
#   WP_CORE=<directory with WordPress files> (default: wp core download)   PORT_BASE=8680
#   PLUGIN_ZIP (default: this version's zip in dist/, built if missing)
#   MINUTES_ZIP (default: tests/dependencies/bmlt-minutes.zip from bin/install-bmlt-minutes.sh)
set -euo pipefail

[ $# -eq 1 ] || { sed -n '2,21p' "$0" | sed 's/^# \{0,1\}//'; exit 2; }
ROOT=$(cd "$(dirname "$0")/.." && pwd)
W=$(mkdir -p "$1" && cd "$1" && pwd)
DB_HOST=${DB_HOST:-127.0.0.1}
DB_PORT=${DB_PORT:-3306}
DB_ADMIN_USER=${DB_ADMIN_USER:-root}
DB_ADMIN_PASS=${DB_ADMIN_PASS:-}
APACHE=${APACHE:-apache2}
APACHE_MODULES=${APACHE_MODULES:-/usr/lib/apache2/modules}
NGINX=${NGINX:-nginx}
PHP_FPM=${PHP_FPM:-php-fpm8.3}
P=${PORT_BASE:-8680}
APACHE_PORT=$((P + 3)) NGINX_PORT=$((P + 4))
SITE_A="http://127.0.0.1:$APACHE_PORT"
SITE_N="http://127.0.0.1:$NGINX_PORT"

pass() { echo "  ok   $*"; }
fail() { echo "  FAIL $*" >&2; exit 1; }
section() { echo; echo "== $*"; }

PL=bmlt-minutes-by-month
DB=e2e_bmbm
V=$(sed -n 's/^ \* Version: *//p' "$ROOT/$PL/$PL.php")
# PLUGIN_ZIP tests another zip.
PLUGIN_ZIP=${PLUGIN_ZIP:-$ROOT/dist/$PL-$V.zip}
[ -f "$PLUGIN_ZIP" ] || "$ROOT/bin/build.sh" --dev --allow-dirty
MINUTES_ZIP=${MINUTES_ZIP:-$ROOT/tests/dependencies/bmlt-minutes.zip}
[ -f "$MINUTES_ZIP" ] || "$ROOT/bin/install-bmlt-minutes.sh"

PIDS=()
cleanup() {
	for pid in "${PIDS[@]}"; do kill "$pid" 2>/dev/null || true; done
	[ -f "$W/run/apache.pid" ] && kill "$(cat "$W/run/apache.pid")" 2>/dev/null || true
	mysql_admin -e "DROP DATABASE IF EXISTS $DB" 2>/dev/null || true
	if [ "${KEEP:-0}" != 1 ]; then rm -rf "$W"; fi
}
trap cleanup EXIT
mysql_admin() { mysql -h "$DB_HOST" -P "$DB_PORT" -u "$DB_ADMIN_USER" ${DB_ADMIN_PASS:+-p"$DB_ADMIN_PASS"} "$@"; }

rm -rf "${W:?}"/*
mkdir -p "$W/run" "$W/logs" "$W/nginx"

section "PHP-FPM, Apache, nginx"
cat > "$W/run/fpm.conf" <<EOF
[global]
error_log = $W/logs/fpm.log
pid = $W/run/fpm.pid
daemonize = no
[wp]
listen = $W/run/fpm.sock
pm = static
pm.max_children = 8
clear_env = no
catch_workers_output = yes
php_admin_value[error_log] = $W/logs/php.log
php_admin_flag[log_errors] = on
; As on a production server: PHP warnings go to the log, not into pages.
php_admin_flag[display_errors] = off
EOF
"$PHP_FPM" -y "$W/run/fpm.conf" -F >"$W/logs/fpm.out" 2>&1 &
PIDS+=($!)

mod() { echo "LoadModule ${1}_module $APACHE_MODULES/mod_$1.so"; }
{
	echo "ServerRoot $W/run"
	echo "ServerName 127.0.0.1"
	echo "PidFile $W/run/apache.pid"
	echo "ErrorLog $W/logs/apache-error.log"
	echo "Mutex file:$W/run"
	echo "<IfModule !mpm_event_module>"; mod mpm_event; echo "</IfModule>"
	echo "<IfModule !unixd_module>"; mod unixd; echo "</IfModule>"
	for m in authz_core authz_host access_compat dir mime rewrite proxy proxy_fcgi setenvif headers env log_config; do
		[ -f "$APACHE_MODULES/mod_$m.so" ] && echo "<IfModule !${m}_module>" && mod "$m" && echo "</IfModule>"
	done
	cat <<EOF
LogFormat "%h \"%r\" %>s" e2e
TypesConfig /dev/null
AddType text/css .css
AddType application/javascript .js
AddType text/plain .txt .md
AddType application/pdf .pdf
AddType text/csv .csv
AddType application/vnd.openxmlformats-officedocument.wordprocessingml.document .docx
DirectoryIndex index.php index.html
<Directory />
	AllowOverride None
	Require all denied
</Directory>
Listen 127.0.0.1:$APACHE_PORT
<Directory $W/wp>
	AllowOverride All
	Require all granted
	<FilesMatch "\.php$">
		SetHandler "proxy:unix:$W/run/fpm.sock|fcgi://wp"
	</FilesMatch>
</Directory>
<VirtualHost 127.0.0.1:$APACHE_PORT>
	DocumentRoot $W/wp
	CustomLog $W/logs/apache-access.log e2e
</VirtualHost>
EOF
} > "$W/run/apache.conf"
"$APACHE" -f "$W/run/apache.conf" -t >/dev/null 2>"$W/logs/apache-configtest.log" || { cat "$W/logs/apache-configtest.log"; fail "apache configuration"; }

cat > "$W/nginx/nginx.conf" <<EOF
pid $W/run/nginx.pid;
error_log $W/logs/nginx-error.log;
daemon off;
worker_processes 1;
events { worker_connections 64; }
http {
	access_log $W/logs/nginx-access.log;
	client_body_temp_path $W/run/nb; proxy_temp_path $W/run/np; fastcgi_temp_path $W/run/nf; uwsgi_temp_path $W/run/nu; scgi_temp_path $W/run/ns;
	types { text/html html; text/css css; application/javascript js; image/svg+xml svg; application/pdf pdf; text/csv csv;
		application/vnd.openxmlformats-officedocument.wordprocessingml.document docx; }
	default_type application/octet-stream;
	# The usual WordPress server block (wordpress.org's nginx guide).
	server {
		listen 127.0.0.1:$NGINX_PORT;
		server_name 127.0.0.1;
		root $W/wp;
		index index.php;
		client_max_body_size 64m;
		location / { try_files \$uri \$uri/ /index.php?\$args; }
		location ~ \.php\$ {
			try_files \$uri =404;
			fastcgi_param SCRIPT_FILENAME \$document_root\$fastcgi_script_name;
			fastcgi_param QUERY_STRING \$query_string; fastcgi_param REQUEST_METHOD \$request_method;
			fastcgi_param CONTENT_TYPE \$content_type; fastcgi_param CONTENT_LENGTH \$content_length;
			fastcgi_param SCRIPT_NAME \$fastcgi_script_name; fastcgi_param REQUEST_URI \$request_uri;
			fastcgi_param DOCUMENT_URI \$document_uri; fastcgi_param DOCUMENT_ROOT \$document_root;
			fastcgi_param SERVER_PROTOCOL \$server_protocol; fastcgi_param REMOTE_ADDR \$remote_addr;
			fastcgi_param REMOTE_PORT \$remote_port; fastcgi_param SERVER_ADDR \$server_addr;
			fastcgi_param SERVER_PORT \$server_port; fastcgi_param SERVER_NAME \$server_name;
			fastcgi_param HTTP_HOST \$http_host;
			fastcgi_pass unix:$W/run/fpm.sock;
		}
	}
}
EOF

section "WordPress with the plugin zip"
WP=(wp --path="$W/wp" --quiet)
WPU=("${WP[@]}" --user=admin)
if [ -n "${WP_CORE:-}" ]; then
	mkdir -p "$W/wp" && (cd "$WP_CORE" && tar --exclude=./wp-config.php --exclude=./wp-content/uploads -cf - .) | tar -xf - -C "$W/wp"
else
	"${WP[@]}" core download --path="$W/wp" >/dev/null
fi
mysql_admin -e "DROP DATABASE IF EXISTS $DB; CREATE DATABASE $DB CHARACTER SET utf8mb4"
"${WP[@]}" config create --dbname=$DB --dbuser="$DB_ADMIN_USER" --dbpass="$DB_ADMIN_PASS" --dbhost="$DB_HOST:$DB_PORT" --skip-check --force \
	--extra-php <<'PHP'
define( 'WP_ENVIRONMENT_TYPE', 'local' );
define( 'DISABLE_WP_CRON', true );
define( 'WP_DEBUG', true );
define( 'WP_DEBUG_LOG', true );
define( 'WP_DEBUG_DISPLAY', false );
// The same site behind Apache and nginx (two ports): links follow the address used.
if ( ! empty( $_SERVER['HTTP_HOST'] ) ) {
	define( 'WP_HOME', 'http://' . $_SERVER['HTTP_HOST'] );
	define( 'WP_SITEURL', 'http://' . $_SERVER['HTTP_HOST'] );
}
PHP
"${WP[@]}" core install --url="$SITE_A" --title="E2E Area" --admin_user=admin --admin_password=Admin-pass-1 --admin_email=admin@example.org --skip-email
"${WP[@]}" rewrite structure '/%postname%/'
cat > "$W/wp/.htaccess" <<'HT'
# BEGIN WordPress
<IfModule mod_rewrite.c>
RewriteEngine On
RewriteBase /
RewriteRule ^index\.php$ - [L]
RewriteCond %{REQUEST_FILENAME} !-f
RewriteCond %{REQUEST_FILENAME} !-d
RewriteRule . /index.php [L]
</IfModule>
# END WordPress
HT
"${WP[@]}" plugin install "$MINUTES_ZIP" --activate
"${WP[@]}" plugin install "$PLUGIN_ZIP" --activate
"$APACHE" -f "$W/run/apache.conf" -k start
NGINX_EARLY=()
"$NGINX" -h 2>&1 | grep -q -- '-e filename' && NGINX_EARLY=(-e "$W/logs/nginx-early.log")
"$NGINX" "${NGINX_EARLY[@]}" -p "$W/nginx" -c "$W/nginx/nginx.conf" >"$W/logs/nginx.out" 2>&1 &
PIDS+=($!)
for i in $(seq 1 50); do
	curl -s -o /dev/null "$SITE_A/" && curl -s -o /dev/null "$SITE_N/" && break
	sleep 0.2
done
curl -s -o /dev/null "$SITE_N/" || { cat "$W/logs/nginx.out"; fail "nginx did not start"; }
pass "WordPress $("${WP[@]}" core version), theme $("${WP[@]}" theme list --status=active --field=name), $PL $("${WP[@]}" plugin get $PL --field=version), BMLT Minutes $("${WP[@]}" plugin get bmlt-minutes --field=version); Apache $("$APACHE" -v | sed -n 's/Server version: Apache\/\([0-9.]*\).*/\1/p'), nginx $("$NGINX" -v 2>&1 | sed 's/.*nginx\///'), PHP $(php -r 'echo PHP_VERSION;')"

section "Minutes"
F="$W/files"
mkdir -p "$F"
FX="$ROOT/tests/wordpress/fixtures"
for n in asc-2025-03 asc-2025-01 asc-2024-11 policy-2025-02 protected; do cp "$FX/minutes.pdf" "$F/$n.pdf"; done
cp "$FX/motions.csv" "$F/asc-2024-11-motions.csv"
# A real (minimal) Word document.
python3 - "$F/asc-2025-03-treasurer.docx" <<'PY'
import sys, zipfile
with zipfile.ZipFile(sys.argv[1], 'w', zipfile.ZIP_DEFLATED) as z:
    z.writestr('[Content_Types].xml', '<?xml version="1.0" encoding="UTF-8"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/><Override PartName="/word/document.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.document.main+xml"/></Types>')
    z.writestr('_rels/.rels', '<?xml version="1.0" encoding="UTF-8"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="word/document.xml"/></Relationships>')
    z.writestr('word/document.xml', '<?xml version="1.0" encoding="UTF-8"?><w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main"><w:body><w:p><w:r><w:t>Treasurer report</w:t></w:r></w:p></w:body></w:document>')
PY
ASC=$("${WP[@]}" term list bmlt_committee --slug=area-service-committee --field=term_id)
POLICY=$("${WP[@]}" term list bmlt_committee --slug=policy --field=term_id)
[ -n "$ASC" ] && [ -n "$POLICY" ] || fail "BMLT Minutes' default committees: $("${WP[@]}" term list bmlt_committee --fields=term_id,slug)"
# minutes <title> <posted Y-m-d> <committee slug> [file in $F | url] [password]
minutes() {
	local id att
	id=$("${WP[@]}" post create --post_type=bmlt_minutes --post_status=publish --post_title="$1" --post_date="$2 12:00:00" ${5:+--post_password="$5"} --porcelain)
	"${WP[@]}" post term set "$id" bmlt_committee "$3"
	"${WP[@]}" post meta update "$id" _bmlt_minutes_date "$2"
	case "${4:-}" in
		http*) "${WP[@]}" post meta update "$id" _bmlt_minutes_url "$4" ;;
		?*) att=$("${WPU[@]}" media import "$F/$4" --post_id="$id" --porcelain) || fail "import of $4"
			"${WP[@]}" post meta update "$id" _bmlt_minutes_attachment_id "$att" ;;
	esac
	echo "$id"
}
minutes "ASC March 2025" 2025-03-09 area-service-committee asc-2025-03.pdf >/dev/null
minutes "Treasurer March 2025" 2025-03-09 area-service-committee asc-2025-03-treasurer.docx >/dev/null
minutes "Newsletter March 2025" 2025-03-10 area-service-committee https://docs.google.com/document/d/e2e/edit >/dev/null
PROTECTED=$(minutes "Unredacted March 2025" 2025-03-11 area-service-committee protected.pdf secret-pass)
minutes "ASC January 2025" 2025-01-12 area-service-committee asc-2025-01.pdf >/dev/null
minutes "ASC November 2024" 2024-11-10 area-service-committee asc-2024-11.pdf >/dev/null
minutes "Motions November 2024" 2024-11-10 area-service-committee asc-2024-11-motions.csv >/dev/null
minutes "Policy February 2025" 2025-02-02 policy policy-2025-02.pdf >/dev/null
"${WP[@]}" post create --post_type=page --post_status=publish --post_title='Minutes by Month' --post_name=minutes-by-month --post_content='<!-- wp:shortcode -->[bmlt_minutes_by_month]<!-- /wp:shortcode -->' >/dev/null
pass "8 minutes (PDF, DOCX, CSV, Google Docs link, password-protected PDF) in two committees; page /minutes-by-month/"

section "The page's HTML (Apache)"
HTML=$(curl -fsS "$SITE_A/minutes-by-month/")
PROTECTED_FILE=$("${WP[@]}" eval "echo basename( get_attached_file( (int) get_post_meta( $PROTECTED, '_bmlt_minutes_attachment_id', true ) ) );")
grep -q "$PROTECTED_FILE" <<<"$HTML" && fail "the protected minutes' document ($PROTECTED_FILE) is on the page"
grep -q 'bmbm__month-title">March</' <<<"$HTML" || fail "no March section"
[ "$(grep -o '<details class="bmbm__month" open>' <<<"$HTML" | wc -l)" = 2 ] || fail "open months: $(grep -o '<details class="bmbm__month"[^>]*>' <<<"$HTML")"
grep -q 'bmbm__doc--docx' <<<"$HTML" || fail "the Word document is not a File block"
grep -q 'bmbm__doc--csv' <<<"$HTML" || fail "the CSV file is not a File block"
grep -q 'wp-block-file' <<<"$HTML" && grep -q 'view.js' <<<"$HTML" || fail "File block or the plugin's script missing"
pass "two open months (one per committee); DOCX and CSV as File blocks; the protected document's address is not on the page"

section "Browser: desktop (Apache)"
python3 "$ROOT/bin/browser-check.py" "$SITE_A/minutes-by-month/" --shot "$W/logs/desktop-apache.png"
section "Browser: desktop (nginx)"
python3 "$ROOT/bin/browser-check.py" "$SITE_N/minutes-by-month/"
section "Browser: phone (nginx)"
python3 "$ROOT/bin/browser-check.py" "$SITE_N/minutes-by-month/" --phone --shot "$W/logs/phone-nginx.png"

section "Uninstall"
"${WP[@]}" plugin deactivate $PL
"${WP[@]}" plugin uninstall $PL
left=$("${WP[@]}" db query "SELECT option_name FROM wp_options WHERE option_name LIKE '%bmbm%' OR option_name LIKE '%by_month%' OR option_name LIKE '%by-month%'" --skip-column-names)
[ -z "$left" ] || fail "left behind: $left"
[ ! -e "$W/wp/wp-content/plugins/$PL" ] || fail "plugin folder left behind"
[ "$("${WP[@]}" post list --post_type=bmlt_minutes --format=count)" = 8 ] || fail "minutes changed by uninstall"
pass "nothing left behind; the minutes are untouched"
grep -q 'bmlt_minutes_by_month' <<<"$(curl -fsS "$SITE_A/minutes-by-month/")" && pass "the page shows the bare shortcode once the plugin is gone (WordPress's usual behaviour)" || true

section "Logs"
if grep -v 'PHP Deprecated' "$W/logs/php.log" 2>/dev/null | grep -q 'PHP '; then
	grep 'PHP ' "$W/logs/php.log" | tail -20
	fail "PHP errors in the server log"
fi
if [ -s "$W/wp/wp-content/debug.log" ] && grep -q "$PL" "$W/wp/wp-content/debug.log"; then
	grep "$PL" "$W/wp/wp-content/debug.log" | tail -20
	fail "the plugin logged PHP notices or errors"
fi
pass "no PHP warnings or errors from the plugin"
grep -q "\" 5[0-9][0-9]$" "$W/logs/apache-access.log" && fail "a 5xx answer from Apache: $(grep '" 5' "$W/logs/apache-access.log" | head -3)"
awk '$9 ~ /^5/' "$W/logs/nginx-access.log" | grep -q . && fail "a 5xx answer from nginx: $(awk '$9 ~ /^5/' "$W/logs/nginx-access.log" | head -3)"
pass "no server errors"
echo
echo "End-to-end test passed."
