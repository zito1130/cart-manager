<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit; // 安全檢查
}

/**
 * 模組：購物車共享顯示 (Shared Cart Display)
 *
 * 職責：在購物車的商品名稱下方，同時顯示預購狀態與溫層資訊。
 * 來源：display-pre-tmp-in-cart.php
 */
class CM_Cart_Display {

    /**
     * 建構子：註冊鉤子
     */
    public function __construct() {
        add_filter( 'woocommerce_cart_item_name', array( $this, 'display_preorder_and_shipping_class' ), 10, 3 );
    }

    /**
     * 在購物車中顯示商品的預購狀態和運送類別
     * (此函數邏輯完全來自舊的 tpm_display_preorder_status_and_shipping_class_in_cart 函數)
     *
     * @param string $name          商品名稱.
     * @param array  $cart_item     購物車項目.
     * @param string $cart_item_key 購物車項目索引.
     * @return string               修改後的商品名稱.
     */
    public function display_preorder_and_shipping_class( $name, $cart_item, $cart_item_key ) {
        $product_id = $cart_item['product_id'];
        
        // --- 1. 顯示預購狀態 ---
        $preorder_product = get_post_meta( $product_id, 'preorder-product', true ); 
        if ( $preorder_product === 'yes' ) {
            $name .= '<br><small>' . __( '預購', 'woocommerce' ) . '</small>'; // 顯示預購狀態
        } else {
            $name .= '<br><small>' . __( '現貨', 'woocommerce' ) . '</small>'; // 顯示現貨狀態
        }

        // --- 2. 顯示運送類別 (溫層) ---
        $product = wc_get_product( $product_id ); // 獲取商品物件
        $shipping_class_slug = $product->get_shipping_class(); // 獲取運送類別的slug

        if ( $shipping_class_slug ) {
            $shipping_class = get_term_by( 'slug', $shipping_class_slug, 'product_shipping_class' ); // 獲取運送類別的詳細資訊
            if ( $shipping_class && ! is_wp_error( $shipping_class ) ) {
                $name .= '<small>' . __( ' / ', 'woocommerce' ) . esc_html( $shipping_class->name ) . '</small>'; // 顯示運送類別名稱
            }
        }

        return $name; // 返回更新後的商品名稱
    }
}