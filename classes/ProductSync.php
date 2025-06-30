<?php

/**
* @file: ProductSync.php
* @description: Class for synchronizing products from Compo PIM to CS-Cart
* @dependencies: PimApiClient
* @created: 2025-06-30
*/

namespace Tygh\Addons\PimSync;

use Exception;
use Tygh\Addons\CommerceML\Tests\Unit\CatalogImportTest;

class ProductSync
{
    private $api_client;
    private $feature_sync;
    private $synced_count = 0;
    private $batch_size = 100;

    /**
     * Contruct
     * @param PimApiClient $api_client
     */

     public function __construct(PimApiClient $api_client)
     {
        $this->api_client = $api_client;
        $this->feature_sync = new FeatureSync($api_client);
     }

     /**
      * Synchronizing all goods
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
              fn_pim_sync_log("Начинаем полную синхронизацию товаров");
  
              $scroll_id = null;
              $batch_num = 0;
  
              do {
                  $batch_num++;
                  fn_pim_sync_log("Получаем batch #$batch_num товаров");
  
                  // Get another batch of goods
                  $response = $this->api_client->scrollProducts($scroll_id, [
                      'catalogId' => $catalog_uid,
                  ]);
  
                  if (! isset($response['data']['products']) || ! is_array($response['data']['products'])) {
                      break;
                  }
  
                  $products = $response['data']['products'];
                  $products_count = count($products);
  
                  if ($products_count === 0) {
                      break;
                  }
  
                  fn_pim_sync_log("Получено товаров в batch #$batch_num: $products_count");
  
                  $this->processBatch($products);
                  $scroll_id = $response['data']['scrollId'] ?? null;
  
                  // Clear memory
                  unset($products);
                  unset($response);
  
              } while ($scroll_id !== null);
  
              $result['synced'] = $this->synced_count;
              fn_pim_sync_log("Полная синхронизация товаров завершена. Обработано: {$this->synced_count}");
  
          } catch (Exception $e) {
              $result['errors'][] = $e->getMessage();
              fn_pim_sync_log("Ошибка при синхронизации товаров: " . $e->getMessage(), 'error');
          }
  
          return $result;
      }

      /**
     * Synchronize changed products
     * @param string $catalog_uid
     * @param int $days
     * @return array
     */

     public function syncChanged($catalog_uid, $days = 1)
     {
        $result = [
            'updated' => 0,
            'errors' => [],
        ];

        try {
            fn_pim_sync_log("Начинаем синхронизацию измененных товаров за $days дней");

            $scroll_id = null;
            $updated_count = 0;

            do {
                // Get changes goods
                $response = $this->api_client->scrollProducts($scroll_id, [
                    'catalogId' => $catalog_uid,
                    'day' => $days,
                ]);

                if (! isset($response['data']['products']) || ! is_array($response['data']['products'])) {
                    break;
                }

                $products = $response['data']['products'];
                $products_count = count($products);

                if ($products_count === 0) {
                    break;
                }

                fn_pim_sync_log("Найдено измененных товаров: $products_count");

                $batch_result = $this->processBatch($products);
                $updated_count += $batch_result['synced'];
                $scroll_id = $response['data']['scrollId'] ?? null;
                unset($products);
                unset($response);

            } while ($scroll_id !== null);

            $result['updated'] = $updated_count;
            fn_pim_sync_log("Синхронизация измененных товаров завершена. Обновлено: $updated_count");

        } catch (Exception $e) {
            $result['errors'][] = $e->getMessage();
            fn_pim_sync_log("Ошибка при синхронизации измененных товаров: " . $e->getMessage(), 'error');
        }

        return $result;
     }

     /**
     * Process a batch of goods
     * @param array $products
     * @return array
     */

     private function processBatch($products)
     {
         $result = [
             'synced' => 0,
             'errors' => [],
         ];
 
         foreach ($products as $product) {
             try {
                 if ($this->syncProduct($product)) {
                     $result['synced']++;
                 }
             } catch (Exception $e) {
                 $result['errors'][] = "Product {$product['syncUid']}: " . $e->getMessage();
             }
         }
 
         return $result;
     }

     /**
     * Synchronize one product
     * @param array $pim_product
     * @return bool
     */

