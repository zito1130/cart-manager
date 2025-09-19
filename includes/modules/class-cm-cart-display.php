<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit; // 安全檢查
}

/**
 * 模組：購物車共享顯示 (Shared Cart Display)
 *
 * (已重構 - JS + 隱藏資料方案)
 * (已修正) 修正 reset_supplier_tracker_mini() 中的 PHP 語法錯誤
 */
class CM_Cart_Display {

    // (主購物車) 追蹤器
    private $current_supplier_id = null;
    // (Mini-Cart) 獨立追蹤器
    private $current_supplier_id_mini = null;

    /**
     * 建構子：註冊鉤子
     */
    public function __construct() {
        
        // 1. (PHP) 排序購物車內容
        add_filter( 'woocommerce_get_cart_contents', array( $this, 'sort_cart_by_supplier_and_time' ), 10, 1 );

        // 2. (JS) 在頁尾載入 JS 腳本
        add_action( 'wp_footer', array( $this, 'output_cart_grouping_script' ) );

        // 3. (Mini-Cart + Meta + 隱藏資料) 鉤子
        add_filter( 'woocommerce_cart_item_name', array( $this, 'display_minicart_header_and_meta' ), 10, 3 );
        
        // 4. 重置追蹤器
        add_action( 'woocommerce_after_cart_table', array( $this, 'reset_supplier_tracker' ) );
        add_action( 'woocommerce_after_mini_cart', array( $this, 'reset_supplier_tracker_mini' ) );
    }

    /**
     * 1. 排序購物車內容 (不變)
     */
    public function sort_cart_by_supplier_and_time( $cart_contents ) {
        $sorted_cart = array_reverse( $cart_contents, true );
        uasort( $sorted_cart, array( $this, 'sort_by_supplier_callback' ) );
        return $sorted_cart;
    }
    public function sort_by_supplier_callback( $a, $b ) {
        $supplier_a = $this->get_item_supplier_id( $a );
        $supplier_b = $this->get_item_supplier_id( $b );
        return $supplier_a <=> $supplier_b;
    }

    
    /**
     * 2. (JS) 輸出 JavaScript 腳本 (不變)
     */
    public function output_cart_grouping_script() {
        // 只在主購物車頁面執行
        if ( ! is_cart() ) {
            return;
        }
        ?>
        <script type="text/javascript">
            jQuery(document).ready(function($) {
                
                var $cartTable = $('form.woocommerce-cart-form table.shop_table, table.shop_table.cart').first();
                if ( ! $cartTable.length ) {
                    console.log('Cart Manager: 找不到購物車表格。');
                    return;
                }

                var colCount = $cartTable.find('thead tr:first th').length;
                if ( colCount === 0 ) colCount = 6; // 備用

                var currentSupplierId = null;

                // 迴圈遍歷表格中「所有」商品列
                $cartTable.find('tbody tr.cart_item').each(function() {
                    var $productRow = $(this);
                    
                    // 尋找我們在 PHP 中 "隱藏" 的 <span>
                    var $supplierData = $productRow.find('span.cm-supplier-data').first();
                    if ( ! $supplierData.length ) {
                        return;
                    }

                    var supplierId = $supplierData.data('supplier-id');
                    
                    // 檢查 ID 是否變更
                    if ( supplierId !== currentSupplierId ) {
                        currentSupplierId = supplierId; // 更新追蹤器
                        var supplierName = $supplierData.data('supplier-name');
                        
                        // 建立一個全新的、獨立的 <tr> 標題列
                        var $headerRow = $(
                            '<tr class="cm-supplier-header-row">' +
                                '<td colspan="' + colCount + '" style="padding-top: 25px; border: none;">' +
                                    '<h3 style="margin: 0;">' + supplierName + '</h3>' +
                                '</td>' +
                            '</tr>'
                        );
                        
                        // 將標題列插入到商品列的「上方」
                        $productRow.before($headerRow);
                    }
                });
            });
        </script>
        <?php
    }


