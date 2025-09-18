<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit; // 安全檢查
}

/**
 * 模組：溫層訂單後台管理 (Temperature Order Admin)
 * (已更新) 現在使用核心常數，並呼叫靜態輔助函數來處理批次操作。
 */
class CM_Temp_Order_Admin {

    public function __construct() {
        // ... (其他鉤子不變) ...
        add_filter( 'manage_woocommerce_page_wc-orders_columns', array( $this, 'add_temperature_layer_column' ) );
        add_action( 'manage_woocommerce_page_wc-orders_custom_column', array( $this, 'show_temperature_layer_column' ), 10, 2 );
        add_action( 'woocommerce_order_list_table_restrict_manage_orders', array( $this, 'add_temperature_layer_filter' ) );
        add_filter( 'woocommerce_order_query_args', array( $this, 'filter_orders_by_temperature_layer' ) );
        add_filter( 'bulk_actions-woocommerce_page_wc-orders', array( $this, 'add_bulk_actions_check_temperature' ), 20, 1 );
        add_filter( 'handle_bulk_actions-woocommerce_page_wc-orders', array( $this, 'handle_bulk_action_check_temperature' ), 10, 3 );
        add_action( 'admin_footer', array( $this, 'order_selection_script' ) );
    }

    /**
     * 1. 添加溫層欄位
     */
    public function add_temperature_layer_column( $columns ) {
        // ... (邏輯不變) ...
        $new_columns = array();
        foreach ( $columns as $key => $value ) {
            $new_columns[$key] = $value;
            if ( 'order_status' === $key ) {
                $new_columns['temperature_layer'] = __( '溫層', 'woocommerce' );
            }
        }
        return $new_columns;
    }

    /**
     * 2. 顯示溫層欄位值 (使用核心常數)
     */
    public function show_temperature_layer_column( $column, $post_id ) {
        if ( 'temperature_layer' === $column ) {
            $order = wc_get_order( $post_id ); 
            // (改進 #3) 使用常數
            $temperature_layer = $order->get_meta( Cart_Manager_Core::META_ORDER_TEMP_LAYER ); 

            if ( is_array( $temperature_layer ) && isset( $temperature_layer['name'] ) ) {
                $name = esc_html( $temperature_layer['name'] ); 
                if ( '冷凍' === $temperature_layer['name'] ) { 
                    echo '<span style="color: lightblue;">' . $name . '</span>';
                } else {
                    echo $name; 
                }
            } else {
                echo esc_html__( '無', 'woocommerce' );
            }
        }
    }

    /**
     * 3. 添加溫層篩選器
     */
    public function add_temperature_layer_filter() {
        // ... (邏輯不變) ...
        $shipping_classes = get_terms( array(
            'taxonomy' => 'product_shipping_class',
            'hide_empty' => false,
        ) );

        echo '<select id="temperature_layer_filter" name="temperature_layer_filter">';
        echo '<option value="">' . __( '所有溫層', 'woocommerce' ) . '</option>';
        foreach ( $shipping_classes as $class ) {
            echo '<option value="' . esc_attr( $class->slug ) . '">' . esc_html( $class->name ) . '</option>';
        }
        echo '</select>';
    }

    /**
     * 4. 處理溫層篩選邏輯 (使用核心常數)
     */
    public function filter_orders_by_temperature_layer( $query_args ) {
        if ( isset( $_GET['temperature_layer_filter'] ) && ! empty( $_GET['temperature_layer_filter'] ) ) {
            $temperature_layer_slug = sanitize_text_field( $_GET['temperature_layer_filter'] );
            $query_args['meta_query'][] = array(
                // (改進 #3) 使用常數
                'key' => Cart_Manager_Core::META_ORDER_TEMP_LAYER,
                'value' => $temperature_layer_slug,
                'compare' => 'LIKE'
            );
        }
        return $query_args;
    }

    /**
     * 5. 添加 "檢查溫層" 批次操作
     */
    public function add_bulk_actions_check_temperature( $bulk_actions ) {
        $bulk_actions['check_temperature'] = __( '檢查溫層', 'your-text-domain' );
        return $bulk_actions;
    }

    /**
     * 6. 處理 "檢查溫層" 批次操作邏輯 (遵循 DRY 原則)
     */
    public function handle_bulk_action_check_temperature( $redirect_to, $action, $order_ids ) {
        if ( $action !== 'check_temperature' ) {
            return $redirect_to;
        }

        foreach ( $order_ids as $order_id ) {
            $order = wc_get_order( $order_id );
            if ( ! $order ) continue;

            // --- (改進 #2) 遵循 DRY 原則 ---
            // 刪除所有重複的 foreach 檢查邏輯，
            // 直接呼叫我們在 Meta 類別中建立的靜態輔助函數。
            $temp_data = CM_Temp_Order_Meta::get_temperature_data_from_order( $order );
            
            // (改進 #3) 使用常數
            $order->update_meta_data( Cart_Manager_Core::META_ORDER_TEMP_LAYER, $temp_data );
            $order->save();
            // -----------------------------
        }
        return $redirect_to;
    }

    /**
     * 7. 添加溫層批次勾選的 JS 驗證
     */
    public function order_selection_script() {
        // ... (JS 邏輯不變) ...
        $screen = get_current_screen();
        if ($screen && $screen->id === 'woocommerce_page_wc-orders') {
            ?>
            <script type"text/javascript">
                jQuery(document).ready(function($) {
                    var hasShownAlert = false; 

                    $('input[type="checkbox"]').change(function() {
                        var checkedOrders = $('table.wp-list-table tbody .check-column input[type="checkbox"]:checked');
                        if (checkedOrders.length > 0) {
                            var temperatureLayers = [];
                            var firstTemperatureLayer = '';
                            var uncheckedCount = 0;

                            checkedOrders.each(function() {
                                var row = $(this).closest('tr');
                                var temperatureLayer = row.find('.temperature_layer').text().trim(); 
                                if (temperatureLayers.length === 0) {
                                    firstTemperatureLayer = temperatureLayer;
                                    temperatureLayers.push(temperatureLayer);
                                } else if (temperatureLayers.indexOf(temperatureLayer) === -1) {
                                    $(this).prop('checked', false); 
                                    uncheckedCount++;
                                }
                            });

                            if (uncheckedCount > 0 && !hasShownAlert) {
                                var message = '已自動取消勾選 ' + uncheckedCount + ' 個不同運送類別的訂單。\n';
                                message += '現在只選擇了 ' + firstTemperatureLayer + ' 溫層的訂單。';
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