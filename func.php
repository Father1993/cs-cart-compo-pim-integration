<?php
/**
* @file: func.php
* @description: Main features of PIM Sync addon
* @dependencies: CS-Cart core
* @created: 2025-06-27
*/

if (!defined('BOOTSTRAP')) { die('Access denied'); }

use Tygh\Registry;
use Tygh\Addons\PimSync\PimApiClient;
use Tygh\Addons\PimSync\CategorySync;
use Tygh\Addons\PimSync\ProductSync;

/**
* Get PIM connection settings
* @return array
*/
function fn_pim_sync_get_settings()
{
    return [
        'api_url' => Registry::get('addons.pim_sync.api_url'),
        'api_login' => Registry::get('addons.pim_sync.api_login'),
        'api_password' => Registry::get('addons.pim_sync.api_password'),
        'catalog_uid' => Registry::get('addons.pim_sync.catalog_uid'),
        'sync_enabled' => Registry::get('addons.pim_sync.sync_enabled') === 'Y',
        'sync_interval' => (int)Registry::get('addons.pim_sync.sync_interval')
    ];
}

/**
* Logging of synchronization operations
* @param string $message
* @param string $level
*/
function fn_pim_sync_log($message, $level = 'info')
{
    $log_file = Registry::get('config.dir.var') . 'pim_sync.log';
    $timestamp = date('Y-m-d H:i:s');
    $log_entry = "[$timestamp] [$level] $message" . PHP_EOL;
    file_put_contents($log_file, $log_entry, FILE_APPEND | LOCK_EX);
    if ($level === 'error' || $level === 'critical') {
        fn_log_event('pim_sync', $level, ['message' => $message]);
    }
}

/**
* Create a sync log entry
* @param string $sync_type
* @return int
*/
function fn_pim_sync_create_log_entry($sync_type = 'manual')
{
    $data = [
        'sync_type' => $sync_type,
        'started_at' => date('Y-m-d H:i:s'),
        'status' => 'running'
    ];
    db_query('INSERT INTO ?:pim_sync_log ?e', $data);
    return db_get_last_id();
}

/**
* Update sync log entry
* @param int $log_id
* @param array $data
*/
function fn_pim_sync_update_log_entry($log_id, $data)
{
    db_query('UPDATE ?:pim_sync_log SET ?u WHERE log_id = ?i', $data, $log_id);
}

/**
* Get or create a link between PIM UID and CS-Cart ID
* @param string $entity_type
* @param string $entity_uid
* @return int|false
*/
function fn_pim_sync_get_cs_cart_id($entity_type, $entity_uid)
{
    $cs_cart_id = db_get_field(
        'SELECT cs_cart_id FROM ?:pim_sync_state WHERE entity_type = ?s AND entity_uid = ?s',
        $entity_type, $entity_uid
    );
    
    return $cs_cart_id ?: false;
}

/**
* Keep PIM UID linked to CS-Cart ID
* @param string $entity_type
* @param string $entity_uid
* @param int $cs_cart_id
* @param string $status
*/
function fn_pim_sync_save_mapping($entity_type, $entity_uid, $cs_cart_id, $status = 'synced')
{
    $data = [
        'entity_type' => $entity_type,
        'entity_uid' => $entity_uid,
        'cs_cart_id' => $cs_cart_id,
        'sync_status' => $status,
        'last_sync' => date('Y-m-d H:i:s')
    ];
    db_replace_into('pim_sync_state', $data);
}

/**
* Perform a full synchronization
* @return array
*/
function fn_pim_sync_full()
{
    $result = [
        'success' => false,
        'categories_synced' => 0,
        'products_synced' => 0,
        'errors' => []
    ];
    
    try {
        $log_id = fn_pim_sync_create_log_entry('full');
        $settings = fn_pim_sync_get_settings();
        $client = new PimApiClient(
            $settings['api_url'],
            $settings['api_login'],
            $settings['api_password']
        );
        fn_pim_sync_log('Начинаем полную синхронизацию категорий');
        $category_sync = new CategorySync($client);
        $categories_result = $category_sync->syncAll($settings['catalog_uid']);
        $result['categories_synced'] = $categories_result['synced'];
        fn_pim_sync_log('Начинаем полную синхронизацию товаров');
        $product_sync = new ProductSync($client);
        $products_result = $product_sync->syncAll($settings['catalog_uid']);
        $result['products_synced'] = $products_result['synced'];
        fn_pim_sync_update_log_entry($log_id, [
            'completed_at' => date('Y-m-d H:i:s'),
            'status' => 'completed',
            'affected_categories' => $result['categories_synced'],
            'affected_products' => $result['products_synced']
        ]);
        
        $result['success'] = true;
        fn_pim_sync_log("Полная синхронизация завершена. Категорий: {$result['categories_synced']}, Товаров: {$result['products_synced']}");
        
    } catch (Exception $e) {
        $result['errors'][] = $e->getMessage();
        fn_pim_sync_log('Ошибка при полной синхронизации: ' . $e->getMessage(), 'error');
        
        if (isset($log_id)) {
            fn_pim_sync_update_log_entry($log_id, [
                'completed_at' => date('Y-m-d H:i:s'),
                'status' => 'failed',
                'error_details' => $e->getMessage()
            ]);
        }
    }
    return $result;
}

