<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit; // 安全檢查
}

/**
 * 模組：供應商權限控管 (Supplier Permissions)
 * (已修正) 修正了 CPT 訂單篩選的鉤子，從 pre_get_posts 改為 woocommerce_order_query_args。
 * (已修正) 提高了篩選器的優先級，以避免與其他模組衝突。
 */
class CM_Supplier_Permissions {

    public function __construct() {
        // ... (清理選單和重導向的鉤子保持不變) ...
        add_action( 'admin_menu', array( $this, 'cleanup_admin_menu_for_supplier' ), 999 );
        add_action( 'admin_init', array( $this, 'redirect_supplier_from_dashboard' ) );

        $hpos_enabled = ( get_option( 'woocommerce_custom_orders_table_enabled' ) === 'yes' );

        // 1. 過濾 CPT 列表 (保持不變)
        add_filter( 'woocommerce_order_query_args', array( $this, 'filter_cpt_orders_for_supplier' ), 999 ); 

        // 2. 過濾 HPOS 列表 (保持不變)
        add_filter( 'woocommerce_orders_list_table_query_args', array( $this, 'filter_hpos_orders_for_supplier' ), 999 );

        // 3. (修正) 根據網站模式，動態過濾「計數」查詢
        if ( $hpos_enabled ) {
            // 如果是 HPOS，計數鉤子必須使用 HPOS 的過濾函數
            add_filter( 'woocommerce_order_list_table_get_views_query_args', array( $this, 'filter_hpos_orders_for_supplier' ), 999 );
        } else {
            // 如果是 CPT，計數鉤子使用 CPT 的過濾函數
            add_filter( 'woocommerce_order_list_table_get_views_query_args', array( $this, 'filter_cpt_orders_for_supplier' ), 999 );
        }
        
        // (移除我們之前測試用的鉤子)
        remove_filter( 'woocommerce_order_list_table_count_orders_query_args', array( $this, 'filter_cpt_orders_for_supplier' ), 999 );
    }

    /**
     * 1. 清理後台選單 (保持不變)
     */
    public function cleanup_admin_menu_for_supplier() {
        
        if ( ! current_user_can( Cart_Manager_Core::ROLE_SUPPLIER ) || current_user_can( 'manage_options' ) ) {
            return;
        }

        global $menu, $submenu;
        $top_level_whitelist = array('woocommerce', 'profile.php');

        foreach ( $menu as $key => $item ) {
            $menu_slug = $item[2];
            if ( ! in_array( $menu_slug, $top_level_whitelist ) && $item[4] !== 'wp-menu-separator' ) {
                remove_menu_page( $menu_slug );
            }
        }

        foreach ( $menu as $key => $item ) {
            if ( $item[2] === 'woocommerce' ) {
                $menu[$key][0] = __( '訂單', 'woocommerce' ); 
                break;
            }
        }

        if ( isset( $submenu['woocommerce'] ) ) {
            $submenu_whitelist = array('wc-orders', 'edit.php?post_type=shop_order');
            foreach ( $submenu['woocommerce'] as $key => $item ) {
                $submenu_slug = $item[2];
                if ( ! in_array( $submenu_slug, $submenu_whitelist ) ) {
                    unset( $submenu['woocommerce'][$key] );
                }
            }
        }
    }

    /**
     * 2. 重導向儀表板 (保持不變)
     */
    public function redirect_supplier_from_dashboard() {
        
        if ( isset( $_GET['page'] ) && $_GET['page'] === 'woocommerce' ) {
            if ( current_user_can( Cart_Manager_Core::ROLE_SUPPLIER ) && ! current_user_can( 'manage_options' ) ) {
                
                $hpos_enabled = ( get_option( 'woocommerce_custom_orders_table_enabled' ) === 'yes' );
                if ( $hpos_enabled ) {
                    wp_redirect( admin_url( 'admin.php?page=wc-orders' ) );
                } else {
                    wp_redirect( admin_url( 'edit.php?post_type=shop_order' ) );
                }
                exit; 
            }
        }
    }

    /**
     * 3. 過濾 CPT (舊版) 訂單查詢 (保持不變)
     */
    public function filter_cpt_orders_for_supplier( $query_args ) {

        // 檢查是否為供應商且非管理員
        if ( current_user_can( Cart_Manager_Core::ROLE_SUPPLIER ) && ! current_user_can( 'manage_options' ) ) {
            
            $meta_query = $query_args['meta_query'] ?? array();
            $meta_query[] = array(
                'key'     => Cart_Manager_Core::META_ORDER_SUPPLIER_ID, 
                'value'   => get_current_user_id(), 
                'compare' => '=',
            );
            $query_args['meta_query'] = $meta_query;
        }
        return $query_args;
    }

    /**
     * 4. 過濾 HPOS (新版) 訂單查詢 (保持不變)
     */
    public function filter_hpos_orders_for_supplier( $query_vars ) {
        
        if ( current_user_can( Cart_Manager_Core::ROLE_SUPPLIER ) && ! current_user_can( 'manage_options' ) ) {
            
            $meta_query = $query_vars['meta_query'] ?? array();
            $meta_query[] = array(
                'key'     => Cart_Manager_Core::META_ORDER_SUPPLIER_ID, 
                'value'   => get_current_user_id(),
                'compare' => '=',
            );
            $query_vars['meta_query'] = $meta_query;
        }
        return $query_vars;
    }
}