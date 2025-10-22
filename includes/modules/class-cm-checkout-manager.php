<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit; // 安全檢查
}

/**
 * 模組：結帳管理器 (Checkout Manager)
 *
 * (*** 最終修正：已加入「運送方式」複製邏輯，解決致命錯誤 ***)
 */
class CM_Checkout_Manager {

    // Session 鍵名 (不變)
    private $session_key = 'cm_selected_suppliers';
    private $removed_items_key = 'cm_removed_for_checkout';


    public function __construct() {
        // 1. 註冊 AJAX 鉤子 (不變)
        add_action( 'wp_ajax_cm_set_checkout_suppliers', array( $this, 'ajax_set_checkout_suppliers' ) );
        add_action( 'wp_ajax_nopriv_cm_set_checkout_suppliers', array( $this, 'ajax_set_checkout_suppliers' ) );

        // 2. 在結帳頁面載入前，過濾購物車 (不變)
        add_action( 'woocommerce_before_checkout_form', array( $this, 'filter_cart_for_checkout' ), 10 );
        add_action( 'woocommerce_before_checkout_form', array( $this, 'show_split_order_notice' ), 20 );

        add_action( 'woocommerce_order_status_processing', array( $this, 'split_order_by_supplier' ), 10, 1 );
        add_action( 'woocommerce_order_status_on-hold', array( $this, 'split_order_by_supplier' ), 10, 1 );

        add_action( 'woocommerce_thankyou', array( $this, 'restore_removed_cart_items' ), 100, 1 );
        // (*** 已重構 ***) 感謝頁面：使用單一函數處理所有邏輯
        add_action( 'woocommerce_thankyou', array( $this, 'display_thankyou_page_details' ), 5, 1 );
    }

    /**
     * 1. AJAX 處理函數 (不變)
     */
    public function ajax_set_checkout_suppliers() {
        check_ajax_referer( 'cm-cart-nonce', 'security' );
        if ( ! isset( $_POST['selected_suppliers'] ) || ! is_array( $_POST['selected_suppliers'] ) ) {
            wp_send_json_error( '資料無效' );
            return;
        }
        $selected_ids = array_map( 'sanitize_text_field', $_POST['selected_suppliers'] );
        WC()->session->set( $this->session_key, $selected_ids );
        wp_send_json_success( '已儲存' );
    }

    /**
     * 2. 過濾購物車並「暫存」被移除的商品 (不變)
     */
    public function filter_cart_for_checkout() {
        $selected_suppliers = WC()->session->get( $this->session_key );
        if ( empty( $selected_suppliers ) ) {
            return;
        }
        $removed_items_to_store = array();
        foreach ( WC()->cart->get_cart() as $cart_item_key => $cart_item ) {
            $supplier_id = CM_Cart_Display::get_item_supplier_id( $cart_item );
            if ( ! in_array( $supplier_id, $selected_suppliers ) ) {
                $removed_items_to_store[ $cart_item_key ] = array(
                    'product_id'     => $cart_item['product_id'],
                    'variation_id'   => $cart_item['variation_id'],
                    'quantity'       => $cart_item['quantity'],
                    'variation'      => $cart_item['variation'],
                    'cart_item_data' => $cart_item
                );
                WC()->cart->remove_cart_item( $cart_item_key );
            }
        }
        if ( ! empty( $removed_items_to_store ) ) {
            WC()->session->set( $this->removed_items_key, $removed_items_to_store );
        }
        WC()->session->set( $this->session_key, null );
    }

    /**
     * 輔助函數：從 WC_Order_Item 獲取供應商 ID (不變)
     */
    private function get_supplier_id_from_order_item( $item ) {
        $product = $item->get_product();
        if ( ! $product ) {
            return 'stand'; 
        }
        $parent_id = $product->is_type('variation') ? $product->get_parent_id() : $product->get_id();
        $supplier_id = get_post_meta( $parent_id, Cart_Manager_Core::META_PRODUCT_SUPPLIER_ID, true );
        
        return $supplier_id ? $supplier_id : 'stand';
    }

