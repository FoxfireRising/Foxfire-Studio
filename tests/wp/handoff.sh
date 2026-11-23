#!/usr/bin/env bash
# Download counting's hand-off (1.24.0) on real web servers: puts the test
# WordPress behind nginx (X-Accel-Redirect) and behind Apache with
# mod_xsendfile (X-Sendfile), each through PHP-FPM, then runs
# tests/php/handoff.php against them. A server that is not installed is
# skipped with a line, so this runs wherever it can (LiteSpeed is checked on a
# real site with Check counting).
#
#   tests/wp/handoff.sh <test WordPress dir>
#
# Ports: HO_NGINX (8183), HO_APACHE (8184); PHP-FPM on a socket in the dir.
set -uo pipefail
DIR="$1"
REPO="$(cd "$(dirname "$0")/../.." && pwd)"
WP="php $DIR/wp-cli.phar --path=$DIR/wordpress --allow-root"
NPORT="${HO_NGINX:-8183}"
APORT="${HO_APACHE:-8184}"
RUN="$DIR/handoff"
UP="$DIR/wordpress/wp-content/uploads"
rm -rf "$RUN"; mkdir -p "$RUN"
HT="$DIR/wordpress/.htaccess"
MU="$DIR/wordpress/wp-content/mu-plugins/foxfire-hide-range.php"
[ -f "$HT" ] && cp "$HT" "$RUN/htaccess.saved"

FPM="$(command -v php-fpm || command -v php-fpm8.3 || command -v php-fpm8.2 || command -v php-fpm8.1 || command -v php-fpm7.4 || ls /usr/sbin/php-fpm* 2>/dev/null | head -1 || true)"
if [ -z "$FPM" ]; then
	echo "skipped: PHP-FPM is not installed here"
	exit 0
fi
# The same PHP as the tests, with SQLite (the test site's database).
CLI_V="$(php -r 'echo PHP_MAJOR_VERSION, ".", PHP_MINOR_VERSION;')"
FPM_V="$($FPM -v 2>/dev/null | head -1 | grep -oE '[0-9]+\.[0-9]+' | head -1)"
if [ "$CLI_V" != "$FPM_V" ] || ! $FPM -m 2>/dev/null | grep -qi '^pdo_sqlite$'; then
	echo "skipped: PHP-FPM here is $FPM_V (the tests use $CLI_V) or has no SQLite"
	exit 0
fi
cat > "$RUN/fpm.conf" <<F
[global]
pid = $RUN/fpm.pid
error_log = $RUN/fpm.log
[www]
listen = $RUN/fpm.sock
listen.mode = 0666
pm = static
pm.max_children = 4
clear_env = no
catch_workers_output = yes
F
$FPM -R -y "$RUN/fpm.conf" -D || { echo "skipped: PHP-FPM would not start"; exit 0; }
for i in 1 2 3 4 5 6 7 8 9 10; do [ -S "$RUN/fpm.sock" ] && break; sleep 0.3; done

NGINX_OK=""
if command -v nginx > /dev/null; then
	mkdir -p "$RUN/nginx"
	cat > "$RUN/nginx.conf" <<N
daemon on;
pid $RUN/nginx.pid;
error_log $RUN/nginx-error.log;
events {}
http {
	include /etc/nginx/mime.types;
	access_log off;
	client_body_temp_path $RUN/nginx; fastcgi_temp_path $RUN/nginx; proxy_temp_path $RUN/nginx; uwsgi_temp_path $RUN/nginx; scgi_temp_path $RUN/nginx;
	server {
		listen 127.0.0.1:$NPORT;
		root $DIR/wordpress;
		index index.php;
		location /foxfire-internal/ { internal; alias $UP/; }
		location / { try_files \$uri \$uri/ /index.php?\$args; }
		location ~ \.php\$ { include /etc/nginx/fastcgi_params; fastcgi_param SCRIPT_FILENAME \$document_root\$fastcgi_script_name; fastcgi_pass unix:$RUN/fpm.sock; }
	}
}
N
	if nginx -c "$RUN/nginx.conf" -p "$RUN" 2> "$RUN/nginx-start.log"; then NGINX_OK=1; else echo "nginx would not start: $(cat "$RUN/nginx-start.log")"; fi
