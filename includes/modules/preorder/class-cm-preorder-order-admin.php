<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit; // 安全檢查
}

/**
 * 模組：預購訂單後台管理 (Preorder Order Admin)
 *
 * 職責：處理所有 WooCommerce 訂單列表頁面上的預購相關功能。
 * 包含：新增欄位、新增篩選器、以及批次勾選的 JS 邏輯。
 * (這是此檔案的正確版本)
 */
class CM_Preorder_Order_Admin {

    /**
     * 建構子：註冊所有訂單相關的鉤子
     */
    public function __construct() {
        
        // 1. 來自 add-orders-page-pre-column.php 的功能
        add_filter( 'manage_woocommerce_page_wc-orders_columns', array( $this, 'add_preorder_status_column' ) );
        add_action( 'manage_woocommerce_page_wc-orders_custom_column', array( $this, 'show_preorder_status_column' ), 10, 2 );

        // 2. 來自 pre-filter-orders-page.php 的功能
        add_action( 'woocommerce_order_list_table_restrict_manage_orders', array( $this, 'add_preorder_status_filter' ) );
        add_filter( 'woocommerce_order_query_args', array( $this, 'filter_orders_by_preorder_status' ) );

        // 3. 來自 pre-checkbox-bulk-action.php 的功能
        add_action( 'admin_footer', array( $this, 'pre_order_selection_script' ) );
    }

    /**
     * 1. 在訂單列表中添加預購狀態欄位
     * (來源: add-orders-page-pre-column.php)
     */
    public function add_preorder_status_column( $columns ) {
        $new_columns = array();
        foreach ( $columns as $key => $value ) {
            $new_columns[$key] = $value;
            if ( 'order_status' === $key ) {
                $new_columns['preorder_status'] = __( '預購狀態', 'woocommerce' );
            }
        }
        return $new_columns;
    }

    /**
     * 2. 在訂單列表中顯示預購狀態的值 (使用核心常數)
     * (來源: add-orders-page-pre-column.php)
     */
    public function show_preorder_status_column( $column, $post_id ) {
        if ( 'preorder_status' === $column ) {
            $order = wc_get_order( $post_id ); 
            // 使用我們定義的核心常數 Meta Key
            $preorder_status = $order->get_meta( Cart_Manager_Core::META_ORDER_PREORDER_STATUS ); 

            $color = $preorder_status === 'yes' ? 'red' : 'black';
            echo '<span style="color: ' . esc_attr( $color ) . ';">' . esc_html( $preorder_status === 'yes' ? '是' : '否' ) . '</span>';
        }
    } 

    /**
     * 3. 在訂單頁面添加預購狀態篩選下拉選單
     * (來源: pre-filter-orders-page.php)
     */
    public function add_preorder_status_filter() {
        $selected = isset( $_GET['preorder_status_filter'] ) ? sanitize_text_field( $_GET['preorder_status_filter'] ) : '';
        echo '<select name="preorder_status_filter" id="preorder_status_filter">';
        echo '<option value="">' . __( '所有訂單', 'woocommerce' ) . '</option>';
        echo '<option value="yes"' . selected( $selected, 'yes', false ) . '>' . __( '預購訂單', 'woocommerce' ) . '</option>';
        echo '<option value="no"' . selected( $selected, 'no', false ) . '>' . __( '一般訂單', 'woocommerce' ) . '</option>';
        echo '</select>';
    } 

    /**
     * 4. 根據選擇的預購狀態過濾訂單 (使用核心常數)
     * (來源: pre-filter-orders-page.php)
     */
    public function filter_orders_by_preorder_status( $query_args ) {
        if ( isset( $_GET['preorder_status_filter'] ) && $_GET['preorder_status_filter'] !== '' ) {
            $preorder_status = sanitize_text_field( $_GET['preorder_status_filter'] );

            $query_args['meta_query'][] = array(
                 // 使用我們定義的核心常數 Meta Key
                'key' => Cart_Manager_Core::META_ORDER_PREORDER_STATUS,
                'value' => $preorder_status,
                'compare' => '='
            );
        }
        return $query_args;
    } 

    /**
     * 5. 添加自定義 JavaScript 以處理勾選邏輯 (確保同狀態才可批次處理)
     * (來源: pre-checkbox-bulk-action.php)
     */
    public function pre_order_selection_script() {
        // 只在訂單列表頁載入此 JS
        $screen = get_current_screen();
        if ($screen && $screen->id === 'woocommerce_page_wc-orders') {
            ?>
            <script type="text/javascript">
                jQuery(document).ready(function($) {
                    var hasShownAlert = false; 

                    $('input[type="checkbox"]').change(function() {
                        var checkedOrders = $('table.wp-list-table tbody .check-column input[type="checkbox"]:checked');
                        if (checkedOrders.length > 0) {
                            var preorderStatuses = [];
                            var uncheckedCount = 0;

                            checkedOrders.each(function() {
                                var row = $(this).closest('tr');
                                var preorderStatus = row.find('.preorder_status').text().trim(); 
                                if (preorderStatuses.length === 0) {
                                    preorderStatuses.push(preorderStatus);
                                } else if (preorderStatuses.indexOf(preorderStatus) === -1) {
                                    $(this).prop('checked', false); // 取消勾選
                                    uncheckedCount++;
                                }
                            });

                            if (uncheckedCount > 0 && !hasShownAlert) {
                                var message = '已自動取消勾選 ' + uncheckedCount + ' 個不同預購狀態的訂單。';
                                alert(message);
                                hasShownAlert = true;
                            }
                        } else {
                            hasShownAlert = false; 
                        }
                    });
                });
            </script>
            <?php
        }
    }
}