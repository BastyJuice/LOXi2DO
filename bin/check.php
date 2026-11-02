#!/usr/bin/php
<?php
declare(strict_types=1);

if (PHP_VERSION_ID < 70400) { fwrite(STDERR, "<ERROR> LOXi2DO requires the existing system PHP 7.4 or newer.\n"); exit(2); }
foreach (['json_encode', 'hash_hmac', 'hash_file', 'random_bytes', 'hash_equals', 'flock', 'stream_get_contents', 'session_start', 'stream_socket_client', 'stream_select'] as $function) {
    if (!function_exists($function)) { fwrite(STDERR, "<ERROR> Missing system PHP function: $function. No packages were installed.\n"); exit(2); }
}
foreach (['TabletStore.php', 'RemoteClient.php', 'ConfigForm.php', 'FleetState.php', 'RamStorage.php', 'LegacyMigration.php'] as $file) { require_once __DIR__ . '/lib/' . $file; }
echo '<OK> LOXi2DO uses existing PHP ' . PHP_VERSION . " with JSON, hash, sessions and private files. No extra packages or daemon.\n";
