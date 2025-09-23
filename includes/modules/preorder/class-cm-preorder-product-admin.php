<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit; // 安全檢查
}

/**
 * 模組：預購商品後台
 * (已更新) 現在使用核心常數。
 */
class CM_Preorder_Product_Admin {

    public function __construct() {
        add_action( 'woocommerce_product_options_general_product_data', array( $this, 'add_preorder_checkbox_simple' ) );
        add_action( 'woocommerce_process_product_meta', array( $this, 'save_preorder_checkbox_simple' ) );

        add_filter( 'manage_edit-product_columns', array( $this, 'add_preorder_column' ) );
        add_action( 'manage_product_posts_custom_column', array( $this, 'show_preorder_column' ), 10, 2 );
        add_action( 'admin_head', array( $this, 'set_column_width' ) );

        add_action( 'woocommerce_variation_options', array( $this, 'add_preorder_checkbox_variable' ), 10, 3 );
        add_action( 'woocommerce_save_product_variation', array( $this, 'save_preorder_checkbox_variable' ), 10, 2 );

        add_action( 'woocommerce_product_bulk_edit_start', array( $this, 'add_preorder_bulk_edit_field' ) );
        add_action( 'woocommerce_product_bulk_edit_save', array( $this, 'save_preorder_bulk_edit_field' ) );
    }

    /**
     * 添加預購核取方塊 (使用核心常數)
     */
    public function add_preorder_checkbox_simple() {
        global $post;
        $preorder_product = get_post_meta( $post->ID, Cart_Manager_Core::META_PRODUCT_IS_PREORDER, true );
        
        // 顯示一個 div 包住，方便用 JS 隱藏
        echo '<div class="options_group show_if_simple">'; 
        woocommerce_wp_checkbox( array(
            'id'            => Cart_Manager_Core::META_PRODUCT_IS_PREORDER, 
            'label'         => __( '預購商品', 'woocommerce' ),
            'description'   => __( '勾選此選項以將此商品設為預購商品。', 'woocommerce' ),
            'value'         => $preorder_product
        ));
        echo '</div>';
    }

    /**
     * 保存預購核取方塊 (使用核心常數)
     */
    public function save_preorder_checkbox_simple( $post_id ) {
        // 檢查是否為可變商品，如果是，我們就忽略這個欄位，讓 variation 儲存 hook 處理
        $product = wc_get_product($post_id);
        if ($product && $product->is_type('variable')) {
            // 如果是可變商品，清除主商品的 meta，因為邏輯將轉移到 variation 上
             delete_post_meta($post_id, Cart_Manager_Core::META_PRODUCT_IS_PREORDER);
             return; 
        }

        $preorder_product = isset( $_POST[Cart_Manager_Core::META_PRODUCT_IS_PREORDER] ) ? 'yes' : 'no';
        update_post_meta( $post_id, Cart_Manager_Core::META_PRODUCT_IS_PREORDER, $preorder_product );
    }

    /**
     * (全新) 在「變化類型」中添加預購勾選框
     *
     * @param int     $loop           循環索引
     * @param array   $variation_data 變化類型資料
     * @param WP_Post $variation      變化類型 Post 物件
     */
    public function add_preorder_checkbox_variable( $loop, $variation_data, $variation ) {
        // 從「變化類型 ID」($variation->ID) 獲取 meta
        $is_preorder = get_post_meta( $variation->ID, Cart_Manager_Core::META_PRODUCT_IS_PREORDER, true );

        woocommerce_wp_checkbox( array(
            'id'            => "variation_preorder_{$loop}", // ID 必須是唯一的
            'name'          => "variation_preorder[{$loop}]", // 儲存時 WordPress 會讀取的陣列名稱
            'label'         => __( '預購商品', 'woocommerce' ),
            'description'   => __( '勾選以將此變化類型設為預購。', 'woocommerce' ),
            'value'         => $is_preorder === 'yes' ? 'yes' : '', // 勾選框的值
            'cbvalue'       => 'yes', // 當被勾選時，POST 的值
        ));
    }

    /**
     * (全新) 儲存「變化類型」的預購 meta
     *
     * @param int $variation_id 變化類型 ID
     * @param int $i            索引
     */
    public function save_preorder_checkbox_variable( $variation_id, $i ) {
        // 讀取我們在上面 'name' 欄位中定義的陣列
        $is_preorder = isset( $_POST['variation_preorder'][$i] ) && $_POST['variation_preorder'][$i] === 'yes' ? 'yes' : 'no';
        update_post_meta( $variation_id, Cart_Manager_Core::META_PRODUCT_IS_PREORDER, $is_preorder );
    }

    // --- 商品列表欄位功能 ---

    public function add_preorder_column( $columns ) {
        $columns['preorder'] = __( '預購商品', 'woocommerce' ); 
        return $columns;
    }

    /**
     * 顯示預購欄位值 (使用核心常數)
     */
    public function show_preorder_column( $column, $post_id ) {
        if ( 'preorder' === $column ) {
            // (改進 #3) 使用常數
            $preorder_product = get_post_meta( $post_id, Cart_Manager_Core::META_PRODUCT_IS_PREORDER, true ); 
            echo esc_html( $preorder_product === 'yes' ? '是' : '否' ); 
        }
    }

    public function set_column_width() {
        echo '<style>
            .column-preorder { width: 60px; }
        </style>';
    }

    /**
     * (全新) 將「預購狀態」欄位添加到「批次編輯」視窗
     */
    public function add_preorder_bulk_edit_field() {
        ?>
        <div class="inline-edit-group">
            <label class="alignleft">
                <span class="title"><?php esc_html_e( '預購狀態', 'cart-manager' ); ?></span>
                <span class="input-text-wrap">
                    <select class="preorder_status" name="_cm_preorder_product">
                        <option value="-1"><?php esc_html_e( '— 不變更 —', 'woocommerce' ); ?></option>
                        <option value="yes"><?php esc_html_e( '是 (預購)', 'cart-manager' ); ?></option>
                        <option value="no"><?php esc_html_e( '否 (現貨)', 'cart-manager' ); ?></option>
                    </select>
                </span>
            </label>
        </div>
        <?php
    }

    /**
     * (全新) 儲存「批次編輯」中的「預購狀態」
     *
     * @param WC_Product $product 正在被儲存的商品物件
     */
    public function save_preorder_bulk_edit_field( $product ) {
        
        // 檢查從「批次編輯」框傳來的值
        if ( isset( $_REQUEST[ Cart_Manager_Core::META_PRODUCT_IS_PREORDER ] ) ) {
            $new_status = wc_clean( $_REQUEST[ Cart_Manager_Core::META_PRODUCT_IS_PREORDER ] );
            
            // 如果值是 "-1" (— 不變更 —)，則不做任何事
            if ( $new_status !== '-1' ) {
                
                if ( $product && ! $product->is_type('variable') ) {
                    $product->update_meta_data( Cart_Manager_Core::META_PRODUCT_IS_PREORDER, $new_status );
                    
                    // --- (*** 關鍵修正 ***) ---
                    // 強制儲存變更
                    $product->save();
                    // -------------------------
                }
            }
        }
    }
}