<?php
/**
 * DKPay WooCommerce Blocks 支付方法集成
 *
 * 使 DKPay 支付方式出现在新版 WooCommerce Checkout Block 中。
 *
 * 注意：Blocks 集成是辅助性的。传统 shortcode/template checkout
 * 仍通过 WC_Payment_Gateway 的 payment_fields() / process_payment() 工作。
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * 兼容 WooCommerce Blocks 基类命名空间变更。
 *
 * WooCommerce 11+ 中 AbstractPaymentMethodType 位于：
 * \Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType
 *
 * 你们插件历史代码使用的是：
 * \Automattic\WooCommerce\Blocks\Payments\AbstractPaymentMethodType
 *
 * 为了避免因为 class_exists 检测失败而导致 DKPay Blocks 支付方式无法注册，
 * 这里在新类存在且旧类不存在时，给旧类名做一个别名映射（不影响 WooCommerce 核心类）。
 */
if (
    class_exists( '\\Automattic\\WooCommerce\\Blocks\\Payments\\Integrations\\AbstractPaymentMethodType' ) &&
    ! class_exists( '\\Automattic\\WooCommerce\\Blocks\\Payments\\AbstractPaymentMethodType' )
) {
    class_alias(
        '\\Automattic\\WooCommerce\\Blocks\\Payments\\Integrations\\AbstractPaymentMethodType',
        '\\Automattic\\WooCommerce\\Blocks\\Payments\\AbstractPaymentMethodType'
    );
}

/**
 * 检测 WooCommerce Blocks 是否可用
 */
function dkpay_is_blocks_enabled(): bool {
    // WooCommerce 9.0+ 使用 @woocommerce/settings 注入
    if ( class_exists( '\Automattic\WooCommerce\Utilities\FeaturesUtil' ) ) {
        return \Automattic\WooCommerce\Utilities\FeaturesUtil::feature_is_enabled( 'checkout' );
    }
    // 旧版：检测 WC Blocks 插件或内置 blocks 功能
    if ( class_exists( '\Automattic\WooCommerce\Blocks\Package' ) ) {
        return true;
    }
    // 通过全局 $wp_query 检测结账页是否使用了 blocks
    global $wp_query;
    if ( isset( $wp_query->query['wc_block_store_api'] ) ) {
        return true;
    }
    // 兜底：WooCommerce 5.0+ 默认启用 blocks
    return class_exists( 'WC_Shortcode_Checkout' );
}

/**
 * DKPay Blocks 支付方法类型
 *
 * 继承 AbstractPaymentMethodType，实现 register_payment_method()
 * 以便在 Checkout Block 中注册 DKPay。
 */
class DKPay_Blocks_Payment_Method {

    /**
     * 注册 Blocks 支付方法
     *
     * 钩子：woocommerce_blocks_payment_method_type_registration
     *
     * @param \Automattic\WooCommerce\Blocks\Payments\PaymentMethodTypesRegistry $registry
     */
    public static function register_block_method( $registry ) {
        if ( ! class_exists( '\Automattic\WooCommerce\Blocks\Payments\AbstractPaymentMethodType' ) ) {
            return;
        }

        // 检查 DKPay_WC_Gateway 类是否已加载（避免类未注册时的致命错误）
        if ( ! class_exists( 'DKPay_WC_Gateway' ) ) {
            return;
        }

        // 检查 DKPay 是否已启用
        $gateway = new DKPay_WC_Gateway();
        if ( 'yes' !== $gateway->get_option( 'enabled', 'yes' ) ) {
            return;
        }

        // 检查配置是否完整
        if ( ! class_exists( 'DKPay_API' ) || ! DKPay_API::is_configured() ) {
            return;
        }

        // 检查是否有可用支付方式
        $methods = DKPay_API::get_available_methods();
        if ( empty( $methods ) ) {
            return;
        }

        $block_method = new DKPay_Blocks_Payment_Method_Type();
        $block_method->set_gateway( $gateway );
        $registry->register( $block_method );
    }
}