else
	echo "skipped: nginx is not installed here"
fi

# LiteSpeed keeps the Range header from PHP; this stand-in does the same for a request that asks (header X-Foxfire-Hide-Range), so the .htaccess lines can be tested.
mkdir -p "$(dirname "$MU")"
cat > "$MU" <<'H'
<?php
if ( ! empty( $_SERVER['HTTP_X_FOXFIRE_HIDE_RANGE'] ) ) {
	unset( $_SERVER['HTTP_RANGE'] );
}
H
# WordPress's own rules, as a real site's .htaccess has them (Apache reads it; nginx and the PHP server do not).
cat > "$HT" <<'W'
# BEGIN WordPress
<IfModule mod_rewrite.c>
RewriteEngine On
RewriteRule ^index\.php$ - [L]
RewriteCond %{REQUEST_FILENAME} !-f
RewriteCond %{REQUEST_FILENAME} !-d
RewriteRule . /index.php [L]
</IfModule>
# END WordPress
W

APACHE_OK=""
XS="$(ls /usr/lib/apache2/modules/mod_xsendfile.so 2>/dev/null || true)"
if command -v apache2 > /dev/null && [ -n "$XS" ]; then
	M=/usr/lib/apache2/modules
	cat > "$RUN/apache.conf" <<A
ServerRoot $RUN
ServerName localhost
Listen 127.0.0.1:$APORT
PidFile $RUN/apache.pid
ErrorLog $RUN/apache-error.log
LoadModule mpm_event_module $M/mod_mpm_event.so
LoadModule authz_core_module $M/mod_authz_core.so
LoadModule dir_module $M/mod_dir.so
LoadModule mime_module $M/mod_mime.so
LoadModule rewrite_module $M/mod_rewrite.so
LoadModule headers_module $M/mod_headers.so
LoadModule setenvif_module $M/mod_setenvif.so
LoadModule proxy_module $M/mod_proxy.so
LoadModule proxy_fcgi_module $M/mod_proxy_fcgi.so
LoadModule xsendfile_module $XS
TypesConfig /etc/mime.types
DocumentRoot $DIR/wordpress
DirectoryIndex index.php
XSendFile On
XSendFilePath $UP
<Directory $DIR/wordpress>
	Require all granted
	AllowOverride All
</Directory>
<FilesMatch "\.php$">
	SetHandler "proxy:unix:$RUN/fpm.sock|fcgi://localhost"
</FilesMatch>
A
	if apache2 -f "$RUN/apache.conf" -k start 2> "$RUN/apache-start.log"; then APACHE_OK=1; else echo "Apache would not start: $(cat "$RUN/apache-start.log")"; fi
else
	echo "skipped: Apache with mod_xsendfile is not installed here"
fi
sleep 1

RC=0
HO_NGINX="${NGINX_OK:+$NPORT}" HO_APACHE="${APACHE_OK:+$APORT}" $WP eval-file "$REPO/tests/php/handoff.php" || RC=1

[ -n "$NGINX_OK" ] && nginx -c "$RUN/nginx.conf" -p "$RUN" -s stop 2> /dev/null
[ -n "$APACHE_OK" ] && apache2 -f "$RUN/apache.conf" -k stop 2> /dev/null
[ -f "$RUN/fpm.pid" ] && kill "$(cat "$RUN/fpm.pid")" 2> /dev/null
rm -f "$MU" "$HT"
[ -f "$RUN/htaccess.saved" ] && cp "$RUN/htaccess.saved" "$HT"
exit $RC
