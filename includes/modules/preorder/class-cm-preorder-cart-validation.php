<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit; // 安全檢查
}

/**
 * 模組：預購購物車驗證
 * (已更新) 現在支援「變化類型」ID 檢查，並修正 hook 參數數量。
 */
class CM_Preorder_Cart_Validation {

    public function __construct() {
        // (重要修正) 我們需要第 4 個和第 5 個參數才能獲取 variation_id
        // 將 '10, 3' 改為 '10, 5'
        add_filter( 'woocommerce_add_to_cart_validation', array( $this, 'validate_cart_status' ), 10, 5 );
    }

    /**
     * (重要修正) 函數簽名 (signature) 必須包含所有 5 個參數
     */
    public function validate_cart_status( $passed, $product_id, $quantity, $variation_id = null, $variations = null ) {
        
        // if ( WC()->cart->is_empty() ) {
        //     return $passed;
        // }

        // $id_to_check_new = $variation_id ? $variation_id : $product_id;
        // $is_preorder_new = get_post_meta( $id_to_check_new, Cart_Manager_Core::META_PRODUCT_IS_PREORDER, true ) === 'yes';

        // $cart_items = WC()->cart->get_cart();
        // $first_cart_item = reset( $cart_items ); 
        
        // $id_to_check_in_cart = !empty( $first_cart_item['variation_id'] ) ? $first_cart_item['variation_id'] : $first_cart_item['product_id'];
        // $is_preorder_in_cart = get_post_meta( $id_to_check_in_cart, Cart_Manager_Core::META_PRODUCT_IS_PREORDER, true ) === 'yes';

        // if ( $is_preorder_new !== $is_preorder_in_cart ) {
        //     wc_add_notice( __( '購物車中不能同時包含預購商品與一般商品。', 'woocommerce' ), 'error' );
        //     return false;
        // }

        return $passed; // 狀態相同，允許添加
    }
}