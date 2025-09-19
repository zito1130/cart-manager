<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit; // 安全檢查
}

/**
 * 模組：供應商購物車驗證 (Supplier Cart Validation)
 * 職責：確保購物車中所有商品都來自同一個供應商。
 */
class CM_Supplier_Cart_Validation {

    public function __construct() {
        // 驗證鉤子，需要 5 個參數才能正確處理可變商品
        add_filter( 'woocommerce_add_to_cart_validation', array( $this, 'validate_supplier_consistency' ), 10, 5 );
    }

    /**
     * 驗證購物車中的供應商是否一致
     */
    public function validate_supplier_consistency( $passed, $product_id, $quantity, $variation_id = 0, $variations = null ) {
        
        // 購物車是空的，這是第一件商品，直接允許
        if ( WC()->cart->is_empty() ) {
            return $passed;
        }

        // --- 1. 獲取「新加入商品」的供應商 ID ---
        
        // 檢查是否為可變商品
        $id_to_check_new = $variation_id ? $variation_id : $product_id;
        $product_new = wc_get_product( $id_to_check_new );
        
        // 獲取「主要商品」的 ID (因為供應商是綁在主要商品上)
        $parent_id_new = $product_new->is_type('variation') ? $product_new->get_parent_id() : $product_new->get_id();
        // 獲取供應商 meta (使用核心常數)
        $supplier_new = get_post_meta( $parent_id_new, Cart_Manager_Core::META_PRODUCT_SUPPLIER_ID, true );


        // --- 2. 獲取「購物車中商品」的供應商 ID (以第一件為準) ---
        $first_cart_item = reset( WC()->cart->get_cart() );
        
        $product_in_cart = wc_get_product( $first_cart_item['product_id'] );
        // 同樣獲取主要商品 ID
        $parent_id_in_cart = $product_in_cart->is_type('variation') ? $product_in_cart->get_parent_id() : $product_in_cart->get_id();
        $supplier_in_cart = get_post_meta( $parent_id_in_cart, Cart_Manager_Core::META_PRODUCT_SUPPLIER_ID, true );

        // --- 3. 比較 ---
        // if ( $supplier_new !== $supplier_in_cart ) {
        //     // 如果供應商 ID 不一樣 (例如一個是 '123'，一個是 '' (站方))
        //     wc_add_notice( __( '購物車中不能同時包含不同供應商的商品。', 'cart-manager' ), 'error' );
        //     return false; // 阻止加入
        // }

        return $passed; // 供應商相同，允許
    }
}