     private function syncProduct($pim_product)
     {
         try {
             // Validation of required fields
             if (empty($pim_product['syncUid'])) {
                 fn_pim_sync_log("Товар без syncUid, пропускаем", 'error');
 
                 return false;
             }
 
             if (empty($pim_product['header'])) {
                 fn_pim_sync_log("Товар {$pim_product['syncUid']} без названия, пропускаем", 'error');
 
                 return false;
             }
 
             // Checking the product category
             if (empty($pim_product['catalogUid'])) {
                 fn_pim_sync_log("Товар {$pim_product['header']} не имеет категории, пропускаем", 'warning');
 
                 return false;
             }
 
             // Getting Category ID in CS-Cart
             $category_id = fn_pim_sync_get_cs_cart_id('category', $pim_product['catalogUid']);
             if (! $category_id) {
                 fn_pim_sync_log("Категория для товара {$pim_product['header']} не найдена, пропускаем", 'warning');
 
                 return false;
             }
 
             // Checking if the product already exists
             $cs_cart_id = fn_pim_sync_get_cs_cart_id('product', $pim_product['syncUid']);
 
             // Подготавливаем данные для CS-Cart
             $product_data = $this->mapPimToCSCart($pim_product, $category_id);
 
             if ($cs_cart_id) {
                 // Updating an existing product
                 $product_data['product_id'] = $cs_cart_id;
                 fn_update_product($product_data);
                 fn_pim_sync_log("Обновлен товар ID $cs_cart_id: {$pim_product['header']}");
             } else {
                 // Create new products
                 $cs_cart_id = fn_update_product($product_data);
                 fn_pim_sync_log("Создан новый товар ID $cs_cart_id: {$pim_product['header']}");
             }
 
             // Processing images
             if ($cs_cart_id && ! empty($pim_product['picture'])) {
                 $this->syncProductImages($cs_cart_id, $pim_product);
             }
 
             // Processing features
             if ($cs_cart_id && ! empty($pim_product['params'])) {
                 $this->syncProductFeatures($cs_cart_id, $pim_product['params']);
             }
 
             // Processing manufacturer
             if ($cs_cart_id && ! empty($pim_product['manufacturer'])) {
                 $this->syncManufacturer($cs_cart_id, $pim_product);
             }
 
             // Keeping in touch
             if ($cs_cart_id) {
                 fn_pim_sync_save_mapping('product', $pim_product['syncUid'], $cs_cart_id, 'synced');
                 $this->synced_count++;
 
                 return true;
             }
         } catch (Exception $e) {
             fn_pim_sync_log("Ошибка при синхронизации товара {$pim_product['header']}: " . $e->getMessage(), 'error');
             fn_pim_sync_save_mapping('product', $pim_product['syncUid'], 0, 'error');
         }
         return false;
     }

        /**
     * Mapping data from PIM to CS-Cart format
     * @param array $pim_product
     * @param int $category_id
     * @return array
     */

     private function mapPimToCSCart($pim_product, $category_id)
    {
        // Processing product codes
        $product_code = $pim_product['articul'] ?? '';
        if (empty($product_code) && ! empty($pim_product['codes'])) {
            // Take the first available code
            $product_code = reset($pim_product['codes']);
        }

        // Convert weight to grams (if in PIM in kg)
        $weight = floatval($pim_product['weight'] ?? 0) * 1000;

        // Convert sizes to cm (if in PIM in mm)
        $width = intval($pim_product['width'] ?? 0) / 10;
        $height = intval($pim_product['height'] ?? 0) / 10;
        $length = intval($pim_product['length'] ?? 0) / 10;

        // Determining the status by productStatus
        $status = 'A';
        if (isset($pim_product['productStatus'])) {
            $status = ($pim_product['productStatus'] === 'ACTIVE') ? 'A' : 'D';
        } elseif (isset($pim_product['enabled'])) {
            $status = $pim_product['enabled'] ? 'A' : 'D';
        }

        // Merging descriptions
        $full_description = $pim_product['fullHeader'] ?? '';
        if (! empty($pim_product['content'])) {
            $full_description .= "\n\n" . $pim_product['content'];
        }

        return [
            'product' => $pim_product['header'],
            'product_code' => $product_code,
            'status' => $status,
            'list_price' => floatval($pim_product['price'] ?? 0),
            'weight' => $weight,
            'tracking' => 'B', // Tracking balances
            'timestamp' => time(),
            'min_qty' => intval($pim_product['minOrderQuantity'] ?? 1),
            'qty_step' => intval($pim_product['multiplicityOrder'] ?? 1),
            'barcode' => $pim_product['barCode'] ?? '',
            'shipping_params' => serialize([
                'min_items_in_box' => 1,
                'max_items_in_box' => 1,
                'box_length' => $length,
                'box_width' => $width,
                'box_height' => $height,
            ]),
            'main_category' => $category_id,
            'category_ids' => [$category_id],
            'product_data' => [
                'ru' => [
                    'product' => $pim_product['header'],
                    'full_description' => $full_description,
                    'short_description' => $pim_product['description'] ?? '',
                    'meta_keywords' => '',
                    'meta_description' => '',
                    'page_title' => $pim_product['header'],
                    'search_words' => '',
                ],
            ],
        ];
    }

