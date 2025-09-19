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
}