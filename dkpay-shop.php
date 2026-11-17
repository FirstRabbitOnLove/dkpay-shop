<?php
/**
 * Plugin Name: DKPay Shop
 * Description: DKPay 多支付方式网关集成 — PayPal / Card / Apple Pay / Google Pay，支持 WooCommerce 结账。
 * Version:     3.0.1
 * Author:      DKPay Team
 * License:     GPL-2.0+
 * Text Domain: dkpay-shop
 * Requires at least: 5.0
 * Requires PHP: 7.4
 * Tested up to: 8.4
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

define( 'DKPAY_VERSION', '3.0.1' );
define( 'DKPAY_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'DKPAY_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

// ---------------------------------------------------------------------------
// 加载核心类
// ---------------------------------------------------------------------------
require_once DKPAY_PLUGIN_DIR . 'includes/class-dkpay-log.php';
require_once DKPAY_PLUGIN_DIR . 'includes/class-dkpay-callback.php';
require_once DKPAY_PLUGIN_DIR . 'includes/class-dkpay-methods.php';
require_once DKPAY_PLUGIN_DIR . 'includes/class-dkpay-api.php';
require_once DKPAY_PLUGIN_DIR . 'includes/class-dkpay-settings.php';
require_once DKPAY_PLUGIN_DIR . 'includes/class-dkpay-standalone.php';

register_activation_hook( __FILE__, [ 'DKPay_Callback', 'flush_rules_at_activation' ] );
register_deactivation_hook( __FILE__, 'dkpay_flush_rewrite_rules' );

// ---------------------------------------------------------------------------
// 自动加载
// ---------------------------------------------------------------------------
spl_autoload_register( function ( $class ) {
    $prefix = 'DKPay_';
    $base   = DKPAY_PLUGIN_DIR . 'includes/';

    if ( strpos( $class, $prefix ) !== 0 ) {
        return;
    }

    $relative_class = substr( $class, strlen( $prefix ) );
    $file           = $base . 'class-' . strtolower( str_replace( '_', '-', $relative_class ) ) . '.php';

    if ( file_exists( $file ) ) {
        require_once $file;
    }
} );

// ---------------------------------------------------------------------------
// 初始化
// ---------------------------------------------------------------------------
add_action( 'plugins_loaded', 'dkpay_init' );

function dkpay_init() {
    load_plugin_textdomain( 'dkpay-shop', false, dirname( plugin_basename( __FILE__ ) ) . '/languages' );

    // 独立支付页（短代码场景）
    DKPay_Standalone_Payment::init();

    // 日志初始化
    DKPay_Log::init();

    // 回调处理
    DKPay_Callback::init();

    DKPay_Methods::init();
    DKPay_API::init();
    DKPay_Settings::init();

    add_action( 'send_headers', 'dkpay_send_referrer_policy_header', 0 );
    add_action( 'wp_head', 'dkpay_output_referrer_policy_meta', 0 );

    // WooCommerce 在 init 阶段才加载支付网关基类，不能在 plugins_loaded 时提前判断。
    if ( did_action( 'woocommerce_loaded' ) ) {
        dkpay_load_wc_integration();
    } else {
        add_action( 'woocommerce_loaded', 'dkpay_load_wc_integration', 10 );
    }

    // AJAX：独立短代码和独立支付页创建支付订单
    add_action( 'wp_ajax_dkpay_create_order', [ 'DKPay_API', 'handle_ajax_order' ] );
    add_action( 'wp_ajax_nopriv_dkpay_create_order', [ 'DKPay_API', 'handle_ajax_order' ] );

    // 短代码
    add_shortcode( 'dkpay_checkout', [ 'DKPay_API', 'render_shortcode' ] );
    add_shortcode( 'dkpay_payment_button', [ 'DKPay_Standalone_Payment', 'render_payment_button' ] );
    add_shortcode( 'dkpay_checkout_inline', [ 'DKPay_Standalone_Payment', 'render_inline_checkout' ] );

    add_action( 'woocommerce_store_api_checkout_update_order_from_request', 'dkpay_store_api_capture_selected_method', 10, 2 );
}

function dkpay_flush_rewrite_rules(): void {
    flush_rewrite_rules();
}

function dkpay_send_referrer_policy_header(): void {
    if ( is_admin() || wp_doing_ajax() ) {
        return;
    }
    if ( ! function_exists( 'is_checkout' ) || ! is_checkout() ) {
        return;
    }
    if ( function_exists( 'is_order_received_page' ) && is_order_received_page() ) {
        return;
    }
    if ( headers_sent() ) {
        return;
    }
    header( 'Referrer-Policy: no-referrer' );
}

function dkpay_output_referrer_policy_meta(): void {
    if ( is_admin() || wp_doing_ajax() ) {
        return;
    }
    if ( ! function_exists( 'is_checkout' ) || ! is_checkout() ) {
        return;
    }
    if ( function_exists( 'is_order_received_page' ) && is_order_received_page() ) {
        return;
    }
    echo '<meta name="referrer" content="no-referrer" />';
}

function dkpay_store_api_capture_selected_method( $order, $request ): void {
    if ( ! $order || ! is_a( $order, 'WC_Order' ) ) {
        return;
    }
    if ( ! $request || ! is_a( $request, 'WP_REST_Request' ) ) {
        return;
    }

    $payment_method = $request->get_param( 'payment_method' );
    if ( 'dkpay' !== $payment_method ) {
        return;
    }

    $payment_data = $request->get_param( 'payment_data' );
    if ( ! is_array( $payment_data ) ) {
        return;
    }

    foreach ( $payment_data as $row ) {
        if ( ! is_array( $row ) ) {
            continue;
        }
        if ( empty( $row['key'] ) ) {
            continue;
        }
        $key = (string) $row['key'];
        $value = isset( $row['value'] ) ? (string) $row['value'] : '';
        if ( '' === $value ) {
            continue;
        }

        if ( '_dkpay_selected_method' === $key ) {
            $order->update_meta_data( '_dkpay_selected_method', wc_clean( $value ) );
            continue;
        }
        if ( '_dkpay_visitor_id' === $key ) {
            $order->update_meta_data( '_dkpay_visitor_id', sanitize_text_field( $value ) );
            continue;
        }
        if ( '_dkpay_utm_source' === $key ) {
            $order->update_meta_data( '_dkpay_utm_source', sanitize_text_field( $value ) );
            continue;
        }
        if ( '_dkpay_utm_medium' === $key ) {
            $order->update_meta_data( '_dkpay_utm_medium', sanitize_text_field( $value ) );
            continue;
        }
        if ( '_dkpay_source_device' === $key ) {
            $order->update_meta_data( '_dkpay_source_device', sanitize_text_field( $value ) );
            continue;
        }
        if ( '_dkpay_customer_sysinfo' === $key ) {
            $order->update_meta_data( '_dkpay_customer_sysinfo', wp_kses_post( $value ) );
            continue;
        }
    }
}

function dkpay_maybe_force_classic_checkout( $content ) {
    if ( ! is_string( $content ) ) {
        return $content;
    }

    if ( is_admin() || wp_doing_ajax() ) {
        return $content;
    }

    if ( defined( 'REST_REQUEST' ) && REST_REQUEST ) {
        return $content;
    }

    if ( ! function_exists( 'is_checkout' ) || ! is_checkout() ) {
        return $content;
    }

    if ( function_exists( 'is_order_received_page' ) && is_order_received_page() ) {
        return $content;
    }

    if ( function_exists( 'has_block' ) && has_block( 'woocommerce/checkout', $content ) ) {
        return $content;
    }

    if ( false !== strpos( $content, 'wp:woocommerce/checkout' ) ) {
        return $content;
    }

    return $content;
}

function dkpay_is_store_api_available(): bool {
    $cached = get_transient( 'dkpay_store_api_available' );
    if ( false !== $cached ) {
        return (bool) $cached;
    }

    $url      = home_url( '/wp-json/wc/store/v1/payment-methods' );
    $response = wp_remote_get( $url, [
        'timeout'     => 2,
        'redirection' => 0,
        'headers'     => [
            'Accept' => 'application/json',
        ],
    ] );

    if ( is_wp_error( $response ) ) {
        set_transient( 'dkpay_store_api_available', 0, 10 * MINUTE_IN_SECONDS );
        return false;
    }

    $code = (int) wp_remote_retrieve_response_code( $response );
    $body = (string) wp_remote_retrieve_body( $response );

    if ( 200 !== $code ) {
        set_transient( 'dkpay_store_api_available', 0, 10 * MINUTE_IN_SECONDS );
        return false;
    }

    json_decode( $body, true );
    $is_json = JSON_ERROR_NONE === json_last_error();

    set_transient( 'dkpay_store_api_available', $is_json ? 1 : 0, 10 * MINUTE_IN_SECONDS );
    return $is_json;
}

/**
 * 引入 WooCommerce 网关和 Checkout Block 支付集成。
 */
