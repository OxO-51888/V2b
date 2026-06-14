# XiaoV2B Client Core

这是对接 XiaoV2B/V2Board 后台的静态客户端核心文件。入口地址：

```text
/v2b-client/index.html
```

## 文件结构

```text
public/v2b-client/
  index.html
  assets/
    css/core.css
    data/client-catalog.json
    js/api.js
    js/app.js
    js/catalog.js
    js/format.js
    images/
```

## 真实接口

客户端默认调用当前站点的 `/api/v1`：

- `/passport/auth/login`
- `/passport/auth/register`
- `/passport/auth/token2Login`
- `/user/info`
- `/user/getSubscribe`
- `/user/comm/config`
- `/user/plan/fetch`
- `/user/order/save`
- `/user/order/fetch`
- `/user/order/detail`
- `/user/order/getPaymentMethod`
- `/user/order/checkout`
- `/user/order/cancel`
- `/user/server/fetch`
- `/user/update`
- `/user/resetSecurity`
- `/user/redeemgiftcard`

登录使用网站账号，即 `/passport/auth/login` 返回的 `auth_data`。客户端不创建独立账号体系。

没有模拟数据。登录后展示的数据全部来自后台接口；客户端推荐只匹配 `vmess`、`vless`、`trojan`、`hysteria2` 四类协议。

## 改 API 地址

如需连接其他后台，改 `assets/js/app.js` 中的 API 初始化：

```js
const api = new V2BoardApi('https://你的后台域名/api/v1');
```
