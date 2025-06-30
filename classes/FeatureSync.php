<?php

/**
 * @file: FeatureSync.php
 * @description: Class for synchronizing product characteristics from PIM to CS-Cart
 * @dependencies: PimApiClient
 * @created: 2025-06-30
 */

namespace Tygh\Addons\PimSync;

class FeatureSync
{
    /** @var PimApiClient */
    private $api_client;

    /** @var int */
    private $created_features = 0;

    /** @var int */
    private $created_variants = 0;

    /** @var int */
    private $processed_features = 0;

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
     * @param array $params
     * @return bool
     */
    public function syncProductFeatures(int $product_id, array $params): bool
    {
        // Заглушка для метода, который вы позже реализуете
        $this->processed_features += count($params);

        return true;
    }

    /**
     * Get sync statistics
     * @return array
     */
    public function getStats(): array
    {
        return [
            'created_features' => $this->created_features,
            'created_variants' => $this->created_variants,
            'processed_features' => $this->processed_features,
        ];
    }
}
