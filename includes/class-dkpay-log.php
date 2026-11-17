<?php
/**
 * DKPay 日志工具类
 *
 * 功能：
 *  1. 下单请求日志 — 记录发送给 DKPay 的数据摘要
 *  2. API 响应日志 — 记录 DKPay 返回的 link / error
 *  3. 服务端回调日志 — 记录 DKPay POST 过来的完整 payload
 *  4. 写入 wp-content/uploads/dkpay-logs/ 目录，按日期分文件
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class DKPay_Log {

    private static string $log_dir = '';
    private static bool   $enabled = true;

    // -------------------------------------------------------------------------
    // 初始化
    // -------------------------------------------------------------------------

    public static function init(): void {
        self::$log_dir = wp_upload_dir()['basedir'] . '/dkpay-logs';
        if ( ! is_dir( self::$log_dir ) ) {
            wp_mkdir_p( self::$log_dir );
        }
        // 可以通过 define('DKPAY_DEBUG_LOG', false) 在 wp-config.php 中关闭
        self::$enabled = ! defined( 'DKPAY_DEBUG_LOG' ) || DKPAY_DEBUG_LOG;
    }

    // -------------------------------------------------------------------------
    // 日志写入
    // -------------------------------------------------------------------------

    /**
     * 统一写入日志文件
     *
     * @param string $level   INFO | ERROR | WARN
     * @param string $message 日志内容
     * @param array  $context  附加数据（可选，会 json_encode 写入）
     */
    public static function write( string $level, string $message, array $context = [] ): void {
        if ( ! self::$enabled ) {
            return;
        }

        $date      = gmdate( 'Y-m-d' );
        $filename  = self::$log_dir . '/dkpay-' . $date . '.log';
        $timestamp = gmdate( 'Y-m-d H:i:s' ) . ' GMT';
        $level_str = sprintf( '[%s]', $level );

        $line = $timestamp . ' ' . $level_str . ' ' . $message;
        if ( ! empty( $context ) ) {
            $line .= ' | ' . wp_json_encode( $context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
        }
        $line .= PHP_EOL;

        file_put_contents( $filename, $line, FILE_APPEND | LOCK_EX );
    }

    /**
     * 记录下单请求（发出前）
     */
    public static function log_request( string $method, string $url, array $order_data, string $uuid ): void {
        self::write( 'INFO', "下单请求 → {$method} (UUID: {$uuid})", [
            'action'     => 'create_order',
            'method'     => $method,
            'url'        => $url,
            'order_uuid' => $uuid,
            'amount'     => $order_data['amount'] ?? '',
            'order_no'   => $order_data['order_number'] ?? '',
            'currency'   => $order_data['currency'] ?? '',
            'products'   => is_string( $order_data['products'] ?? '' )
                ? strlen( $order_data['products'] ) . ' chars (JSON)'
                : count( $order_data['products'] ?? [] ) . ' items',
        ] );
    }

    /**
     * 记录 API 响应
     */
    public static function log_response( string $method, string $url, array $response, int $http_code, string $uuid ): void {
        self::write(
            'paid' === ($response['status'] ?? '') || 'success' === ($response['status'] ?? '') ? 'INFO' : 'WARN',
            "API 响应 ← {$method} (HTTP {$http_code}, UUID: {$uuid})",
            [
                'action'    => 'create_order_response',
                'method'    => $method,
                'url'       => $url,
                'uuid'      => $uuid,
                'status'    => $response['status'] ?? '',
                'link'      => $response['link'] ?? null,
                'message'   => $response['message'] ?? null,
                'http_code' => $http_code,
            ]
        );
    }

    /**
     * 记录服务端回调（DKPay POST 过来）
     */
    public static function log_server_callback( array $payload, int $wc_order_id, string $result ): void {
        self::write( 'INFO', "服务端回调处理 (order_id={$wc_order_id}, result={$result})", [
            'action'      => 'server_callback',
            'wc_order_id' => $wc_order_id,
            'payload'     => $payload,
            'result'      => $result,
        ] );
    }

    /**
     * 记录客户端回调跳转
     */
    public static function log_client_callback( string $uuid, string $status, int $wc_order_id ): void {
        self::write( 'INFO', "客户端回调跳转 (UUID: {$uuid}, status: {$status}, order_id: {$wc_order_id})", [
            'action'      => 'client_callback',
            'uuid'        => $uuid,
            'status'      => $status,
            'wc_order_id' => $wc_order_id,
        ] );
    }

    /**
     * 记录错误（网络、支付失败等）
     */
    public static function log_error( string $message, array $context = [] ): void {
        self::write( 'ERROR', $message, $context );
    }

    /**
     * 获取最近 N 条日志（供调试用）
     */
    public static function get_last_lines( int $lines = 50 ): string {
        $date = gmdate( 'Y-m-d' );
        $file = self::$log_dir . '/dkpay-' . $date . '.log';
        if ( ! is_file( $file ) ) {
            return "[{$date}] 日志文件不存在";
        }
        $content = file_get_contents( $file );
        $all     = explode( PHP_EOL, trim( $content ) );
        $last    = array_slice( $all, - $lines );
        return implode( PHP_EOL, $last );
    }
}
