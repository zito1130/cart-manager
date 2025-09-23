<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit; // 安全檢查
}

/**
 * 模組：供應商訂單後台 (Supplier Order Admin)
 * 職責：1. 在「訂單列表」頁面加入 "供應商" 欄位。
 * 2. (全新) 加入 "重新檢查供應商" 批次操作。
 */
class CM_Supplier_Order_Admin {

    public function __construct() {
        // --- 1. 顯示欄位 (HPOS + CPT) ---
        add_filter( 'manage_woocommerce_page_wc-orders_columns', array( $this, 'add_supplier_column_to_orders' ) );
        add_action( 'manage_woocommerce_page_wc-orders_custom_column', array( $this, 'show_supplier_column_for_orders' ), 10, 2 );
        add_filter( 'manage_edit-shop_order_columns', array( $this, 'add_supplier_column_to_orders' ) );
        add_action( 'manage_shop_order_posts_custom_column', array( $this, 'show_supplier_column_for_orders' ), 10, 2 );

        // --- 2. (全新) 批次操作 (HPOS + CPT) ---
        // (HPOS)
        add_filter( 'bulk_actions-woocommerce_page_wc-orders', array( $this, 'add_bulk_action_check_supplier' ), 20, 1 );
        add_filter( 'handle_bulk_actions-woocommerce_page_wc-orders', array( $this, 'handle_bulk_action_check_supplier' ), 10, 3 );
        // (CPT)
        add_filter( 'bulk_actions-edit-shop_order', array( $this, 'add_bulk_action_check_supplier' ), 20, 1 );
        add_filter( 'handle_bulk_actions-edit-shop_order', array( $this, 'handle_bulk_action_check_supplier' ), 10, 3 );

        // --- 3. (全新) 供應商篩選下拉選單 ---
        // (CPT - 舊版)
        add_action( 'restrict_manage_posts', array( $this, 'add_supplier_filter_dropdown' ) );
        // (HPOS - 新版)
        add_action( 'woocommerce_order_list_table_restrict_manage_orders', array( $this, 'add_supplier_filter_dropdown_hpos' ) );

        // --- 4. (全新) 處理篩選邏輯 ---
        // (CPT - 舊版)
        add_filter( 'woocommerce_order_query_args', array( $this, 'filter_orders_by_supplier' ), 20 );
        // (HPOS - 新版)
        add_filter( 'woocommerce_orders_list_table_query_args', array( $this, 'filter_orders_by_supplier' ), 20 );
    }

    /**
     * 1. 在訂單列表加入 "供應商" 欄位標題
     */
    public function add_supplier_column_to_orders( $columns ) {
        $new_columns = array();
        foreach ( $columns as $key => $value ) {
            $new_columns[$key] = $value;
            if ( 'order_status' === $key ) {
                $new_columns['supplier'] = __( '供應商', 'cart-manager' );
            }
        }
        return $new_columns;
    }

    /**
     * 2. 顯示 "供應商" 欄位內容
     */
    public function show_supplier_column_for_orders( $column_name, $order_or_post_id ) {
        if ( 'supplier' === $column_name ) {
            $order = wc_get_order( $order_or_post_id );
            if ( ! $order ) return;

            $supplier_id = $order->get_meta( Cart_Manager_Core::META_ORDER_SUPPLIER_ID );
            
            if ( empty( $supplier_id ) ) {
                echo esc_html__( '無 (站方商品)', 'cart-manager' );
                return;
            }

            $supplier = get_userdata( $supplier_id );
            if ( $supplier ) {
                $display_name = ! empty( $supplier->nickname ) ? $supplier->nickname : $supplier->user_login;
                echo esc_html( $display_name );
            } else {
                echo esc_html__( '供應商不存在', 'cart-manager' );
            }
        }
    }

    /**
     * (全新) 3. 添加 "重新檢查供應商" 批次操作
     */
    public function add_bulk_action_check_supplier( $bulk_actions ) {
        $bulk_actions['check_supplier'] = __( '重新檢查供應商', 'cart-manager' );
        return $bulk_actions;
    }

    /**
     * (全新) 4. 處理 "重新檢查供應商" 批次操作邏輯
     */
    public function handle_bulk_action_check_supplier( $redirect_to, $action, $order_ids ) {
        if ( $action !== 'check_supplier' ) {
            return $redirect_to;
        }

        foreach ( $order_ids as $order_id ) {
            $order = wc_get_order( $order_id );
            if ( ! $order ) continue;

            // --- 呼叫我們在 Meta 類別中建立的靜態輔助函數 ---
            $supplier_id = CM_Supplier_Order_Meta::get_supplier_id_from_order( $order );
            
            // 重新儲存 meta
            if ( ! empty( $supplier_id ) ) {
                $order->update_meta_data( Cart_Manager_Core::META_ORDER_SUPPLIER_ID, $supplier_id );
            } else {
                // 如果是站方商品，確保 meta 是空的
                $order->delete_meta_data( Cart_Manager_Core::META_ORDER_SUPPLIER_ID );
            }
            $order->save();
        }
        return $redirect_to;
    }