/**
* Perform incremental synchronization
* @param int $days Number of days to check for changes
* @return array
*/
function fn_pim_sync_delta($days = 1)
{
    $result = [
        'success' => false,
        'products_updated' => 0,
        'errors' => []
    ];
    
    try {
        $log_id = fn_pim_sync_create_log_entry('delta');
        $settings = fn_pim_sync_get_settings();
        $client = new PimApiClient(
            $settings['api_url'],
            $settings['api_login'],
            $settings['api_password']
        );
        fn_pim_sync_log("Начинаем инкрементальную синхронизацию за последние $days дней");
        $product_sync = new ProductSync($client);
        $products_result = $product_sync->syncChanged($settings['catalog_uid'], $days);
        $result['products_updated'] = $products_result['updated'];
        fn_pim_sync_update_log_entry($log_id, [
            'completed_at' => date('Y-m-d H:i:s'),
            'status' => 'completed',
            'affected_products' => $result['products_updated']
        ]);
        $result['success'] = true;
        fn_pim_sync_log("Инкрементальная синхронизация завершена. Обновлено товаров: {$result['products_updated']}");
        
    } catch (Exception $e) {
        $result['errors'][] = $e->getMessage();
        fn_pim_sync_log('Ошибка при инкрементальной синхронизации: ' . $e->getMessage(), 'error');
        if (isset($log_id)) {
            fn_pim_sync_update_log_entry($log_id, [
                'completed_at' => date('Y-m-d H:i:s'),
                'status' => 'failed',
                'error_details' => $e->getMessage()
            ]);
        }
    }
    
    return $result;
}

/**
* Get the latest entries from the sync log
* @param int $limit
* @return array
*/
function fn_pim_sync_get_log_entries($limit = 10)
{
    return db_get_array(
        'SELECT * FROM ?:pim_sync_log ORDER BY log_id DESC LIMIT ?i',
        $limit
    );
}

/**
* Clear old log entries
* @param int $days_to_keep
*/
function fn_pim_sync_cleanup_logs($days_to_keep = 30)
{
    $date_threshold = date('Y-m-d H:i:s', strtotime("-$days_to_keep days"));
    db_query(
        'DELETE FROM ?:pim_sync_log WHERE completed_at < ?s AND status != ?s',
        $date_threshold, 'running'
    );
}

/**
* Hook: After product update
* @param array $product_data
* @param int $product_id
* @param string $lang_code
* @param bool $create
*/
function fn_pim_sync_update_product_post($product_data, $product_id, $lang_code, $create)
{
    // Для будущей реализации двусторонней синхронизации
}

/**
* Hook: After item is removed
* @param int $product_id
*/
function fn_pim_sync_delete_product_post($product_id)
{
    // Удаляем связь при удалении товара
    db_query("DELETE FROM ?:pim_sync_state WHERE entity_type = 'product' AND cs_cart_id = ?i", $product_id);
}

/**
* Hook: After category update
* @param array $category_data
* @param int $category_id
* @param string $lang_code
*/
function fn_pim_sync_update_category_post($category_data, $category_id, $lang_code)
{
    // Для будущей реализации двусторонней синхронизации
}

/**
* Hook: After category deletion
* @param int $category_id
*/
function fn_pim_sync_delete_category_post($category_id)
{
    // Удаляем связь при удалении категории
    db_query("DELETE FROM ?:pim_sync_state WHERE entity_type = 'category' AND cs_cart_id = ?i", $category_id);
}

/**
* Hook: When receiving goods
* @param array $params
* @param array $fields
* @param array $sortings
* @param string $condition
* @param string $join
* @param string $sorting
* @param string $group_by
* @param string $lang_code
* @param array $having
*/
function fn_pim_sync_get_products(&$params, &$fields, &$sortings, &$condition, &$join, &$sorting, &$group_by, &$lang_code, &$having)
{
    // Можно добавить дополнительные поля из PIM при необходимости
}

/**
* Hook: When receiving categories
* @param array $params
* @param string $join
* @param string $condition
* @param array $fields
* @param string $group_by
* @param array $sortings
* @param string $lang_code
*/
function fn_pim_sync_get_categories(&$params, &$join, &$condition, &$fields, &$group_by, &$sortings, &$lang_code)
{
    // Можно добавить дополнительные поля из PIM при необходимости
} 