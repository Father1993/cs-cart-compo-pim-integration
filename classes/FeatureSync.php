<?php

/**
 * @file: FeatureSync.php
 * @description: Class for synchronizing product characteristics from PIM to CS-Cart
 * @dependencies: PimApiClient
 * @created: 2025-06-30
 */

namespace Tygh\Addons\PimSync;

use Exception;

class FeatureSync
{
    private PimApiClient $api_client;
    private array $feature_variants = [];
    private array $stats = [
      'created_features' => 0,
      'created_variants' => 0,
      'updated_features' => 0,
      'processed_features' => 0,
    ];
    /**
    * @phpstan-ignore-next-line
    */
    private array $created_features = [];

    /**
    * Constructor
    * @param PimApiClient $api_client
    */
    public function __construct(PimApiClient $api_client)
    {
        $this->api_client = $api_client;
    }

    /**
    * Synchronize product characteristics
    * @param int $product_id
    * @param array $product_params Array of product characteristics from COMPO PIM
    * @return bool
    */
    public function syncProductFeatures($product_id, $product_params): bool
    {
        try {
            if (empty($product_params) || ! is_array($product_params)) {
                fn_pim_sync_log("Характеристики для товара {$product_id} отсутствуют", 'debug');

                return true;
            }
            fn_pim_sync_log("Начинаем синхронизацию " . count($product_params) . " характеристик для товара {$product_id}", 'info');
            // Delete old values ​​of product characteristics
            db_query("DELETE FROM ?:product_features_values WHERE product_id = ?i", $product_id);
            foreach ($product_params as $param) {
                $this->stats['processed_features']++;
                if (empty($param['paramUid']) || empty($param['values'])) {
                    fn_pim_sync_log("Пропускаем характеристику без UID или значений", 'warning');

                    continue;
                }
                $feature_id = $this->syncFeature($param['paramUid']);
                if (! $feature_id) {
                    fn_pim_sync_log("Не удалось синхронизировать характеристику {$param['paramUid']}", 'error');

                    continue;
                }
                $this->syncFeatureValues($product_id, $feature_id, $param['values']);
            }
            fn_pim_sync_log("Завершена синхронизация характеристик для товара {$product_id}. Создано характеристик: {$this->stats['created_features']}, вариантов: {$this->stats['created_variants']}", 'info');

            return true;
        } catch (Exception $e) {
            fn_pim_sync_log("Ошибка синхронизации характеристик для товара {$product_id}: " . $e->getMessage(), 'error');

            return false;
        }
    }

    /**
    * Synchronize one characteristic
    * @param string $feature_uid UID of the feature in Compo PIM
    * @return int|false ID of the characteristic in CS-Cart or false on error
    */
    private function syncFeature($feature_uid): int|false
    {
        try {
            // Let's check if such a characteristic already exists
            $cs_cart_id = fn_pim_sync_get_cs_cart_id('feature', $feature_uid);
            if ($cs_cart_id) {
                return $cs_cart_id;
            }
            // We obtain these characteristics from PIM
            $feature_data = $this->api_client->getFeatureByUid($feature_uid);
            if (! isset($feature_data['data'])) {
                fn_pim_sync_log("Не удалось получить данные характеристики {$feature_uid} из PIM", 'error');

                return false;
            }
            $pim_feature = $feature_data['data'];
            // Determine the CS-Cart characteristic type by PIM type
            $feature_type = $this->mapFeatureType($pim_feature['featureType'] ?? 'STRING');
            // Create a characteristic
            $feature_data_cs = [
                'feature_type' => $feature_type,
                'parent_id' => 0,
                'display_on_product' => 'Y',
                'display_on_catalog' => 'Y',
                'display_on_header' => 'N',
                'status' => 'A',
                'comparison' => ($pim_feature['isFilter'] ?? false) ? 'Y' : 'N',
                'position' => $pim_feature['sort'] ?? 0,
                'description' => [
                    'ru' => [
                        'description' => $pim_feature['header'] ?? "Характеристика {$feature_uid}",
                        'prefix' => '',
                        'suffix' => $pim_feature['unit'] ?? '',
                        'full_description' => $pim_feature['description'] ?? '',
                    ],
                ],
            ];

            $feature_id = fn_update_product_feature($feature_data_cs, 0);
            if ($feature_id) {
                // Save the connection in the synchronization table
                fn_pim_sync_save_mapping('feature', $feature_uid, $feature_id, 'synced');
                $this->stats['created_features']++;
                $this->created_features[$feature_uid] = $feature_id;
                fn_pim_sync_log("Создана характеристика '{$pim_feature['header']}' (ID: {$feature_id}, UID: {$feature_uid})", 'info');

                return $feature_id;
            }
        } catch (Exception $e) {
            fn_pim_sync_log("Ошибка создания характеристики {$feature_uid}: " . $e->getMessage(), 'error');
        }

        return false;
    }

