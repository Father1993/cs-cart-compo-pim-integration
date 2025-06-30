<?php

/**
 * @file: pim_sync.php
 * @description: Admin panel controller for managing PIM synchronization
 * @dependencies: CS-Cart core
 * @created: 2025-06-30
 */

use Tygh\Addons\PimSync\PimApiClient;
use Tygh\Registry;

if (! defined('BOOTSTRAP')) {
    die('Access denied');
}

// Checking access rights
if (! fn_check_permissions('pim_sync', 'manage', 'admin')) {
    return [CONTROLLER_STATUS_DENIED];
}

$mode = ! empty($_REQUEST['mode']) ? $_REQUEST['mode'] : 'manage';

if ($_SERVER['REQUEST_METHOD'] == 'POST') {

    if ($mode == 'sync_full') {
        // Starting a full synchronization
        $result = fn_pim_sync_full();
        if ($result['success']) {
            fn_set_notification(
                'N',
                __('notice'),
                __('pim_sync.full_sync_completed', [
                    '[categories]' => $result['categories_synced'],
                    '[products]' => $result['products_synced'],
                ])
            );
        } else {
            fn_set_notification(
                'E',
                __('error'),
                __('pim_sync.sync_failed') . ': ' . implode(', ', $result['errors'])
            );
        }

        return [CONTROLLER_STATUS_OK, 'pim_sync.manage'];
    }

    if ($mode == 'sync_delta') {
        // Starting incremental synchronization
        $days = ! empty($_REQUEST['days']) ? intval($_REQUEST['days']) : 1;
        $result = fn_pim_sync_delta($days);
        if ($result['success']) {
            fn_set_notification(
                'N',
                __('notice'),
                __('pim_sync.delta_sync_completed', [
                    '[products]' => $result['products_updated'],
                ])
            );
        } else {
            fn_set_notification(
                'E',
                __('error'),
                __('pim_sync.sync_failed') . ': ' . implode(', ', $result['errors'])
            );
        }

        return [CONTROLLER_STATUS_OK, 'pim_sync.manage'];
    }

    if ($mode == 'test_connection') {
        // API connection test
        try {
            $settings = fn_pim_sync_get_settings();
            $client = new PimApiClient(
                $settings['api_url'],
                $settings['api_login'],
                $settings['api_password']
            );

            if ($client->testConnection()) {
                fn_set_notification('N', __('notice'), __('pim_sync.connection_successful'));
            } else {
                fn_set_notification('E', __('error'), __('pim_sync.connection_failed'));
            }
        } catch (Exception $e) {
            fn_set_notification(
                'E',
                __('error'),
                __('pim_sync.connection_failed') . ': ' . $e->getMessage()
            );
        }

        return [CONTROLLER_STATUS_OK, 'pim_sync.manage'];
    }

    if ($mode == 'clear_logs') {
        // Cleaning up old logs
        $days = ! empty($_REQUEST['days_to_keep']) ? intval($_REQUEST['days_to_keep']) : 30;
        fn_pim_sync_cleanup_logs($days);
        fn_set_notification('N', __('notice'), __('pim_sync.logs_cleared'));

        return [CONTROLLER_STATUS_OK, 'pim_sync.manage'];
    }
}

if ($mode == 'manage') {
    // Getting the settings
    $settings = fn_pim_sync_get_settings();
    Registry::get('view')->assign('pim_settings', $settings);
    // Getting the latest entries from the journal
    $log_entries = fn_pim_sync_get_log_entries(20);
    Registry::get('view')->assign('log_entries', $log_entries);
    // We get statistics
    $stats = [
        'total_categories' => db_get_field("SELECT COUNT(*) FROM ?:pim_sync_state WHERE entity_type = 'category' AND sync_status = 'synced'"),
        'total_products' => db_get_field("SELECT COUNT(*) FROM ?:pim_sync_state WHERE entity_type = 'product' AND sync_status = 'synced'"),
        'pending_sync' => db_get_field("SELECT COUNT(*) FROM ?:pim_sync_state WHERE sync_status = 'pending'"),
        'sync_errors' => db_get_field("SELECT COUNT(*) FROM ?:pim_sync_state WHERE sync_status = 'error'"),
        'last_sync' => db_get_field("SELECT MAX(completed_at) FROM ?:pim_sync_log WHERE status = 'completed'"),
    ];
    Registry::get('view')->assign('sync_stats', $stats);
    // Check if there is a running synchronization
    $running_sync = db_get_row("SELECT * FROM ?:pim_sync_log WHERE status = 'running' ORDER BY log_id DESC LIMIT 1");
    if ($running_sync) {
        Registry::get('view')->assign('running_sync', $running_sync);
    }
}

if ($mode == 'log_details') {
    // View log details
    $log_id = ! empty($_REQUEST['log_id']) ? intval($_REQUEST['log_id']) : 0;
    $log_entry = db_get_row("SELECT * FROM ?:pim_sync_log WHERE log_id = ?i", $log_id);
    if ($log_entry) {
        Registry::get('view')->assign('log_entry', $log_entry);

        // We are getting synchronization errors for this period
        if ($log_entry['status'] == 'failed' || $log_entry['error_details']) {
            $sync_errors = db_get_array(
                "SELECT * FROM ?:pim_sync_state 
                 WHERE sync_status = 'error' 
                 AND last_sync >= ?s 
                 AND last_sync <= ?s 
                 LIMIT 50",
                $log_entry['started_at'],
                $log_entry['completed_at'] ?: date('Y-m-d H:i:s')
            );
            Registry::get('view')->assign('sync_errors', $sync_errors);
        }
    }
}

// Registering tabs
Registry::set('navigation.tabs', [
    'general' => [
        'title' => __('general'),
        'js' => true,
    ],
    'logs' => [
        'title' => __('pim_sync.sync_logs'),
        'js' => true,
    ],
    'settings' => [
        'title' => __('settings'),
        'js' => true,
    ],
]);
