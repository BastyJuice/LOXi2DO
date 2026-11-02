#!/bin/bash
set -eu
umask 077
source_dir=${6:-$(CDPATH= cd -- "$(dirname -- "$0")" && pwd -P)}
/usr/bin/php "$source_dir/bin/check.php"
home_dir=${LBHOMEDIR:-${5:-'REPLACELBHOMEDIR'}}
case "$home_dir" in /*) ;; *) echo '<ERROR> LoxBerry base directory is missing.' >&2; exit 2 ;; esac
if [ "$3" != 'loxi2do' ]; then echo '<ERROR> Unexpected LOXi2DO plugin identifier.' >&2; exit 2; fi
config_dir="${LBPCONFIG:-$home_dir/config/plugins}/$3"
data_dir="${LBPDATA:-$home_dir/data/plugins}/$3"
operation="${1:?Missing installer operation}|${4:?Missing plugin version}|$source_dir"
# LoxBerry removes config AND data immediately after this hook. Keep the verified
# copy in a persistent private sibling that the installer does not purge.
/usr/bin/php "$source_dir/bin/migrate-legacy.php" --backup "$config_dir" "$data_dir" "$operation"