    /**
     * 3. (Mini-Cart + Meta + 隱藏資料) (不變)
     */
    public function display_minicart_header_and_meta( $name, $cart_item, $cart_item_key ) {
        
        $heading = ''; 
        $hidden_data = '';

        $item_supplier_id = $this->get_item_supplier_id( $cart_item );
        $supplier_name = $this->get_supplier_display_name( $item_supplier_id );

        // --- (A) Mini-Cart 標題邏輯 (不變) ---
        if ( ! is_cart() ) {
            if ( $this->current_supplier_id_mini !== $item_supplier_id ) {
                $style = "font-weight: bold; font-size: 1em; " .
                         "flex-basis: 100%; " . 
                         "padding-bottom: 8px; " .
                         "border-bottom: 1px solid #e0e0e0; " .
                         "margin-bottom: 10px;";
                $heading = '<div class="cm-supplier-minicart-heading" style="' . esc_attr( $style ) . '">' . esc_html( $supplier_name ) . '</div>';
                $this->current_supplier_id_mini = $item_supplier_id;
            }
        }

        // --- (B) 隱藏資料 (不變) ---
        $hidden_data = sprintf(
            '<span class="cm-supplier-data" data-supplier-id="%s" data-supplier-name="%s" style="display:none;"></span>',
            esc_attr( $item_supplier_id ),
            esc_attr( $supplier_name )
        );


        // --- (C) 商品 Meta 邏輯 (不變) ---
        $id_to_check = !empty( $cart_item['variation_id'] ) ? $cart_item['variation_id'] : $cart_item['product_id'];
        $meta_html = '';

        $preorder_product = get_post_meta( $id_to_check, Cart_Manager_Core::META_PRODUCT_IS_PREORDER, true ); 
        if ( $preorder_product === 'yes' ) {
            $meta_html .= '<br><small>' . __( '預購', 'woocommerce' ) . '</small>';
        } else {
            $meta_html .= '<br><small>' . __( '現貨', 'woocommerce' ) . '</small>';
        }

        $product = $cart_item['data'];
        $shipping_class_slug = $product->get_shipping_class(); 

        if ( $shipping_class_slug ) {
            $shipping_class = get_term_by( 'slug', $shipping_class_slug, 'product_shipping_class' );
            if ( $shipping_class && ! is_wp_error( $shipping_class ) ) {
                $meta_html .= '<small>' . __( ' / ', 'woocommerce' ) . esc_html( $shipping_class->name ) . '</small>';
            }
        }

        // --- 組合輸出 ---
        return $hidden_data . $heading . $name . $meta_html;
    }

    /**
     * 4. 重置追蹤器 (不變)
     */
    public function reset_supplier_tracker() {
        $this->current_supplier_id = null;
    }
    
    /**
     * (*** 關鍵修正 ***)
     * 修正語法錯誤
     */
    public function reset_supplier_tracker_mini() {
        // (修正：從 $this. 改為 $this->)
        $this->current_supplier_id_mini = null;
    }

    /**
     * 5. 輔助函數 (修正)
     */
    private function get_item_supplier_id( $cart_item ) {
        
        // (*** 修正 ***) 
        // 確保 $cart_item['data'] 是存在的，如果不存在 (例如在排序鉤子中)，
        // 就手動獲取商品物件。
        $product = null;
        if ( ! empty( $cart_item['data'] ) ) {
            $product = $cart_item['data'];
        } else {
            $product_id = $cart_item['product_id'];
            $variation_id = $cart_item['variation_id'];
            $id_to_check = $variation_id ? $variation_id : $product_id;
            $product = wc_get_product( $id_to_check );
        }

        if ( ! $product ) {
            return '';
        }

        $parent_id = $product->is_type('variation') ? $product->get_parent_id() : $product->get_id();
        $supplier_id = get_post_meta( $parent_id, Cart_Manager_Core::META_PRODUCT_SUPPLIER_ID, true );
        
        return $supplier_id ? $supplier_id : '';
    }

    private function get_supplier_display_name( $supplier_id ) {
        if ( empty( $supplier_id ) ) {
            return __( '站方商品', 'cart-manager' );
        }

        $supplier = get_userdata( $supplier_id );
        if ( $supplier ) {
            return ! empty( $supplier->nickname ) ? $supplier->nickname : $supplier->user_login;
        }

        return __( '未知供應商', 'cart-manager' );
    }
}