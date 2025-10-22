<?php
/**
 * Plugin Name: Cart Manager
 * Description: 提供先進的 WooCommerce 購物車驗證規則與管理功能。
 * Version: 1.1.0
 * Author: zito
 */

// 防止直接訪問
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// 包含並啟動主框架類別
include_once __DIR__ . '/includes/class-cart-manager-core.php';

/**
 * 返回 Cart_Manager_Core 的主實例 (Singleton)
 *
 * @return Cart_Manager_Core
 */
function CartManager() {
    return Cart_Manager_Core::instance();
}

// 啟動插件
CartManager();