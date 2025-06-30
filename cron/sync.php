#!/usr/bin/env php
<?php

/**
 * @file: sync.php
 * @description: Cron script for automatic synchronization with Compo PIM
 * @dependencies: CS-Cart core
 * @created: 2025-06-30
 *
 * Использование:
 * php /path/to/cscart/app/addons/pim_sync/cron/sync.php [--full] [--days=N]
 *
 * Параметры:
 * --full   - perform a full synchronization
 * --days=N - sync items for N days (default 1)
 *
 * Example for crontab (every 30 minutes):
 * 0,30 * * * * php /path/to/cscart/app/addons/pim_sync/cron/sync.php
 */

// Defining the CS-Cart root directory
$root_dir = dirname(dirname(dirname(dirname(__DIR__))));

// Basic constants for CS-Cart
define('AREA', 'C');
define('ACCOUNT_TYPE', 'admin');
define('NO_SESSION', true);
define('DESCR_SL', 'ru');
// Connecting the CS-Cart core
require $root_dir . '/init.php';
// Connecting addon files
require_once DIR_ROOT . '/app/addons/pim_sync/func.php';
// Parsing command line arguments
$options = getopt('', ['full', 'days::']);
// Check if synchronization is enabled
$settings = fn_pim_sync_get_settings();
if (! $settings['sync_enabled']) {
    echo "PIM sync is disabled in settings\n";
    exit(0);
}
// Check if synchronization has already started
$running_sync = db_get_field(
    "SELECT COUNT(*) FROM ?:pim_sync_log WHERE status = 'running'"
);
if ($running_sync > 0) {
    echo "Sync is already running\n";
    exit(0);
}
// Determine the type of synchronization
$is_full_sync = isset($options['full']);
$days = isset($options['days']) ? intval($options['days']) : 1;

try {
    if ($is_full_sync) {
        echo "Starting full synchronization...\n";
        $result = fn_pim_sync_full();

        if ($result['success']) {
            echo "Full sync completed successfully!\n";
            echo "Categories synced: {$result['categories_synced']}\n";
            echo "Products synced: {$result['products_synced']}\n";
        } else {
            echo "Full sync failed!\n";
            echo "Errors: " . implode(', ', $result['errors']) . "\n";
            exit(1);
        }
    } else {
        echo "Starting delta synchronization for last $days day(s)...\n";
        $result = fn_pim_sync_delta($days);

        if ($result['success']) {
            echo "Delta sync completed successfully!\n";
            echo "Products updated: {$result['products_updated']}\n";
        } else {
            echo "Delta sync failed!\n";
            echo "Errors: " . implode(', ', $result['errors']) . "\n";
            exit(1);
        }
    }
    // Clearing old logs (older than 30 days)
    fn_pim_sync_cleanup_logs(30);

} catch (Exception $e) {
    echo "Fatal error: " . $e->getMessage() . "\n";
    exit(1);
}
echo "Sync completed at " . date('Y-m-d H:i:s') . "\n";
exit(0);
