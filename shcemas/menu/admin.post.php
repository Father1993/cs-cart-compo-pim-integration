<?php
/**
 * @file: admin.post.php
 * @description: Схема меню админ-панели
 * @dependencies: CS-Cart menu system
 * @created: 2025-06-27
 */

if (!defined('BOOTSTRAP')) { die('Access denied'); }

$schema['top']['catalog']['items']['pim_sync'] = [
    'title' => 'pim_sync',
    'href' => 'pim_sync.manage',
    'position' => 500
];

return $schema; 