    /**
     * Sync product images
     * @param int $product_id
     * @param array $pim_product
     */
    private function syncProductImages($product_id, $pim_product)
    {
        try {
            $images_to_sync = [];

            // Основное изображение
            if (! empty($pim_product['picture'])) {
                $images_to_sync[] = [
                    'name' => $pim_product['picture'],
                    'type' => 'M', // Main image
                ];
            }

            // Дополнительные изображения
            if (! empty($pim_product['pictures']) && is_array($pim_product['pictures'])) {
                foreach ($pim_product['pictures'] as $picture_name) {
                    if ($picture_name !== $pim_product['picture']) {
                        $images_to_sync[] = [
                            'name' => $picture_name,
                            'type' => 'A', // Additional image
                        ];
                    }
                }
            }

            // Удаляем старые изображения
            fn_delete_image_pairs($product_id, 'product');

            // Загружаем новые изображения
            foreach ($images_to_sync as $index => $image) {
                $this->syncImage($product_id, $image['name'], $image['type'], $index);
            }

        } catch (Exception $e) {
            fn_pim_sync_log("Ошибка при синхронизации изображений товара ID $product_id: " . $e->getMessage(), 'error');
        }
    }

    /**
     * Download and save image
     * @param int $product_id
     * @param string $image_name
     * @param string $type
     * @param int $position
     */

     private function syncImage($product_id, $image_name, $type, $position = 0)
     {
        try {
            // Gen path for save
            $temp_path = fn_get_cache_path(false) . 'pim_sync/';
            fn_mkdir($temp_path);

            $file_path = $temp_path . $image_name . '.jpg';

            // Deownload image
            $this->api_client->downloadImage($image_name, $file_path);

            // Save to CS-CART
            // Copy file in tmp dir
            $import_path = fn_get_cache_path(false) . 'import/';
            fn_mkdir($import_path);
            $import_file = $import_path . basename($file_path);
            copy($file_path, $import_file);

            // Data from download
            $_REQUEST['file_product_main_image_icon'] = [];
            $_REQUEST['type_product_main_image_icon'] = [];

            $_REQUEST['file_product_main_image_icon'][] = $import_file;
            $_REQUEST['type_product_main_image_icon'][] = $type;

            // Download images
            fn_attach_image_pairs('product_main', 'product', $product_id, 'ru');

            unset($_REQUEST['file_product_main_image_icon']);
            unset($_REQUEST['type_product_main_image_icon']);
            @unlink($import_file);
            @unlink($file_path);

        } catch (Exception $e) {
            fn_pim_sync_log("Ошибка при загрузке изображения $image_name: " . $e->getMessage(), 'error');
        }
     }

     /**
     * Synchronize product characteristics
     * @param int $product_id
     * @param array $params
     */
     private function syncProductFeatures($product_id, $params)
     {
        return $this->feature_sync->syncProductFeatures($product_id, $params);
     }

     /**
     * Synchronize manufacturer as a characteristic
     * @param int $product_id
     * @param array $pim_product
     */
    private function syncManufacturer($product_id, $pim_product)
    {
        try {
            // Create an array of characteristics for the manufacturer
            $manufacturer_features = [];

            // Manufacturer
            if (! empty($pim_product['manufacturer'])) {
                $manufacturer_features[] = [
                    'paramUid' => 'manufacturer_brand',
                    'values' => [$pim_product['manufacturer']],
                ];
            }

            // Manufacturer series
            if (! empty($pim_product['manufacturerSeries'])) {
                $manufacturer_features[] = [
                    'paramUid' => 'manufacturer_series',
                    'values' => [$pim_product['manufacturerSeries']],
                ];
            }

            // Link to the manufacturer's website
            if (! empty($pim_product['manufacturerSiteLink'])) {
                $manufacturer_features[] = [
                    'paramUid' => 'manufacturer_site_link',
                    'values' => [$pim_product['manufacturerSiteLink']],
                ];
            }

            // Synchronize as normal characteristics
            if (! empty($manufacturer_features)) {
                $this->feature_sync->syncProductFeatures($product_id, $manufacturer_features);
            }

        } catch (Exception $e) {
            fn_pim_sync_log("Ошибка при синхронизации производителя для товара ID $product_id: " . $e->getMessage(), 'error');
        }
    }

    /**
     * Get sync statistics
     * @return array
     */
    public function getStats()
    {
        $feature_stats = $this->feature_sync->getStats();

        return [
            'synced' => $this->synced_count,
            'features_created' => $feature_stats['created_features'],
            'feature_variants_created' => $feature_stats['created_variants'],
            'features_processed' => $feature_stats['processed_features'],
        ];
    }
}
