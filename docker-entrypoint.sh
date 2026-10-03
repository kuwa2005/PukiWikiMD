#!/bin/sh
set -eu

cd /var/www/html

# バインドマウント先の所有者に合わせ、Apache ワーカー (www-data) の UID/GID を揃える
HOST_UID=$(stat -c '%u' /var/www/html)
HOST_GID=$(stat -c '%g' /var/www/html)
if [ "$(id -u)" = "0" ] && [ -n "$HOST_UID" ] && [ -n "$HOST_GID" ]; then
	groupmod -o -g "$HOST_GID" www-data 2>/dev/null || true
	usermod -o -u "$HOST_UID" -g "$HOST_GID" www-data 2>/dev/null || true
fi

if [ ! -f pukiwiki/pukiwiki.ini.php ]; then
	cp pukiwiki/pukiwiki.ini.php.example pukiwiki/pukiwiki.ini.php
	echo "Created pukiwiki/pukiwiki.ini.php from example"
fi

# root で作られた ini が残っていた場合の救済
if [ "$(id -u)" = "0" ]; then
	chown "$HOST_UID:$HOST_GID" pukiwiki/pukiwiki.ini.php 2>/dev/null || true
fi

composer install --no-dev --optimize-autoloader --no-interaction

for d in wiki cache backup attach counter diff; do
	mkdir -p "pukiwiki/$d"
	chmod -R a+rwX "pukiwiki/$d" || true
done

# atomic rename 用に親ディレクトリも Web ユーザーが書けること
chmod a+rwx pukiwiki || true

exec "$@"
