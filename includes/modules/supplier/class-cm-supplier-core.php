<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit; // 安全檢查
}

/**
 * 模組：供應商核心 (Supplier Core)
 * 職責：註冊 "供應商" 使用者角色及其權限。
 */
class CM_Supplier_Core {

    public function __construct() {
        // WordPress 啟動時檢查角色是否存在，沒有就新增
        add_action( 'admin_init', array( $this, 'register_supplier_role' ) );

        add_filter( 'woocommerce_prevent_admin_access', array( $this, 'allow_supplier_admin_access' ), 10, 1 );
        
        // (重要) 在您的主插件檔案 cart-manager.php 中註冊 "停用" 鉤子，
        // 以便在停用插件時呼叫 'remove_supplier_role' 來清理資料庫。
        // register_deactivation_hook( __FILE__, array($this, 'remove_supplier_role') );
    }

    /**
     * 註冊 "供應商" 角色
     * (需求 1 達成)
     */
    public function register_supplier_role() {
        
        // --- (重要修正：複製商店經理權限) ---
        
        // 1. 獲取 "商店經理" 角色的物件
        $shop_manager_role = get_role( 'shop_manager' );
        $caps_to_have = array();

        if ( $shop_manager_role ) {
            // 2. 如果商店經理角色存在，就抓取它的所有權限
            $caps_to_have = $shop_manager_role->capabilities;
        } else {
            // 3. (備用) 如果商店經理不存在，使用一組基本權限
            $caps_to_have = array(
                'read'             => true,
                'view_shop_orders' => true,
                'edit_shop_orders' => true,
                'read_shop_order'  => true,
                'manage_woocommerce' => true
            );
        }
        
        // 確保 'read' 權限一定存在 (登入後台的基礎)
        $caps_to_have['read'] = true;
        // --- (修正完畢) ---


        // 4. 嘗試獲取現有的 "供應商" 角色物件
        $role = get_role( Cart_Manager_Core::ROLE_SUPPLIER );

        if ( $role === null ) {
            // --- 角色不存在 ---
            // 直接用我們完整的權限列表來建立新角色
            add_role( Cart_Manager_Core::ROLE_SUPPLIER, '供應商', $caps_to_have );

        } else {
            // --- 角色已存在 ---
            // 我們必須手動檢查並添加 "缺失" 的權限，確保舊角色也能被更新
            foreach ( $caps_to_have as $cap => $value ) {
                if ( ! $role->has_cap( $cap ) ) {
                    // 如果現有角色缺少商店經理的某個權限，就幫它加上去
                    $role->add_cap( $cap, $value );
                }
            }
        }
    }

    /**
     * (建議) 移除 "供應商" 角色 (當插件停用時呼叫)
     */
    public function remove_supplier_role() {
        remove_role( Cart_Manager_Core::ROLE_SUPPLIER );
    }

    /**
     * (全新) 允許供應商角色進入 /wp-admin
     *
     * 這是掛載到 'woocommerce_prevent_admin_access' 篩選器的函數。
     *
     * @param bool $prevent_access WooCommerce 預設的決定 (true = 阻擋)
     * @param WP_User $user 正在登入的使用者物件
     * @return bool
     */
    public function allow_supplier_admin_access( $prevent_access ) {
        
        // --- (修正) ---
        // 自己獲取當前登入的使用者
        $user = wp_get_current_user();

        // 檢查使用者物件是否存在
        if ( ! $user || ! $user->ID ) {
            return $prevent_access;
        }
        // --- (修正完畢) ---

        // 檢查登入的使用者是否擁有 "供應商" 角色
        if ( user_can( $user, Cart_Manager_Core::ROLE_SUPPLIER ) ) {
            
            // 如果是我們的供應商，返回 false (意思是：DO NOT "prevent access" = "不要阻擋")
            return false;
        }

        // 對於所有其他非管理員/非商店經理的角色，保持 WooCommerce 預設的阻擋決定
        return $prevent_access;
    }
}