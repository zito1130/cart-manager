<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit; // 安全檢查
}

/**
 * 模組：供應商訂單 Meta (Supplier Order Meta)
 * 職責：當訂單成立時，將商品上的供應商 ID 儲存到訂單上。
 * (已更新) 提取了靜態輔助函數，以便批次操作可以共用。
 */
class CM_Supplier_Order_Meta {

    public function __construct() {
        add_action( 'woocommerce_thankyou', array( $this, 'save_supplier_id_to_order' ), 10, 1 );
    }

    /**
     * (已修改) 将供应商 ID 储存到订单 Meta
     */
    public function save_supplier_id_to_order( $order_id ) {
        $order = wc_get_order( $order_id );
        if ( ! $order ) {
            return;
        }

        // (新增檢查) 如果已經儲存過，就不要重複執行 (防止兩個鉤子重複寫入)
        if ( $order->get_meta( Cart_Manager_Core::META_ORDER_SUPPLIER_ID, true ) ) {
            return;
        }

        // --- 呼叫靜態輔助函數 ---
        $supplier_id = self::get_supplier_id_from_order( $order );
        
        // 如果 $supplier_id 不是空的
        if ( ! empty( $supplier_id ) ) {
            $order->update_meta_data( Cart_Manager_Core::META_ORDER_SUPPLIER_ID, $supplier_id );
            $order->save();
        }
    }

    /**
     * (保持不變) 靜態輔助函數 (Static Helper Function)
     */
    public static function get_supplier_id_from_order( $order ) {
        
        $items = $order->get_items();
        if ( empty( $items ) ) {
            return '';
        }
        $first_item = reset( $items );
        
        $product = $first_item->get_product();
        if ( ! $product ) {
            return '';
        }

        $parent_id = $product->is_type('variation') ? $product->get_parent_id() : $product->get_id();
        $supplier_id = get_post_meta( $parent_id, Cart_Manager_Core::META_PRODUCT_SUPPLIER_ID, true );

        return $supplier_id; 
    }
}