#!/bin/bash
set -eu
umask 077
home_dir=${LBHOMEDIR:-${5:-'REPLACELBHOMEDIR'}}
case "$home_dir" in /*) ;; *) echo '<ERROR> LoxBerry base directory is missing.' >&2; exit 2 ;; esac
config_dir="${LBPCONFIG:-$home_dir/config/plugins}/$3"
data_dir="${LBPDATA:-$home_dir/data/plugins}/$3"
if [ "$3" != 'loxi2do' ]; then
    echo '<ERROR> Unexpected LOXi2DO plugin identifier.' >&2
    exit 2
fi
mkdir -p "$(dirname -- "$config_dir")" "$(dirname -- "$data_dir")"
source_dir=${6:-$(CDPATH= cd -- "$(dirname -- "$0")" && pwd -P)}
operation="${1:?Missing installer operation}|${4:?Missing plugin version}|$source_dir"
/usr/bin/php "$source_dir/bin/migrate-legacy.php" --install "$config_dir" "$data_dir" "$operation"
mkdir -p "$config_dir" "$data_dir" "$data_dir/assets"
chmod 700 "$config_dir" "$data_dir" "$data_dir/assets"
if [ -f "$config_dir/tablets.json" ]; then chmod 600 "$config_dir/tablets.json"; fi
if [ -f "$config_dir/tablets.lock" ]; then chmod 600 "$config_dir/tablets.lock"; fi
echo '<OK> LOXi2DO installed. Add tablets in its web interface. Existing configuration was preserved.'
