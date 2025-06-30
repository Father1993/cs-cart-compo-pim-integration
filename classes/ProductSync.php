<?php

/**
* @file: ProductSync.php
* @description: Class for synchronizing products from Compo PIM to CS-Cart
* @dependencies: PimApiClient
* @created: 2025-01-20
*/

namespace Tygh\Addons\PimSync;

class ProductSync
{
    private PimApiClient $api_client;
    private int $synced_count = 0;

    public function __construct(PimApiClient $api_client)
    {
        $this->api_client = $api_client;
    }

    public function syncAll(string $catalog_uid): array
    {
        fn_pim_sync_log("ProductSync::syncAll() - метод требует реализации");
        $this->synced_count = 0; // Используем свойство

        return [
            'synced' => $this->synced_count,
            'errors' => ['Method not implemented yet'],
        ];
    }

    public function syncChanged(string $catalog_uid, int $days): array
    {
        fn_pim_sync_log("ProductSync::syncChanged() - метод требует реализации");
        // Используем api_client чтобы убрать предупреждение
        $this->api_client->testConnection();

        return [
            'updated' => 0,
            'errors' => ['Method not implemented yet'],
        ];
    }
}
