<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit; // 安全檢查
}

/**
 * 模組：溫層訂單 Meta 處理 (Temperature Order Meta)
 *
 * 職責：當訂單成立時，自動檢查並寫入 'temperature-layer' meta。
 * (改進 #2) 這個類別現在還包含一個靜態輔助函數，供其他模組 (如 Admin) 呼叫。
 */
class CM_Temp_Order_Meta {

    public function __construct() {
        add_action( 'woocommerce_thankyou', array( $this, 'validate_and_set_meta' ), 10, 1 );
    }

    /**
     * 當訂單成立時觸發的鉤子
     */
    public function validate_and_set_meta( $order_id ) {
        $order = wc_get_order( $order_id ); 
        if ( ! $order ) {
            return;
        }

        // 檢查 meta 是否已經被設置 (避免重複執行)
        if ( $order->get_meta( Cart_Manager_Core::META_ORDER_TEMP_LAYER, true ) ) {
             return;
        }

        // --- (改進 #2) 呼叫靜態輔助函數來獲取溫層資料 ---
        $temp_data = self::get_temperature_data_from_order( $order );
        
        // 根據結果更新 Meta
        $order->update_meta_data( Cart_Manager_Core::META_ORDER_TEMP_LAYER, $temp_data );

        // 如果是混合的錯誤訂單，自動取消
        if ( $temp_data === '錯誤訂單' ) {
            $order->update_status( 'cancelled', __( '訂單因運送類別不一致而被取消。', 'woocommerce' ) );
        }

        $order->save(); 
    }

    /**
     * (改進 #2) 靜態輔助函數 (Static Helper Function)
     *
     * 抽離出來的共享邏輯：分析一個訂單物件，並返回它的溫層資料。
     * 這允許 'handle_bulk_action_check_temperature' (在 Admin 類別中) 重複使用此邏輯，遵循 DRY 原則。
     *
     * @param WC_Order $order 訂單物件.
     * @return array|string 成功時返回陣列 ['slug' => ..., 'name' => ...]，失敗或無溫層時返回字串。
     */
    public static function get_temperature_data_from_order( $order ) {
        $shipping_classes = array();
        $shipping_class_names = array(); 

        // 獲取訂單中的每個商品的運送類別
        foreach ( $order->get_items() as $item_id => $item ) {
            $product = $item->get_product(); 
            if ( $product ) {
                $shipping_class_slug = $product->get_shipping_class(); 
                if ( $shipping_class_slug ) {
                    $shipping_classes[] = $shipping_class_slug; 
                    $shipping_class = get_term_by( 'slug', $shipping_class_slug, 'product_shipping_class' ); 
                    if ( $shipping_class && ! is_wp_error( $shipping_class ) ) {
                        $shipping_class_names[] = $shipping_class->name; 
                    }
                }
            }
        } // 邏輯來源

        // 檢查運送類別是否一致
        if ( count( array_unique( $shipping_classes ) ) === 1 ) {
            // 所有運送類別相同
            return array(
                'slug' => reset( $shipping_classes ),
                'name' => reset( $shipping_class_names )
            );
        } elseif ( count( array_unique( $shipping_classes ) ) > 1 ) {
             // 運送類別不相同 (混合)
            return '錯誤訂單';
        } else {
            // 沒有任何商品有運送類別
            return '無溫層';
        }
    }
}