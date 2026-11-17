<?php
/**
 * DKPay 独立支付页 — 不依赖 WooCommerce 支付网关
 *
 * 功能：
 *  1. 创建独立支付页 /pay-with-dkpay/
 *  2. 支持短代码 [dkpay_payment_button] 在任意页面插入支付按钮
 *  3. 支持短代码 [dkpay_checkout_inline] 内联支付表单（无需跳转）
 *  4. 处理支付回调并手动更新 WC 订单状态
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class DKPay_Standalone_Payment {

    // -------------------------------------------------------------------------
    // 初始化
    // -------------------------------------------------------------------------

    public static function init() {
        add_action( 'init', [ __CLASS__, 'register_rewrite_rules' ] );
        add_shortcode( 'dkpay_payment_button', [ __CLASS__, 'render_payment_button' ] );
        add_shortcode( 'dkpay_checkout_inline', [ __CLASS__, 'render_inline_checkout' ] );
        add_shortcode( 'dkpay_payment_form', [ __CLASS__, 'render_payment_page' ] );
        add_action( 'template_redirect', [ __CLASS__, 'handle_payment_page' ] );
        add_action( 'wp_enqueue_scripts', [ __CLASS__, 'enqueue_assets' ] );
    }

    // -------------------------------------------------------------------------
    // 重写规则
    // -------------------------------------------------------------------------

    public static function register_rewrite_rules() {
        add_rewrite_rule(
            '^pay-with-dkpay/?$',
            'index.php?dkpay_payment_page=1',
            'top'
        );
    }

    public static function flush_rules_at_activation() {
        self::register_rewrite_rules();
        flush_rewrite_rules();
    }

    // -------------------------------------------------------------------------
    // 支付页渲染
    // -------------------------------------------------------------------------

    public static function handle_payment_page() {
        if ( get_query_var( 'dkpay_payment_page' ) ) {
            echo self::render_payment_page();
            exit;
        }
    }

    /**
     * 渲染独立支付页
     * 可选参数：amount, order_id（WooCommerce 订单 ID）, payment_method
     * @return string
     */
    public static function render_payment_page() {
        global $wp_query;
        $query_vars = $wp_query->query_vars ?? [];

        // 从查询参数读取
        $amount       = floatval( $query_vars['amount'] ?? $_GET['amount'] ?? 0 );
        $order_id     = intval( $query_vars['order_id'] ?? $_GET['order_id'] ?? 0 );
        $payment_method = sanitize_text_field( $query_vars['payment_method'] ?? $_GET['payment_method'] ?? 'paypal' );

        // 如果有 order_id，从订单读取金额
        if ( $order_id && class_exists( 'WC_Order' ) ) {
            $wc_order = wc_get_order( $order_id );
            if ( $wc_order ) {
                $amount = floatval( $wc_order->get_total() );
                $order_id_for_dkpay = $order_id;
            }
        }

        $nonce  = wp_create_nonce( 'dkpay_standalone_nonce' );
        $methods = DKPay_API::get_available_methods();
        $labels  = DKPay_API::get_method_labels();

        ob_start();
        ?>
        <!DOCTYPE html>
        <html <?php language_attributes(); ?>>
        <head>
            <meta charset="<?php bloginfo( 'charset' ); ?>" />
            <meta name="viewport" content="width=device-width, initial-scale=1" />
            <title><?php _e( '安全支付 - DKPay', 'dkpay-shop' ); ?> — <?php bloginfo( 'name' ); ?></title>
            <style>
                * { box-sizing: border-box; margin: 0; padding: 0; }
                body {
                    font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
                    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
                    min-height: 100vh;
                    display: flex;
                    align-items: center;
                    justify-content: center;
                    padding: 20px;
                }
                .dkpay-page {
                    background: #fff;
                    border-radius: 16px;
                    padding: 40px;
                    max-width: 480px;
                    width: 100%;
                    box-shadow: 0 20px 60px rgba(0,0,0,0.3);
                }
                .dkpay-header {
                    text-align: center;
                    margin-bottom: 32px;
                }
                .dkpay-header .logo {
                    font-size: 28px;
                    font-weight: 700;
                    color: #1a1a2e;
                    margin-bottom: 8px;
                }
                .dkpay-header .subtitle {
                    color: #666;
                    font-size: 14px;
                }
                .dkpay-order-info {
                    background: #f8f9fa;
                    border-radius: 12px;
                    padding: 20px;
                    margin-bottom: 24px;
                }
                .dkpay-order-info .label {
                    font-size: 13px;
                    color: #888;
                    margin-bottom: 4px;
                }
                .dkpay-order-info .value {
                    font-size: 28px;
                    font-weight: 700;
                    color: #1a1a2e;
                }
                .dkpay-methods {
                    margin-bottom: 24px;
                }
                .dkpay-methods .section-title {
                    font-size: 14px;
                    color: #888;
                    margin-bottom: 12px;
                    text-transform: uppercase;
                    letter-spacing: 0.5px;
                }
                .dkpay-method-btn {
                    display: flex;
                    align-items: center;
                    width: 100%;
                    padding: 16px 20px;
                    border: 2px solid #e0e0e0;
                    border-radius: 12px;
                    background: #fff;
                    cursor: pointer;
                    transition: all 0.2s ease;
                    margin-bottom: 10px;
                    font-size: 16px;
                    color: #1a1a2e;
                }
                .dkpay-method-btn:hover {
                    border-color: #667eea;
                    background: #f8f7ff;
                }
                .dkpay-method-btn.selected {
                    border-color: #667eea;
                    background: #f0eeff;
                }
                .dkpay-method-btn .method-icon {
                    width: 40px;
                    height: 40px;
                    border-radius: 8px;
                    display: flex;
                    align-items: center;
                    justify-content: center;
                    margin-right: 16px;
                    font-size: 20px;
                    flex-shrink: 0;
                }
                .dkpay-method-btn .method-name {
                    font-weight: 600;
                }
                .dkpay-method-btn .method-desc {
                    font-size: 12px;
                    color: #888;
                    margin-top: 2px;
                }
                .dkpay-pay-btn {
                    width: 100%;
                    padding: 18px;
                    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
                    color: #fff;
                    border: none;
                    border-radius: 12px;
                    font-size: 18px;
                    font-weight: 700;
                    cursor: pointer;
                    transition: opacity 0.2s;
                }
                .dkpay-pay-btn:hover { opacity: 0.9; }
                .dkpay-pay-btn:disabled { background: #ccc; cursor: not-allowed; }
                .dkpay-msg {
                    margin-top: 12px;
                    padding: 12px;
                    border-radius: 8px;
                    font-size: 14px;
                    display: none;
                }
                .dkpay-msg.error { background: #ffebee; color: #c62828; }
                .dkpay-msg.loading { background: #e3f2fd; color: #1565c0; }
                .dkpay-footer {
                    text-align: center;
                    margin-top: 24px;
                    font-size: 12px;
                    color: #999;
                }
                .dkpay-footer a { color: #667eea; text-decoration: none; }
            </style>
        </head>
        <body>
            <div class="dkpay-page">
                <div class="dkpay-header">
                    <div class="logo">💳 DKPay</div>
                    <div class="subtitle"><?php _e( '安全支付网关', 'dkpay-shop' ); ?></div>
                </div>

                <div class="dkpay-order-info">
                    <div class="label"><?php _e( '支付金额', 'dkpay-shop' ); ?></div>
                    <div class="value">$<?php echo number_format( $amount, 2 ); ?></div>
                    <?php if ( $order_id ): ?>
                        <div class="label" style="margin-top:12px;"><?php _e( '订单号', 'dkpay-shop' ); ?></div>
                        <div style="font-size:14px;color:#666;"><?php echo esc_html( '#'. $order_id ); ?></div>
                    <?php endif; ?>
                </div>

                <div class="dkpay-methods">
                    <div class="section-title"><?php _e( '选择支付方式', 'dkpay-shop' ); ?></div>
                    <?php foreach ( $methods as $key => $url ): ?>
                        <button class="dkpay-method-btn<?php echo $key === $payment_method ? ' selected' : ''; ?>"
                                data-method="<?php echo esc_attr( $key ); ?>"
                                type="button">
                            <div class="method-icon">
                                <?php
                                $method_icon = DKPay_WC_Integration::get_method_icon_html( $key );
                                if ( ! empty( $method_icon ) ) {
                                    echo wp_kses(
                                        (string) $method_icon,
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
                                }
                                ?>
                            </div>
                            <div>
                                <div class="method-name"><?php echo esc_html( $labels[ $key ] ?? $key ); ?></div>
                                <div class="method-desc"><?php echo esc_html( DKPay_WC_Integration::get_method_desc( $key ) ); ?></div>
                            </div>
                        </button>
                    <?php endforeach; ?>
                </div>

                <button class="dkpay-pay-btn" id="dkpay-pay-btn" data-nonce="<?php echo esc_attr( $nonce ); ?>">
                    <?php printf( __( '立即支付 $%s', 'dkpay-shop' ), number_format( $amount, 2 ) ); ?>
                </button>
                <div class="dkpay-msg" id="dkpay-msg"></div>

                <div class="dkpay-footer">
                    <?php _e( '支付由 DKPay 提供技术支持', 'dkpay-shop' ); ?><br />
                    <a href="<?php echo esc_url( home_url() ); ?>"><?php _e( '← 返回商城', 'dkpay-shop' ); ?></a>
                </div>
            </div>

            <script>
            (function(){
                var selectedMethod = '<?php echo esc_js( $payment_method ); ?>';
                var amount = <?php echo floatval( $amount ); ?>;
                var orderId = <?php echo intval( $order_id ); ?>;

                document.querySelectorAll('.dkpay-method-btn').forEach(function(btn){
                    btn.addEventListener('click', function(){
                        document.querySelectorAll('.dkpay-method-btn').forEach(function(b){ b.classList.remove('selected'); });
                        btn.classList.add('selected');
                        selectedMethod = btn.dataset.method;
                    });
                });

                document.addEventListener('error', function(e){
                    var t = e && e.target ? e.target : null;
                    if (!t || !t.tagName) return;
                    if (String(t.tagName).toUpperCase() !== 'IMG') return;
                    if (!t.classList || !t.classList.contains('dkpay-method-icon-img')) return;
                    t.style.display = 'none';
                    if (t.parentElement && String(t.parentElement.tagName).toUpperCase() === 'SPAN') {
                        t.parentElement.style.display = 'none';
                    }
                }, true);

                document.getElementById('dkpay-pay-btn').addEventListener('click', function(){
                    var btn = this;
                    var msg = document.getElementById('dkpay-msg');
                    btn.disabled = true;
                    msg.style.display = 'block';
                    msg.className = 'dkpay-msg loading';
                    msg.textContent = '<?php _e( '正在跳转至支付页面...', 'dkpay-shop' ); ?>';

                    fetch('<?php echo esc_url( admin_url( 'admin-ajax.php' ) ); ?>', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                        body: new URLSearchParams({
                            action: 'dkpay_create_order',
                            nonce: '<?php echo esc_js( $nonce ); ?>',
                            amount: amount,
                            order_number: orderId ? 'ORD-' + orderId : 'STANDALONE-' + Date.now(),
                            payment_method: selectedMethod,
                            customer_email: '',
                            telephone: '',
                            country_code: 'US'
                        })
                    })
                    .then(function(r){ return r.json(); })
                    .then(function(res){
                        if (res.success){
                            window.location.href = res.data.link;
                        } else {
                            msg.className = 'dkpay-msg error';
                            msg.textContent = res.data.message || '<?php _e( '支付请求失败，请重试', 'dkpay-shop' ); ?>';
                            btn.disabled = false;
                        }
                    })
                    .catch(function(){
                        msg.className = 'dkpay-msg error';
                        msg.textContent = '<?php _e( '网络错误，请检查连接后重试', 'dkpay-shop' ); ?>';
                        btn.disabled = false;
                    });
                });
            })();
            </script>
        </body>
        </html>
        <?php
        return ob_get_clean();
    }

    /**
     * 短代码：[dkpay_payment_button order_id="123" amount="99.00"]
     * 在任意页面显示支付按钮
     */
    public static function render_payment_button( array $atts ): string {
        $atts = shortcode_atts( [
            'order_id' => '',
            'amount'   => '',
            'label'    => '使用 DKPay 支付',
        ], $atts, 'dkpay_payment_button' );

        $order_id = intval( $atts['order_id'] );
        $amount   = floatval( $atts['amount'] );

        if ( ! $order_id && ! $amount ) {
            return '<p>' . __( '请指定 order_id 或 amount 参数', 'dkpay-shop' ) . '</p>';
        }

        $pay_url = add_query_arg(
            array_filter( [
                'amount'    => $amount ?: null,
                'order_id'  => $order_id ?: null,
            ] ),
            home_url( '/pay-with-dkpay/' )
        );

        return sprintf(
            '<a href="%s" class="dkpay-btn" target="_blank" rel="noopener">%s</a>
            <style>.dkpay-btn{display:inline-block;padding:14px 32px;background:linear-gradient(135deg,#667eea,#764ba2);color:#fff;border-radius:8px;text-decoration:none;font-weight:700;font-size:16px;}</style>',
            esc_url( $pay_url ),
            esc_html( $atts['label'] )
        );
    }

    /**
     * 短代码：[dkpay_checkout_inline amount="99.00"]
     * 在当前页面内嵌支付表单（不跳转）
     */
    public static function render_inline_checkout( array $atts ): string {
        return DKPay_API::render_shortcode( $atts );
    }

    // -------------------------------------------------------------------------
    // 辅助
    // -------------------------------------------------------------------------

    public static function enqueue_assets() {
        // 无需额外 CSS/JS，样式已内联
    }
}