    /**
     * 3. 依供應商拆分訂單 (*** 已更新為「高兼容性」運費分配 ***)
     */
    public function split_order_by_supplier( $order_id ) {
        
        $parent_order = wc_get_order( $order_id );
        if ( ! $parent_order || $parent_order->get_meta( '_cm_order_split_parent' ) || $parent_order->get_parent_id() > 0 ) {
            return;
        }
        $items = $parent_order->get_items();
        if ( empty( $items ) ) {
            return;
        }

        $gift_supplier_nickname = '贈品';
        $gift_supplier_id = null;

        if ( ! empty( $gift_supplier_nickname ) ) {
            $users = get_users( array(
                'meta_key' => 'nickname',
                'meta_value' => $gift_supplier_nickname,
                'number' => 1,
                'fields' => 'ID',
            ) );
            if ( ! empty( $users ) ) {
                $gift_supplier_id = $users[0];
            }
        }

        // --- 步驟 1：分組 (商品) (不變) ---
        $supplier_groups = array();
        foreach ( $items as $item_id => $item ) {
            $supplier_id = $this->get_supplier_id_from_order_item( $item );
            if ( ! isset( $supplier_groups[ $supplier_id ] ) ) {
                $supplier_groups[ $supplier_id ] = array();
            }
            $supplier_groups[ $supplier_id ][ $item_id ] = $item;
        }

        if ( $gift_supplier_id && isset( $supplier_groups[ $gift_supplier_id ] ) && count( $supplier_groups ) > 1) {
            $gift_items = $supplier_groups[ $gift_supplier_id ];
            unset( $supplier_groups[ $gift_supplier_id ] );

            reset( $supplier_groups );
            $first_supplier_id = key( $supplier_groups );

            if ( $first_supplier_id !== null ) {
                $supplier_groups[ $first_supplier_id ] = array_merge( $supplier_groups[ $first_supplier_id ], $gift_items );
            } else {
                $supplier_groups[ $gift_supplier_id ] = $gift_items;
            }
        }

        $supplier_count = count($supplier_groups);

        // --- 步驟 2：檢查是否需要分單 (不變) ---
        if ( $supplier_count <= 1 ) {
            $this->trigger_all_meta_functions( $order_id );
            $parent_order->save();
            return;
        }

        // --- 步驟 3：需要分單 (*** 運費邏輯已修改 ***) ---
        $parent_order->update_meta_data( '_cm_order_split_parent', true );
        $parent_order->add_order_note( '這是一筆父訂單，已開始進行分單。' );
        
        // (*** 關鍵修正 ***)
        // 1. 獲取父訂單的 *單一* 運送方式 (現在只會有一個)
        $original_shipping_item = reset($parent_order->get_items('shipping'));
        if ( !$original_shipping_item ) {
            // 如果沒有運費，無法繼續
            $parent_order->add_order_note('錯誤：找不到原始運費項目，無法分配運費。');
            $parent_order->save();
            return;
        }
        
        // 2. 計算出「單筆」基本運費
        $total_shipping_cost = $original_shipping_item->get_total();
        $base_shipping_cost = $total_shipping_cost / $supplier_count;
        
        // 3. (可選) 處理稅金
        $total_shipping_tax = $original_shipping_item->get_taxes();
        $base_shipping_tax = [];
        if ( !empty($total_shipping_tax['total']) ) {
            foreach ($total_shipping_tax['total'] as $tax_id => $tax_amount) {
                $base_shipping_tax['total'][$tax_id] = $tax_amount / $supplier_count;
            }
        }
        // (*** 修正完畢 ***)
        
        $parent_order->save();

        array_shift( $supplier_groups ); 
        $child_order_ids = array();

        // --- 步驟 4：迴圈建立子訂單 (*** 運費邏輯已修改 ***) ---
        foreach ( $supplier_groups as $supplier_id => $child_items ) {
            try {
                $child_order = wc_create_order( array( 'customer_id' => $parent_order->get_customer_id(), 'parent' => $order_id, 'status' => $parent_order->get_status(), 'currency' => $parent_order->get_currency(), 'customer_note' => $parent_order->get_customer_note() ) );
                if ( is_wp_error( $child_order ) ) { continue; }
                
                $child_order_id = $child_order->get_id();
                $billing_address = $parent_order->get_address( 'billing' );
                if ( is_array( $billing_address ) ) { $child_order->set_address( $billing_address, 'billing' ); }
                $shipping_address = $parent_order->get_address( 'shipping' );
                if ( is_array( $shipping_address ) ) { $child_order->set_address( $shipping_address, 'shipping' ); }
                $child_order->set_payment_method( $parent_order->get_payment_method() );

                // (*** 關鍵修正 ***) 建立一個新的運費項目，並設定為「基本運費」
                $new_shipping_item = new WC_Order_Item_Shipping();
                $new_shipping_item->set_method_title( str_replace( sprintf(' (%d 個包裹)', $supplier_count), '', $original_shipping_item->get_method_title() ) ); // 移除括號
                $new_shipping_item->set_method_id( $original_shipping_item->get_method_id() );
                $new_shipping_item->set_total( $base_shipping_cost );
                $new_shipping_item->set_taxes( $base_shipping_tax );
                $child_order->add_item( $new_shipping_item );

                $cvs_meta_keys = ['_shipping_cvs_store_ID','_shipping_cvs_store_name','_shipping_cvs_store_address','_shipping_cvs_store_telephone'];
                foreach ($cvs_meta_keys as $meta_key) {
                    $meta_value = $parent_order->get_meta($meta_key, true);
                    if ($meta_value) { $child_order->update_meta_data($meta_key, $meta_value); }
                }
                foreach ( $child_items as $item_id => $item ) {
                    $product = $item->get_product();
                    $variation_data = ($product && $product->is_type('variation')) ? $product->get_variation_attributes() : [];
                    $child_order->add_product( $product, $item->get_quantity(), ['variation' => $variation_data, 'subtotal' => $item->get_subtotal(), 'total' => $item->get_total()] );
                    $parent_order->remove_item( $item_id );
                }

                $child_order->calculate_totals(); 
                $child_order->save();
                $this->trigger_all_meta_functions( $child_order_id );
                $child_order->add_order_note( '此訂單是從父訂單 #' . $order_id . ' 拆分而來。' );
                $child_order_ids[] = $child_order_id;

            } catch ( Throwable $e ) {
                $parent_order->add_order_note( '分單失敗：' . $e->getMessage() );
            }
        } 

        // --- 步驟 5：更新父訂單 ---
        // (*** 關鍵修正 ***) 手動將父訂單的運費也設定為「基本運費」
        $original_shipping_item->set_total( $base_shipping_cost );
        $original_shipping_item->set_taxes( $base_shipping_tax );
        $original_shipping_item->set_method_title( str_replace( sprintf(' (%d 個包裹)', $supplier_count), '', $original_shipping_item->get_method_title() ) );
        $original_shipping_item->save();

        $parent_order->calculate_totals(); 
        $parent_order->save();
        $this->trigger_all_meta_functions( $order_id ); 
        $parent_order->add_order_note( '父訂單已完成分單。建立的子訂單：' . implode( ', ', $child_order_ids ) );
    }

