<?php
/**
 * DKPay 支付方式管理类
 *
 * 功能：
 *  1. 注册自定义文章类型 dkpay_method（支付方式配置）
 *  2. 提供后台 Meta Box 管理每个支付方式的：
 *     - 启用状态
 *     - 显示名称（前台展示用的标题）
 *     - 内部标识（method_key，用于 API 请求和短代码参数）
 *     - API 支付方法值（API value，发送给网关的 payment_method 字段值）
 *     - 专属网关地址
 *     - 图标（可选）
 *  3. 提供静态方法从数据库读取所有支付方式
 *
 * 管理员 → DKPay 收款 → 支付方式 即可编辑。
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class DKPay_Methods {

    // -------------------------------------------------------------------------
    // 初始化
    // -------------------------------------------------------------------------

    public static function init(): void {
        add_action( 'init',                    [ __CLASS__, 'register_cpt' ] );
        add_action( 'add_meta_boxes',          [ __CLASS__, 'add_meta_boxes' ] );
        add_action( 'save_post_dkpay_method',  [ __CLASS__, 'save_meta' ], 10, 3 );
        add_filter( 'manage_dkpay_method_posts_columns', [ __CLASS__, 'manage_columns' ] );
        add_action( 'manage_dkpay_method_posts_custom_column', [ __CLASS__, 'render_column' ], 10, 2 );
    }

    // -------------------------------------------------------------------------
    // 自定义文章类型
    // -------------------------------------------------------------------------

    public static function register_cpt(): void {
        if ( post_type_exists( 'dkpay_method' ) ) {
            return;
        }

        register_post_type( 'dkpay_method', [
            'labels'       => [
                'name'               => __( '支付方式', 'dkpay-shop' ),
                'singular_name'      => __( '支付方式', 'dkpay-shop' ),
                'add_new'            => __( '新增支付方式', 'dkpay-shop' ),
                'add_new_item'       => __( '新增支付方式', 'dkpay-shop' ),
                'edit_item'          => __( '编辑支付方式', 'dkpay-shop' ),
                'new_item'           => __( '新支付方式', 'dkpay-shop' ),
                'view_item'          => __( '查看支付方式', 'dkpay-shop' ),
                'search_items'       => __( '搜索支付方式', 'dkpay-shop' ),
                'not_found'          => __( '未找到支付方式', 'dkpay-shop' ),
                'not_found_in_trash' => __( '回收站中没有支付方式', 'dkpay-shop' ),
                'menu_name'          => __( '支付方式', 'dkpay-shop' ),
            ],
            'public'       => false,
            'show_ui'      => true,
            'show_in_menu' => 'options-general.php',
            'menu_icon'    => 'dashicons-credit-card',
            'menu_position' => 31,
            'supports'     => [ 'title' ],
            'rewrite'      => false,
            'query_var'    => false,
            'capability_type' => 'page',
        ] );
    }

    // -------------------------------------------------------------------------
    // Meta Box
    // -------------------------------------------------------------------------

    public static function add_meta_boxes(): void {
        add_meta_box(
            'dkpay_method_config',
            __( '支付方式配置', 'dkpay-shop' ),
            [ __CLASS__, 'render_meta_box' ],
            'dkpay_method',
            'normal',
            'high'
        );
    }

    public static function render_meta_box( \WP_Post $post ): void {
        $method_key    = get_post_meta( $post->ID, '_dkpay_method_key', true );
        $api_value     = get_post_meta( $post->ID, '_dkpay_api_value', true );
        $gateway_url   = get_post_meta( $post->ID, '_dkpay_gateway_url', true );
        $enabled       = get_post_meta( $post->ID, '_dkpay_enabled', true );
        $display_label = get_post_meta( $post->ID, '_dkpay_display_label', true );
        $icon          = get_post_meta( $post->ID, '_dkpay_icon', true );
        $description   = get_post_meta( $post->ID, '_dkpay_description', true );

        // 如果标题为空，用标题作为默认显示名称
        if ( empty( $display_label ) ) {
            $display_label = $post->post_title;
        }
        // 如果方法标识为空，用标题生成
        if ( empty( $method_key ) ) {
            $method_key = sanitize_title( $post->post_title );
        }
        if ( empty( $api_value ) ) {
            $api_value = $method_key;
        }

        wp_nonce_field( 'dkpay_method_save', 'dkpay_method_nonce' );

        $method_key = self::normalize_method_key( (string) $method_key );
        $api_value  = self::normalize_method_key( (string) $api_value );
        if ( '' !== $method_key ) {
            $api_value = $method_key;
        }

        $default_icon_url = self::get_default_icon_url_for_key( (string) $method_key );
        ?>
        <table class="form-table">
            <tr>
                <th scope="row"><label><?php _e( '启用', 'dkpay-shop' ); ?></label></th>
                <td>
                    <label>
                        <input type="checkbox" name="_dkpay_enabled" value="1"
                               <?php checked( $enabled, '1' ); ?> />
                        <?php _e( '勾选后该支付方式在前台结账页显示', 'dkpay-shop' ); ?>
                    </label>
                </td>
            </tr>
            <tr>
                <th scope="row"><label for="display_label"><?php _e( '前台显示名称', 'dkpay-shop' ); ?><span class="required">*</span></label></th>
                <td>
                    <input type="text" name="_dkpay_display_label" id="display_label"
                           value="<?php echo esc_attr( $display_label ); ?>" class="regular-text" />
                    <p class="description"><?php _e( '客户在结账页看到的支付方式名称，例如 "PayPal"', 'dkpay-shop' ); ?></p>
                </td>
            </tr>
            <tr>
                <th scope="row"><label for="method_key"><?php _e( '内部标识（英文）', 'dkpay-shop' ); ?><span class="required">*</span></label></th>
                <td>
                    <input type="text" name="_dkpay_method_key" id="method_key"
                           value="<?php echo esc_attr( $method_key ); ?>" class="regular-text code" />
                    <p class="description"><?php _e( '内部唯一标识，用于短代码和 API 调用：paypal / card / apple_pay / google_pay', 'dkpay-shop' ); ?></p>
                </td>
            </tr>
            <tr>
                <th scope="row"><label for="api_value"><?php _e( 'API 支付方法值', 'dkpay-shop' ); ?><span class="required">*</span></label></th>
                <td>
                    <input type="text" name="_dkpay_api_value" id="api_value"
                           value="<?php echo esc_attr( $api_value ); ?>" class="regular-text code" />
                    <p class="description"><?php _e( '发送给网关的 payment_method 字段值：paypal / card / apple_pay / google_pay', 'dkpay-shop' ); ?></p>
                </td>
            </tr>
            <tr>
                <th scope="row"><label for="gateway_url"><?php _e( '专属网关地址', 'dkpay-shop' ); ?><span class="required">*</span></label></th>
                <td>
                    <input type="url" name="_dkpay_gateway_url" id="gateway_url"
                           value="<?php echo esc_attr( $gateway_url ); ?>" class="regular-text code" />
                </td>
            </tr>
            <tr>
                <th scope="row"><label for="icon"><?php _e( '图标（可选）', 'dkpay-shop' ); ?></label></th>
                <td>
                    <input type="text" name="_dkpay_icon" id="icon"
                           value="<?php echo esc_attr( $icon ); ?>" class="regular-text" />
                    <p class="description"><?php _e( '填写图片 URL（如 SVG/PNG），留空则不显示图标。', 'dkpay-shop' ); ?></p>
                    <p class="description"><?php _e( '提示：图标留空并保存，内部标识为 paypal / card / apple_pay / google_pay 时会自动填入插件内置的默认图标链接。', 'dkpay-shop' ); ?></p>
                    <?php if ( empty( $icon ) && '' !== $default_icon_url ) : ?>
                        <p class="description" style="margin-top:6px;">
                            <?php _e( '默认官方图标（插件内置）：', 'dkpay-shop' ); ?>
                            <code><?php echo esc_html( $default_icon_url ); ?></code>
                            <button type="button" class="button" id="dkpay_fill_default_icon" data-icon="<?php echo esc_attr( $default_icon_url ); ?>">
                                <?php _e( '填入默认图标', 'dkpay-shop' ); ?>
                            </button>
                        </p>
                        <script>
                        (function(){
                            var btn = document.getElementById('dkpay_fill_default_icon');
                            var input = document.getElementById('icon');
                            if (!btn || !input) return;
                            btn.addEventListener('click', function(){
                                var v = btn.getAttribute('data-icon') || '';
                                if (v) input.value = v;
                            });
                        })();
                        </script>
                    <?php endif; ?>
                </td>
            </tr>
            <tr>
                <th scope="row"><label for="description"><?php _e( '描述文字（可选）', 'dkpay-shop' ); ?></label></th>
                <td>
                    <input type="text" name="_dkpay_description" id="description"
                           value="<?php echo esc_attr( $description ); ?>" class="regular-text" />
                    <p class="description"><?php _e( '支付方式下方显示的小字说明，留空则不显示。', 'dkpay-shop' ); ?></p>
                </td>
            </tr>
        </table>
        <?php
    }

    private static function get_default_icon_url_for_key( string $method_key ): string {
        $normalized_key = self::normalize_method_key( $method_key );
        $key = '' !== $normalized_key ? $normalized_key : strtolower( $method_key );
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

    public static function save_meta( int $post_id, \WP_Post $post, bool $update ): void {
        if ( ! isset( $_POST['dkpay_method_nonce'] )
            || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['dkpay_method_nonce'] ) ), 'dkpay_method_save' )
        ) {
            return;
        }
        if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
            return;
        }
        if ( ! current_user_can( 'edit_post', $post_id ) ) {
            return;
        }

        $method_key    = self::normalize_method_key( sanitize_text_field( $_POST['_dkpay_method_key'] ?? '' ) );
        $api_value     = self::normalize_method_key( sanitize_text_field( $_POST['_dkpay_api_value'] ?? '' ) );
        $gateway_url   = esc_url_raw( $_POST['_dkpay_gateway_url'] ?? '' );
        $display_label = sanitize_text_field( $_POST['_dkpay_display_label'] ?? '' );
        $icon          = sanitize_text_field( $_POST['_dkpay_icon'] ?? '' );
        $description   = sanitize_text_field( $_POST['_dkpay_description'] ?? '' );

        if ( '' !== $method_key ) {
            $api_value = $method_key;
        }

        if ( '' === $icon ) {
            $icon = self::get_default_icon_url_for_key( $method_key );
        }

        update_post_meta( $post_id, '_dkpay_enabled',            isset( $_POST['_dkpay_enabled'] ) ? '1' : '0' );
        update_post_meta( $post_id, '_dkpay_method_key',         $method_key );
        update_post_meta( $post_id, '_dkpay_api_value',          $api_value );
        update_post_meta( $post_id, '_dkpay_gateway_url',        $gateway_url );
        update_post_meta( $post_id, '_dkpay_display_label',      $display_label );
        update_post_meta( $post_id, '_dkpay_icon',               $icon );
        update_post_meta( $post_id, '_dkpay_description',        $description );

        // 同步更新文章标题为显示名称（先移除自身钩子，防止递归）
        if ( ! empty( $_POST['_dkpay_display_label'] ) ) {
            remove_action( 'save_post_dkpay_method', [ __CLASS__, 'save_meta' ], 10 );
            wp_update_post( [
                'ID'         => $post_id,
                'post_title' => sanitize_text_field( $_POST['_dkpay_display_label'] ),
            ] );
            add_action( 'save_post_dkpay_method', [ __CLASS__, 'save_meta' ], 10, 3 );
        }
    }

    private static function normalize_method_key( string $key ): string {
        $key = strtolower( trim( $key ) );
        if ( '' === $key ) {
            return '';
        }
        $key = str_replace( [ ' ', '-' ], '_', $key );

        $map = [
            'paypal'    => 'paypal',
            'card'      => 'card',
            'card_pay'  => 'card',
            'apple'     => 'apple_pay',
            'applepay'  => 'apple_pay',
            'apple_pay' => 'apple_pay',
            'google'     => 'google_pay',
            'googlepay'  => 'google_pay',
            'google_pay' => 'google_pay',
            'gpay'       => 'google_pay',
            'creditcard' => 'card',
            'credit_card' => 'card',
        ];

        return $map[ $key ] ?? $key;
    }

    // -------------------------------------------------------------------------
    // 列表列
    // -------------------------------------------------------------------------

    public static function manage_columns( array $columns ): array {
        return [
            'cb'            => '<input type="checkbox" />',
            'enabled'       => __( '启用', 'dkpay-shop' ),
            'title'         => __( '显示名称', 'dkpay-shop' ),
            'method_key'    => __( '内部标识', 'dkpay-shop' ),
            'api_value'     => __( 'API 值', 'dkpay-shop' ),
            'gateway_url'   => __( '网关地址', 'dkpay-shop' ),
        ];
    }

    public static function render_column( string $column, int $post_id ): void {
        switch ( $column ) {
            case 'enabled':
                $val = get_post_meta( $post_id, '_dkpay_enabled', true );
                echo $val === '1'
                    ? '<span style="color:green;font-weight:600;">✓ 启用</span>'
                    : '<span style="color:#999;">✗ 未启用</span>';
                break;
            case 'method_key':
                echo esc_html( get_post_meta( $post_id, '_dkpay_method_key', true ) ?: '-' );
                break;
            case 'api_value':
                echo esc_html( get_post_meta( $post_id, '_dkpay_api_value', true ) ?: '-' );
                break;
            case 'gateway_url':
                $url = get_post_meta( $post_id, '_dkpay_gateway_url', true );
                echo $url
                    ? '<code>' . esc_html( $url ) . '</code>'
                    : '<span style="color:#999;">-</span>';
                break;
            default:
                break;
        }
    }

    // -------------------------------------------------------------------------
    // 数据读取（供其他类调用）
    // -------------------------------------------------------------------------

    /**
     * 获取所有已启用的支付方式（数组形式）
     *
     * 返回格式：
     * [
     *   'paypal' => [
     *     'key'           => 'paypal',
     *     'label'         => 'PayPal',
     *     'api_value'     => 'paypal',
     *     'gateway_url'   => 'https://...',
     *   ],
     *   ...
     * ]
     */
    public static function get_all_methods(): array {
        $posts = get_posts( [
            'post_type'      => 'dkpay_method',
            'posts_per_page' => -1,
            'orderby'        => 'menu_order',
            'order'          => 'ASC',
            'post_status'    => 'publish',
        ] );

        $methods = [];
        foreach ( $posts as $post ) {
            $key       = get_post_meta( $post->ID, '_dkpay_method_key', true );
            $label     = get_post_meta( $post->ID, '_dkpay_display_label', true );
            $api       = get_post_meta( $post->ID, '_dkpay_api_value', true );
            $url       = get_post_meta( $post->ID, '_dkpay_gateway_url', true );
            $on        = get_post_meta( $post->ID, '_dkpay_enabled', true );
            $icon      = get_post_meta( $post->ID, '_dkpay_icon', true );
            $desc      = get_post_meta( $post->ID, '_dkpay_description', true );

            // key 和 label 必须非空
            if ( empty( $key ) || empty( $label ) || empty( $url ) ) {
                continue;
            }

            $methods[ $key ] = [
                'key'           => $key,
                'label'         => $label,
                'api_value'     => $api ?: $key,
                'gateway_url'   => rtrim( $url, '/' ),
                'enabled'       => $on === '1',
                'icon'          => $icon,
                'description'   => $desc,
            ];
        }
        return $methods;
    }

    /**
     * 获取指定 key 的支付方式数据
     */
    public static function get_method( string $key ): ?array {
        $methods = self::get_all_methods();
        return $methods[ $key ] ?? null;
    }

    /**
     * 根据 gateway_url 反查方法 key
     */
    public static function find_method_by_url( string $url ): ?string {
        $methods = self::get_all_methods();
        foreach ( $methods as $key => $m ) {
            if ( strtolower( $m['gateway_url'] ) === strtolower( $url ) ) {
                return $key;
            }
        }
        return null;
    }

    /**
     * 判断某个方法 key 是否已启用
     */
    public static function is_enabled( string $key ): bool {
        $method = self::get_method( $key );
        return $method !== null && $method['enabled'];
    }

    /**
     * 获取显示给前端的所有启用方法
     * 过滤掉未启用的
     */
    public static function get_active_methods(): array {
        $all = self::get_all_methods();
        return array_filter( $all, fn( $m ) => $m['enabled'] );
    }
}
