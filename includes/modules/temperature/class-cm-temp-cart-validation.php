<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit; // 安全檢查
}

/**
 * 模組：溫層購物車驗證
 * (已更新) 移除 foreach 迴圈，提升驗證效率。
 */
class CM_Temp_Cart_Validation {

    public function __construct() {
        add_filter( 'woocommerce_add_to_cart_validation', array( $this, 'validate_shipping_classes' ), 10, 3 );
    }

    /**
     * 驗證邏輯 (改進 #1：效能優化)
     *
     * 採用與預購驗證相同的邏輯：
     * 1. 如果購物車是空的，直接通過。
     * 2. 如果不是空的，只需比較「新商品溫層」與「購物車第一件商品溫層」是否相同。
     */
    public function validate_shipping_classes( $passed, $product_id, $quantity ) {
        
        // 如果購物車是空的，這是第一件商品，直接允許加入。
        if ( WC()->cart->is_empty() ) {
            return $passed; 
        }

        // 1. 獲取新加入商品的溫層 (Shipping Class Slug)
        $product_new = wc_get_product( $product_id ); 
        $shipping_class_slug_new = $product_new->get_shipping_class(); 

        // 2. 獲取購物車中 "控制組" (第一件商品) 的溫層
        $cart_items = WC()->cart->get_cart();
        $first_cart_item = reset( $cart_items );
        $product_in_cart = wc_get_product( $first_cart_item['product_id'] );
        $shipping_class_slug_in_cart = $product_in_cart->get_shipping_class();

        // 3. 比較兩者
        if ( $shipping_class_slug_new !== $shipping_class_slug_in_cart ) {
            wc_add_notice( __( '購物車中不能同時包含不同運送類別的商品。', 'woocommerce' ), 'error' );
            return false; // 阻止添加
        }

        return $passed; // 溫層相同 (或兩者都為空)，允許添加
    }
}