    /**
     * 輔助函數：手動觸發我們所有的 Meta 寫入 (不變 - 這個版本是正確的)
     */
    private function trigger_all_meta_functions( $order_id ) {
        
        $order = wc_get_order( $order_id );
        if ( ! $order ) {
            return;
        }

        // --- 1. 寫入供應商 Meta ---
        if ( class_exists( 'CM_Supplier_Order_Meta' ) ) {
            $supplier_id = CM_Supplier_Order_Meta::get_supplier_id_from_order( $order );
            if ( ! empty( $supplier_id ) ) {
                $order->update_meta_data( Cart_Manager_Core::META_ORDER_SUPPLIER_ID, $supplier_id );
            } else {
                $order->delete_meta_data( Cart_Manager_Core::META_ORDER_SUPPLIER_ID );
            }
        }

        // --- 2. 寫入溫層 Meta ---
        if ( class_exists( 'CM_Temp_Order_Meta' ) ) {
            $temp_data = CM_Temp_Order_Meta::get_temperature_data_from_order( $order );
            $order->update_meta_data( Cart_Manager_Core::META_ORDER_TEMP_LAYER, $temp_data );
        }

        // --- 3. 寫入預購 Meta ---
        if ( class_exists( 'CM_Preorder_Order_Meta' ) ) {
            $is_preorder = false; 
            foreach ( $order->get_items() as $item ) {
                $id_to_check = $item->get_variation_id() ? $item->get_variation_id() : $item->get_product_id();
                if ( get_post_meta( $id_to_check, Cart_Manager_Core::META_PRODUCT_IS_PREORDER, true ) === 'yes' ) {
                    $is_preorder = true;
                    break; 
                }
            }
            $order->update_meta_data( Cart_Manager_Core::META_ORDER_PREORDER_STATUS, $is_preorder ? 'yes' : 'no' );
        }
        
        $order->save();
    }


