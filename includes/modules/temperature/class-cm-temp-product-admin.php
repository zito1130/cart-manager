<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit; // 安全檢查
}

/**
 * 模組：溫層商品後台管理 (Temperature Product Admin)
 *
 * 職責：在 WooCommerce 的「商品列表」後台頁面新增 "運送類別" 欄位。
 * 來源：add-products-page-tmp-column.php
 */
class CM_Temp_Product_Admin {

    /**
     * 建構子：註冊鉤子
     */
    public function __construct() {
        // 1. 添加欄位標題
        add_filter( 'manage_edit-product_columns', array( $this, 'add_shipping_class_column' ) );
        // 2. 顯示欄位內容
        add_action( 'manage_product_posts_custom_column', array( $this, 'show_shipping_class_column' ), 10, 2 );
        // 3. 設置欄位寬度
        add_action( 'admin_head', array( $this, 'set_shipping_class_column_width' ) );
    }

    /**
     * 1. 在商品列表中添加 shipping_class 欄位
     * (來源: tpm_add_shipping_class_column)
     */
    public function add_shipping_class_column( $columns ) {
        $columns['shipping_class'] = __( '運送類別', 'woocommerce' ); // 新增欄位名稱
        return $columns;
    }

    /**
     * 2. 在商品列表中顯示 shipping_class 的值
     * (來源: tpm_show_shipping_class_column)
     */
    public function show_shipping_class_column( $column, $post_id ) {
        if ( 'shipping_class' === $column ) {
            $product = wc_get_product( $post_id ); // 獲取商品物件
            $shipping_class_slug = $product->get_shipping_class(); // 獲取運送類別的slug
            if ( $shipping_class_slug ) {
                $shipping_class = get_term_by( 'slug', $shipping_class_slug, 'product_shipping_class' ); // 獲取運送類別的詳細資訊
                if ( $shipping_class && ! is_wp_error( $shipping_class ) ) {
                    echo esc_html( $shipping_class->name ); // 顯示運送類別名稱
                }
            } else {
                echo esc_html__( '無', 'woocommerce' ); // 如果沒有運送類別，顯示無
            }
        }
    }

    /**
     * 3. 設置欄位寬度
     * (來源: tpm_set_shipping_class_column_width)
     */
    public function set_shipping_class_column_width() {
        // 只在商品列表頁載入
        $screen = get_current_screen();
        if ($screen && $screen->id === 'edit-product') {
            echo '<style>
                .column-shipping_class {
                    width: 60px; /* 設置欄位寬度 */
                }
            </style>';
        }
    }
}