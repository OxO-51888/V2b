# XiaoV2B Windows Client

这是面板专属 Windows 客户端源码，不是订阅导入页，也不提供导入其他订阅的入口。

## 工作方式

1. 用户输入网站账号邮箱和密码，面板地址由客户端内置。
2. 客户端调用面板接口登录：`/api/v1/passport/auth/login`。
3. 客户端读取用户信息、订阅、节点、套餐、订单。
4. 客户端从面板订阅地址拉取 `flag=clashmeta` 配置。
5. 客户端启动本地 `mihomo.exe` 核心并加载该配置。

## 支持协议

客户端 UI 只识别和展示：

- `vmess`
- `vless`
- `trojan`
- `hysteria2`

其他协议不会作为当前客户端的匹配协议展示。

## 文件结构

```text
clients/windows
├── package.json
├── resources
│   └── mihomo.exe
└── src
    ├── main.js
    ├── preload.js
    ├── main
    │   ├── core-manager.js
    │   ├── panel-api.js
    │   ├── protocols.js
    │   ├── state-store.js
    │   └── system-proxy.js
    └── renderer
        ├── index.html
        └── assets
            ├── css
            │   └── app.css
            └── js
                └── app.js
```

## 核心文件

## 内置面板地址

客户登录页不显示面板地址。正式打包前修改：

```text
src/main/app-config.js
```

把 `PANEL_URL` 改成你的正式站点域名，例如：

```js
const PANEL_URL = 'https://your-domain.com';
```

把 Windows 版 mihomo 核心放到：

```text
clients/windows/resources/mihomo.exe
```

没有这个文件时，登录和读取面板仍可用，但不能连接。

## 开发运行

```bash
npm install
npm start
```
