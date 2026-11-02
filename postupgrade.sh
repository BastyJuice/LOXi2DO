#!/bin/bash
set -eu
umask 077
home_dir=${LBHOMEDIR:-${5:-'REPLACELBHOMEDIR'}}
case "$home_dir" in /*) ;; *) echo '<ERROR> LoxBerry base directory is missing.' >&2; exit 2 ;; esac
if [ "$3" != 'loxi2do' ]; then echo '<ERROR> Unexpected LOXi2DO plugin identifier.' >&2; exit 2; fi
source_dir=${6:-$(CDPATH= cd -- "$(dirname -- "$0")" && pwd -P)}
config_dir="${LBPCONFIG:-$home_dir/config/plugins}/$3"
data_dir="${LBPDATA:-$home_dir/data/plugins}/$3"
operation="${1:?Missing installer operation}|${4:?Missing plugin version}|$source_dir"
/usr/bin/php "$source_dir/bin/migrate-legacy.php" --complete "$config_dir" "$data_dir" "$operation"
