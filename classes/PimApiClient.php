<?php
/**
 * @file: PimApiClient.php
 * @description: Client for Compo PIM API
 * @dependencies: cURL
 * @created: 2025-06-27
 */

namespace Tygh\Addons\PimSync;

use Exception;

class PimApiClient
{
    private $api_url;
    private $token;
    private $token_expires;
    private $login;
    private $password;
    
    /**
     * Конструктор
     * @param string $api_url
     * @param string $login
     * @param string $password
     */
    public function __construct($api_url, $login, $password)
    {
        $this->api_url = rtrim($api_url, '/');
        $this->login = $login;
        $this->password = $password;
        $this->authenticate();
    }
    
    /**
     * Authorization and obtaining a token
     * @throws Exception
     */
    private function authenticate()
    {
        try {
            $response = $this->makeRequest('/sign-in/', 'POST', [
                'login' => $this->login,
                'password' => $this->password,
                'remember' => true
            ], false);
            
            if ($response && isset($response['success']) && $response['success'] === true) {
                $this->token = $response['data']['access']['token'];
                // Токен живет 1 час, обновляем за 5 минут до истечения
                $this->token_expires = time() + 3300;
                fn_pim_sync_log('Успешная авторизация в PIM API');
            } else {
                throw new Exception('PIM API authentication failed: ' . json_encode($response));
            }
        } catch (Exception $e) {
            fn_pim_sync_log('Ошибка авторизации в PIM API: ' . $e->getMessage(), 'error');
            throw $e;
        }
    }
    
    /**
     * Make a request to the API
     * @param string $endpoint
     * @param string $method
     * @param mixed $data
     * @param bool $use_auth
     * @return array
     * @throws Exception
     */
    private function makeRequest($endpoint, $method = 'GET', $data = null, $use_auth = true)
    {
        // Check and update the token if necessary
        if ($use_auth && time() >= $this->token_expires) {
            fn_pim_sync_log('Токен истек, обновляем авторизацию');
            $this->authenticate();
        }
        
        $url = $this->api_url . $endpoint;
        $ch = curl_init();
        
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
        
        $headers = ['Content-Type: application/json'];
        if ($use_auth) {
            $headers[] = 'Authorization: Bearer ' . $this->token;
        }
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        
        if ($method === 'POST' && $data !== null) {
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
        }
        
        $response = curl_exec($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curl_error = curl_error($ch);
        curl_close($ch);
        
        if ($response === false) {
            throw new Exception('CURL error: ' . $curl_error);
        }
        
        $decoded = json_decode($response, true);
        
        if ($http_code !== 200) {
            throw new Exception('API error: HTTP ' . $http_code . ' - ' . $response);
        }
        
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new Exception('JSON decode error: ' . json_last_error_msg());
        }
        
        return $decoded;
    }
    
    /**
     * Get information about the catalog
     * @param string $catalog_uid
     * @return array
     * @throws Exception
     */
    public function getCatalog($catalog_uid)
    {
        fn_pim_sync_log("Получаем информацию о каталоге: $catalog_uid");
        return $this->makeRequest('/catalog/' . $catalog_uid);
    }
    
    /**
     * Get full tree products
     * @return array
     * @throws Exception
     */
    public function getCatalogTree()
    {
        fn_pim_sync_log("Получаем дерево категорий");
        return $this->makeRequest('/catalog');
    }
    
    /**
     * Get products by scroll API
     * @param string|null $scroll_id
     * @param array $params
     * @return array
     * @throws Exception
     */
    public function scrollProducts($scroll_id = null, $params = [])
    {
        $endpoint = '/product/scroll';
        $query_params = [];
        
        if ($scroll_id) {
            $query_params['scrollId'] = $scroll_id;
        }
        
        // add additional parameters
        if (!empty($params['catalogId'])) {
            $query_params['catalogId'] = $params['catalogId'];
        }
        
        if (!empty($params['day'])) {
            $query_params['day'] = $params['day'];
        }
        
        if (!empty($params['manufacturerId'])) {
            $query_params['manufacturerId'] = $params['manufacturerId'];
        }
        
        if (!empty($query_params)) {
            $endpoint .= '?' . http_build_query($query_params);
        }
        
        fn_pim_sync_log("Получаем товары через scroll API: $endpoint");
        return $this->makeRequest($endpoint);
    }
    
    /**
     * Get products info by ID
     * @param string $product_id
     * @return array
     * @throws Exception
     */
    public function getProduct($product_id)
    {
        fn_pim_sync_log("Получаем информацию о товаре: $product_id");
        return $this->makeRequest('/product/' . $product_id);
    }
    
    /**
     * Get products info by UID
     * @param string $product_uid
     * @return array
     * @throws Exception
     */
    public function getProductByUid($product_uid)
    {
        fn_pim_sync_log("Получаем информацию о товаре по UID: $product_uid");
        return $this->makeRequest('/product/uid/' . $product_uid);
    }
    
    /**
     * Get features info
     * @param string $feature_id
     * @return array
     * @throws Exception
     */
    public function getFeature($feature_id)
    {
        fn_pim_sync_log("Получаем информацию о характеристике: $feature_id");
        return $this->makeRequest('/feature/' . $feature_id);
    }
    
    /**
     * Get information about the characteristic by UID
     * @param string $feature_uid
     * @return array
     * @throws Exception
     */
    public function getFeatureByUid($feature_uid)
    {
        fn_pim_sync_log("Получаем информацию о характеристике по UID: $feature_uid");
        return $this->makeRequest('/feature/uid/' . $feature_uid);
    }
    
    /**
     * Get features value
     * @param string $value_id
     * @return array
     * @throws Exception
     */
    public function getFeatureValue($value_id)
    {
        return $this->makeRequest('/feature-value/' . $value_id);
    }
    
    /**
     * Get unit id
     * @param string $unit_id
     * @return array
     * @throws Exception
     */
    public function getUnit($unit_id)
    {
        return $this->makeRequest('/unit/' . $unit_id);
    }
    
    /**
     * Get group features
     * @param string $group_id
     * @return array
     * @throws Exception
     */
    public function getFeatureGroup($group_id)
    {
        return $this->makeRequest('/feature-group/' . $group_id);
    }
    
    /**
     * Download images
     * @param string $image_name
     * @param string $save_path
     * @return bool
     * @throws Exception
     */
    public function downloadImage($image_name, $save_path)
    {
        $url = 'https://pim.uroven.pro/pictures/originals/' . $image_name . '.JPG';
        
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
        curl_setopt($ch, CURLOPT_TIMEOUT, 60);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        
        $image_data = curl_exec($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curl_error = curl_error($ch);
        curl_close($ch);
        
        if ($image_data === false) {
            throw new Exception('Failed to download image: ' . $curl_error);
        }
        
        if ($http_code !== 200) {
            throw new Exception('Failed to download image: HTTP ' . $http_code);
        }
        
        // Create a dir if it does not exist
        $dir = dirname($save_path);
        if (!is_dir($dir)) {
            mkdir($dir, 0777, true);
        }
        
        // Save images
        $result = file_put_contents($save_path, $image_data);
        
        if ($result === false) {
            throw new Exception('Failed to save image to: ' . $save_path);
        }
        
        return true;
    }
    
    /**
     * Check API connection
     * @return bool
     */
    public function testConnection()
    {
        try {
            $this->authenticate();
            return true;
        } catch (Exception $e) {
            return false;
        }
    }
} 