#!/usr/bin/env bash
set -euo pipefail
cd /workspace
test -f pukiwiki/pukiwiki.ini.php || cp pukiwiki/pukiwiki.ini.php.example pukiwiki/pukiwiki.ini.php
if ss -ltn | grep -q ':43123 '; then
  exit 0
fi
exec php -S 0.0.0.0:43123 -t /workspace
