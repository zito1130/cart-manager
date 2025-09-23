<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit; // 安全檢查
}

/**
 * 模組：預購訂單 Meta 處理
 * (已更新) 現在支援檢查「變化類型」ID。
 */
class CM_Preorder_Order_Meta {

    public function __construct() {
        // add_action( 'woocommerce_thankyou', array( $this, 'set_preorder_status_meta' ), 10, 1 );
    }

    /**
     * 設置訂單的預購狀態 Meta (邏輯更新)
     */
    public function set_preorder_status_meta( $order_id ) {
        $order = wc_get_order( $order_id ); 
        if ( $order && $order instanceof WC_Order ) { 
            $order_id = $order->get_id(); 
            $is_preorder = false; 

            foreach ( $order->get_items() as $item_id => $item ) {
                
                // --- (重要修改) ---
                // 優先檢查 variation_id，如果不存在，才使用 product_id
                $id_to_check = $item->get_variation_id() ? $item->get_variation_id() : $item->get_product_id();
                
                $preorder_product = get_post_meta( $id_to_check, Cart_Manager_Core::META_PRODUCT_IS_PREORDER, true ); 

                if ( $preorder_product === 'yes' ) {
                    $is_preorder = true; 
                    break; 
                }
            }

            $order->update_meta_data( Cart_Manager_Core::META_ORDER_PREORDER_STATUS, $is_preorder ? 'yes' : 'no' );
            $order->save(); 
        } else {
            error_log( 'Cart Manager: 未找到訂單 ID: ' . $order_id ); 
        }
    }
}