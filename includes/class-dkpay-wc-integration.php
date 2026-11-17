<?php
/**
 * DKPay WooCommerce 结账页集成
 *
 * 职责：
 *  1. 提供 get_method_icon / get_method_desc 供网关渲染使用
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class DKPay_WC_Integration {

    // -------------------------------------------------------------------------
    // 辅助：供网关渲染时使用
    // -------------------------------------------------------------------------

    public static function get_method_icon_html( string $method ): string {
        $post_id = self::get_method_post_id( $method );
        if ( $post_id ) {
            $icon = get_post_meta( $post_id, '_dkpay_icon', true );
            if ( ! empty( $icon ) ) {
                $decoded = html_entity_decode( (string) $icon, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
                $maybe_url = esc_url_raw( $decoded );
                if ( '' !== $maybe_url && $maybe_url === $decoded ) {
                    return self::build_icon_img_html( $maybe_url, $method );
                }
                return '<span aria-hidden="true" style="display:block;line-height:1;">' . esc_html( $decoded ) . '</span>';
            }
        }
        $default_url = self::get_default_method_icon_url( $method );
        if ( '' !== $default_url ) {
            return self::build_icon_img_html( $default_url, $method );
        }

        return '';
    }

    public static function get_method_desc( string $method ): string {
        $post_id = self::get_method_post_id( $method );
        if ( $post_id ) {
            $desc = get_post_meta( $post_id, '_dkpay_description', true );
            if ( ! empty( $desc ) ) {
                return $desc;
            }
        }

        $method = self::normalize_method_key( $method );
        $fallbacks = [
            'paypal' => 'Pay with PayPal',
            'card'   => 'Pay with Card',
            'apple_pay'  => 'Pay with Apple Pay',
            'google_pay' => 'Pay with Google Pay',
        ];
        return $fallbacks[ $method ] ?? '';
    }

    private static function get_method_post_id( string $method ): int {
        $posts = get_posts( [
            'post_type'      => 'dkpay_method',
            'posts_per_page' => 1,
            'meta_key'       => '_dkpay_method_key',
            'meta_value'     => $method,
            'fields'         => 'ids',
            'no_found_rows'  => true,
        ] );
        return ! empty( $posts ) ? (int) $posts[0] : 0;
    }

    private static function get_default_method_icon_url( string $method ): string {
        $key = self::normalize_method_key( $method );
        $map = [
            'paypal'     => 'paypal.png',
            'card'       => 'card.svg',
            'apple_pay'  => 'applepay.svg',
            'google_pay' => 'googlepay.svg',
        ];

        $file = $map[ $key ] ?? '';
        if ( '' === $file ) {
            return '';
        }

        if ( ! defined( 'DKPAY_PLUGIN_URL' ) ) {
            return '';
        }

        return DKPAY_PLUGIN_URL . 'assets/icons/' . $file;
    }

    private static function build_icon_img_html( string $url, string $method ): string {
        $alt = strtoupper( $method );
        $key = self::normalize_method_key( $method );
        $wide_marks = [
            'apple_pay',
            'google_pay',
        ];
        $is_wide = in_array( $key, $wide_marks, true );

        if ( $is_wide ) {
            return '<img src="' . esc_url( $url ) . '" alt="' . esc_attr( $alt ) . '" class="dkpay-method-icon-img" style="height:24px;width:auto;max-width:120px;object-fit:contain;display:block;" />';
        }

        return '<img src="' . esc_url( $url ) . '" alt="' . esc_attr( $alt ) . '" class="dkpay-method-icon-img" style="width:24px;height:24px;object-fit:contain;display:block;" />';
    }

    private static function normalize_method_key( string $key ): string {
        $key = strtolower( trim( $key ) );
        if ( '' === $key ) {
            return '';
        }
        $key = str_replace( [ ' ', '-' ], '_', $key );

        $map = [
            'paypal'     => 'paypal',
            'card'       => 'card',
            'card_pay'   => 'card',
            'creditcard' => 'card',
            'credit_card' => 'card',
            'apple'      => 'apple_pay',
            'applepay'   => 'apple_pay',
            'apple_pay'  => 'apple_pay',
            'google'      => 'google_pay',
            'googlepay'   => 'google_pay',
            'google_pay'  => 'google_pay',
            'gpay'        => 'google_pay',
        ];

        return $map[ $key ] ?? $key;
    }
}
