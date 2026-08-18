# V2b 面板 aaPanel 搭建教程

这是自用 V2Board / xiao 分支整合版仓库。以后重新搭建时，直接拉取自己的 GitHub 仓库，不用再从官方仓库迁移。

本教程按照 V2Board 的 aaPanel 手动部署流程整理，使用当前正式服对应的环境：

- aaPanel：纯净版
- Nginx：面板推荐版本
- MySQL：5.7
- PHP：8.1，选择 Fast install
- Redis：必须安装
- Supervisor Manager：必须安装，用来守护队列
- Git：必须安装，用来拉取和更新仓库

不要使用 PHP 7.4 新装。PHP 7.4 版本较低，部分组件和依赖已经不再支持；本仓库使用 PHP 8.1。

## 1. 安装环境

登录 aaPanel，安装 LNMP：

```text
Nginx
MySQL 5.7
PHP 8.1
Redis
Supervisor Manager
```

注意：

- PHP 8.1 选择 `Fast install`
- 不选 `Compile install`
- Supervisor Manager 是 App Store 里的独立插件，需要单独安装

SSH 安装并检查 Git：

```bash
apt update && apt install -y git
git --version
```

## 2. 配置 PHP 8.1

进入：

```text
App Store -> PHP-8.1 -> Setting
```

安装扩展：

```text
redis
fileinfo
```

进入：

```text
PHP-8.1 -> Setting -> Disabled functions
```

删除：

```text
putenv
proc_open
pcntl_alarm
pcntl_signal
```

保存后重启 PHP 8.1。

SSH 检查：

```bash
php -v
php -m | grep -E 'redis|fileinfo|pcntl'
```

如果 SSH 里的 `php -v` 不是 PHP 8.1，在 aaPanel 里设置：

```text
Website -> PHP CLI version -> PHP-8.1
```

## 3. 添加站点和数据库

进入：

```text
Website -> Add site
```

填写：

- Domain：你的面板域名
- Database：MySQL
- PHP Version：PHP-81

记下数据库名、数据库用户名、数据库密码，安装 V2Board 时要用。

## 4. 一条命令拉取并安装

先进入 aaPanel 创建的站点目录：

```bash
cd /www/wwwroot/你的域名
```

确认当前目录是新建的空站点后，删除 aaPanel 默认文件：

```bash
chattr -i .user.ini 2>/dev/null || true
rm -f .htaccess 404.html index.html .user.ini
```

执行下面这一条命令。它会拉取当前正式服代码并立即启动 V2Board 安装向导：

```bash
git clone --branch codex/push-subscription-fixes https://github.com/OxO-51888/V2b.git . && chmod +x init.sh update.sh && ./init.sh
```

安装向导按提示填写：

- 数据库地址：`localhost`
- 数据库端口：`3306`
- 数据库名
- 数据库用户名
- 数据库密码
- 管理员邮箱
- 管理员密码
- 网站 URL：`https://你的域名`

安装完成后确认 `.env` 存在：

```bash
ls -la .env
```

如果安装中断，进入站点目录后重新执行：

```bash
./init.sh
```

## 5. 设置运行目录和伪静态

进入：

```text
Website -> 你的站点 -> Site directory
```

Running directory 选择：

```text
/public
```

进入：

```text
Website -> 你的站点 -> URL rewrite
```

填入：

```nginx
location /downloads {
}

location / {
    try_files $uri $uri/ /index.php$is_args$query_string;
}

location ~ .*\.(js|css)?$
{
    expires      1h;
    error_log off;
    access_log /dev/null;
}
```

保存后 Reload Nginx。

## 6. 配置 SSL

进入：

```text
Website -> 你的站点 -> SSL
```

申请 Let's Encrypt 证书。证书成功后开启 Force HTTPS。

如果域名走 Cloudflare，建议先临时关闭代理，证书签发成功后再打开代理。

## 7. 配置计划任务

进入左侧菜单：

```text
计划任务
```

在“添加任务”中填写：

- 任务类型：`Shell脚本`
- 任务名称：`V2Board`
- 执行周期：`N 分钟`
- 分钟：`1`
- 执行用户：`root`
- 脚本内容：

```bash
php /www/wwwroot/你的域名/artisan schedule:run
```

点击“添加任务”。添加后在任务列表中确认任务状态为“运行中”，并且每 1 分钟执行一次。

## 8. 启动队列

进入：

```text
App Store -> Supervisor Manager -> Add Daemon
```

填写：

- Name：V2Board
- Run User：`www`
- Run Dir：`/www/wwwroot/你的域名`
- Start Command：`php artisan horizon`
- Processes：`1`

保存并启动。V2Board 必须启动队列，否则订单、邮件、统计等功能会异常。

## 9. 恢复旧数据库

如果是重新搭建后恢复旧数据，在 aaPanel 数据库里导入旧 SQL 备份即可。

导入后如果后台密码不是新装时的密码，说明管理员账号已经被旧数据库覆盖，请使用旧数据库里的管理员账号登录。

## 10. 常见问题

### 访问 500

检查：

- Running directory 是否是 `/public`
- URL rewrite 是否填写
- Redis 是否安装并运行
- PHP 扩展 `redis`、`fileinfo`、`pcntl` 是否存在
- 禁用函数是否删除
- `storage/logs` 下的错误日志

### 队列没运行

检查 Supervisor Manager 里 `V2Board` 是否为 Running。

## 参考

- V2Board 官方 aaPanel 教程：`https://v2board.com/deploy/aapanel`
- aaPanel 官方下载页：`https://www.aapanel.com/new/download.html`
- 自用仓库：`https://github.com/OxO-51888/V2b`
