<?php
/**
 * DKPay WooCommerce 支付网关
 *
 * 标准 WC_Payment_Gateway 注册，在 Checkout 页 Payment options 区域显示。
 * 支持传统 Checkout 和 WooCommerce Blocks（通过 DKPay_Blocks 辅助类）。
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class DKPay_WC_Gateway extends WC_Payment_Gateway {

    // ---------------------------------------------------------------------
    // 构造
    // ---------------------------------------------------------------------

    public function __construct() {
        $this->id                 = 'dkpay';
        $this->icon               = '';
        $this->has_fields         = true;
        $this->method_title       = __( 'DKPay 收款', 'dkpay-shop' );
        $this->method_description = __( 'Accept payments via DKPay (PayPal, Card, Apple Pay, Google Pay).', 'dkpay-shop' );
        $this->title              = __( 'DKPay', 'dkpay-shop' );

        $this->init_form_fields();
        $this->init_settings();
        $this->description = (string) $this->get_option( 'description', '' );

        add_action( 'woocommerce_update_options_payment_gateways_' . $this->id, [ $this, 'process_admin_options' ] );
    }

    // ---------------------------------------------------------------------
    // 表单字段
    // ---------------------------------------------------------------------

    public function init_form_fields() {
        $this->form_fields = [
            'enabled' => [
                'title'   => __( '启用/停用', 'dkpay-shop' ),
                'label'   => __( '启用 DKPay', 'dkpay-shop' ),
                'type'    => 'checkbox',
                'default' => 'yes',
            ],
            'description' => [
                'title'       => __( '支付方式说明', 'dkpay-shop' ),
                'type'        => 'textarea',
                'description' => __( '显示在结账页 DKPay 区块上方，留空则不显示。', 'dkpay-shop' ),
                'default'     => '',
                'desc_tip'    => true,
            ],
        ];
    }

    // ---------------------------------------------------------------------
    // 可用性
    // ---------------------------------------------------------------------

    /**
     * 仅检查插件是否启用，不在这里拦截凭证检查。
     * 凭证和支付方式配置的有效性在 payment_fields() 中显示提示，不在这里阻断显示。
     */
    public function is_available( $order = null ) {
        $enabled = $this->get_option( 'enabled', 'yes' );
        if ( 'yes' !== $enabled ) {
            return false;
        }

        if ( ! class_exists( 'DKPay_API' ) ) {
            return false;
        }

        if ( ! DKPay_API::is_configured() ) {
            return false;
        }

        return ! empty( DKPay_API::get_available_methods() );
    }

    public function get_option_key() {
        return 'woocommerce_dkpay_settings';
    }

    public function init_settings() {
        parent::init_settings();
        if ( empty( $this->settings['enabled'] ) ) {
            $this->settings['enabled'] = 'yes';
        }
        $this->enabled = 'yes' === $this->settings['enabled'] ? 'yes' : 'no';
        $this->description = (string) $this->get_option( 'description', '' );
    }

    // ---------------------------------------------------------------------
    // 结账页渲染
    // ---------------------------------------------------------------------

    /**
     * 结账页渲染 DKPay 支付方式选择列表。
     *
     * 输出说明：
     * - 不在 <ul class="wc_payment_methods"> 内再嵌套一层，避免双重列表
     * - 不提供自定义 Place Order 按钮，使用 WooCommerce 原生流程
     * - 选中支付方式后写入隐藏字段 _dkpay_selected_method
     * - process_payment() 读取该字段并创建 DKPay 订单后跳转
     */
    public function payment_fields() {
        if ( ! class_exists( 'DKPay_API' ) || ! DKPay_API::is_configured() ) {
            echo '<p class="woocommerce-info">'
                . esc_html__( 'DKPay is not configured. Please contact the site administrator.', 'dkpay-shop' )
                . '</p>';
            return;
        }

        $methods = DKPay_API::get_available_methods();
        if ( empty( $methods ) ) {
            echo '<p class="woocommerce-info">'
                . esc_html__( 'No DKPay payment methods are available. Please contact the site administrator.', 'dkpay-shop' )
                . '</p>';
            return;
        }

        $labels   = DKPay_API::get_method_labels();
        $desc     = $this->get_option( 'description', '' );
        $first_key = array_key_first( $methods );

        // 预加载所有方法的图标和描述，避免循环内重复查询数据库
        $icons  = [];
        $descs  = [];
        foreach ( array_keys( $methods ) as $key ) {
            $icons[ $key ] = DKPay_WC_Integration::get_method_icon_html( $key );
            $descs[ $key ] = DKPay_WC_Integration::get_method_desc( $key );
        }

        // 隐藏字段：供 process_payment() 读取所选支付方式
        echo '<input type="hidden" name="_dkpay_selected_method" id="_dkpay_selected_method" value="'
            . esc_attr( $first_key ) . '" />';
        ?>

<div class="dkpay-payment-section" style="margin:8px 0;">
    <?php if ( $desc ) : ?>
        <p style="margin:0 0 12px;font-size:13px;color:#666;"><?php echo wp_kses_post( $desc ); ?></p>
    <?php endif; ?>

    <div class="dkpay-method-list" id="dkpay-method-list">
        <?php foreach ( $methods as $key => $_url ) :
            $icon     = $icons[ $key ] ?? '';
            $label    = $labels[ $key ] ?? $key;
            $method_desc = $descs[ $key ] ?? '';
            $selected = ( $first_key === $key ) ? ' dkpay-selected' : '';
        ?>
        <div class="dkpay-method<?php echo esc_attr( $selected ); ?>" data-method="<?php echo esc_attr( $key ); ?>">
            <label style="display:flex;align-items:center;gap:12px;cursor:pointer;padding:8px 12px;border:1px solid #ddd;border-radius:6px;margin-bottom:6px;transition:background .15s;">
                <input type="radio"
                       name="_dkpay_method_choice"
                       value="<?php echo esc_attr( $key ); ?>"
                       <?php echo $first_key === $key ? 'checked' : ''; ?>
                       style="margin:0;" />
                <?php if ( ! empty( $icon ) ) : ?>
                    <span style="font-size:18px;flex-shrink:0;line-height:1;color:#111;">
                        <?php
                            echo wp_kses(
                                (string) $icon,
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
                        ?>
                    </span>
                <?php endif; ?>
                <span>
                    <strong style="font-size:14px;"><?php echo esc_html( $label ); ?></strong>
                    <?php if ( $method_desc ) : ?>
                        <br><span style="font-size:12px;color:#888;"><?php echo esc_html( $method_desc ); ?></span>
                    <?php endif; ?>
                </span>
            </label>
        </div>
        <?php endforeach; ?>
    </div>

    <style>
        .dkpay-method.dkpay-selected { background: #f0f4ff; }
        .dkpay-method:hover { background: #fafafa; }
    </style>

    <script>
    (function(){
        var list   = document.getElementById('dkpay-method-list');
        var hidden = document.getElementById('_dkpay_selected_method');
        if (!list || !hidden) return;

        function syncFromChecked(){
            var checked = list.querySelector('input[name=_dkpay_method_choice]:checked');
            if (checked) {
                hidden.value = checked.value;
            }
        }

        list.addEventListener('click', function(e){
            var div = e.target.closest('.dkpay-method');
            if (!div) return;
            list.querySelectorAll('.dkpay-method').forEach(function(el){
                el.classList.remove('dkpay-selected');
            });
            div.classList.add('dkpay-selected');
            var radio = div.querySelector('input[type=radio]');
            if (radio) radio.checked = true;
            hidden.value = div.dataset.method;

            var gatewayRadio = document.getElementById('payment_method_<?php echo esc_js( $this->id ); ?>');
            if (gatewayRadio) gatewayRadio.checked = true;
        });

        list.addEventListener('error', function(e){
            var t = e.target;
            if (!t || !t.tagName) return;
            if (t.tagName.toUpperCase() !== 'IMG') return;
            if (!t.classList || !t.classList.contains('dkpay-method-icon-img')) return;
            t.style.display = 'none';
            var wrapper = t.closest('span');
            if (wrapper) wrapper.style.display = 'none';
        }, true);

        list.addEventListener('change', function(){
            syncFromChecked();
        });

        syncFromChecked();
    })();
    </script>
</div>
        <?php
    }

    // ---------------------------------------------------------------------
    // 支付处理
    // ---------------------------------------------------------------------

    /**
     * WooCommerce 标准流程：客户点击 Place Order → WooCommerce 创建 WC_Order → 调用此方法。
     *
     * 流程：
     * 1. 获取所选支付方式（_dkpay_selected_method）
     * 2. 构建 DKPay 订单数据（含商品、地址、回调 URL）
     * 3. 调用 DKPay_API::create_order()
     * 4. 保存 UUID 到订单 meta
     * 5. 设置订单为 on-hold
     * 6. 返回 redirect URL 跳转至 DKPay 收银台
     */
    public function process_payment( $order_id ) {
        $order = wc_get_order( $order_id );
        if ( ! $order ) {
            return [
                'result'   => 'failure',
                'redirect' => '',
                'message'  => __( 'Order not found.', 'dkpay-shop' ),
            ];
        }

        // 读取客户选择的支付方式：本次提交优先（传统结账页 / pay-for-order 重试页），
        // 订单 meta 仅作兜底（Blocks/Store API 流程会把 payment_data 注入 $_POST，同样能取到最新值）。
        $method_key = '';
        if ( isset( $_POST['_dkpay_selected_method'] ) ) {
            $method_key = wc_clean( wp_unslash( $_POST['_dkpay_selected_method'] ) );
        }
        if ( '' === $method_key ) {
            $method_key = (string) $order->get_meta( '_dkpay_selected_method', true );
        }

        // 校验支付方式是否有效且已启用
        $methods = DKPay_API::get_all_methods();
        if ( ! isset( $methods[ $method_key ] ) || ! DKPay_Methods::is_enabled( $method_key ) ) {
            $method_key = array_key_first( $methods );
        }
        if ( ! $method_key ) {
            wc_add_notice( esc_html__( 'No DKPay payment methods are available.', 'dkpay-shop' ), 'error' );
            return [ 'result' => 'failure', 'redirect' => '' ];
        }

        // 记录本次实际使用的支付方式，保证重试 / 回调流程读到的是最新选择。
        $order->update_meta_data( '_dkpay_selected_method', $method_key );

        // 构建 DKPay 下单数据（包含完整的 WC_Order 信息）
        $order_data = DKPay_API::build_order_data_from_wc( $order, $method_key );

        // 仅将支付所需字段发送给 DKPay，恢复凭证哈希和时间戳只保存在本地订单中。
        $restore_token_hash = (string) ( $order_data['restore_token_hash'] ?? '' );
        $restore_timestamp = (int) ( $order_data['restore_timestamp'] ?? 0 );
        unset( $order_data['restore_token_hash'], $order_data['restore_timestamp'] );

        // 调用 DKPay API 创建支付订单
        $result = DKPay_API::create_order( $method_key, $order_data );

        if ( 'success' !== $result['status'] || empty( $result['link'] ) ) {
            $msg = $result['message']
                ?? __( 'Payment request failed. Please try again.', 'dkpay-shop' );
            wc_add_notice( esc_html( $msg ), 'error' );
            DKPay_Log::log_error( "process_payment 失败: {$msg}", [
                'order_id' => $order_id,
                'method'   => $method_key,
            ] );
            return [
                'result'   => 'failure',
                'redirect' => '',
                'message'  => $msg,
            ];
        }

        // 保存 DKPay 关联信息到订单，并保存取消恢复所需的服务端凭证哈希。
        $uuid_local  = (string) ( $order_data['checkout_token'] ?? '' );
        $visitor_id = (string) ( $order_data['visitor_id'] ?? '' );
        if ( '' !== $restore_token_hash ) {
            $order->update_meta_data( '_dkpay_restore_token_hash', $restore_token_hash );
        }
        if ( $restore_timestamp > 0 ) {
            $order->update_meta_data( '_dkpay_restore_timestamp', $restore_timestamp );
        }
        if ( '' !== $visitor_id ) {
            $order->update_meta_data( '_dkpay_restore_visitor_id', $visitor_id );
        }
        if ( function_exists( 'WC' ) && WC()->session ) {
            $order->update_meta_data( '_dkpay_restore_session_id', (string) WC()->session->get_customer_id() );
        }
        $order->update_meta_data( '_dkpay_restore_used', '0' );
        $uuid_remote = (string) ( $result['uuid'] ?? '' );
        $method_url  = DKPay_API::get_method_url( $method_key );

        if ( '' !== $uuid_local ) {
            $order->update_meta_data( '_dkpay_uuid', $uuid_local );
        }
        if ( '' !== $uuid_remote && $uuid_remote !== $uuid_local ) {
            $order->update_meta_data( '_dkpay_remote_uuid', $uuid_remote );
        }
        $order->update_meta_data( '_dkpay_method',  $method_key );
        $order->update_meta_data( '_dkpay_gateway', $method_url );
        if ( ! empty( $order_data['order_number'] ) ) {
            $order->update_meta_data( '_dkpay_order_number', (string) $order_data['order_number'] );
        }
        if ( ! empty( $order_data['source_order_number'] ) ) {
            $order->update_meta_data( '_dkpay_source_order_number', (string) $order_data['source_order_number'] );
        }
        $order->save();

        // 标记为待处理，等待 DKPay 回调确认
        $order->update_status( 'on-hold', __( 'Awaiting DKPay payment confirmation', 'dkpay-shop' ) );

        DKPay_Log::log_request( $method_key, $method_url, $order_data, $uuid_local ?: $uuid_remote );

        return [
            'result'   => 'success',
            'redirect' => $result['link'],
        ];
    }
}
