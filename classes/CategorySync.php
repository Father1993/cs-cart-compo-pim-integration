<?php

/**
* @file: CategorySync.php
* @description: Class for synchronizing categories from Compo PIM to CS-Cart
* @dependencies: PimApiClient
* @created: 2025-06-27
*/

namespace Tygh\Addons\PimSync;

use Exception;

class CategorySync
{
    private PimApiClient $api_client;
    private array $categories_map = [];
    private int $synced_count = 0;

    /**
    * Constructor
    * @param PimApiClient $api_client
    */
    public function __construct(PimApiClient $api_client)
    {
        $this->api_client = $api_client;
    }

    /**
    * Sync all categories
    * @param string $catalog_uid
    * @return array
    */
    public function syncAll($catalog_uid)
    {
        $result = [
            'synced' => 0,
            'errors' => [],
        ];

        try {
            fn_pim_sync_log("Начинаем синхронизацию категорий для каталога: $catalog_uid");
            $response = $this->api_client->getCatalogTree();
            if (! isset($response['data']) || ! is_array($response['data'])) {
                throw new Exception('Invalid catalog tree response');
            }
            $categories = array_filter($response['data'], function ($category) use ($catalog_uid) {
                return isset($category['catalogs']) && in_array($catalog_uid, $category['catalogs']);
            });
            fn_pim_sync_log("Найдено категорий для синхронизации: " . count($categories));
            foreach ($categories as $category) {
                $this->categories_map[$category['syncUid']] = $category;
            }
            foreach ($categories as $category) {
                if (empty($category['parentUid'])) {
                    $this->syncCategory($category);
                }
            }
            foreach ($categories as $category) {
                if (! empty($category['parentUid'])) {
                    $this->syncCategory($category);
                }
            }
            $result['synced'] = $this->synced_count;
            fn_pim_sync_log("Синхронизация категорий завершена. Обработано: {$this->synced_count}");
        } catch (Exception $e) {
            $result['errors'][] = $e->getMessage();
            fn_pim_sync_log("Ошибка при синхронизации категорий: " . $e->getMessage(), 'error');
        }

        return $result;
    }

    /**
     * Sync one category
     * @param array $pim_category
     * @return int|false
     */
    private function syncCategory($pim_category)
    {
        try {
            $cs_cart_id = fn_pim_sync_get_cs_cart_id('category', $pim_category['syncUid']);
            $parent_id = 0;
            if (! empty($pim_category['parentUid'])) {
                $parent_id = fn_pim_sync_get_cs_cart_id('category', $pim_category['parentUid']);
                if (! $parent_id) {
                    fn_pim_sync_log(
                        "Пропускаем категорию {$pim_category['header']}, родитель еще не синхронизирован",
                        'warning'
                    );

                    return false;
                }
            }
            $category_data = $this->mapPimToCSCart($pim_category, $parent_id);
            if ($cs_cart_id) {
                $category_data['category_id'] = $cs_cart_id;
                fn_update_category($category_data, $cs_cart_id, DESCR_SL);
                fn_pim_sync_log("Обновлена категория ID $cs_cart_id: {$pim_category['header']}");
            } else {
                $cs_cart_id = fn_update_category($category_data, 0, DESCR_SL);
                fn_pim_sync_log("Создана новая категория ID $cs_cart_id: {$pim_category['header']}");
            }
            if ($cs_cart_id) {
                fn_pim_sync_save_mapping('category', $pim_category['syncUid'], $cs_cart_id, 'synced');
                $this->synced_count++;

                return $cs_cart_id;
            }
        } catch (Exception $e) {
            fn_pim_sync_log("Ошибка при синхронизации категории {$pim_category['header']}: " . $e->getMessage(), 'error');
            fn_pim_sync_save_mapping('category', $pim_category['syncUid'], 0, 'error');
        }

        return false;
    }

    /**
    * Mapping data from PIM to CS-Cart format
    * @param array $pim_category
    * @param int $parent_id
    * @return array
    */
    private function mapPimToCSCart($pim_category, $parent_id = 0)
    {
        return [
            'category' => $pim_category['header'],
            'parent_id' => $parent_id,
            'position' => $pim_category['pos'] ?? 0,
            'status' => $pim_category['enabled'] ? 'A' : 'D',
            'timestamp' => time(),
            'description' => '',
            'meta_keywords' => '',
            'meta_description' => '',
            'page_title' => $pim_category['header'],
        ];
    }

    /**
     * Get sync statistics
     * @return array
     */
    public function getStats()
    {
        return [
            'synced' => $this->synced_count,
            'map_size' => count($this->categories_map),
        ];
    }
}
