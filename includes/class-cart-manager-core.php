<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit; // 安全檢查
}

/**
 * 核心插件框架類別
 */
final class Cart_Manager_Core {

    // --- 1. (改進 #1) 定義所有 Meta Keys 常數 ---
    // 這可以消除所有模組中的 "Magic Strings"，方便統一管理
    public const META_PRODUCT_IS_PREORDER = 'preorder-product';
    public const META_ORDER_PREORDER_STATUS = 'preorder-status';
    public const META_ORDER_TEMP_LAYER = 'temperature-layer';
    // ---------------------------------------------

    /**
     * @var Cart_Manager_Core 主實例
     */
    private static $instance = null;

    /**
     * 主實例獲取器 (Singleton Pattern)
     */
    public static function instance() {
        if ( is_null( self::$instance ) ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * 建構子 - 在這裡初始化所有內容
     */
    private function __construct() {
        $this->load_modules();
    }

    /**
     * 載入並初始化所有功能模組
     */
    private function load_modules() {
        
        $module_path = plugin_dir_path( __FILE__ ) . 'modules/';

        // --- 載入預購模組 ---
        include_once $module_path . 'preorder/class-cm-preorder-cart-validation.php';
        include_once $module_path . 'preorder/class-cm-preorder-order-meta.php';
        include_once $module_path . 'preorder/class-cm-preorder-product-admin.php';
        include_once $module_path . 'preorder/class-cm-preorder-order-admin.php';

        // --- 載入溫層模組 ---
        include_once $module_path . 'temperature/class-cm-temp-cart-validation.php';
        include_once $module_path . 'temperature/class-cm-temp-order-meta.php';
        include_once $module_path . 'temperature/class-cm-temp-product-admin.php';
        include_once $module_path . 'temperature/class-cm-temp-order-admin.php';

        // --- 載入共享模組 ---
        include_once $module_path . 'class-cm-cart-display.php';


        // --- 初始化所有模組類別 (這將觸發它們各自的 __construct 掛鉤) ---
        new CM_Preorder_Cart_Validation();
        new CM_Preorder_Order_Meta();
        new CM_Preorder_Product_Admin();
        new CM_Preorder_Order_Admin();

        new CM_Temp_Cart_Validation();
        new CM_Temp_Order_Meta();
        new CM_Temp_Product_Admin();
        new CM_Temp_Order_Admin();

        new CM_Cart_Display();
    }
}