/**
 * Blocks 支付方法类型实现
 */
class DKPay_Blocks_Payment_Method_Type extends \Automattic\WooCommerce\Blocks\Payments\AbstractPaymentMethodType {

    /** @var string */
    private $gateway;

    /**
     * 设置支付网关实例
     *
     * @param WC_Payment_Gateway $gateway
     */
    public function set_gateway( $gateway ) {
        $this->gateway = $gateway;
    }

    public function initialize() {
        $this->settings = get_option( 'woocommerce_dkpay_settings', [] );
    }

    public function is_active() {
        return $this->gateway && 'yes' === $this->gateway->get_option( 'enabled', 'yes' )
            && DKPay_API::is_configured()
            && ! empty( DKPay_API::get_available_methods() );
    }

    public function get_payment_method_script_handles() {
        $handle = 'dkpay-blocks';
        $ver    = DKPAY_VERSION;
        $path   = DKPAY_PLUGIN_DIR . 'assets/js/dkpay-blocks.js';
        if ( file_exists( $path ) ) {
            $ver = (string) filemtime( $path );
        }
        wp_register_script(
            $handle,
            DKPAY_PLUGIN_URL . 'assets/js/dkpay-blocks.js',
            [ 'wc-blocks-registry', 'wc-settings', 'wp-element' ],
            $ver,
            true
        );
        return [ $handle ];
    }

    /**
     * 支付方法名称（唯一标识）
     *
     * @return string
     */
    public function get_name() {
        return 'dkpay';
    }

    /**
     * 渲染支付方法数据（供前端 JS 使用）
     *
     * @return array
     */
    public function get_payment_method_data() {
        if ( ! $this->gateway ) {
            return [];
        }

        $methods    = DKPay_API::get_available_methods();
        $labels     = DKPay_API::get_method_labels();

        return [
            'title'       => $this->gateway->title ?? __( 'DKPay', 'dkpay-shop' ),
            'description' => $this->gateway->get_option( 'description', '' ),
            'methods'     => array_map( function( $key ) use ( $labels ) {
                $icon_html = DKPay_WC_Integration::get_method_icon_html( $key );
                if ( ! empty( $icon_html ) ) {
                    $icon_html = wp_kses(
                        (string) $icon_html,
                        [
                            'img'  => [ 'src' => true, 'alt' => true, 'style' => true, 'class' => true ],
                            'span' => [ 'style' => true, 'class' => true, 'aria-hidden' => true ],
                            'svg'  => [ 'xmlns' => true, 'width' => true, 'height' => true, 'viewBox' => true, 'aria-hidden' => true, 'role' => true, 'fill' => true, 'stroke' => true, 'stroke-width' => true ],
                            'g'    => [ 'fill' => true, 'stroke' => true, 'stroke-width' => true ],
                            'path' => [ 'd' => true, 'fill' => true, 'stroke' => true, 'stroke-width' => true ],
                            'rect' => [ 'x' => true, 'y' => true, 'width' => true, 'height' => true, 'rx' => true, 'fill' => true, 'stroke' => true, 'stroke-width' => true ],
                            'text' => [ 'x' => true, 'y' => true, 'fill' => true, 'font-size' => true, 'font-family' => true, 'text-anchor' => true, 'dominant-baseline' => true ],
                        ]
                    );
                } else {
                    $icon_html = '';
                }
                return [
                    'key'         => $key,
                    'label'       => $labels[ $key ] ?? $key,
                    'icon_html'   => $icon_html,
                    'gateway_url' => DKPay_API::get_method_url( $key ),
                ];
            }, array_keys( $methods ) ),
        ];
    }
}

/**
 * 挂载 Blocks 注册钩子
 */
add_action(
    'woocommerce_blocks_payment_method_type_registration',
    [ 'DKPay_Blocks_Payment_Method', 'register_block_method' ],
    10,
    1
);
