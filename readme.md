# V2b 面板 aaPanel 搭建教程

这是自用 V2Board / xiao 分支整合版仓库。以后重新搭建时，直接拉取自己的 GitHub 仓库，不用再从官方仓库迁移。

本教程跟着 V2Board 官方 aaPanel 手动部署流程写，只把版本改成当前更稳的组合：

- aaPanel：纯净版
- Nginx：面板推荐版本
- MySQL：5.7
- PHP：8.1，选择 Fast install
- Redis：必须安装
- Supervisor：必须安装，用来守护队列

不要用 PHP 7.4 新装。官方老教程写的是 PHP 7.4 / MySQL 5.6，但现在 PHP 7.4 版本太低，部分组件和依赖已经不支持或不稳定支持。本仓库使用 PHP 8.1。

## 1. 安装纯净 aaPanel

SSH 登录服务器后执行：

```bash
URL=https://www.aapanel.com/script/install_panel_en.sh && if [ -f /usr/bin/curl ]; then curl -ksSO "$URL"; else wget --no-check-certificate -O install_panel_en.sh "$URL"; fi; bash install_panel_en.sh
```

安装时提示是否安装到 `/www`，输入：

```text
y
```

安装完成后保存面板地址、账号、密码和面板端口。

不要使用 `aaClaw.sh`，它会额外安装 OpenClaw(Docker)，V2Board 不需要。

## 2. 安装环境

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
- Supervisor Manager 是 App Store 里的独立插件，要单独点 `Install`

## 3. 配置 PHP 8.1

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

## 4. 添加站点和数据库

进入：

```text
Website -> Add site
```

填写：

- Domain：你的面板域名
- Database：MySQL
- PHP Version：PHP-81

记下数据库名、数据库用户名、数据库密码，安装 V2Board 时要用。

## 5. 拉取代码

进入站点目录：

```bash
cd /www/wwwroot/你的域名
```

删除 aaPanel 默认文件：

```bash
chattr -i .user.ini 2>/dev/null || true
rm -rf .htaccess 404.html index.html .user.ini
```

如果仓库是私有仓库，先在服务器生成 SSH key：

```bash
ssh-keygen -t ed25519 -f /root/.ssh/v2board_deploy_ed25519 -C "v2board deploy" -N ""
cat /root/.ssh/v2board_deploy_ed25519.pub
```

把公钥添加到 GitHub：

```text
Repository -> Settings -> Deploy keys -> Add deploy key
```

服务器配置这个 key：

```bash
cat >> /root/.ssh/config <<'EOF'
Host github.com
    HostName github.com
    User git
    IdentityFile /root/.ssh/v2board_deploy_ed25519
    IdentitiesOnly yes
EOF
chmod 600 /root/.ssh/config
```

拉取自己的仓库：

```bash
git clone git@github.com:OxO-51888/V2b-.git ./
```

## 6. 安装 V2Board

在站点目录执行：

```bash
sh init.sh
```

按提示填写：

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

## 7. 设置运行目录和伪静态

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

## 8. 配置 SSL

进入：

```text
Website -> 你的站点 -> SSL
```

申请 Let's Encrypt 证书。证书成功后开启 Force HTTPS。

如果域名走 Cloudflare，建议先临时关闭代理，证书签发成功后再打开代理。

## 9. 配置 Cron

进入：

```text
Cron -> Add Task
```

填写：

- Type of Task：Shell Script
- Name of Task：v2board
- Period：N Minutes / 1 Minute
- Script content：

```bash
php /www/wwwroot/你的域名/artisan schedule:run
```

保存后确保任务每分钟执行。

## 10. 启动队列

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

## 11. 恢复旧数据库

如果是重新搭建后恢复旧数据，在 aaPanel 数据库里导入旧 SQL 备份即可。

导入后如果后台密码不是新装时的密码，说明管理员账号已经被旧数据库覆盖，使用旧数据库里的管理员账号登录。

## 12. 更新面板

以后更新代码：

```bash
cd /www/wwwroot/你的域名
sh update.sh
php artisan config:clear
php artisan config:cache
php artisan horizon:terminate
```

然后在 Supervisor Manager 里重启 `V2Board` 队列。

## 13. 常见问题

### 访问 500

检查：

- Running directory 是否是 `/public`
- URL rewrite 是否填写
- Redis 是否安装并运行
- PHP 扩展 `redis`、`fileinfo`、`pcntl` 是否存在
- 禁用函数是否删除
- `storage/logs` 下的错误日志

### GitHub 拉取失败

检查服务器 SSH key 是否已经添加到 GitHub Deploy keys：

```bash
ssh -T git@github.com
```

### 队列没运行

检查 Supervisor Manager 里 `V2Board` 是否为 Running。

## 参考

- V2Board 官方 aaPanel 教程：`https://v2board.com/deploy/aapanel`
- aaPanel 官方下载页：`https://www.aapanel.com/new/download.html`
- 自用仓库：`https://github.com/OxO-51888/V2b-`
