#!/bin/bash
set -eu
source_dir=${6:-$(CDPATH= cd -- "$(dirname -- "$0")" && pwd -P)}
if [ ! -x /usr/bin/php ]; then
    echo '<ERROR> LoxBerry system PHP /usr/bin/php is missing.'
    exit 2
fi
/usr/bin/php "$source_dir/bin/check.php"
