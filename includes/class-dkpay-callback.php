<?php
/**
 * DKPay 回调处理类
 *
 * 负责：
 *  1. 注册 WordPress 自定义重写规则（/dkpay/callback/client/ 与 /dkpay/callback/server/）
 *  2. 处理 DKPay 支付完成的客户端跳转（return_url 指向此处）
 *  3. 处理 DKPay 的 server-to-server 支付回调（notify_url 指向此处）
 *  4. 根据回调结果更新 WooCommerce 订单状态
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class DKPay_Callback {

    private const CLIENT_SIGN_PARAM = '_sign';
    private const CLIENT_SIGN_TS_PARAM = '_ts';

    // -------------------------------------------------------------------------
    // 初始化
    // -------------------------------------------------------------------------

    public static function init() {
        add_action( 'init', [ __CLASS__, 'register_rewrite_rules' ] );
        add_action( 'parse_request', [ __CLASS__, 'handle_parse_request' ], 0 );
        add_action( 'rest_api_init', [ __CLASS__, 'register_rest_routes' ] );
        add_filter( 'query_vars', [ __CLASS__, 'register_query_vars' ] );
    }

    public static function register_query_vars( array $vars ): array {
        $vars[] = 'dkpay_client_oid';
        $vars[] = 'dkpay_cancel_oid';
        $vars[] = 'dkpay_server_oid';
        return $vars;
    }

    // -------------------------------------------------------------------------
    // 自定义重写规则
    // -------------------------------------------------------------------------

    public static function register_rewrite_rules() {
        add_rewrite_rule(
            '^dkpay/callback/client/([^/]+)/?$',
            'index.php?dkpay_client_oid=$matches[1]',
            'top'
        );
        add_rewrite_rule(
            '^dkpay/callback/cancel/([^/]+)/?$',
            'index.php?dkpay_cancel_oid=$matches[1]',
            'top'
        );
        add_rewrite_rule(
            '^dkpay/callback/server/([^/]+)/?$',
            'index.php?dkpay_server_oid=$matches[1]',
            'top'
        );
    }

    public static function flush_rules_at_activation() {
        self::register_rewrite_rules();
        flush_rewrite_rules();
    }

    // -------------------------------------------------------------------------
    // parse_request 拦截
    // -------------------------------------------------------------------------

    public static function handle_parse_request( $wp ) {
        $qv = $wp->query_vars ?? [];
        if ( ! empty( $qv['dkpay_client_oid'] ) ) {
            self::handle_client_callback( $qv['dkpay_client_oid'] );
            exit;
        }
        if ( ! empty( $qv['dkpay_cancel_oid'] ) ) {
            self::handle_cancel_callback( $qv['dkpay_cancel_oid'] );
            exit;
        }
        if ( ! empty( $qv['dkpay_server_oid'] ) ) {
            self::handle_server_callback( (string) $qv['dkpay_server_oid'] );
            exit;
        }

        $path = isset( $wp->request ) ? (string) $wp->request : '';
        if ( '' !== $path ) {
            if ( preg_match( '#^dkpay/callback/client/([^/&\?]+).*$#', $path, $m ) ) {
                self::handle_client_callback( (string) $m[1] );
                exit;
            }
            if ( preg_match( '#^dkpay/callback/cancel/([^/&\?]+).*$#', $path, $m ) ) {
                self::handle_cancel_callback( (string) $m[1] );
                exit;
            }
            if ( preg_match( '#^dkpay/callback/server/([^/&\?]+).*$#', $path, $m ) ) {
                self::handle_server_callback( (string) $m[1] );
                exit;
            }
        }
    }

    /**
     * 处理取消支付回调。
     *
     * 取消回调通过独立的 /cancel/ 路径进入，不依赖 URL 查询参数判断回调类型。
     *
     * @param string $order_uuid 订单回调标识。
     */
    private static function handle_cancel_callback( string $order_uuid ): void {
        self::handle_client_callback( $order_uuid, 'cancel' );
    }

    // -------------------------------------------------------------------------
    // 客户端回调（return_url）
    // -------------------------------------------------------------------------

    private static function handle_client_callback( string $order_uuid, string $callback_status = '' ): void {
        $order_uuid = self::hydrate_client_params_from_path( $order_uuid );
        $status_raw = '' !== $callback_status ? $callback_status : ( $_GET['status'] ?? 'pending' );
        if ( is_array( $status_raw ) ) {
            $status_raw = end( $status_raw );
        }
        $status = sanitize_text_field( $status_raw );
        $message = '';

        $qs_unique_id_raw = isset( $_GET['unique_id'] ) ? (string) wp_unslash( $_GET['unique_id'] ) : '';
        $qs_order_no_raw  = isset( $_GET['order_number'] ) ? (string) wp_unslash( $_GET['order_number'] ) : '';
        $qs_txn_id_raw    = isset( $_GET['transaction_id'] ) ? (string) wp_unslash( $_GET['transaction_id'] ) : '';
        $qs_error_msg_raw = isset( $_GET['error_msg'] ) ? (string) wp_unslash( $_GET['error_msg'] ) : '';

        $qs_unique_id = sanitize_text_field( $qs_unique_id_raw );
        $qs_order_no  = sanitize_text_field( $qs_order_no_raw );
        $qs_txn_id    = sanitize_text_field( $qs_txn_id_raw );
        $qs_error_msg = sanitize_text_field( $qs_error_msg_raw );

        $client_status_for_ui = self::normalize_client_status( $status );
        if ( 'cancelled' === $client_status_for_ui ) {
            $message = __( 'Payment was cancelled. Your order is still pending payment.', 'dkpay-shop' );
        } elseif ( 'paid' === $client_status_for_ui ) {
            $message = __( 'Payment completed. Redirecting you back to the store...', 'dkpay-shop' );
        } elseif ( 'failed' === $client_status_for_ui ) {
            $message = __( 'Payment was not completed. Redirecting you back...', 'dkpay-shop' );
        } else {
            $message = __( 'Confirming your payment status. Please wait...', 'dkpay-shop' );
        }

        $wc_order_id = self::find_wc_order_by_uuid( $order_uuid );

        if ( ! $wc_order_id && $qs_unique_id ) {
            $wc_order_id = self::find_wc_order_by_unique_id( $qs_unique_id );
        }
        if ( ! $wc_order_id && $qs_order_no ) {
            $wc_order_id = self::find_wc_order_by_order_number( $qs_order_no );
        }
        if ( $wc_order_id && 'cancel' !== $callback_status ) {
            $client_status = self::normalize_client_status( $status );
            if ( $client_status ) {
                $order = wc_get_order( $wc_order_id );
                if ( $order ) {
                    $final_txn = $qs_txn_id ?: ( $qs_unique_id ?: $order_uuid );
                    self::apply_payment_status_update( $order, $client_status, $final_txn, $qs_error_msg, 'client_callback' );
                }
            }
        }

        DKPay_Log::log_client_callback( $order_uuid, $status, $wc_order_id );

        // 取消支付只允许原浏览器使用一次性凭证恢复，验证失败时不加载订单数据。
        if ( 'cancel' === $callback_status ) {
            if ( $wc_order_id ) {
                $order = wc_get_order( $wc_order_id );
                if ( $order && self::verify_checkout_restore_request( $order, $order_uuid ) ) {
                    self::restore_checkout_from_order( $order );
                    $order->update_meta_data( '_dkpay_restore_used', '1' );
                    $order->save();
                }
            }
            wp_safe_redirect( wc_get_checkout_url() );
            exit;
        }

        $order_page = wc_get_checkout_url();
        if ( $wc_order_id ) {
            $order = wc_get_order( $wc_order_id );
            if ( $order ) {
                if ( 'cancelled' === $client_status_for_ui && method_exists( $order, 'get_checkout_payment_url' ) ) {
                    $order_page = $order->get_checkout_payment_url();
                } elseif ( method_exists( $order, 'get_checkout_order_received_url' ) ) {
                    $order_page = $order->get_checkout_order_received_url();
                }
            }
        }

        // 已支付 / 支付失败：订单状态与日志均已在上面处理完毕，直接 302 到最终页面
        // （已支付 → 订单成功页；失败 → 订单失败页，页面本身带 "Try again"），不再渲染结果中间页。
        // 若因极端情况（页面已有输出，无法再发送跳转头）跳转失败，则回退渲染下方结果页。
        if ( in_array( $client_status_for_ui, [ 'paid', 'failed' ], true ) && wp_safe_redirect( $order_page ) ) {
            exit;
        }

        // 结果页仅用于取消 / 确认中（以及极端情况下跳转失败的回退展示），按状态区分图标与标题。
        $ui_state = 'confirming';
        if ( 'paid' === $client_status_for_ui ) {
            $ui_state = 'paid';
        } elseif ( 'cancelled' === $client_status_for_ui ) {
            $ui_state = 'cancelled';
        } elseif ( 'failed' === $client_status_for_ui ) {
            $ui_state = 'failed';
        }

        $ui_icons = [
            'paid'       => '✅',
            'cancelled'  => '❌',
            'failed'     => '❌',
            'confirming' => '⏳',
        ];
        $ui_titles = [
            'paid'       => __( 'Payment received', 'dkpay-shop' ),
            'cancelled'  => __( 'Payment cancelled', 'dkpay-shop' ),
            'failed'     => __( 'Payment failed', 'dkpay-shop' ),
            'confirming' => __( 'Confirming payment', 'dkpay-shop' ),
        ];

        ?>
        <!DOCTYPE html>
        <html <?php language_attributes(); ?>>
        <head>
            <meta charset="<?php bloginfo( 'charset' ); ?>" />
            <meta name="viewport" content="width=device-width, initial-scale=1" />
            <title><?php echo esc_html( $status === 'cancel' ? __( 'Payment cancelled', 'dkpay-shop' ) : __( 'Payment status', 'dkpay-shop' ) ); ?> — <?php bloginfo( 'name' ); ?></title>
            <meta http-equiv="refresh" content="1;url=<?php echo esc_url( $order_page ); ?>" />
            <style>
                body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif; display: flex;
                    justify-content: center; align-items: center; min-height: 100vh; margin: 0; background: #f5f5f5; }
                .dkpay-result { background: #fff; border-radius: 12px; padding: 48px 40px; max-width: 420px;
                    width: 90%; text-align: center; box-shadow: 0 4px 24px rgba(0,0,0,.08); }
                .dkpay-result .icon { font-size: 56px; margin-bottom: 16px; }
                .dkpay-result h1 { margin: 0 0 12px; font-size: 24px; color: #1a1a1a; }
                .dkpay-result p { color: #555; line-height: 1.6; margin-bottom: 24px; }
                .dkpay-result a { display: inline-block; background: #0073aa; color: #fff; padding: 12px 32px;
                    border-radius: 6px; text-decoration: none; font-weight: 600; }
                .dkpay-result a:hover { background: #005177; }
                .dkpay-result .hint { font-size: 12px; color: #999; margin-top: 16px; }
                @media (max-width: 480px) {
                    .dkpay-result { padding: 36px 22px; }
                    .dkpay-result h1 { font-size: 20px; }
                    .dkpay-result .icon { font-size: 48px; }
                }
            </style>
        </head>
        <body>
            <div class="dkpay-result">
                <div class="icon"><?php echo esc_html( $ui_icons[ $ui_state ] ?? 'ℹ️' ); ?></div>
                <h1><?php echo esc_html( $ui_titles[ $ui_state ] ?? $ui_titles['confirming'] ); ?></h1>
                <p><?php echo nl2br( esc_html( $message ) ); ?></p>
                <a href="<?php echo esc_url( $order_page ); ?>"><?php _e( 'Continue', 'dkpay-shop' ); ?></a>
                <p class="hint">
                    <?php if ( 'cancelled' === $ui_state ) : ?>
                        <?php _e( 'You can retry the payment from the order page.', 'dkpay-shop' ); ?>
                    <?php elseif ( 'failed' === $ui_state ) : ?>
                        <?php _e( 'You can retry the payment or choose another payment method on the order page.', 'dkpay-shop' ); ?>
                    <?php else : ?>
                        <?php _e( 'Order status may take a few seconds to update. Refresh if needed.', 'dkpay-shop' ); ?>
                    <?php endif; ?>
                </p>
            </div>
        </body>
        </html>
        <?php
        exit;
    }

    private static function hydrate_client_params_from_path( string $order_uuid ): string {
        if ( '' === $order_uuid ) {
            return $order_uuid;
        }

        $extra = '';
        if ( false !== strpos( $order_uuid, '?' ) ) {
            $parts = explode( '?', $order_uuid, 2 );
            $order_uuid = (string) ( $parts[0] ?? '' );
            $extra = (string) ( $parts[1] ?? '' );
        } elseif ( false !== strpos( $order_uuid, '&' ) ) {
            $parts = explode( '&', $order_uuid, 2 );
            $order_uuid = (string) ( $parts[0] ?? '' );
            $extra = (string) ( $parts[1] ?? '' );
        }

        if ( '' !== $extra ) {
            $parsed = [];
            parse_str( $extra, $parsed );
            if ( is_array( $parsed ) ) {
                foreach ( $parsed as $k => $v ) {
                    if ( ! is_string( $k ) || '' === $k ) {
                        continue;
                    }
                    if ( ! isset( $_GET[ $k ] ) ) {
                        $_GET[ $k ] = $v;
                    }
                }
            }
        }

        if ( isset( $_GET['Status'] ) && ! isset( $_GET['status'] ) ) {
            $_GET['status'] = $_GET['Status'];
        }
        if ( isset( $_GET['SystemOrderID'] ) && ! isset( $_GET['order_number'] ) ) {
            $_GET['order_number'] = $_GET['SystemOrderID'];
        }
        if ( isset( $_GET['PaypalOrderID'] ) && ! isset( $_GET['transaction_id'] ) ) {
            $_GET['transaction_id'] = $_GET['PaypalOrderID'];
        }
        if ( isset( $_GET['UniqueID'] ) && ! isset( $_GET['unique_id'] ) ) {
            $_GET['unique_id'] = $_GET['UniqueID'];
        }
        if ( isset( $_GET['token'] ) && ! isset( $_GET['unique_id'] ) ) {
            $_GET['unique_id'] = $_GET['token'];
        }

        return $order_uuid;
    }

    /**
     * 验证取消回调是否来自原订单的原始浏览器，并确认凭证只能使用一次。
     *
     * @param \WC_Order $order 原支付订单。
     * @param string $order_uuid 订单回调标识。
     * @return bool 验证通过时返回 true。
     */
    private static function verify_checkout_restore_request( \WC_Order $order, string $order_uuid ): bool {
        $token = isset( $_GET['restore_token'] ) ? sanitize_text_field( wp_unslash( $_GET['restore_token'] ) ) : '';
        $visitor_id = isset( $_GET['visitor_id'] ) ? sanitize_text_field( wp_unslash( $_GET['visitor_id'] ) ) : '';
        $timestamp = isset( $_GET['ts'] ) ? sanitize_text_field( wp_unslash( $_GET['ts'] ) ) : '';
        $sign = isset( $_GET['sign'] ) ? sanitize_text_field( wp_unslash( $_GET['sign'] ) ) : '';
        if ( '' === $token || '' === $visitor_id || '' === $timestamp || '' === $sign || ! ctype_digit( $timestamp ) ) {
            return false;
        }

        $timestamp = (int) $timestamp;
        if ( $timestamp <= 0 || abs( time() - $timestamp ) > 2 * HOUR_IN_SECONDS ) {
            return false;
        }
        if ( '1' === (string) $order->get_meta( '_dkpay_restore_used', true ) ) {
            return false;
        }

        $expected_sign = DKPay_API::build_restore_signature( $order_uuid, $token, $visitor_id, $timestamp );
        if ( ! hash_equals( $expected_sign, $sign ) ) {
            return false;
        }
        if ( ! hash_equals( (string) $order->get_meta( '_dkpay_restore_token_hash', true ), wp_hash( $token ) ) ) {
            return false;
        }
        if ( ! hash_equals( (string) $order->get_meta( '_dkpay_restore_visitor_id', true ), $visitor_id ) ) {
            return false;
        }
        if ( ! function_exists( 'WC' ) || ! WC()->session ) {
            return false;
        }

        $current_session_id = (string) WC()->session->get_customer_id();
        $order_session_id = (string) $order->get_meta( '_dkpay_restore_session_id', true );
        return '' !== $current_session_id && '' !== $order_session_id && hash_equals( $order_session_id, $current_session_id );
    }

    /**
     * 从已创建的 WooCommerce 订单恢复取消支付前的结算状态。
     *
     * 输入为原订单对象，输出为当前购物车、客户地址和结算字段的恢复结果。
     * 该逻辑让用户取消支付后回到普通 /checkout/ 页面，并可继续修改后重新下单。
     *
     * @param \WC_Order $order 原支付订单。
     * @return void
     */
    private static function restore_checkout_from_order( \WC_Order $order ): void {
        if ( ! function_exists( 'WC' ) || ! WC()->cart || ! WC()->customer ) {
            return;
        }

        WC()->cart->empty_cart();
        foreach ( $order->get_items() as $item ) {
            $product = $item->get_product();
            if ( ! $product ) {
                continue;
            }

            $cart_item_data = [];
            $variation_id = $product->is_type( 'variation' ) ? $product->get_id() : 0;
            $product_id = $variation_id ? $product->get_parent_id() : $product->get_id();
            $variation = $variation_id ? $product->get_variation_attributes() : [];
            WC()->cart->add_to_cart(
                $product_id,
                (int) $item->get_quantity(),
                $variation_id,
                $variation,
                $cart_item_data
            );
        }

        $billing = [
            'first_name' => $order->get_billing_first_name(),
            'last_name'  => $order->get_billing_last_name(),
            'company'    => $order->get_billing_company(),
            'country'    => $order->get_billing_country(),
            'address_1'  => $order->get_billing_address_1(),
            'address_2'  => $order->get_billing_address_2(),
            'city'       => $order->get_billing_city(),
            'state'      => $order->get_billing_state(),
            'postcode'   => $order->get_billing_postcode(),
            'phone'      => $order->get_billing_phone(),
            'email'      => $order->get_billing_email(),
        ];
        $shipping = [
            'first_name' => $order->get_shipping_first_name(),
            'last_name'  => $order->get_shipping_last_name(),
            'company'    => $order->get_shipping_company(),
            'country'    => $order->get_shipping_country(),
            'address_1'  => $order->get_shipping_address_1(),
            'address_2'  => $order->get_shipping_address_2(),
            'city'       => $order->get_shipping_city(),
            'state'      => $order->get_shipping_state(),
            'postcode'   => $order->get_shipping_postcode(),
        ];

        foreach ( $billing as $key => $value ) {
            $setter = 'set_billing_' . $key;
            if ( method_exists( WC()->customer, $setter ) ) {
                WC()->customer->{$setter}( $value );
            }
        }
        foreach ( $shipping as $key => $value ) {
            $setter = 'set_shipping_' . $key;
            if ( method_exists( WC()->customer, $setter ) ) {
                WC()->customer->{$setter}( $value );
            }
        }

        WC()->customer->save();
        WC()->cart->calculate_totals();
        WC()->cart->set_session();
    }

    // -------------------------------------------------------------------------
    // 服务端回调（notify_url）
    // -------------------------------------------------------------------------

    /**
     * 处理 DKPay server-to-server 支付回调
     *
     * DKPay POST 到此端点，payload 格式（由网关端统一构建）：
     *   {
     *     "order_number": "...",      // 商城订单号
     *     "unique_id":   "...",       // 下单时传入的 checkout_token（即 UUID）
     *     "status":      "paid|cancelled|failed",
     *     "error_msg":   "..."        // 失败原因，成功时为 null
     *   }
     *
     * 我们通过 unique_id 精确定位订单，据此更新状态。
     */
    private static function handle_server_callback( string $order_uuid = '' ): void {
        $raw_body = file_get_contents( 'php://input' );
        $payload  = json_decode( $raw_body, true ) ?? [];

        $unique_id_raw = (string) ( $payload['unique_id'] ?? '' );
        $order_no_raw  = (string) ( $payload['order_number'] ?? '' );
        $status_raw    = (string) ( $payload['status'] ?? '' );
        $txn_id_raw    = (string) ( $payload['transaction_id'] ?? '' );
        $error_msg_raw = (string) ( $payload['error_msg'] ?? '' );
        $ts_raw        = (string) ( $payload[ self::CLIENT_SIGN_TS_PARAM ] ?? '' );
        $sign_raw      = (string) ( $payload[ self::CLIENT_SIGN_PARAM ] ?? '' );

        $unique_id  = sanitize_text_field( $unique_id_raw );
        if ( '' === $unique_id && '' !== $order_uuid ) {
            $unique_id = sanitize_text_field( $order_uuid );
        }
        $order_no   = sanitize_text_field( $order_no_raw );
        $status     = sanitize_text_field( $status_raw );
        $error_msg  = sanitize_text_field( $error_msg_raw );
        $txn_id     = sanitize_text_field( $txn_id_raw );
        $status_norm = strtolower( trim( $status ) );

        // 记录完整回调 payload
        DKPay_Log::write( 'INFO', "服务端回调收到 (unique_id={$unique_id}, status={$status})", [
            'action'      => 'server_callback_raw',
            'unique_id'   => $unique_id,
            'order_no'    => $order_no,
            'status'      => $status,
            'error_msg'   => $error_msg,
            'txn_id'      => $txn_id,
            'raw_body'    => $raw_body,
        ] );

        // 优先用 unique_id（UUID）查找，其次用 order_number
        $wc_order_id = self::find_wc_order_by_unique_id( $unique_id )
            ?: self::find_wc_order_by_order_number( $order_no );

        if ( ! $wc_order_id ) {
            DKPay_Log::log_error( "服务端回调：找不到订单 UUID={$unique_id} order_no={$order_no}", [
                'unique_id' => $unique_id,
                'order_no'  => $order_no,
            ] );
            // 找不到订单也返回成功，避免 DKPay 持续重试
            wp_send_json_success( [ 'acknowledged' => true, 'reason' => 'order_not_found' ] );
            return;
        }

        $order = wc_get_order( $wc_order_id );
        if ( ! $order ) {
            DKPay_Log::log_error( "服务端回调：订单对象不存在 order_id={$wc_order_id}" );
            wp_send_json_error( [ 'message' => 'Order object not found' ], 404 );
            return;
        }

        $result = 'unknown';

        // 已完成订单只能保持已完成，防止重复或乱序回调降级订单状态。
        if ( $order->is_paid() && 'paid' !== $status_norm && 'pending' !== $status_norm ) {
            $result = 'ignored';
        } elseif ( 'paid' === $status_norm || 'pending' === $status_norm || 'cancelled' === $status_norm || 'cancel' === $status_norm || 'failed' === $status_norm ) {
            $normalized = self::normalize_client_status( $status_norm );
            if ( ! $normalized ) {
                $result = 'unknown';
            } else {
                self::apply_payment_status_update( $order, $normalized, $txn_id ?: $unique_id, $error_msg, 'server_callback' );
                $result = $normalized;
            }
        } else {
            // 未知状态：记录日志，维持 on-hold，返回成功避免重试
            $order->add_order_note( sprintf(
                __( 'DKPay callback: unknown status "%s", unique_id=%s. See logs for details.', 'dkpay-shop' ),
                $status,
                $unique_id
            ) );
            DKPay_Log::log_error( "服务端回调：未知状态 {$status}", [
                'unique_id' => $unique_id,
                'order_id'  => $wc_order_id,
            ] );
            $result = 'unknown';
        }

        DKPay_Log::log_server_callback( $payload, $wc_order_id, $result );
        wp_send_json_success( [ 'status' => $result, 'order_id' => $wc_order_id ] );
    }

    private static function is_authenticated_callback( array $payload, $request = null ): bool {
        $provided = (string) ( $payload['securityToken'] ?? $payload['security_token'] ?? '' );
        if ( '' === $provided && $request instanceof \WP_REST_Request ) {
            $provided = (string) $request->get_header( 'x-dkpay-security-token' );
        }
        $expected = DKPay_API::get_security_token();
        if ( $provided === '' || $expected === '' ) {
            return false;
        }
        return hash_equals( $expected, $provided );
    }

    private static function normalize_client_status( string $status ): string {
        $s = strtolower( trim( $status ) );
        if ( 'success' === $s ) {
            return 'paid';
        }
        if ( 'completed' === $s ) {
            return 'paid';
        }
        if ( 'paid' === $s ) {
            return 'paid';
        }
        if ( 'pending' === $s ) {
            return 'paid';
        }
        if ( 'cancelled' === $s || 'cancel' === $s ) {
            return 'cancelled';
        }
        if ( 'failed' === $s ) {
            return 'failed';
        }
        return '';
    }

    private static function apply_payment_status_update( \WC_Order $order, string $status, string $txn_id, string $error_msg, string $source ): void {
        if ( $order->is_paid() && 'paid' !== $status ) {
            $order->add_order_note( sprintf( 'DKPay %s: ignored status "%s" because order is already paid.', $source, $status ) );
            return;
        }

        if ( 'paid' === $status ) {
            $order->payment_complete( $txn_id ?: $order->get_transaction_id() );
            $order->add_order_note( sprintf( 'DKPay %s: payment confirmed.', $source ) );
            return;
        }

        if ( 'cancelled' === $status ) {
            $order->update_status( 'cancelled', sprintf( 'DKPay %s: payment cancelled.', $source ) );
            if ( $error_msg ) {
                $order->add_order_note( sprintf( 'Cancel reason: %s', $error_msg ) );
            }
            return;
        }

        if ( 'failed' === $status ) {
            $note = sprintf( 'DKPay %s: payment failed', $source );
            if ( $error_msg ) {
                $note .= ' — ' . $error_msg;
            }
            $order->update_status( 'failed', $note );
        }
    }

    private static function verify_client_signature( array $params ): bool {
        $expected = DKPay_API::get_security_token();
        if ( '' === $expected ) {
            return false;
        }

        $ts = isset( $params[ self::CLIENT_SIGN_TS_PARAM ] ) ? (string) $params[ self::CLIENT_SIGN_TS_PARAM ] : '';
        $sign = isset( $params[ self::CLIENT_SIGN_PARAM ] ) ? (string) $params[ self::CLIENT_SIGN_PARAM ] : '';
        if ( '' === $ts || '' === $sign || ! ctype_digit( $ts ) ) {
            return false;
        }

        $now = time();
        $t = (int) $ts;
        $t_sec = strlen( $ts ) > 10 ? (int) floor( $t / 1000 ) : $t;
        if ( $t_sec <= 0 || abs( $now - $t_sec ) > 24 * HOUR_IN_SECONDS ) {
            return false;
        }

        $data = [
            'unique_id'      => (string) ( $params['unique_id'] ?? '' ),
            'order_number'   => (string) ( $params['order_number'] ?? '' ),
            'status'         => (string) ( $params['status'] ?? '' ),
            'transaction_id' => (string) ( $params['transaction_id'] ?? '' ),
            'error_msg'      => (string) ( $params['error_msg'] ?? '' ),
            self::CLIENT_SIGN_TS_PARAM => $ts,
        ];

        $provided = strtolower( trim( $sign ) );
        if ( 0 === strpos( $provided, '0x' ) ) {
            $provided = substr( $provided, 2 );
        }

        $modes = [ 'rfc3986', 'rfc1738', 'none' ];
        foreach ( $modes as $mode ) {
            $canonical = self::build_client_sign_string( $data, $mode );
            $calc = hash_hmac( 'sha256', $canonical, $expected );
            if ( hash_equals( $calc, $provided ) ) {
                DKPay_Log::write( 'INFO', 'Client return signature verified', [
                    'action'      => 'client_return_verify_ok',
                    'mode'        => $mode,
                    'ts'          => $ts,
                    'status'      => (string) ( $data['status'] ?? '' ),
                    'sign_prefix' => substr( $provided, 0, 12 ),
                ] );
                return true;
            }
        }

        DKPay_Log::write( 'WARN', 'Client return signature mismatch', [
            'action'      => 'client_return_verify_fail',
            'ts'          => $ts,
            'status'      => (string) ( $data['status'] ?? '' ),
            'sign_prefix' => substr( $provided, 0, 12 ),
        ] );
        return false;
    }

    private static function build_client_sign_string( array $data, string $encoding = 'rfc3986' ): string {
        $keys = [ 'unique_id', 'order_number', 'status', 'transaction_id', 'error_msg', self::CLIENT_SIGN_TS_PARAM ];
        $pairs = [];
        foreach ( $keys as $k ) {
            $value = (string) ( $data[ $k ] ?? '' );
            if ( 'none' === $encoding ) {
                $pairs[] = $k . '=' . $value;
            } elseif ( 'rfc1738' === $encoding ) {
                $pairs[] = $k . '=' . urlencode( $value );
            } else {
                $pairs[] = $k . '=' . rawurlencode( $value );
            }
        }
        return implode( '&', $pairs );
    }

    private static function verify_server_signature( array $params ): bool {
        $security_token = DKPay_API::get_security_token();
        if ( '' === $security_token ) {
            return false;
        }

        $ts = isset( $params[ self::CLIENT_SIGN_TS_PARAM ] ) ? (string) $params[ self::CLIENT_SIGN_TS_PARAM ] : '';
        $sign = isset( $params[ self::CLIENT_SIGN_PARAM ] ) ? (string) $params[ self::CLIENT_SIGN_PARAM ] : '';
        if ( '' === $ts || '' === $sign || ! ctype_digit( $ts ) ) {
            return false;
        }

        $t = (int) $ts;
        $t_sec = strlen( $ts ) > 10 ? (int) floor( $t / 1000 ) : $t;
        $now = time();
        if ( $t_sec <= 0 || abs( $now - $t_sec ) > 24 * HOUR_IN_SECONDS ) {
            return false;
        }

        $canonical = self::build_client_sign_string( [
            'unique_id'      => (string) ( $params['unique_id'] ?? '' ),
            'order_number'   => (string) ( $params['order_number'] ?? '' ),
            'status'         => (string) ( $params['status'] ?? '' ),
            'transaction_id' => (string) ( $params['transaction_id'] ?? '' ),
            'error_msg'      => (string) ( $params['error_msg'] ?? '' ),
            self::CLIENT_SIGN_TS_PARAM => $ts,
        ], 'none' );

        $provided = strtolower( trim( $sign ) );
        if ( 0 === strpos( $provided, '0x' ) ) {
            $provided = substr( $provided, 2 );
        }

        $calc = hash_hmac( 'sha256', $canonical, $security_token );
        return hash_equals( $calc, $provided );
    }

    private static function find_wc_order_by_uuid( string $order_uuid ): int {
        return self::find_wc_order_by_unique_id( $order_uuid );
    }

    // -------------------------------------------------------------------------
    // 订单查找
    // -------------------------------------------------------------------------

    /**
     * 通过 unique_id（UUID / checkout_token）精确查找 WC 订单
     */
    private static function find_wc_order_by_unique_id( string $unique_id ): int {
        if ( empty( $unique_id ) ) {
            return 0;
        }
        $order_ids = wc_get_orders( [
            'limit'      => 1,
            'meta_key'   => '_dkpay_uuid',
            'meta_value' => $unique_id,
            'return'     => 'ids',
        ] );
        return ! empty( $order_ids ) ? (int) $order_ids[0] : 0;
    }

    /**
     * 通过 order_number（商城订单号）查找 WC 订单
     */
    private static function find_wc_order_by_order_number( string $order_number ): int {
        if ( empty( $order_number ) ) {
            return 0;
        }
        $normalized = $order_number;
        $prefix = DKPay_API::ORDER_NUMBER_PREFIX;
        if ( '' !== $prefix && 0 === strpos( $normalized, $prefix ) ) {
            $normalized = substr( $normalized, strlen( $prefix ) );
        }
        $normalized = trim( $normalized );

        // 先尝试精确匹配 WC 内置订单号/ID
        $order = wc_get_order( $order_number );
        if ( $order ) {
            return (int) $order->get_id();
        }
        if ( '' !== $normalized && $normalized !== $order_number ) {
            $order = wc_get_order( $normalized );
            if ( $order ) {
                return (int) $order->get_id();
            }
        }
        // 再尝试按订单号元数据模糊匹配
        $order_ids = wc_get_orders( [
            'limit'      => 1,
            'meta_key'   => '_dkpay_order_number',
            'meta_value' => $order_number,
            'return'     => 'ids',
        ] );
        if ( ! empty( $order_ids ) ) {
            return (int) $order_ids[0];
        }

        if ( '' !== $normalized && $normalized !== $order_number ) {
            $order_ids = wc_get_orders( [
                'limit'      => 1,
                'meta_key'   => '_dkpay_order_number',
                'meta_value' => $normalized,
                'return'     => 'ids',
            ] );
            if ( ! empty( $order_ids ) ) {
                return (int) $order_ids[0];
            }
        }

        if ( '' !== $normalized ) {
            $order_ids = wc_get_orders( [
                'limit'      => 1,
                'meta_key'   => '_dkpay_source_order_number',
                'meta_value' => $normalized,
                'return'     => 'ids',
            ] );
            if ( ! empty( $order_ids ) ) {
                return (int) $order_ids[0];
            }
        }

        return 0;
    }

    // -------------------------------------------------------------------------
    // REST API（备用回调方式）
    // -------------------------------------------------------------------------

    public static function register_rest_routes() {
        register_rest_route( 'dkpay/v1', '/callback', [
            'methods'             => 'POST',
            'callback'            => [ __CLASS__, 'rest_server_callback' ],
            'permission_callback' => '__return_true',
        ] );
    }

    public static function rest_server_callback( \WP_REST_Request $request ): \WP_REST_Response {
        $payload  = $request->get_json_params() ?? [];
        $unique_id_raw = (string) ( $payload['unique_id'] ?? '' );
        $order_no_raw  = (string) ( $payload['order_number'] ?? '' );
        $status_raw    = (string) ( $payload['status'] ?? '' );
        $txn_id_raw    = (string) ( $payload['transaction_id'] ?? '' );
        $error_msg_raw = (string) ( $payload['error_msg'] ?? '' );
        $ts_raw        = (string) ( $payload[ self::CLIENT_SIGN_TS_PARAM ] ?? '' );
        $sign_raw      = (string) ( $payload[ self::CLIENT_SIGN_PARAM ] ?? '' );

        $unique_id  = sanitize_text_field( $payload['unique_id'] ?? '' );
        $order_no   = sanitize_text_field( $payload['order_number'] ?? '' );
        $status     = sanitize_text_field( $payload['status'] ?? '' );
        $error_msg  = sanitize_text_field( $payload['error_msg'] ?? '' );

        error_log( '[DKPay REST Callback] unique_id=' . $unique_id
            . ' order_number=' . $order_no
            . ' status=' . $status
            . ' error_msg=' . $error_msg );

        $wc_order_id = self::find_wc_order_by_unique_id( $unique_id )
            ?: self::find_wc_order_by_order_number( $order_no );

        if ( $wc_order_id ) {
            $order = wc_get_order( $wc_order_id );
            if ( $order && ! ( $order->is_paid() && 'paid' !== $status ) ) {
                if ( 'paid' === $status ) {
                    $txn_id = sanitize_text_field( $payload['transaction_id'] ?? $unique_id );
                    $order->payment_complete( $txn_id, __( 'DKPay 支付成功（REST回调）', 'dkpay-shop' ) );
                } elseif ( 'cancelled' === $status || 'cancel' === $status ) {
                    $order->update_status( 'cancelled', __( 'DKPay 支付已取消（REST回调）', 'dkpay-shop' ) );
                    if ( $error_msg ) {
                        $order->add_order_note( sprintf( __( '取消原因：%s', 'dkpay-shop' ), $error_msg ) );
                    }
                } elseif ( 'failed' === $status ) {
                    $order->update_status( 'failed', 'DKPay 支付失败' . ( $error_msg ? ': ' . $error_msg : '' ) );
                }
            }
        }

        return rest_ensure_response( [ 'status' => 'acknowledged' ] );
    }
}