    /**
     * 4. 還原購物車 (不變，由 'thankyou' 觸發)
     */
    public function restore_removed_cart_items( $order_id ) {
        $removed_items = WC()->session->get( $this->removed_items_key );
        if ( empty( $removed_items ) || ! is_array( $removed_items ) ) {
            return; 
        }
        if ( WC()->cart->is_empty() ) {
            foreach ( $removed_items as $item_to_add ) {
                WC()->cart->add_to_cart(
                    $item_to_add['product_id'],
                    $item_to_add['quantity'],
                    $item_to_add['variation_id'],
                    $item_to_add['variation'],
                    $item_to_add['cart_item_data']
                );
            }
        }
        WC()->session->set( $this->removed_items_key, null );
    }

    /**
     * (全新) 7. 在結帳頁面顯示訂單拆分提醒
     *
     * 在 'filter_cart_for_checkout' (priority 10) 之後執行。
     * 檢查 *過濾後* 的購物車中是否仍包含多個供應商。
     * (注意：此鉤子在 wc_print_notices() 之後，所以我們必須手動 echo 提示)
     */
    public function show_split_order_notice() {
        
        // 確保 CM_Cart_Display 類別和其靜態方法存在
        if ( ! class_exists('CM_Cart_Display') || ! method_exists('CM_Cart_Display', 'get_item_supplier_id') ) {
            return;
        }

        // 獲取當前（已過濾）的購物車內容
        $cart_items = WC()->cart->get_cart();
        if ( empty( $cart_items ) ) {
            return;
        }

        $gift_supplier_nickname = '贈品';
        $gift_supplier_id = null;

        if ( ! empty( $gift_supplier_nickname ) ) {
            $users = get_users( array(
                'meta_key' => 'nickname',
                'meta_value' => $gift_supplier_nickname,
                'number' => 1,
                'fields' => 'ID',
            ) );
            if ( ! empty( $users ) ) {
                $gift_supplier_id = $users[0];
            }
        }

        // 1. 將購物車中的商品依供應商分組
        $supplier_groups = array();
        foreach ( $cart_items as $cart_item_key => $cart_item ) {
            // 呼叫 CM_Cart_Display 中的靜態輔助函數
            $supplier_id = CM_Cart_Display::get_item_supplier_id( $cart_item );

            if ( $gift_supplier_id && $supplier_id == $gift_supplier_id ) {
                continue;
            }
            
            // 我們只需要計算數量
            if ( ! array_key_exists( $supplier_id, $supplier_groups ) ) {
                $supplier_groups[ $supplier_id ] = 1;
            }
        }

        // 2. 檢查群組數量
        $supplier_count = count( $supplier_groups );

        if ( $supplier_count > 1 ) {
            // 3. 如果大於 1，手動 echo 提示框的 HTML
            $message = sprintf(
                // translators: %d = number of orders
                esc_html__( '請注意：由於您選擇了 %d 個不同供應商的商品，您的訂單將會被拆分為 %d 筆獨立訂單，並且將收取 %d 筆運費。', 'cart-manager' ),
                $supplier_count,
                $supplier_count,
                $supplier_count
            );
            
            // (*** 關鍵修正 ***)
            // 不使用 wc_add_notice()，而是直接印出 HTML
            echo '<div class="woocommerce-info" role="alert">' . $message . '</div>';
        }
    }

