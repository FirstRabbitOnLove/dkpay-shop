<?php
/**
 * DKPay 后台设置页
 *
 * 集中管理：
 *  1. 核心凭证（密钥、安全令牌、基础网关地址）
 *  2. 引导用户前往「支付方式」管理页面配置各支付方式
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class DKPay_Settings {

    // -------------------------------------------------------------------------
    // 初始化
    // -------------------------------------------------------------------------

    public static function init() {
        add_action( 'admin_menu', [ __CLASS__, 'add_menu_page' ] );
        add_action( 'admin_init', [ __CLASS__, 'register_settings' ] );
    }

    // -------------------------------------------------------------------------
    // 后台菜单
    // -------------------------------------------------------------------------

    public static function add_menu_page() {
        add_options_page(
            __( 'DKPay 支付配置', 'dkpay-shop' ),
            __( 'DKPay 收款', 'dkpay-shop' ),
            'manage_options',
            'dkpay-settings',
            [ __CLASS__, 'render_page' ]
        );
    }

    // -------------------------------------------------------------------------
    // 注册设置
    // -------------------------------------------------------------------------

    public static function register_settings() {
        // 核心凭证
        $core_fields = [
            'dkpay_gateway_url'    => [ 'esc_url_raw',     'https://pay.example.com' ],
            'dkpay_secret_key'     => [ 'sanitize_text_field', '' ],
            'dkpay_security_token' => [ 'sanitize_text_field', '' ],
        ];
        foreach ( $core_fields as $key => $cfg ) {
            register_setting( 'dkpay_group', $key, [
                'type'              => 'string',
                'sanitize_callback' => $cfg[0],
                'default'           => $cfg[1],
            ] );
        }


    }

    // -------------------------------------------------------------------------
    // 渲染设置页面
    // -------------------------------------------------------------------------

    public static function render_page() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( '没有权限访问此页面', 'dkpay-shop' ) );
        }

        $methods = DKPay_Methods::get_all_methods();
        ?>
        <div class="wrap">
            <h1><?php echo esc_html( get_admin_page_title() ); ?></h1>
            <?php settings_errors( 'dkpay_group' ); ?>

            <form method="post" action="options.php">
                <?php settings_fields( 'dkpay_group' ); do_settings_sections( 'dkpay_group' ); ?>

                <!-- 核心凭证 -->
                <h2><?php _e( '核心凭证（共享）', 'dkpay-shop' ); ?></h2>
                <table class="form-table">
                    <tr>
                        <th scope="row"><label for="dkpay_gateway_url"><?php _e( '支付网关基础地址', 'dkpay-shop' ); ?></label></th>
                        <td>
                            <input type="url" name="dkpay_gateway_url" id="dkpay_gateway_url"
                                   value="<?php echo esc_attr( get_option( 'dkpay_gateway_url', 'https://pay.example.com' ) ); ?>" class="regular-text" />
                            <p class="description"><?php _e('DKPay 商户后台提供的基础网关地址，例如 https://pay.example.com', 'dkpay-shop'); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="dkpay_secret_key"><?php _e( '密钥 Key（商户 ID）', 'dkpay-shop' ); ?></label></th>
                        <td>
                            <input type="text" name="dkpay_secret_key" id="dkpay_secret_key"
                                   value="<?php echo esc_attr( get_option( 'dkpay_secret_key' ) ); ?>" class="regular-text code" />
                            <p class="description"><?php _e('商户后台「密钥Key」字段值', 'dkpay-shop'); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="dkpay_security_token"><?php _e( '安全令牌 securityToken', 'dkpay-shop' ); ?><span class="required">*</span></label></th>
                        <td>
                            <input type="password" name="dkpay_security_token" id="dkpay_security_token"
                                   value="<?php echo esc_attr( get_option( 'dkpay_security_token' ) ); ?>" class="regular-text code" />
                            <p class="description"><?php _e('商户后台配置的 SECURITY_TOKEN', 'dkpay-shop'); ?></p>
                        </td>
                    </tr>
                </table>

                <!-- 支付方式管理 -->
                <h2><?php _e( '支付方式管理', 'dkpay-shop' ); ?></h2>
                <p class="description" style="margin-bottom:16px;">
                    <?php _e('所有支付方式（含新增自定义方式）统一在「支付方式」管理页面配置。结账页只显示已启用的支付方式。', 'dkpay-shop'); ?>
                </p>
                <p>
                    <a href="<?php echo esc_url( admin_url( 'edit.php?post_type=dkpay_method' ) ); ?>"
                       class="button button-primary">
                        <?php _e( '管理支付方式', 'dkpay-shop' ); ?>
                    </a>
                </p>

                <?php if ( ! empty( $methods ) ) : ?>
                <table class="widefat fixed" style="margin-top:16px;">
                    <thead>
                        <tr>
                            <th><?php _e( '状态', 'dkpay-shop' ); ?></th>
                            <th><?php _e( '显示名称', 'dkpay-shop' ); ?></th>
                            <th><?php _e( '内部标识', 'dkpay-shop' ); ?></th>
                            <th><?php _e( 'API 值', 'dkpay-shop' ); ?></th>
                            <th><?php _e( '网关地址', 'dkpay-shop' ); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ( $methods as $key => $m ) : ?>
                        <tr>
                            <td>
                                <?php if ( $m['enabled'] ) : ?>
                                    <span style="color:#46b450;font-weight:600;">✓ <?php _e( '启用', 'dkpay-shop' ); ?></span>
                                <?php else : ?>
                                    <span style="color:#999;"><?php _e( '未启用', 'dkpay-shop' ); ?></span>
                                <?php endif; ?>
                            </td>
                            <td><strong><?php echo esc_html( $m['label'] ); ?></strong></td>
                            <td><code><?php echo esc_html( $key ); ?></code></td>
                            <td><code><?php echo esc_html( $m['api_value'] ); ?></code></td>
                            <td><code style="font-size:12px;"><?php echo esc_html( $m['gateway_url'] ); ?></code></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                <?php else : ?>
                <p style="color:#666;padding:8px 0;">
                    <?php _e( '暂无支付方式配置，请点击上方按钮添加。', 'dkpay-shop' ); ?>
                </p>
                <?php endif; ?>

                <!-- 说明 -->
                <h2><?php _e( '使用方式', 'dkpay-shop' ); ?></h2>
                <table class="widefat fixed">
                    <thead><tr><th><?php _e( '场景', 'dkpay-shop' ); ?></th><th><?php _e( '方式', 'dkpay-shop' ); ?></th><th><?php _e( '说明', 'dkpay-shop' ); ?></th></tr></thead>
                    <tbody>
                        <tr>
                            <td><strong><?php _e( 'WooCommerce 结账页', 'dkpay-shop' ); ?></strong></td>
                            <td><?php _e( '自动显示', 'dkpay-shop' ); ?></td>
                            <td><?php _e( '结账页 Payment options 区域将自动显示 DKPay 支付方式，客户选择后直接跳转收银台。无需在 WooCommerce 付款设置中操作。', 'dkpay-shop' ); ?></td>
                        </tr>
                        <tr>
                            <td><strong><?php _e( '短代码', 'dkpay-shop' ); ?></strong></td>
                            <td><code>[dkpay_checkout amount="99.00" payment_method="apple"]</code></td>
                            <td><?php _e('放入任意页面。可选属性：payment_method (paypal/card/apple/google)、email、phone、country_code', 'dkpay-shop'); ?></td>
                        </tr>
                        <tr>
                            <td><strong><?php _e( '独立支付页', 'dkpay-shop' ); ?></strong></td>
                            <td><code>/pay-with-dkpay/?amount=99&order_id=123</code></td>
                            <td><?php _e('独立支付页，可通过 [dkpay_payment_button] 短代码链接到该页面。', 'dkpay-shop'); ?></td>
                        </tr>
                    </tbody>
                </table>

                <p style="margin-top:20px;">
                    <?php submit_button( __( '保存配置', 'dkpay-shop' ) ); ?>
                </p>
            </form>
        </div>
        <?php
    }
}
