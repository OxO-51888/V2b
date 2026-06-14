# XiaoV2B 授权服务器

这是授权版客户端专用的独立授权服务器。客户端只访问授权服务器，不直接访问任何数据库。

数据使用 MySQL 存储，方便以后迁移。授权服务器可以读取网站后台数据库，但客户端不能拿到数据库地址、账号、密码或 SQL。

## 当前能力

- 客户端启动校验：`POST /api/client/verify`
- 客户端心跳续期：`POST /api/client/heartbeat`
- 客户端退出会话：`POST /api/client/deactivate`
- 固定 6 个网站授权配置
- 管理页修改每个网站的名字、反代 IP/端口/域名、客户端名
- 记录用户邮箱、本地 IP、来源 IP
- 管理端封禁或解除封禁设备记录
- 服务端 Ed25519 私钥签发运行许可，客户端只内置公钥验证

## 启动

```bash
cp .env.example .env
nano .env
npm start
```

必须修改：

```env
AUTH_ADMIN_TOKEN=换成很长的随机字符串
AUTH_PUBLIC_BASE_URL=https://你的授权域名
AUTH_LICENSE_KEY_SECRET=换成很长的随机字符串
AUTH_DB_HOST=127.0.0.1
AUTH_DB_PORT=3306
AUTH_DB_NAME=xiaov2b_auth
AUTH_DB_USER=xiaov2b_auth
AUTH_DB_PASSWORD=数据库密码
```

默认监听：

```text
127.0.0.1:8787
```

默认数据库：

```text
MySQL / MariaDB
数据库名：xiaov2b_auth
数据表：auth_state, audit_log
```

## 网站配置

网站配置文件：`config/panels.json`

每个网站固定一个后台域名、接口签名和只读数据库配置：

```json
{
  "id": "panel_01",
  "panelUrl": "https://113.29.230.78:60518"
}
```

管理页负责保存：

- 网站名字
- 启用状态
- 反代 IP / 端口 / 域名
- 客户端名

所以上面 3 个字段不要再写进 `panels.json`。后台域名、接口签名、数据库配置固定写在授权服务器配置里，不下发给客户端。

## 客户端校验接口

```http
POST /api/client/verify
Content-Type: application/json

{
  "clientId": "windows-auth",
  "version": "0.1.0",
  "deviceId": "设备指纹",
  "deviceName": "电脑名称",
  "os": "Windows 11",
  "clientName": "XiaoV2B Auth",
  "requestedTarget": "113.29.230.78:60518"
}
```

只有客户端名和请求目标匹配已启用网站时，授权服务器才会返回运行许可和短期解密密钥。

如果请求的目标不是授权过的网站反代目标，授权服务器不会下发许可解密密钥，并返回中文错误。

## 管理接口

所有管理接口都要带：

```http
X-Admin-Token: 你的 AUTH_ADMIN_TOKEN
```

查看 6 个网站：

```bash
curl -H "X-Admin-Token: $AUTH_ADMIN_TOKEN" \
  http://127.0.0.1:8787/api/admin/panels
```

修改网站：

```bash
curl -X POST http://127.0.0.1:8787/api/admin/panels/update \
  -H "Content-Type: application/json" \
  -H "X-Admin-Token: $AUTH_ADMIN_TOKEN" \
  -d '{"panelId":"panel_01","name":"正式网站","gatewayTarget":"113.29.230.78:60518","clientName":"XiaoV2B Auth"}'
```

查看设备记录：

```bash
curl -H "X-Admin-Token: $AUTH_ADMIN_TOKEN" \
  http://127.0.0.1:8787/api/admin/devices
```

解除封禁：

```bash
curl -X POST http://127.0.0.1:8787/api/admin/devices/approve \
  -H "Content-Type: application/json" \
  -H "X-Admin-Token: $AUTH_ADMIN_TOKEN" \
  -d '{"deviceId":"设备指纹","note":"解除封禁"}'
```

封禁设备：

```bash
curl -X POST http://127.0.0.1:8787/api/admin/devices/block \
  -H "Content-Type: application/json" \
  -H "X-Admin-Token: $AUTH_ADMIN_TOKEN" \
  -d '{"deviceId":"设备指纹","reason":"禁止使用"}'
```

## 部署建议

推荐结构：

```text
客户端 -> 授权服务器 -> 网站后台
```

客户端只知道授权服务器地址和反代目标，不知道数据库信息。
