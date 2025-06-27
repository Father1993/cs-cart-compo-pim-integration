<?php
/**
* @file: admin.post.php
* @description: Permissions scheme for pim_sync controller
* @dependencies: CS-Cart permissions system
* @created: 2025-06-27
*/

if (!defined('BOOTSTRAP')) { die('Access denied'); }

$schema['pim_sync'] = [
    'permissions' => ['GET_ORDERS', 'MANAGE_ORDERS'],
    'modes' => [
        'manage' => [
            'permissions' => 'GET_ORDERS'
        ],
        'sync_full' => [
            'permissions' => 'MANAGE_ORDERS'
        ],
        'sync_delta' => [
            'permissions' => 'MANAGE_ORDERS'
        ],
        'test_connection' => [
            'permissions' => 'MANAGE_ORDERS'
        ],
        'clear_logs' => [
            'permissions' => 'MANAGE_ORDERS'
        ],
        'log_details' => [
            'permissions' => 'GET_ORDERS'
        ]
    ]
];

return $schema; 