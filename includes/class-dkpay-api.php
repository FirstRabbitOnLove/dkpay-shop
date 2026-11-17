<?php
/**
 * DKPay API 核心类
 *
 * 功能：
 *  1. 读取四种支付方式的网关配置
 *  2. 构造 subkeys（serialize → base64_encode）
 *  3. 发送 POST 请求到对应网关
 *  4. AJAX 处理短代码下单（含可选商品信息）
 *  5. 渲染 [dkpay_checkout] 短代码
 *  6. 为 WooCommerce 提供完整的订单数据构建方法
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class DKPay_API {

    public const DEFAULT_METHODS = [
        'paypal'     => 'paypal',
        'card'       => 'card',
        'apple_pay'  => 'apple_pay',
        'google_pay' => 'google_pay',
    ];

    public const ORDER_NUMBER_PREFIX = 'WP-';

    // -------------------------------------------------------------------------
    // 初始化
    // -------------------------------------------------------------------------

    public static function init() {
        // UUID 已在 process_payment() 中保存，无需额外钩子
    }

    /**
     * 获取所有可用支付方式（含网关地址）
     * 优先级：已配置 gateway_url 的 > fallback
     */
    public static function get_all_methods(): array {
        // 先保留 CPT 中的停用状态，避免停用后又被 fallback 重新启用。
        $db_all_methods = DKPay_Methods::get_all_methods();
        $db_methods     = DKPay_Methods::get_active_methods();

        // 仅在后台还没有配置任何 dkpay_method 时提供兼容 fallback（避免前后台数据对不上）。
        if ( empty( $db_all_methods ) ) {
            foreach ( self::DEFAULT_METHODS as $key => $api_val ) {
                $db_methods[ $key ] = [
                    'key'         => $key,
                    'label'       => self::get_fallback_label( $key ),
                    'api_value'   => $api_val,
                    'gateway_url' => self::get_fallback_url( $key ),
                    'enabled'     => true,
                ];
            }
        }

        return $db_methods;
    }

    private static function get_fallback_label( string $key ): string {
        $labels = [
            'paypal' => 'PayPal',
            'card'   => __( 'Credit / Debit Card', 'dkpay-shop' ),
            'apple_pay'  => 'Apple Pay',
            'google_pay' => 'Google Pay',
        ];
        return $labels[ $key ] ?? ucfirst( $key );
    }

    private static function get_fallback_url( string $key ): string {
        $option_urls = [
            'paypal' => (string) get_option( 'dkpay_paypal_url', '' ),
            'card'   => (string) get_option( 'dkpay_card_url', '' ),
            'apple_pay'  => (string) get_option( 'dkpay_apple_url', '' ),
            'google_pay' => (string) get_option( 'dkpay_google_url', '' ),
        ];
        if ( ! empty( $option_urls[ $key ] ) ) {
            return (string) $option_urls[ $key ];
        }

        $paths = [
            'paypal' => '/shops/wp/direct',
            'card'   => '/shops/wp/card',
            'apple_pay'  => '/shops/wp/apple',
            'google_pay' => '/shops/wp/google',
        ];
        $path = $paths[ $key ] ?? '';
        if ( '' === $path ) {
            return '';
        }

        return self::get_gateway_url() . ltrim( $path, '/' );
    }

    private static function build_dkpay_order_number( \WC_Order $order ): string {
        $prefix = self::ORDER_NUMBER_PREFIX;
        $raw = (string) $order->get_order_number();
        if ( '' === $raw ) {
            $raw = (string) (int) $order->get_id();
        }
        if ( '' !== $prefix && 0 === strpos( $raw, $prefix ) ) {
            return $raw;
        }
        return $prefix . $raw;
    }

    // -------------------------------------------------------------------------
    // 配置读取
    // -------------------------------------------------------------------------

    public static function get_gateway_url(): string {
        return trailingslashit( rtrim( get_option( 'dkpay_gateway_url', 'https://pay.example.com' ), '/' ) );
    }

    public static function get_secret_key(): string {
        return (string) get_option( 'dkpay_secret_key', '' );
    }

    public static function get_security_token(): string {
        return (string) get_option( 'dkpay_security_token', '' );
    }

    public static function is_configured(): bool {
        return ! empty( self::get_secret_key() ) && ! empty( self::get_security_token() );
    }

    public static function get_method_url( string $method ): string {
        $methods = self::get_all_methods();
        if ( isset( $methods[ $method ] ) && ! empty( $methods[ $method ]['gateway_url'] ) ) {
            return rtrim( $methods[ $method ]['gateway_url'], '/' );
        }
        return self::get_fallback_url( $method );
    }

    public static function get_available_methods(): array {
        $result = [];
        foreach ( self::get_all_methods() as $key => $m ) {
            if ( ! empty( $m['gateway_url'] ) && ! empty( $m['enabled'] ) && ! empty( $m['label'] ) ) {
                $result[ $key ] = $m['gateway_url'];
            }
        }
        return $result;
    }

    // -------------------------------------------------------------------------
    // 回调 URL 生成
    // -------------------------------------------------------------------------

    /**
     * 生成支付完成后的客户端回调 URL。
     *
     * @param string $order_uuid 订单回调标识。
     * @return string 支付完成回调地址。
     */
    public static function build_client_callback_url( string $order_uuid ): string {
        return home_url( '/dkpay/callback/client/' . rawurlencode( $order_uuid ) );
    }

    /**
     * 生成取消支付后的客户端回调 URL。
     *
     * @param string $order_uuid 订单回调标识。
     * @return string 取消支付回调地址。
     */
    /**
     * 生成带一次性恢复凭证的取消支付回调地址。
     *
     * @param string $order_uuid 订单回调标识。
     * @param string $restore_token 一次性恢复凭证。
     * @param string $visitor_id 订单所属游客标识。
     * @param int $timestamp 签名时间戳。
     * @return string 取消支付回调地址。
     */
    public static function build_cancel_callback_url( string $order_uuid, string $restore_token = '', string $visitor_id = '', int $timestamp = 0 ): string {
        $url = home_url( '/dkpay/callback/cancel/' . rawurlencode( $order_uuid ) );
        if ( '' === $restore_token ) {
            return $url;
        }

        $timestamp = $timestamp > 0 ? $timestamp : time();
        $sign = self::build_restore_signature( $order_uuid, $restore_token, $visitor_id, $timestamp );
        return add_query_arg(
            [
                'restore_token' => $restore_token,
                'visitor_id'    => $visitor_id,
                'ts'            => $timestamp,
                'sign'          => $sign,
            ],
            $url
        );
    }

    /**
     * 使用 WordPress 服务端盐为取消恢复参数生成 HMAC 签名。
     *
     * @param string $order_uuid 订单回调标识。
     * @param string $restore_token 一次性恢复凭证。
     * @param string $visitor_id 游客标识。
     * @param int $timestamp 签名时间戳。
     * @return string 十六进制 HMAC 签名。
     */
    public static function build_restore_signature( string $order_uuid, string $restore_token, string $visitor_id, int $timestamp ): string {
        $payload = implode( '|', [ $order_uuid, $restore_token, $visitor_id, (string) $timestamp ] );
        return hash_hmac( 'sha256', $payload, wp_salt( 'dkpay_restore' ) );
    }

    /**
     * 生成服务端回调 URL（notify_url）
     * 格式：/dkpay/callback/server/{order_uuid}
     */
    public static function build_server_callback_url( string $order_uuid ): string {
        return home_url( '/dkpay/callback/server/' . rawurlencode( $order_uuid ) );
    }

    // -------------------------------------------------------------------------
    // subkeys 构造
    // -------------------------------------------------------------------------

    public static function build_subkeys( array $data ): string {
        return base64_encode( serialize( $data ) );
    }

    /**
     * 构建完整下单数据（WooCommerce 场景）
     *
     * 包含：客户信息、账单/发货地址、商品明细、回调 URL
     *
     * @param WC_Order   $order              WooCommerce 订单对象
     * @param string     $payment_method     paypal / card / apple / google
     * @param string     $account_type       live / sandbox
     * @return array
     */
    public static function build_order_data_from_wc( \WC_Order $order, string $payment_method, string $account_type = 'live' ): array {
        // UUID：用于 DKPay 关联与回调
        $order_uuid = md5( $order->get_order_key() );

        // 游客恢复凭证只保存在订单哈希中，明文仅通过取消回调 URL 传递一次。
        $restore_token = bin2hex( random_bytes( 32 ) );
        $restore_timestamp = time();

        // 商品明细
        $products = self::build_products_array( $order );

        // 客户信息
        $customer_name     = trim( $order->get_billing_first_name() . ' ' . $order->get_billing_last_name() );
        $customer_email    = $order->get_billing_email();
        $customer_phone    = $order->get_billing_phone();
        $customer_ip       = self::get_client_ip();
        $visitor_id        = (string) $order->get_meta( '_dkpay_visitor_id', true );
        if ( '' === $visitor_id && function_exists( 'WC' ) && WC()->session ) {
            $visitor_id = (string) WC()->session->get_customer_id();
        }
        if ( '' === $visitor_id ) {
            $visitor_id = md5( self::get_user_agent() . '|' . $customer_ip );
        }
        $utm_source        = (string) $order->get_meta( '_dkpay_utm_source', true );
        $utm_medium        = (string) $order->get_meta( '_dkpay_utm_medium', true );
        $source_device     = (string) $order->get_meta( '_dkpay_source_device', true );
        $customer_sysinfo  = (string) $order->get_meta( '_dkpay_customer_sysinfo', true );
        $customer_sysinfo_arr = [];
        if ( '' !== $customer_sysinfo ) {
            $decoded = json_decode( $customer_sysinfo, true );
            if ( is_array( $decoded ) ) {
                $customer_sysinfo_arr = $decoded;
            }
        }

        // 账单地址
        $billing = [
            'country_code' => strtoupper( $order->get_billing_country() ) ?: 'US',
            'first_name'   => $order->get_billing_first_name(),
            'last_name'    => $order->get_billing_last_name(),
            'address1'     => $order->get_billing_address_1(),
            'address2'     => $order->get_billing_address_2(),
            'city'         => $order->get_billing_city(),
            'state'        => $order->get_billing_state(),
            'postcode'     => $order->get_billing_postcode(),
            'phone'        => $order->get_billing_phone(),
        ];

        // 发货地址（与账单相同）
        $shipping = [
            'country_code' => strtoupper( $order->get_shipping_country() ) ?: $billing['country_code'],
            'first_name'   => $order->get_shipping_first_name() ?: $order->get_billing_first_name(),
            'last_name'    => $order->get_shipping_last_name() ?: $order->get_billing_last_name(),
            'address1'     => $order->get_shipping_address_1() ?: $order->get_billing_address_1(),
            'address2'     => $order->get_shipping_address_2() ?: $order->get_billing_address_2(),
            'city'         => $order->get_shipping_city() ?: $order->get_billing_city(),
            'state'        => $order->get_shipping_state() ?: $order->get_billing_state(),
            'postcode'     => $order->get_shipping_postcode() ?: $order->get_billing_postcode(),
            'phone'        => $order->get_shipping_phone() ?: $order->get_billing_phone(),
        ];

        $country_code = strtoupper( $billing['country_code'] ) ?: 'US';

        $total_num = 0;
        foreach ( $order->get_items() as $item ) {
            $total_num += (int) $item->get_quantity();
        }

        $dkpay_order_number = self::build_dkpay_order_number( $order );
        $source_order_number = (string) $order->get_order_number();

        return [
            // ---- 认证字段 ----
            'securityToken'            => self::get_security_token(),
            'secret_key'               => self::get_secret_key(),
            'merchant_url'             => home_url(),
            'system_name'              => 'WooCommerce',
            'account_type'             => ucfirst( strtolower( $account_type ) ),
            'payment_method'           => self::get_api_value_for_method( $payment_method ),

            // ---- 订单基本信息 ----
            'orders_id'                => (int) $order->get_id(),
            'order_number'             => $dkpay_order_number,
            'source_order_number'      => $source_order_number,
            'amount'                   => floatval( $order->get_total() ),
            'currency'                 => $order->get_currency() ?: 'USD',
            'currency_code'            => $order->get_currency() ?: 'USD',
            'checkout_token'           => $order_uuid, // 用作唯一标识
            'total_price'              => floatval( $order->get_total() ),
            'total_num'                => $total_num,
            'created_at'               => (int) time(),
            'updated_at'               => (int) time(),
            'visitor_id'               => $visitor_id,
            'utm_source'               => '' !== $utm_source ? $utm_source : 'wp',
            'utm_medium'               => '' !== $utm_medium ? $utm_medium : 'checkout',
            'source_device'            => '' !== $source_device ? $source_device : '',

            // ---- 客户信息 ----
            'customer_email_address'   => $customer_email,
            'customer_telephone'       => $customer_phone,
            'customer_ip'              => $customer_ip,
            'customer_country_iso_code_2' => $country_code,
            'customer_lastname'        => $order->get_billing_last_name(),
            'customer_name'            => $customer_name,
            'customer_useragent'       => self::get_user_agent(),
            'customer_street_address1' => $billing['address1'],
            'customer_street_address2' => $billing['address2'],
            'customer_city'            => $billing['city'],
            'customer_postcode'        => $billing['postcode'],
            'customer_state'           => $billing['state'],
            'customer_sysinfo'         => $customer_sysinfo_arr,
            'ip_detail'                => [
                'continent_code'  => '',
                'continent_name'  => '',
                'country_name'    => '',
                'province_code'   => '',
                'province_name'   => '',
                'city_name'       => '',
                'latitude'        => '',
                'longitude'       => '',
                'time_zone'       => $customer_sysinfo_arr['timezone'] ?? '',
                'isp'             => '',
                'organization'    => '',
                'user_type'       => '',
            ],

            // ---- 账单地址 ----
            'bill_address'             => [
                'phone'        => $billing['phone'],
                'first_name'   => $billing['first_name'],
                'last_name'    => $billing['last_name'],
                'country_code' => $billing['country_code'],
                'province'     => $billing['state'],
                'province_code'=> $billing['state'],
                'city'         => $billing['city'],
                'address1'     => $billing['address1'],
                'address2'     => $billing['address2'],
                'zip'          => $billing['postcode'],
                'company'      => (string) $order->get_billing_company(),
                'area'         => '',
                'tel_area_code'=> '',
            ],

            // ---- 发货地址 ----
            'shipping_address'         => [
                'phone'        => $shipping['phone'],
                'first_name'   => $shipping['first_name'],
                'last_name'    => $shipping['last_name'],
                'country_code' => $shipping['country_code'],
                'province'     => $shipping['state'],
                'province_code'=> $shipping['state'],
                'city'         => $shipping['city'],
                'address1'     => $shipping['address1'],
                'address2'     => $shipping['address2'],
                'zip'          => $shipping['postcode'],
                'company'      => (string) $order->get_shipping_company(),
                'area'         => '',
                'tel_area_code'=> '',
            ],

            // ---- 商品明细 ----
            'products'                 => $products,

            // ---- 回调 URL（关键！DKPay 据此跳转回 WP） ----
            'return_url'               => self::build_client_callback_url( $order_uuid ),
            'cancel_return_url'        => self::build_cancel_callback_url( $order_uuid, $restore_token, $visitor_id, $restore_timestamp ),
            'notify_url'               => self::build_server_callback_url( $order_uuid ),
            'restore_token_hash'       => wp_hash( $restore_token ),
            'restore_timestamp'        => $restore_timestamp,
        ];
    }

    /**
     * 构建短代码场景的下单数据（信息较少）
     */
    public static function build_order_data(
        float $amount,
        string $order_number,
        string $payment_method = 'paypal',
        string $customer_email = '',
        string $customer_telephone = '',
        string $country_code = 'US'
    ): array {
        $uuid = md5( $order_number . '_' . $payment_method . '_' . time() );

        return [
            'securityToken'            => self::get_security_token(),
            'secret_key'               => self::get_secret_key(),
            'merchant_url'             => home_url(),
            'system_name'              => 'WooCommerce',
            'account_type'             => 'Live',
            'payment_method'           => self::get_api_value_for_method( $payment_method ),
            'order_number'             => $order_number,
            'amount'                   => $amount,
            'currency'                 => 'USD',
            'currency_code'            => 'USD',
            'checkout_token'           => $uuid,
            'customer_email_address'   => $customer_email,
            'customer_telephone'       => $customer_telephone,
            'customer_ip'              => self::get_client_ip(),
            'customer_country_iso_code_2' => strtoupper( $country_code ),
            'bill_address'             => [
                'country_code' => strtoupper( $country_code ),
            ],
            'products'                 => [],
            'return_url'               => self::build_client_callback_url( $uuid ),
            'cancel_return_url'        => self::build_cancel_callback_url( $uuid ),
            'notify_url'               => self::build_server_callback_url( $uuid ),
        ];
    }

    // -------------------------------------------------------------------------
    // 辅助方法
    // -------------------------------------------------------------------------

    /**
     * 从 WC_Order 构建商品明细数组
     */
    private static function build_products_array( \WC_Order $order ): array {
        $products = [];
        foreach ( $order->get_items() as $item ) {
            $product = $item->get_product();
            $variant_title = '';
            if ( $product && is_a( $product, 'WC_Product_Variation' ) && function_exists( 'wc_get_formatted_variation' ) ) {
                $variant_title = wc_get_formatted_variation( $product, true, true, true );
            }
            $products[] = [
                'id'               => (int) ( $product ? $product->get_id() : 0 ),
                'product_title'    => $item->get_name(),
                'variant_title'    => $variant_title ?: $item->get_name(),
                'sku'              => $product ? $product->get_sku() : '',
                'price'            => number_format( $item->get_subtotal() / max( $item->get_quantity(), 1 ), 2, '.', '' ),
                'quantity'         => (int) $item->get_quantity(),
                'product_detail_url' => $product ? esc_url( $product->get_permalink() ) : '',
                'src'              => $product && has_post_thumbnail( $product->get_id() )
                    ? esc_url( wp_get_attachment_url( get_post_thumbnail_id( $product->get_id() ) ) )
                    : '',
            ];
        }
        return $products;
    }

    private static function get_client_ip(): string {
        $keys = [ 'HTTP_CF_CONNECTING_IP', 'HTTP_X_FORWARDED_FOR', 'HTTP_X_REAL_IP', 'REMOTE_ADDR' ];
        foreach ( $keys as $key ) {
            if ( ! empty( $_SERVER[ $key ] ) ) {
                $ip = $_SERVER[ $key ];
                $ips = array_map( 'trim', explode( ',', $ip ) );
                return $ips[0] ?? $ip;
            }
        }
        return '0.0.0.0';
    }

    private static function get_user_agent(): string {
        return (string) ( $_SERVER['HTTP_USER_AGENT'] ?? '' );
    }

    // -------------------------------------------------------------------------
    // 发送请求
    // -------------------------------------------------------------------------

    /**
     * 向指定支付方式的网关发送下单请求
     *
     * @param string $method   paypal / card / apple / google
     * @param array  $order_data build_order_data_from_wc() 或 build_order_data() 的输出
     * @return array { status, link, uuid, message }
     */
    public static function create_order( string $method, array $order_data ): array {
        $endpoint = self::get_method_url( $method );
        $uuid     = $order_data['checkout_token'] ?? '';

        // 记录下单请求
        DKPay_Log::log_request( $method, $endpoint, $order_data, $uuid );

        $subkeys  = self::build_subkeys( $order_data );

        $response = wp_remote_post( $endpoint, [
            'body'    => [ 'subkeys' => $subkeys ],
            'timeout' => 15,
            'headers' => [
                'Content-Type' => 'application/x-www-form-urlencoded',
            ],
        ] );

        if ( is_wp_error( $response ) ) {
            DKPay_Log::log_error( "下单网络错误: {$response->get_error_message()}", [
                'method' => $method,
                'uuid'   => $uuid,
            ] );
            return [
                'status'  => 'error',
                'message' => sprintf(
                    __( 'Network request failed: %s', 'dkpay-shop' ),
                    $response->get_error_message()
                ),
            ];
        }

        $code    = wp_remote_retrieve_response_code( $response );
        $body    = wp_remote_retrieve_body( $response );
        $result  = json_decode( $body, true );

        // 记录 API 响应
        DKPay_Log::log_response( $method, $endpoint, $result ?? [], $code, $uuid );

        if ( $code !== 200 ) {
            DKPay_Log::log_error( "下单 HTTP 错误码: {$code}", [
                'method' => $method,
                'uuid'   => $uuid,
                'body'   => $body,
            ] );
            return [
                'status'  => 'error',
                'message' => sprintf(
                    __( 'Server returned HTTP status: %d', 'dkpay-shop' ),
                    $code
                ),
            ];
        }

        if ( json_last_error() !== JSON_ERROR_NONE ) {
            DKPay_Log::log_error( "下单响应 JSON 解析失败: " . json_last_error_msg(), [
                'method' => $method,
                'uuid'   => $uuid,
                'body'   => $body,
            ] );
            return [
                'status'  => 'error',
                'message' => __( 'Response parsing failed (invalid JSON).', 'dkpay-shop' ),
            ];
        }

        return $result ?: [
            'status'  => 'error',
            'message' => __( 'Server returned an empty response.', 'dkpay-shop' ),
        ];
    }

    // -------------------------------------------------------------------------
    // AJAX 处理（短代码场景）
    // -------------------------------------------------------------------------

    public static function handle_ajax_order() {
        $nonce = sanitize_text_field( wp_unslash( $_POST['nonce'] ?? '' ) );
        if ( ! wp_verify_nonce( $nonce, 'dkpay_nonce' ) && ! wp_verify_nonce( $nonce, 'dkpay_standalone_nonce' ) ) {
            wp_send_json_error( [ 'message' => __( 'Security check failed. Please refresh the page and try again.', 'dkpay-shop' ) ], 403 );
        }

        $amount        = floatval( $_POST['amount'] ?? 0 );
        $order_number  = sanitize_text_field( $_POST['order_number'] ?? '' );
        $payment_method = sanitize_text_field( $_POST['payment_method'] ?? 'paypal' );
        $customer_email = sanitize_email( $_POST['customer_email'] ?? '' );
        $telephone     = sanitize_text_field( $_POST['telephone'] ?? '' );
        $country_code  = sanitize_text_field( $_POST['country_code'] ?? 'US' );

        if ( $amount <= 0 ) {
            wp_send_json_error( [ 'message' => __( 'Amount must be greater than 0.', 'dkpay-shop' ) ] );
        }
        if ( empty( $order_number ) ) {
            wp_send_json_error( [ 'message' => __( 'Order number is required.', 'dkpay-shop' ) ] );
        }
        if ( ! array_key_exists( $payment_method, self::get_all_methods() ) ) {
            wp_send_json_error( [ 'message' => __( 'Unsupported payment method.', 'dkpay-shop' ) ] );
        }

        $order_data = self::build_order_data(
            $amount,
            $order_number,
            $payment_method,
            $customer_email,
            $telephone,
            $country_code
        );

        $result = self::create_order( $payment_method, $order_data );

        if ( 'success' === $result['status'] && ! empty( $result['link'] ) ) {
            wp_send_json_success( [
                'link' => $result['link'],
                'uuid' => $result['uuid'] ?? $order_data['checkout_token'] ?? '',
            ] );
        } else {
            $msg = $result['message'] ?? __( 'Payment request failed.', 'dkpay-shop' );
            wp_send_json_error( [ 'message' => $msg ] );
        }
    }

    // -------------------------------------------------------------------------
    // 短代码
    // -------------------------------------------------------------------------

    public static function render_shortcode( array $atts ): string {
        $atts = shortcode_atts( [
            'amount'         => '',
            'order_id'       => '',
            'email'          => '',
            'phone'          => '',
            'payment_method' => 'paypal',
            'country_code'   => 'US',
        ], $atts, 'dkpay_checkout' );

        if ( empty( $atts['amount'] ) ) {
            return '<p>' . __( 'Please provide the amount attribute.', 'dkpay-shop' ) . '</p>';
        }
        if ( ! array_key_exists( $atts['payment_method'], self::get_all_methods() ) ) {
            $atts['payment_method'] = 'paypal';
        }

        $order_id   = $atts['order_id'] ?: 'ORD-' . time() . '-' . rand( 1000, 9999 );
        $nonce      = wp_create_nonce( 'dkpay_nonce' );
        $method     = $atts['payment_method'];
        $labels     = self::get_method_labels();

        ob_start();
        ?>
        <div class="dkpay-checkout-wrapper">
            <form id="dkpay-checkout-form" class="dkpay-checkout-form">
                <input type="hidden" name="amount"         value="<?php echo esc_attr( $atts['amount'] ); ?>" />
                <input type="hidden" name="order_number"   value="<?php echo esc_attr( $order_id ); ?>" />
                <input type="hidden" name="payment_method" value="<?php echo esc_attr( $method ); ?>" />
                <input type="hidden" name="country_code"   value="<?php echo esc_attr( $atts['country_code'] ); ?>" />

                <?php if ( ! empty( $atts['email'] ) ) : ?>
                    <input type="hidden" name="customer_email" value="<?php echo esc_attr( $atts['email'] ); ?>" />
                <?php else : ?>
                    <p class="dkpay-field">
                        <label><?php _e( 'Email', 'dkpay-shop' ); ?></label>
                        <input type="email" name="customer_email" required placeholder="your@email.com" />
                    </p>
                <?php endif; ?>

                <?php if ( ! empty( $atts['phone'] ) ) : ?>
                    <input type="hidden" name="telephone" value="<?php echo esc_attr( $atts['phone'] ); ?>" />
                <?php else : ?>
                    <p class="dkpay-field">
                        <label><?php _e( 'Phone', 'dkpay-shop' ); ?></label>
                        <input type="tel" name="telephone" placeholder="+1 234 567 8900" />
                    </p>
                <?php endif; ?>

                <p class="dkpay-submit">
                    <button type="submit" class="dkpay-btn">
                        <?php printf( __( 'Pay $%2$s with %1$s', 'dkpay-shop' ), esc_html( $labels[ $method ] ?? $method ), esc_html( $atts['amount'] ) ); ?>
                    </button>
                </p>
                <p class="dkpay-msg dkpay-msg-error" style="display:none;"></p>
                <p class="dkpay-msg dkpay-msg-loading" style="display:none;">
                    <?php _e( 'Redirecting to checkout...', 'dkpay-shop' ); ?>
                </p>
            </form>
        </div>
        <style>
            .dkpay-checkout-wrapper { max-width: 480px; margin: 20px 0; }
            .dkpay-field { margin-bottom: 12px; }
            .dkpay-field label { display: block; margin-bottom: 4px; font-weight: 600; font-size: 14px; }
            .dkpay-field input { width: 100%; padding: 8px 12px; border: 1px solid #ccc; border-radius: 4px; box-sizing: border-box; }
            .dkpay-btn { background: #0073aa; color: #fff; border: none; padding: 12px 24px;
                border-radius: 4px; cursor: pointer; font-size: 16px; width: 100%; transition: background .2s; }
            .dkpay-btn:hover { background: #005177; }
            .dkpay-btn:disabled { background: #ccc; cursor: not-allowed; }
            .dkpay-msg { margin-top: 10px; font-size: 14px; }
            .dkpay-msg-error { color: #d63638; }
        </style>
        <script>
        (function(){
            var form = document.getElementById('dkpay-checkout-form');
            var btn  = form.querySelector('.dkpay-btn');
            var err  = form.querySelector('.dkpay-msg-error');
            var load = form.querySelector('.dkpay-msg-loading');

            form.addEventListener('submit', function(e){
                e.preventDefault();
                err.style.display = 'none';
                load.style.display = 'none';
                btn.disabled = true;

                var fd = new FormData(form);
                fd.set('action', 'dkpay_create_order');
                fd.set('nonce', '<?php echo esc_js( $nonce ); ?>');

                fetch('<?php echo esc_url( admin_url( 'admin-ajax.php' ) ); ?>', {
                    method: 'POST',
                    body: fd
                })
                .then(function(r){ return r.json(); })
                .then(function(res){
                    if (res.success){
                        load.style.display = 'block';
                        window.location.href = res.data.link;
                    } else {
                        err.textContent = res.data.message || '<?php _e('Payment request failed. Please try again.','dkpay-shop'); ?>';
                        err.style.display = 'block';
                        btn.disabled = false;
                    }
                })
                .catch(function(){
                    err.textContent = '<?php _e('Network error. Please check your connection and try again.','dkpay-shop'); ?>';
                    err.style.display = 'block';
                    btn.disabled = false;
                });
            });
        })();
        </script>
        <?php
        return ob_get_clean();
    }

    public static function get_method_labels(): array {
        $labels = [];
        foreach ( self::get_available_methods() as $key => $_url ) {
            $method = self::get_all_methods()[ $key ] ?? null;
            if ( $method ) {
                $labels[ $key ] = $method['label'];
            }
        }
        return $labels;
    }

    /**
     * 获取指定 key 对应的 API payment_method 字段值
     */
    private static function get_api_value_for_method( string $key ): string {
        $methods = self::get_all_methods();
        return $methods[ $key ]['api_value'] ?? $key;
    }
}