    /**
     * (全新) 輔助函數：獲取供應商列表以供篩選
     * (與 class-cm-supplier-product-admin.php 中的 get_supplier_options_list 類似)
     */
    private function get_supplier_filter_options() {
        $suppliers = get_users( array( 'role' => Cart_Manager_Core::ROLE_SUPPLIER ) );
        $options = array();

        foreach ( $suppliers as $supplier ) {
            $display_name = ! empty( $supplier->nickname ) ? $supplier->nickname : $supplier->user_login;
            $options[ $supplier->ID ] = $display_name;
        }
        return $options;
    }

    /**
     * (全新) 5. 在訂單列表添加 "供應商" 篩選下拉選單 (CPT + HPOS)
     *
     * 註：HPOS 鉤子 'woocommerce_order_list_table_restrict_manage_orders' 不傳遞參數，
     * CPT 鉤子 'restrict_manage_posts' 傳遞 $post_type。
     */
    public function add_supplier_filter_dropdown( $post_type = 'shop_order' ) {
        global $typenow;

        // 確保我們在正確的頁面上 (CPT)
        if ( $post_type !== 'shop_order' && $typenow !== 'shop_order' ) {
            return;
        }
        
        // 僅限管理員或商店經理可見 (供應商不應看到此篩選)
        if ( ! current_user_can('manage_woocommerce') || current_user_can( Cart_Manager_Core::ROLE_SUPPLIER ) ) {
            return;
        }
        
        $this->render_supplier_filter_dropdown();
    }

    /**
     * (全新) 5b. HPOS 的篩選下拉選單包裝函數
     */
    public function add_supplier_filter_dropdown_hpos() {
        // 僅限管理員或商店經理可見 (供應商不應看到此篩選)
        if ( ! current_user_can('manage_woocommerce') || current_user_can( Cart_Manager_Core::ROLE_SUPPLIER ) ) {
            return;
        }
        
        $this->render_supplier_filter_dropdown();
    }
    
    /**
     * (全新) 5c. 渲染下拉選單的 HTML
     */
    private function render_supplier_filter_dropdown() {
        
        $options = $this->get_supplier_filter_options();
        
        // 獲取當前選擇的值
        $selected = $_GET['_cm_supplier_filter'] ?? '';

        echo '<select name="_cm_supplier_filter" id="cm_supplier_filter">';
        echo '<option value="">' . __( '所有供應商', 'cart-manager' ) . '</option>';
        
        // "站方商品" 選項 (value='stand')
        echo '<option value="stand"' . selected( $selected, 'stand', false ) . '>' . __( '無 (站方商品)', 'cart-manager' ) . '</option>';

        foreach ( $options as $id => $name ) {
            echo '<option value="' . esc_attr( $id ) . '"' . selected( $selected, $id, false ) . '>' . esc_html( $name ) . '</option>';
        }
        echo '</select>';
    }

    /**
     * (全新) 6. 處理 "供應商" 篩選邏輯
     */
    public function filter_orders_by_supplier( $query_args ) {
        
        // 僅限管理員或商店經理 (供應商的過濾由 CM_Supplier_Permissions 處理)
        if ( ! current_user_can('manage_woocommerce') || current_user_can( Cart_Manager_Core::ROLE_SUPPLIER ) ) {
            return $query_args;
        }

        // 檢查 GET 參數
        if ( isset( $_GET['_cm_supplier_filter'] ) && ! empty( $_GET['_cm_supplier_filter'] ) ) {
            
            $supplier_id = sanitize_text_field( $_GET['_cm_supplier_filter'] );

            // 確保 meta_query 是一個陣列
            $meta_query = $query_args['meta_query'] ?? array();

            if ( $supplier_id === 'stand' ) {
                // --- 篩選 "站方商品" ---
                // 必須同時檢查 "meta 不存在" 或 "meta 為空字串"
                $meta_query[] = array(
                    'relation' => 'OR',
                    array(
                        'key'     => Cart_Manager_Core::META_ORDER_SUPPLIER_ID,
                        'compare' => 'NOT EXISTS',
                    ),
                    array(
                        'key'     => Cart_Manager_Core::META_ORDER_SUPPLIER_ID,
                        'value'   => '',
                        'compare' => '=',
                    ),
                );
            } else {
                // --- 篩選特定供應商 ID ---
                $meta_query[] = array(
                    'key'     => Cart_Manager_Core::META_ORDER_SUPPLIER_ID,
                    'value'   => $supplier_id,
                    'compare' => '=',
                );
            }
            
            $query_args['meta_query'] = $meta_query;
        }
        
        return $query_args;
    }
}