    /**
     * (*** 已重構 ***) 感謝頁面：使用單一函數處理所有顯示邏輯
     *
     * 此函數以高優先級 (5) 執行。
     * - 如果是父訂單，則移除預設顯示，改為顯示自訂表格。
     * - 如果是正常訂單，則不執行任何動作，讓預設 (10) 顯示。
     *
     * @param int $order_id 傳入的訂單 ID
     */
    public function display_thankyou_page_details( $order_id ) {
        
        $order = wc_get_order( $order_id );

        // --- 檢查是否為我們拆分過的父訂單 ---
        if ( $order && $order->get_meta('_cm_order_split_parent') && $order->get_parent_id() === 0 ) {
            
            // --- 情況 A：這是父訂單，我們接管顯示 ---

            // 1. 移除 WooCommerce 預設的訂單詳情表格 (它在 priority 10)
            remove_action( 'woocommerce_thankyou', 'woocommerce_order_details_table', 10 );

            // 2. 獲取所有子訂單
            $child_orders = wc_get_orders( array(
                'parent'  => $order_id,
                'limit'   => -1,
                'orderby' => 'ID',
                'order'   => 'ASC'
            ) );
            $all_orders = array_merge( array( $order ), $child_orders );

            // 3. 顯示我們的自訂表格 (這部分的 HTML 與您之前的功能相同)
            echo '<h2>' . esc_html__( '您的訂單詳情', 'cart-manager' ) . '</h2>';
            echo '<p>' . esc_html__( '由於您訂購了來自不同供應商的商品，您的訂單已被拆分為以下幾張 (包含原始訂單)：', 'cart-manager' ) . '</p>';
            ?>
            <table class="woocommerce-orders-table woocommerce-MyAccount-orders shop_table shop_table_responsive my_account_orders">
                <thead>
                    <tr>
                        <th class="woocommerce-orders-table__header woocommerce-orders-table__header-order-number"><span class="nobr"><?php esc_html_e( '訂單', 'woocommerce' ); ?></span></th>
                        <th class="woocommerce-orders-table__header woocommerce-orders-table__header-order-date"><span class="nobr"><?php esc_html_e( '日期', 'woocommerce' ); ?></span></th>
                        <th class="woocommerce-orders-table__header woocommerce-orders-table__header-order-status"><span class="nobr"><?php esc_html_e( '狀態', 'woocommerce' ); ?></span></th>
                        <th class="woocommerce-orders-table__header woocommerce-orders-table__header-order-total"><span class="nobr"><?php esc_html_e( '總計', 'woocommerce' ); ?></span></th>
                        <th class="woocommerce-orders-table__header woocommerce-orders-table__header-order-actions"><span class="nobr"><?php esc_html_e( '動作', 'woocommerce' ); ?></span></th>
                    </tr>
                </thead>
                <tbody>
                    <?php
                    foreach ( $all_orders as $loop_order ) {
                        ?>
                        <tr class="woocommerce-orders-table__row order">
                            <td class="woocommerce-orders-table__cell woocommerce-orders-table__cell-order-number" data-title="<?php esc_attr_e( '訂單', 'woocommerce' ); ?>">
                                <a href="<?php echo esc_url( $loop_order->get_view_order_url() ); ?>">
                                    #<?php echo esc_html( $loop_order->get_order_number() ); ?>
                                </a>
                            </td>
                            <td class="woocommerce-orders-table__cell woocommerce-orders-table__cell-order-date" data-title="<?php esc_attr_e( '日期', 'woocommerce' ); ?>">
                                <time datetime="<?php echo esc_attr( $loop_order->get_date_created()->date( 'c' ) ); ?>"><?php echo esc_html( wc_format_datetime( $loop_order->get_date_created() ) ); ?></time>
                            </td>
                            <td class="woocommerce-orders-table__cell woocommerce-orders-table__cell-order-status" data-title="<?php esc_attr_e( '狀態', 'woocommerce' ); ?>">
                                <?php echo esc_html( wc_get_order_status_name( $loop_order->get_status() ) ); ?>
                            </td>
                            <td class="woocommerce-orders-table__cell woocommerce-orders-table__cell-order-total" data-title="<?php esc_attr_e( '總計', 'woocommerce' ); ?>">
                                <?php echo wp_kses_post( $loop_order->get_formatted_order_total() ); ?>
                            </td>
                            <td class="woocommerce-orders-table__cell woocommerce-orders-table__cell-order-actions" data-title="<?php esc_attr_e( '動作', 'woocommerce' ); ?>">
                                <a href="<?php echo esc_url( $loop_order->get_view_order_url() ); ?>" class="woocommerce-button button view"><?php esc_html_e( '查看', 'woocommerce' ); ?></a>
                            </td>
                        </tr>
                        <?php
                    }
                    ?>
                </tbody>
            </table>
            <?php
        }
        // --- 情況 B：這是一般訂單，我們什麼都不做 ---
        // 這樣 WooCommerce 預設的 hook (priority 10) 就會正常執行，且只會執行一次。
    }
}