    /**
    * Синхронизировать значения характеристики для товара
    * @param int $product_id
    * @param int $feature_id
    * @param array $values
    */
    private function syncFeatureValues($product_id, $feature_id, $values): void
    {
        try {
            // Get type characteristics
            /**
            * @phpstan-ignore-next-line
            */
            $feature = db_get_row(
                "SELECT feature_type FROM ?:product_features WHERE feature_id = ?i",
                $feature_id
            );
            if (! $feature) {
                fn_pim_sync_log("Характеристика {$feature_id} не найдена", 'error');

                return;
            }
            foreach ($values as $value) {
                if (empty($value)) {
                    continue;
                }
                $feature_value_data = [
                    'product_id' => $product_id,
                    'feature_id' => $feature_id,
                    'lang_code' => 'ru',
                ];
                // Depending on the type of characteristic, we save the value
                switch ($feature['feature_type']) {
                    case 'N': // Число
                        $feature_value_data['value_int'] = intval($value);

                        break;
                    case 'S': // Selectbox - create a variant
                    case 'M': // Multiple choice
                    case 'E': // Extended version
                        $variant_id = $this->createFeatureVariant($feature_id, $value);
                        if ($variant_id) {
                            $feature_value_data['variant_id'] = $variant_id;
                        }

                        break;
                    case 'C': // Чекбокс
                        $feature_value_data['value'] = ($value === 'true' || $value === '1' || $value === 'да') ? 'Y' : 'N';

                        break;
                    case 'T': // Текст
                    case 'O': // Число+единица измерения
                    default:
                        $feature_value_data['value'] = strval($value);

                        break;
                }
                // We save the value
                db_replace_into('product_features_values', $feature_value_data);
            }

        } catch (Exception $e) {
            fn_pim_sync_log("Ошибка сохранения значений характеристики {$feature_id}: " . $e->getMessage(), 'error');
        }
    }

    /**
    * Create a feature variant
    * @param int $feature_id
    * @param string $variant_name
    * @return int|false ID варианта
    */
    private function createFeatureVariant($feature_id, $variant_name): int|false
    {
        try {
            $variant_key = $feature_id . '_' . md5($variant_name);
            // Let's check if there is already such an option
            if (isset($this->feature_variants[$variant_key])) {
                return (int)$this->feature_variants[$variant_key];
            }
            // We are looking for an existing option
            $existing_variant = db_get_field(
                "SELECT variant_id FROM ?:product_feature_variant_descriptions 
                WHERE feature_id = ?i AND variant = ?s AND lang_code = ?s",
                $feature_id,
                $variant_name,
                'ru'
            );
            if ($existing_variant) {
                $variant_id = (int)$existing_variant;
                $this->feature_variants[$variant_key] = $variant_id;

                return $variant_id;
            }
            // We create a new variant
            $variant_data = [
                'feature_id' => $feature_id,
                'url' => '',
                'color' => '',
                'position' => 0,
            ];
            $variant_id = db_query("INSERT INTO ?:product_feature_variants ?e", $variant_data);
            if ($variant_id) {
                // Adding a description of the option
                $variant_description = [
                    'variant_id' => $variant_id,
                    'feature_id' => $feature_id,
                    'variant' => $variant_name,
                    'description' => '',
                    'page_title' => '',
                    'meta_keywords' => '',
                    'meta_description' => '',
                    'lang_code' => 'ru',
                ];
                db_replace_into('product_feature_variant_descriptions', $variant_description);
                // Гарантируем, что это число
                $variant_id = (int)$variant_id;
                $this->feature_variants[$variant_key] = $variant_id;
                $this->stats['created_variants']++;

                return $variant_id;
            }
        } catch (Exception $e) {
            fn_pim_sync_log("Ошибка создания варианта характеристики: " . $e->getMessage(), 'error');
        }

        return false;
    }

    /**
    * Mapping PIM characteristic types -> CS-Cart
    * @param string $pim_type
    * @return string
    */
    private function mapFeatureType($pim_type): string
    {
        $type_mapping = [
            'STRING' => 'T',        // text
            'NUMBER' => 'N',        // int
            'BOOLEAN' => 'C',       // checkbox
            'ENUM' => 'S',          // select
            'MULTI_ENUM' => 'M',    // list
            'DATE' => 'T',          // string date
            'DECIMAL' => 'O',        // int
        ];

        return $type_mapping[$pim_type] ?? 'T';
    }

    /**
    * Get sync statistics
    * @return array
    */
    public function getStats(): array
    {
        return $this->stats;
    }

    /**
    * Reset statistics
    */
    public function resetStats(): void
    {
        $this->stats = [
            'created_features' => 0,
            'created_variants' => 0,
            'updated_features' => 0,
            'processed_features' => 0,
        ];
        $this->created_features = [];
        $this->feature_variants = [];
    }
}
