# XiaoV2B Windows 授权版客户端

这是授权网关版 Windows 客户端。客户端只内置授权服务器地址，不内置真实面板地址、数据库信息、面板签名密钥或 SQL。

## 工作方式

1. 客户端启动后先请求授权服务器。
2. 授权失败时弹窗提示并关闭客户端。
3. 授权成功后，客户端只访问授权服务器网关。
4. 授权服务器网关再转发到对应后台面板。
5. 登录、套餐、订单、节点、支付接口都通过授权网关访问。
6. 本地 mihomo 内核只在授权通过后允许启动。

## 客户端配置

客户端只需要配置授权服务器地址：

```text
src/main/app-config.js
```

```js
const AUTH_SERVER_URL = process.env.XIAOV2B_AUTH_SERVER_URL || 'https://5188777.xyz';
```

真实面板地址、目标端口、面板签名密钥、数据库只配置在授权服务器。

## 授权服务器配置

授权服务器通过 `/app/config/panels.json` 管理 6 个后台面板。配置里可以写中文注释，启用面板时需要填写：

```jsonc
{
  "id": "panel_01",
  "name": "主面板",
  "status": "active",
  "panelUrl": "https://真实面板域名",
  "gatewayTarget": "真实面板域名:443",
  "allowedClientNames": ["XiaoV2B Auth"],
  "apiSign": {
    "clientId": "windows",
    "signSecret": "面板签名密钥"
  },
  "database": {
    "host": "127.0.0.1",
    "port": 3306,
    "user": "只读账号",
    "password": "只读密码",
    "database": "面板数据库名"
  }
}
```

客户端不能直接访问数据库，也不能包含数据库连接信息。

## 检查

```bash
npm run check
```