function dkpay_load_wc_integration(): void {
    if ( ! class_exists( 'WC_Payment_Gateway' ) ) {
        return;
    }

    require_once DKPAY_PLUGIN_DIR . 'includes/class-dkpay-wc-integration.php';
    require_once DKPAY_PLUGIN_DIR . 'includes/class-dkpay-wc-gateway.php';
    add_filter( 'woocommerce_payment_gateways', 'dkpay_add_wc_gateway' );

    if ( did_action( 'woocommerce_blocks_loaded' ) ) {
        dkpay_load_blocks_integration();
    } else {
        add_action( 'woocommerce_blocks_loaded', 'dkpay_load_blocks_integration', 10 );
    }
}

/**
 * 引入 WooCommerce Checkout Block 支付集成。
 */
function dkpay_load_blocks_integration(): void {
    /**
     * WooCommerce 11+ 的 Blocks 支付基类位于：
     * \Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType
     *
     * 旧版本/旧代码可能使用：
     * \Automattic\WooCommerce\Blocks\Payments\AbstractPaymentMethodType
     *
     * 这里兼容两者，避免因命名空间变化导致 Blocks 支付方式无法注册，从而在 Checkout Block 中不显示。
     */
    if (
        class_exists( '\\Automattic\\WooCommerce\\Blocks\\Payments\\Integrations\\AbstractPaymentMethodType' ) ||
        class_exists( '\\Automattic\\WooCommerce\\Blocks\\Payments\\AbstractPaymentMethodType' )
    ) {
        require_once DKPAY_PLUGIN_DIR . 'includes/class-dkpay-blocks.php';
    }
}

/**
 * 将 DKPay_WC_Gateway 类加入 WooCommerce 支付网关列表。
 */
function dkpay_add_wc_gateway( array $gateways ): array {
    $gateways[] = 'DKPay_WC_Gateway';
    return $gateways;
}

register_activation_hook( __FILE__, 'dkpay_activate' );
register_deactivation_hook( __FILE__, 'dkpay_deactivate' );

function dkpay_activate() {
    DKPay_Callback::flush_rules_at_activation();
    DKPay_Standalone_Payment::flush_rules_at_activation();
}

function dkpay_deactivate() {
    // 无清理需求
}
