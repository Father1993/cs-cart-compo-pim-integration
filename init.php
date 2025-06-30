<?php

/**
*  @file: init.php
*  @description: Инициализация аддона PIM Sync
*  @dependencies: CS-Cart core
*  @created: 2025-06-27
*/

if (! defined('BOOTSTRAP')) {
    die('Access denied');
}

// Register autoloading classes
$autoloader = function ($class) {
    $prefix = 'Tygh\\Addons\\PimSync\\';
    $base_dir = __DIR__ . '/classes/';
    $len = strlen($prefix);
    if (strncmp($prefix, $class, $len) !== 0) {
        return;
    }
    $relative_class = substr($class, $len);
    $file = $base_dir . str_replace('\\', '/', $relative_class) . '.php';
    if (file_exists($file)) {
        require $file;
    }
};

spl_autoload_register($autoloader);
// Register hooks to track changes
fn_register_hooks(
    'update_product_post',
    'delete_product_post',
    'update_category_post',
    'delete_category_post',
    'get_products',
    'get_categories'
);
