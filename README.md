# DKPay Shop

DKPay 多支付方式网关的 WordPress 插件，提供 WooCommerce 结账、Checkout Block 以及独立支付页三种接入方式。

## 支持的支付方式

- PayPal
- 银行卡（Credit / Debit Card）
- Apple Pay
- Google Pay

支付方式可在后台自行启用、改名和设置图标。

## 环境要求

| 项目 | 要求 |
| --- | --- |
| WordPress | 5.0 及以上 |
| PHP | 7.4 及以上 |
| WooCommerce | 可选，使用 WooCommerce 结账时需要 |

## 安装

1. 将本仓库放到 `wp-content/plugins/dkpay-shop`（或直接克隆到该目录）。
2. 在 WordPress 后台「插件」页面启用 **DKPay Shop**。
3. 打开「设置 → DKPay 收款」，填写以下三项：
   - **支付网关基础地址**：默认值为占位地址 `https://pay.example.com`，必须替换为你自己的网关地址。
   - **密钥 Key（商户 ID）**：网关后台提供的密钥。
   - **安全令牌 securityToken**：网关后台配置的安全令牌。
4. 在「支付方式」管理页面确认需要使用的支付方式已启用。

## 使用方式

### WooCommerce 结账

启用 WooCommerce 后，DKPay 会作为支付网关出现在结账页，无需额外配置。

### 短代码

| 短代码 | 说明 |
| --- | --- |
| `[dkpay_checkout amount="99.00" payment_method="apple"]` | 输出结账表单，可选 `email`、`phone`、`country_code` 属性 |
| `[dkpay_payment_button]` | 输出跳转到独立支付页的按钮 |
| `[dkpay_checkout_inline]` | 输出内联支付表单 |
| `[dkpay_payment_form]` | 输出独立支付页表单 |

### 独立支付页

```
/pay-with-dkpay/?amount=99&order_id=123&payment_method=paypal
```

## 回调地址

插件注册了以下回调端点，由网关服务端或浏览器访问：

- `/dkpay/callback/client/{uuid}` —— 支付完成后的客户端跳转
- `/dkpay/callback/server/{uuid}` —— 网关服务端异步通知
- `/dkpay/callback/cancel/{uuid}` —— 取消支付后的客户端跳转

## 日志

默认写入 `wp-content/uploads/dkpay-logs/`，按日期分文件。

可在 `wp-config.php` 中关闭：

```php
define( 'DKPAY_DEBUG_LOG', false );
```

## 安全说明

- 密钥与安全令牌保存在 WordPress options 中，不写入代码仓库。
- 服务端回调会校验 `securityToken`，取消回调使用 HMAC 签名。
- 建议全站启用 HTTPS。
- 提交代码前请确认没有携带 `.env`、数据库导出、日志或站点备份。

## 许可证

[GPL-2.0-or-later](LICENSE)

## 免责声明

本插件为第三方集成工具，与 PayPal、Apple、Google 无隶属关系。相关名称与图标归各自商标所有者所有。
