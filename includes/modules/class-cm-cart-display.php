<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit; // 安全檢查
}

/**
 * 模組：購物車共享顯示 (Shared Cart Display)
 *
 * (已重構 - 多供應商結帳 方案)
 * 職責：
 * 1. (PHP) 排序購物車商品。
 * 2. (PHP) 在商品名稱中 "隱藏" 供應商 ID 和 預購狀態。
 * 3. (JS) 讀取隱藏資料，動態插入「帶有勾選框」的獨立 <tr> 標題列。
 * 4. (JS) 處理勾選邏輯 (禁止 現貨/預購 衝突)。
 * 5. (JS) 攔截「前往結帳」按鈕，改為發送 AJAX 儲存勾選狀態。
 * 6. (Mini-Cart) 維持舊的 CSS 模擬方案。
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

        add_action( 'wp_footer', array( $this, 'output_mini_cart_redirect_script' ) );
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
        $supplier_a = self::get_item_supplier_id( $a ); // (改為 self::)
        $supplier_b = self::get_item_supplier_id( $b ); // (改為 self::)
        return $supplier_a <=> $supplier_b;
    }

    
    /**
     * 2. (JS) 輸出 JavaScript 腳本 (*** 已更新：驗證邏輯切換為「溫層」 ***)
     */
    public function output_cart_grouping_script() {
        // 只在主購物車頁面執行
        if ( ! is_cart() ) {
            return;
        }
        
        $nonce = wp_create_nonce( 'cm-cart-nonce' );
        
        ?>
        <script type="text/javascript">
            jQuery(document).ready(function($) {
                
                var $cartTable = $('form.woocommerce-cart-form table.shop_table, table.shop_table.cart').first();
                if ( ! $cartTable.length ) {
                    console.log('Cart Manager: 找不到購物車表格。');
                    return;
                }

                var colCount = $cartTable.find('thead tr:first th').length;
                if ( colCount === 0 ) colCount = 6; 

                var currentSupplierId = null;

                // --- 步驟 1：建立標題列 和 標記商品列 ---
                $cartTable.find('tbody tr.cart_item').each(function() {
                    var $productRow = $(this);
                    
                    var $supplierData = $productRow.find('span.cm-supplier-data').first();
                    if ( ! $supplierData.length ) return;

                    var supplierId = $supplierData.data('supplier-id');
                    var supplierName = $supplierData.data('supplier-name');
                    
                    // (*** 關鍵修改：從 pre-order 切換到 temp-slug ***)
                    var tempSlug = $supplierData.data('temp-slug'); 
                    
                    // 我們仍然需要 preorder-status，以便「全選」邏輯可以區分它們
                    var preorderStatus = $supplierData.data('preorder-status');
                    
                    $productRow.attr('data-supplier-id', supplierId)
                               .attr('data-temp-slug', tempSlug) // (*** 關鍵修改 ***)
                               .attr('data-preorder-status', preorderStatus); // (保留)

                    if ( supplierId !== currentSupplierId ) {
                        currentSupplierId = supplierId; 
                        
                        var checkboxStyle = "width: 18px; height: 18px; margin-right: 15px; flex-shrink: 0;";
                        var cellStyle = "padding-top: 25px; border: none; display: flex; align-items: center;";
                        
                        var cellHTML = '<td colspan="' + colCount + '" style="' + cellStyle + '">' +
                                           '<input type="checkbox" class="cm-supplier-checkbox" ' +
                                           'style="' + checkboxStyle + '" ' +
                                           'data-supplier-id="' + supplierId + '" ' +
                                           'data-preorder-status="' + preorderStatus + '" ' + // (保留)
                                           'data-temp-slug="' + tempSlug + '">' + // (*** 關鍵修改 ***)
                                           '<h3 style="margin: 0; font-size: 1.2em;">' + supplierName + '</h3>' +
                                       '</td>';

                        var $headerRow = $(
                            '<tr class="cm-supplier-header-row">' +
                                cellHTML +
                            '</tr>'
                        );
                        
                        $productRow.before($headerRow);
                    }
                });
                
                // --- 步驟 1.5：插入「全選」勾選框 (不變) ---
                var $firstHeader = $cartTable.find('tr.cm-supplier-header-row').first();
                if ($firstHeader.length) {
                    var selectAllHTML = '<tr class="cm-select-all-row">' +
                        '<td colspan="' + colCount + '" style="padding: 10px 0 10px 16px; border-top: 1px solid #e0e0e0;">' +
                            '<label style="display: flex; align-items: center; margin-bottom: 0; font-weight: bold; font-size: 1.1em;">' +
                                '<input type="checkbox" id="cm-select-all-suppliers" style="width: 18px; height: 18px; margin-right: 15px;">' +
                                '<?php esc_html_e( '全選供應商', 'cart-manager' ); ?>' +
                            '</label>' +
                        '</td>' +
                    '</tr>';
                    $firstHeader.before(selectAllHTML);
                }


                // --- 步驟 2：處理「個別勾選框」衝突邏輯 (*** 關鍵修改：檢查溫層 ***) ---
                $cartTable.on('change', 'input.cm-supplier-checkbox', function() {
                    var $clickedBox = $(this);
                    
                    // (*** 關鍵修改：檢查 data-temp-slug ***)
                    var clickedTemp = $clickedBox.data('temp-slug');
                    var selectedTemp = null;
                    
                    // 找出其他 *已勾選* 的項目
                    $('input.cm-supplier-checkbox:checked').not($clickedBox).each(function() {
                        selectedTemp = $(this).data('temp-slug');
                        return false; 
                    });
                    
                    // 如果點擊的溫層與已選的溫層不同
                    if ( $clickedBox.prop('checked') && selectedTemp !== null && selectedTemp !== clickedTemp ) {
                        // (*** 關鍵修改：更新提示文字 ***)
                        alert('<?php esc_html_e( '您不能同時結帳「不同溫層」的商品。\n請分開結帳。', 'cart-manager' ); ?>');
                        $clickedBox.prop('checked', false);
                    }
                    
                    // 更新「全選」勾選框的狀態 (不變)
                    updateSelectAllCheckboxState();
                });

                // --- 步驟 3：處理「全選」勾選框的點擊邏輯 (*** 關鍵修改：依預購/現貨全選 ***) ---
                // (注意：全選邏輯 *不* 應該檢查溫層，而是檢查「預購/現貨」，因為這才是分開結帳的主因)
                $cartTable.on('change', '#cm-select-all-suppliers', function() {
                    var $selectAll = $(this);
                    var $supplierBoxes = $('input.cm-supplier-checkbox');
                    var isChecked = $selectAll.prop('checked');
                    
                    if (isChecked) {
                        // (*** 保持不變：全選邏輯 *依然* 跟隨 預購/現貨 ***)
                        var firstStatus = $supplierBoxes.first().data('preorder-status');
                        var hasConflict = false;
                        
                        $supplierBoxes.each(function() {
                            if ($(this).data('preorder-status') === firstStatus) {
                                $(this).prop('checked', true);
                            } else {
                                $(this).prop('checked', false); 
                                hasConflict = true;
                            }
                        });
                        
                        if (hasConflict) {
                            var statusText = (firstStatus === 'yes') ? '<?php esc_html_e( '預購', 'cart-manager' ); ?>' : '<?php esc_html_e( '現貨', 'cart-manager' ); ?>';
                            alert('<?php esc_html_e( '已全選所有', 'cart-manager' ); ?>「' + statusText + '」<?php esc_html_e( '的供應商。\n不同狀態的商品已被略過。', 'cart-manager' ); ?>');
                        }
                    } else {
                        $supplierBoxes.prop('checked', false);
                    }
                    
                    // (*** 全新 ***) 全選後，手動觸發一次溫層檢查
                    // 檢查第一個被勾選的框，並取消勾選所有與其溫層衝突的框
                    var $checkedBoxes = $supplierBoxes.filter(':checked');
                    if ($checkedBoxes.length > 0) {
                        var firstCheckedTemp = $checkedBoxes.first().data('temp-slug');
                        var tempConflict = false;
                        
                        $checkedBoxes.not(':first').each(function() {
                            if ($(this).data('temp-slug') !== firstCheckedTemp) {
                                $(this).prop('checked', false);
                                tempConflict = true;
                            }
                        });
                        
                        if (tempConflict) {
                             alert('<?php esc_html_e( '「全選」操作發現溫層衝突，已自動取消勾選不同溫層的商品。', 'cart-manager' ); ?>');
                        }
                    }
                    
                    // (*** 全新 ***) 再次更新全選框狀態
                    updateSelectAllCheckboxState();
                });

                // --- 步驟 4：更新「全選」狀態的輔助函數 (*** 關鍵修改：依預購/現貨 ***) ---
                // (全選框的狀態 *依然* 跟隨 預購/現貨)
                function updateSelectAllCheckboxState() {
                    var $selectAll = $('#cm-select-all-suppliers');
                    if (!$selectAll.length) return;
                    
                    var $supplierBoxes = $('input.cm-supplier-checkbox');
                    var $checkedBoxes = $supplierBoxes.filter(':checked');
                    
                    if ($checkedBoxes.length === 0) {
                        $selectAll.prop('checked', false).prop('indeterminate', false);
                    } else {
                        // (*** 保持不變：檢查 data-preorder-status ***)
                        var firstStatus = $checkedBoxes.first().data('preorder-status');
                        
                        var $eligibleBoxes = $supplierBoxes.filter(function() {
                            return $(this).data('preorder-status') === firstStatus;
                        });
                        
                        if ($checkedBoxes.length === $eligibleBoxes.length) {
                            $selectAll.prop('checked', true).prop('indeterminate', false);
                        } else {
                            $selectAll.prop('checked', false).prop('indeterminate', true);
                        }
                    }
                }


                // --- 步驟 5：攔截「前往結帳」按鈕 (*** 關鍵修改：檢查溫層 ***) ---
                $(document).on('click', 'a.checkout-button', function(e) {
                    e.preventDefault(); 
                    var $checkoutButton = $(this);
                    var checkoutUrl = $checkoutButton.attr('href');
                    var $checkedBoxes = $('input.cm-supplier-checkbox:checked');
                    
                    if ( $checkedBoxes.length === 0 ) {
                        alert('<?php esc_html_e( '請至少勾選一個您要結帳的供應商。', 'cart-manager' ); ?>');
                        return;
                    }

                    // (*** 關鍵修改：現在我們檢查 溫層 (temp-slug) 是否衝突 ***)
                    var selectedTemp = null;
                    var hasTempConflict = false;
                    $checkedBoxes.each(function() {
                        var currentTemp = $(this).data('temp-slug');
                        if ( selectedTemp === null ) {
                            selectedTemp = currentTemp;
                        } else if ( selectedTemp !== currentTemp ) {
                            hasTempConflict = true;
                        }
                    });
                    
                    if ( hasTempConflict ) {
                         // (*** 關鍵修改：更新提示文字 ***)
                         alert('<?php esc_html_e( '您不能同時結帳「不同溫層」的商品。\n請分開結帳。', 'cart-manager' ); ?>');
                         return;
                    }
                    
                    // (註：我們不再檢查 預購/現貨 衝突，因為您希望允許它們一起結帳)

                    var selectedIds = $checkedBoxes.map(function() {
                        return $(this).data('supplier-id');
                    }).get(); 

                    $checkoutButton.addClass('disabled').text('<?php esc_html_e( '處理中，請稍候...', 'cart-manager' ); ?>');

                    // AJAX 請求 (不變)
                    $.ajax({
                        url: '<?php echo esc_url( admin_url('admin-ajax.php') ); ?>',
                        type: 'POST',
                        data: {
                            action: 'cm_set_checkout_suppliers',
                            security: '<?php echo esc_js( $nonce ); ?>',
                            selected_suppliers: selectedIds
                        },
                        success: function(response) {
                            if ( response.success ) {
                                window.location.href = checkoutUrl;
                            } else {
                                alert('<?php esc_html_e( '儲存勾選時發生錯誤: ', 'cart-manager' ); ?>' + (response.data || '<?php esc_html_e( '未知錯誤', 'cart-manager' ); ?>'));
                                $checkoutButton.removeClass('disabled').text('<?php esc_html_e( '前往結帳', 'cart-manager' ); ?>');
                            }
                        },
                        error: function() {
                            alert('<?php esc_html_e( '網路請求失敗，請重試。', 'cart-manager' ); ?>');
                            $checkoutButton.removeClass('disabled').text('<?php esc_html_e( '前往結帳', 'cart-manager' ); ?>');
                        }
                    });
                });
            });
        </script>
        <?php
    }


    /**
     * 3. (*** 關鍵修正：解決 Undefined variable 錯誤 ***) 
     * (Mini-Cart + Meta + 隱藏資料)
     * a) 顯示 Mini-Cart 標題 (CSS 模擬)
     * b) 顯示 商品 Meta (現貨/常溫)
     * c) 隱藏資料 (包含溫層 slug) 供 JS 讀取
     */
    public function display_minicart_header_and_meta( $name, $cart_item, $cart_item_key ) {
        
        $heading = ''; 
        $hidden_data = '';

        $item_supplier_id = self::get_item_supplier_id( $cart_item );
        $supplier_name = self::get_supplier_display_name( $item_supplier_id );

        // --- (C.1) 獲取 Meta ---
        $id_to_check = !empty( $cart_item['variation_id'] ) ? $cart_item['variation_id'] : $cart_item['product_id'];
        
        // (*** 修正 #1：加入 fallback 邏輯，防止 $product 為 null ***)
        $product = $cart_item['data'];
        if ( ! $product ) {
            $product = wc_get_product( $id_to_check );
        }
        // 如果 $product 仍然不存在 (例如商品已被刪除)，提早退出
        if ( ! $product ) {
            return $name;
        }
        
        // 獲取預購狀態
        $preorder_product = get_post_meta( $id_to_check, Cart_Manager_Core::META_PRODUCT_IS_PREORDER, true );
        $preorder_status_string = ($preorder_product === 'yes') ? 'yes' : 'no';

        // (*** 修正 #2：在這裡 (sprintf 之前) 定義 $shipping_class_slug ***)
        $shipping_class_slug = $product->get_shipping_class();

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

        // --- (B) 隱藏資料 (使用我們在 C.1 中定義的變數) ---
        $hidden_data = sprintf(
            '<span class="cm-supplier-data" data-supplier-id="%s" data-supplier-name="%s" data-preorder-status="%s" data-temp-slug="%s" style="display:none;"></span>',
            esc_attr( $item_supplier_id ),
            esc_attr( $supplier_name ),
            esc_attr( $preorder_status_string ),
            esc_attr( $shipping_class_slug ) // (現在 $shipping_class_slug 肯定已定義)
        );


        // --- (C.2) 商品 Meta 顯示邏輯 (*** 修正 #3：移除多餘的定義 ***) ---
        $meta_html = '';
 
        if ( $preorder_product === 'yes' ) {
            $meta_html .= '<br><small>' . __( '預購', 'woocommerce' ) . '</small>';
        } else {
            $meta_html .= '<br><small>' . __( '現貨', 'woocommerce' ) . '</small>';
        }

        // (*** 修正 ***) $shipping_class_slug 已經在上面定義過了，我們直接使用即可
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
    public function reset_supplier_tracker_mini() {
        $this->current_supplier_id_mini = null;
    }

    /**
     * 5. 輔助函數 (*** 修改 ***)
     * (改為 public static 讓 CM_Checkout_Manager 可以呼叫)
     */
    public static function get_item_supplier_id( $cart_item ) {
        
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
        
        // (*** 全新 ***) 確保站方商品的 ID 是一個 '0' 或 'stand' 之類的字串，而不是空字串
        return $supplier_id ? $supplier_id : 'stand'; // 'stand' 代表站方
    }

    /**
     * 輔助函數 (*** 修改 ***)
     * (改為 public static)
     */
    public static function get_supplier_display_name( $supplier_id ) {
        // (*** 修改 ***) 配合上面的 'stand'
        if ( empty( $supplier_id ) || $supplier_id === 'stand' ) {
            return __( '站方商品', 'cart-manager' );
        }

        $supplier = get_userdata( $supplier_id );
        if ( $supplier ) {
            return ! empty( $supplier->nickname ) ? $supplier->nickname : $supplier->user_login;
        }

        return __( '未知供應商', 'cart-manager' );
    }

    /**
     * (*** 全新 ***)
     * 5. (JS) 輸出 Mini-Cart 重新導向腳本
     *
     * 攔截 mini-cart 的結帳按鈕，強制導向到主購物車頁面。
     */
    public function output_mini_cart_redirect_script() {
        
        // 這個腳本不需要在購物車或結帳頁面執行
        if ( is_cart() || is_checkout() ) {
            return;
        }
        
        // 獲取主購物車頁面的 URL
        $cart_url = wc_get_cart_url();
        if ( ! $cart_url ) {
            return;
        }
        ?>
        <script type="text/javascript">
            jQuery(document).ready(function($) {
                
                // 我們使用 'body' 進行事件委派 (event delegation)，
                // 這樣才能捕捉到由 AJAX 動態載入的 mini-cart 內容
                $('body').on('click', '.widget_shopping_cart_content .checkout.wc-forward', function(e) {
                    
                    // 1. 阻止按鈕的預設行為 (前往結帳)
                    e.preventDefault();
                    
                    // 2. 強制將用戶導向到主購物車頁面
                    window.location.href = '<?php echo esc_url( $cart_url ); ?>';
                });
            });
        </script>
        <?php
    }

    /**
     * (全新) 輔助函數：獲取依供應商分組的重量
     * @return array [ 'supplier_id' => 'weight', 'supplier_id_b' => 'weight_b' ]
     */
    public static function get_cart_weight_by_supplier() {
        $cart_items = WC()->cart->get_cart();
        $supplier_weights = array();

        if ( empty( $cart_items ) ) {
            return $supplier_weights;
        }

        foreach ( $cart_items as $cart_item_key => $cart_item ) {
            // 呼叫本類別中的靜態函數
            $supplier_id = self::get_item_supplier_id( $cart_item );
            
            // 確保 $cart_item['data'] 是一個 WC_Product 物件
            if ( ! is_object( $cart_item['data'] ) || ! method_exists( $cart_item['data'], 'get_weight' ) ) {
                continue;
            }

            $product_weight = (float) $cart_item['data']->get_weight();
            $quantity = (int) $cart_item['quantity'];
            
            if ( ! isset( $supplier_weights[ $supplier_id ] ) ) {
                $supplier_weights[ $supplier_id ] = 0;
            }
            $supplier_weights[ $supplier_id ] += ( $product_weight * $quantity );
        }
        return $supplier_weights;
    }
}