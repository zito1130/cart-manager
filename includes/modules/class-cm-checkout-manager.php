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
        add_action( 'woocommerce_thankyou', array( $this, 'conditionally_remove_default_order_details' ), 5, 1 );
        add_action( 'woocommerce_thankyou', array( $this, 'display_split_orders_table_on_thankyou' ), 20, 1 );
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
     * 3. 依供應商拆分訂單 (*** 已更新為：複製運費 ***)
     * * 父訂單保留原始運費
     * * 所有子訂單 *複製* 原始運費
     */
    public function split_order_by_supplier( $order_id ) {
        
        // --- 檢查階段 (不變) ---
        $parent_order = wc_get_order( $order_id );
        if ( ! $parent_order ) {
            return;
        }
        if ( $parent_order->get_meta( '_cm_order_split_parent' ) || $parent_order->get_parent_id() > 0 ) {
            return;
        }
        $items = $parent_order->get_items();
        if ( empty( $items ) ) {
            return;
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

        // --- 步驟 2：檢查是否需要分單 (不變) ---
        if ( count( $supplier_groups ) <= 1 ) {
            $this->trigger_all_meta_functions( $order_id );
            $parent_order->save();
            return;
        }

        // --- 步驟 3：需要分單 (不變) ---
        $parent_order->update_meta_data( '_cm_order_split_parent', true );
        $parent_order->add_order_note( '這是一筆父訂單，已開始進行分單。' );
        $parent_order->save(); // 立即儲存 (防止競爭條件)

        $first_group_items = array_shift( $supplier_groups ); 
        $child_order_ids = array();

        // --- 步驟 4：迴圈建立子訂單 (*** 運費邏輯已修改 ***) ---
        foreach ( $supplier_groups as $supplier_id => $child_items ) {
            
            try {
                // A. 建立子訂單 (不變)
                $child_order = wc_create_order( array(
                    'customer_id'   => $parent_order->get_customer_id(),
                    'parent'        => $order_id, 
                    'status'        => $parent_order->get_status(),
                    'currency'      => $parent_order->get_currency(),
                    'customer_note' => $parent_order->get_customer_note()
                ) );

                if ( is_wp_error( $child_order ) ) {
                    $error_string = $child_order->get_error_message();
                    $parent_order->add_order_note( 'Debug: 建立子訂單失敗。錯誤：' . $error_string );
                    $parent_order->save();
                    continue;
                }
                
                $child_order_id = $child_order->get_id();

                // B. 複製基礎資訊 (不變)
                $billing_address = $parent_order->get_address( 'billing' );
                if ( is_array( $billing_address ) && ! empty( $billing_address ) ) {
                    $child_order->set_address( $billing_address, 'billing' );
                }
                $shipping_address = $parent_order->get_address( 'shipping' );
                if ( is_array( $shipping_address ) && ! empty( $shipping_address ) ) {
                    $child_order->set_address( $shipping_address, 'shipping' );
                }
                $child_order->set_payment_method( $parent_order->get_payment_method() );
                $child_order->set_payment_method_title( $parent_order->get_payment_method_title() );
                $child_order->set_transaction_id( $parent_order->get_transaction_id() );

                // B.2 (*** 關鍵修正 ***) 
                // 複製父訂單的「運送方式」，並「複製」原始運費
                foreach ( $parent_order->get_items( 'shipping' ) as $shipping_item_id => $shipping_item ) {
                    $new_shipping_item = new WC_Order_Item_Shipping();
                    $new_shipping_item->set_method_title( $shipping_item->get_method_title() );
                    $new_shipping_item->set_method_id( $shipping_item->get_method_id() );
                    
                    // --- (*** 這就是您要的修改 ***) ---
                    // 不再設為 0，而是複製父訂單的運費金額
                    $new_shipping_item->set_total( $shipping_item->get_total() );
                    // (建議) 同時複製稅金，以防萬一
                    $new_shipping_item->set_taxes( $shipping_item->get_taxes() );
                    // --- (*** 修改完畢 ***) ---
                    
                    $child_order->add_item( $new_shipping_item );
                }

                // C. 複製商品 (不變，已包含 'get_variation_attributes' 修正)
                foreach ( $child_items as $item_id => $item ) {
                    $product = $item->get_product();
                    $quantity = $item->get_quantity();
                    
                    $variation_data = array();
                    if ( $product && $product->is_type('variation') ) {
                        $variation_data = $product->get_variation_attributes();
                    }

                    $child_order->add_product( $product, $quantity, array(
                        'variation' => $variation_data, 
                        'subtotal'  => $item->get_subtotal(),
                        'total'     => $item->get_total(),
                    ) );
                    
                    // 從父訂單移除此商品
                    $parent_order->remove_item( $item_id );
                }

                // D. 重新計算子訂單的總價 (現在會包含複製過來的運費)
                $child_order->calculate_totals(); 
                $child_order->save();
                
                // E. 手動觸發 Meta 函數
                $this->trigger_all_meta_functions( $child_order_id );
                
                $child_order->add_order_note( '此訂單是從父訂單 #' . $order_id . ' 拆分而來。' );
                
                $child_order_ids[] = $child_order_id;

            } catch ( Throwable $e ) { // (不變) 捕捉所有錯誤
                $parent_order->add_order_note( '分單失敗：' . $e->getMessage() . ' (檔案: ' . $e->getFile() . ' 行號: ' . $e->getLine() . ')' );
            }
        } // end foreach child group

        // --- 步驟 5：更新父訂單 (不變) ---
        // (*** 關鍵 ***) 傳入 'false' 參數，
        // 告訴 WC *不要* 重新計算運費 (保留原始運費)
        $parent_order->calculate_totals( false ); 
        $parent_order->save();
        
        // 再次觸發父訂單的 Meta
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
     * (全新) 5. 感謝頁面 (A)：有條件地移除預設訂單詳情
     *
     * 此函數以高優先級 (5) 執行，在 WooCommerce (10) 顯示詳情之前。
     * 如果這是一張父訂單，它會移除預設的顯示動作，以便我們後續(20)可以插入自訂表格。
     *
     * @param int $order_id 傳入的父訂單 ID
     */
    public function conditionally_remove_default_order_details( $order_id ) {
        $parent_order = wc_get_order( $order_id );
        
        // 檢查這是否為一個我們拆分過的父訂單
        if ( $parent_order && $parent_order->get_meta( '_cm_order_split_parent' ) && $parent_order->get_parent_id() === 0 ) {
            // 移除 WooCommerce 預設的 'woocommerce_order_details_table' 動作
            remove_action( 'woocommerce_thankyou', 'woocommerce_order_details_table', 10 );
        }
    }

    /**
     * (全新) 6. 感謝頁面 (B)：顯示所有拆分訂單的摘要表格
     *
     * 此函數在 (20) 執行，顯示一個類似「我的帳號」頁面的表格，
     * 列出父訂單以及所有子訂單。
     *
     * @param int $order_id 傳入的父訂單 ID
     */
    public function display_split_orders_table_on_thankyou( $order_id ) {
        
        $parent_order = wc_get_order( $order_id );

        // 1. 再次檢查這是否為父訂單
        if ( ! $parent_order || ! $parent_order->get_meta( '_cm_order_split_parent' ) || $parent_order->get_parent_id() > 0 ) {
            // 如果 'conditionally_remove_default_order_details' 移除了預設表格，
            // 但這裡檢查失敗，我們需要手動把預設表格加回去，以防萬一
            if ( ! did_action('woocommerce_thankyou_order_details_table') ) {
                 wc_get_template( 'order/order-details.php', array( 'order_id' => $order_id ) );
            }
            return;
        }

        // 2. 獲取所有子訂單
        $child_orders = wc_get_orders( array(
            'parent'  => $order_id,
            'limit'   => -1,
            'orderby' => 'ID',
            'order'   => 'ASC'
        ) );

        // 3. 建立一個包含 *所有* 相關訂單的陣列 (父 + 子)
        $all_orders = array_merge( array( $parent_order ), $child_orders );

        if ( empty( $all_orders ) ) {
            return;
        }

        // 4. 顯示介紹標題
        echo '<h2>' . esc_html__( '您的訂單詳情', 'cart-manager' ) . '</h2>';
        echo '<p>' . esc_html__( '由於您訂購了來自不同供應商的商品，您的訂單已被拆分為以下幾張 (包含原始訂單)：', 'cart-manager' ) . '</p>';

        // 5. 建立表格 (使用 WooCommerce 的 CSS class 來匹配樣式)
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
                // 迴圈 $all_orders 陣列
                foreach ( $all_orders as $order ) {
                    ?>
                    <tr class="woocommerce-orders-table__row order">
                        <td class="woocommerce-orders-table__cell woocommerce-orders-table__cell-order-number" data-title="<?php esc_attr_e( '訂單', 'woocommerce' ); ?>">
                            <a href="<?php echo esc_url( $order->get_view_order_url() ); ?>">
                                #<?php echo esc_html( $order->get_order_number() ); ?>
                            </a>
                        </td>
                        <td class="woocommerce-orders-table__cell woocommerce-orders-table__cell-order-date" data-title="<?php esc_attr_e( '日期', 'woocommerce' ); ?>">
                            <time datetime="<?php echo esc_attr( $order->get_date_created()->date( 'c' ) ); ?>"><?php echo esc_html( wc_format_datetime( $order->get_date_created() ) ); ?></time>
                        </td>
                        <td class="woocommerce-orders-table__cell woocommerce-orders-table__cell-order-status" data-title="<?php esc_attr_e( '狀態', 'woocommerce' ); ?>">
                            <?php echo esc_html( wc_get_order_status_name( $order->get_status() ) ); ?>
                        </td>
                        <td class="woocommerce-orders-table__cell woocommerce-orders-table__cell-order-total" data-title="<?php esc_attr_e( '總計', 'woocommerce' ); ?>">
                            <?php
                            // 顯示金額和商品數量
                            echo wp_kses_post( $order->get_formatted_order_total() );
                            ?>
                        </td>
                        <td class="woocommerce-orders-table__cell woocommerce-orders-table__cell-order-actions" data-title="<?php esc_attr_e( '動作', 'woocommerce' ); ?>">
                            <a href="<?php echo esc_url( $order->get_view_order_url() ); ?>" class="woocommerce-button button view"><?php esc_html_e( '查看', 'woocommerce' ); ?></a>
                        </td>
                    </tr>
                    <?php
                }
                ?>
            </tbody>
        </table>
        <?php
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

        // 1. 將購物車中的商品依供應商分組
        $supplier_groups = array();
        foreach ( $cart_items as $cart_item_key => $cart_item ) {
            // 呼叫 CM_Cart_Display 中的靜態輔助函數
            $supplier_id = CM_Cart_Display::get_item_supplier_id( $cart_item );
            
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
                esc_html__( '請注意：由於您選擇了 %d 個不同供應商的商品，您的訂單將會被拆分為 %d 筆獨立訂單。', 'cart-manager' ),
                $supplier_count,
                $supplier_count
            );
            
            // (*** 關鍵修正 ***)
            // 不使用 wc_add_notice()，而是直接印出 HTML
            echo '<div class="woocommerce-info" role="alert">' . $message . '</div>';
        }
    }
}