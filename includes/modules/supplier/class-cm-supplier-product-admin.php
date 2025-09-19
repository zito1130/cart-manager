<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit; // 安全檢查
}

/**
 * 模組：供應商商品後台 (Supplier Product Admin)
 * 職責：在商品編輯頁面加入 "供應商" 指定欄位。
 * (已修改) 將「可變商品」的供應商設定，從「變化類型」層級移至「商品庫存頁籤」層級。
 */
class CM_Supplier_Product_Admin {

    public function __construct() {
        // 1. 在「簡單商品」的「一般」頁籤中加入
        add_action( 'woocommerce_product_options_general_product_data', array( $this, 'add_supplier_dropdown_simple' ) );
        
        // --- (全新) ---
        // 2. 在「可變商品」的「庫存」頁籤中加入 (根據您的要求)
        add_action( 'woocommerce_product_options_inventory_product_data', array( $this, 'add_supplier_dropdown_variable_parent' ) );

        // --- (修改) ---
        // 3. 儲存商品 meta (此函數現在會同時處理簡單商品和可變商品的「主要 ID」)
        add_action( 'woocommerce_process_product_meta', array( $this, 'save_supplier_meta' ) );

        add_filter( 'manage_edit-product_columns', array( $this, 'add_supplier_column_to_products' ) );
        add_action( 'manage_product_posts_custom_column', array( $this, 'show_supplier_column_for_products' ), 10, 2 );
        add_action( 'admin_head', array( $this, 'set_supplier_column_width' ) );
        
        // --- (已移除) ---
        // 移除 "變化類型" 的相關鉤子
        // remove_action( 'woocommerce_variation_options', array( $this, 'add_supplier_dropdown_variable' ), 10, 3 );
        // remove_action( 'woocommerce_save_product_variation', array( $this, 'save_supplier_meta_variable' ), 10, 2 );
    }

    /**
     * 獲取所有供應商列表 (輔助函數) - (保持不變)
     * @return array 格式為 [ 'User ID' => 'Display Name' ]
     */
    private function get_supplier_options_list() {
        $suppliers = get_users( array( 'role' => Cart_Manager_Core::ROLE_SUPPLIER ) );
        $options = array( '' => __( '無 (站方商品)', 'cart-manager' ) ); // 預設選項

        foreach ( $suppliers as $supplier ) {
            
            // --- (開始修改) ---
            // 優先抓取 'nickname' (暱稱)
            $display_name = $supplier->nickname;

            // 如果供應商忘記填寫暱稱，則回退 (fallback) 到抓取他們的登入帳號，確保欄位不會空白
            if ( empty( $display_name ) ) {
                $display_name = $supplier->user_login;
            }
            // --- (修改完畢) ---

            $options[ $supplier->ID ] = $display_name;
        }
        return $options;
    }

    /**
     * 1. 顯示於簡單商品 - (保持不變)
     */
    public function add_supplier_dropdown_simple() {
        global $post;
        $options = $this->get_supplier_options_list();
        
        echo '<div class="options_group show_if_simple">'; // 確保只在簡單商品顯示
        woocommerce_wp_select( array(
            'id'      => Cart_Manager_Core::META_PRODUCT_SUPPLIER_ID,
            'label'   => __( '供應商', 'cart-manager' ),
            'options' => $options,
            'desc_tip' => true,
            'description' => __( '指定此商品的供應商。', 'cart-manager' ),
            'value'   => get_post_meta( $post->ID, Cart_Manager_Core::META_PRODUCT_SUPPLIER_ID, true ),
        ) );
        echo '</div>';
    }

    /**
     * (全新) 2. 顯示於可變商品 (庫存頁籤)
     */
    public function add_supplier_dropdown_variable_parent() {
        global $post;
        $options = $this->get_supplier_options_list();
        
        // 確保只在可變商品顯示
        echo '<div class="options_group show_if_variable">'; 
        woocommerce_wp_select( array(
            'id'      => Cart_Manager_Core::META_PRODUCT_SUPPLIER_ID, // (重要) ID 必須與簡單商品相同
            'label'   => __( '供應商', 'cart-manager' ),
            'options' => $options,
            'desc_tip' => true,
            'description' => __( '指定此商品 (及其所有變化類型) 的供應商。', 'cart-manager' ),
            'value'   => get_post_meta( $post->ID, Cart_Manager_Core::META_PRODUCT_SUPPLIER_ID, true ),
        ) );
        echo '</div>';
    }


    /**
     * (修改) 3. 儲存商品 Meta (同時適用於簡單和可變商品)
     * (原 'save_supplier_meta_simple' 已改名為 'save_supplier_meta')
     */
    public function save_supplier_meta( $post_id ) {
        // 檢查是否為自動儲存或修訂版本
        if ( ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) || wp_is_post_revision( $post_id ) ) {
            return;
        }

        // 檢查欄位是否存在
        if ( isset( $_POST[ Cart_Manager_Core::META_PRODUCT_SUPPLIER_ID ] ) ) {
            $supplier_id = sanitize_text_field( $_POST[ Cart_Manager_Core::META_PRODUCT_SUPPLIER_ID ] );
            update_post_meta( $post_id, Cart_Manager_Core::META_PRODUCT_SUPPLIER_ID, $supplier_id );
        }
    }

    public function add_supplier_column_to_products( $columns ) {
        $columns['supplier'] = __( '供應商', 'cart-manager' ); 
        return $columns;
    }

    /**
     * (全新) 顯示 "供應商" 欄位內容
     */
    public function show_supplier_column_for_products( $column, $post_id ) {
        if ( 'supplier' === $column ) {
            $supplier_id = get_post_meta( $post_id, Cart_Manager_Core::META_PRODUCT_SUPPLIER_ID, true );
            
            if ( empty( $supplier_id ) ) {
                echo esc_html__( '無 (站方商品)', 'cart-manager' );
                return;
            }

            $supplier = get_userdata( $supplier_id );
            if ( $supplier ) {
                // 優先顯示暱稱，若無則顯示登入帳號
                $display_name = ! empty( $supplier->nickname ) ? $supplier->nickname : $supplier->user_login;
                echo esc_html( $display_name );
            } else {
                echo esc_html__( '供應商不存在', 'cart-manager' );
            }
        }
    }

    public function set_supplier_column_width() {
        $screen = get_current_screen();
        if ($screen && $screen->id === 'edit-product') {
            echo '<style>
                .column-supplier { width: 80px; } /* 設置欄位寬度 */
            </style>';
        }
    }
}