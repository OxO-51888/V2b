# V2b 面板 aaPanel 搭建教程

这是自用 V2Board / xiao 分支整合版仓库，基于 `wyx2685/v2board`，并保留本地主题、客户端与认证服务相关定制。

本教程面向 aaPanel，也就是海外版宝塔。以后重新搭建时，直接拉取自己的 GitHub 仓库，不需要再从官方仓库迁移。

本教程按 2026-06-15 实测整理，采用纯净 aaPanel 路线：Ubuntu 24.04、aaPanel、Nginx 1.28、MySQL 5.7、PHP 8.1 Fast install、Redis、Cloudflare 域名代理。

## 0. 先看结论

推荐版本：

- 系统：Ubuntu 22.04 / Ubuntu 24.04 / Debian 12
- Web：Nginx 1.24+
- 数据库：MySQL 5.7
- PHP：PHP 8.1
- PHP 安装方式：Fast install，不选 Compile install
- Redis：必须安装
- 队列：必须运行 Horizon
- 定时任务：必须每分钟运行 `artisan schedule:run`
- aaPanel：只装纯净版，不装 OpenClaw / Docker 组合版

不要新装 PHP 7.4。官方老教程里常见 PHP 7.4，但现在新系统上容易遇到编译失败、扩展失败、Composer 版本冲突。

这个仓库的 `composer.json` 支持：

```text
php ^7.3.0 || ^8.0
```

PHP 8 环境需要 `joanhey/adapterman`。仓库已经固化这个依赖，避免每次 `init.sh` 自动修改 `composer.json`。

## 1. 准备服务器和域名

安全组或防火墙放行：

- `22`：SSH
- `80`：HTTP 和 Let's Encrypt 验证
- `443`：HTTPS
- `888`：phpMyAdmin，如果需要
- `20`、`21`、`39000:40000`：FTP，如果需要
- aaPanel 实际面板端口：安装完成后用 `bt 14` 查看

如果服务器启用了 UFW，可参考：

```bash
ufw allow 22/tcp
ufw allow 80/tcp
ufw allow 443/tcp
ufw allow 888/tcp
ufw allow 20/tcp
ufw allow 21/tcp
ufw allow 39000:40000/tcp
ufw allow 面板端口/tcp
ufw enable
```

Cloudflare 代理可以开启。申请 Let's Encrypt 前要确认：

```bash
curl -I http://你的域名
```

能通过 Cloudflare 回源访问到服务器。

## 2. 安装 aaPanel

SSH 登录服务器，使用 `root` 执行。

使用纯 aaPanel 安装脚本：

```bash
URL=https://www.aapanel.com/script/install_panel_en.sh && if [ -f /usr/bin/curl ]; then curl -ksSO "$URL"; else wget --no-check-certificate -O install_panel_en.sh "$URL"; fi; bash install_panel_en.sh
```

不要使用 `aaClaw.sh`。它会安装 aaPanel + OpenClaw，面板里会多出 `OpenClaw(Docker)`。V2Board 手动搭建不需要它。

脚本提示：

```text
Do you want to install aaPanel to the /www directory now?(y/n):
```

输入：

```text
y
```

安装完成后保存：

- aaPanel 登录地址
- 安全入口
- username
- password
- 面板端口

忘记信息时可执行：

```bash
bt 14
```

## 3. 安装 LNMP 环境

登录 aaPanel 后，在推荐套件或 App Store 安装：

```text
Nginx
MySQL 5.7
PHP 8.1
Redis
Supervisor Manager
```

`Supervisor Manager` 是 aaPanel App Store 里的独立插件。如果它显示未安装，先点 `Install` 安装，安装完成后再配置 Horizon 队列。不要跳过这一步。

关键选择：

```text
PHP 8.1 -> Fast install
```

不要选：

```text
PHP 8.1 -> Compile install
```

MySQL 按你的要求使用 `5.7`。如果 aaPanel 界面有 Fast install，也选 Fast install。Ubuntu 24 上 MySQL 5.7 有时仍会由 aaPanel 拉源码构建，耗时较长，这属于 aaPanel 包源支持情况，耐心等完成。

如果必须用命令行安装 PHP 8.1，Ubuntu / Debian 的 Fast install 参数是 `4`：

```bash
bash /www/server/panel/install/install_soft.sh 4 install php 8.1
```

不要把下面两个当成 PHP 快装：

```bash
bash /www/server/panel/install/install_soft.sh 0 install php 8.1
bash /www/server/panel/install/install_soft.sh 1 install php 8.1
```

在 Ubuntu 24 实测中，`0` 和 `1` 都可能走源码编译。

## 4. 配置 PHP 8.1

进入：

```text
aaPanel -> App Store -> PHP-8.1 -> Setting
```

安装扩展：

```text
fileinfo
redis
opcache
```

命令行快装扩展可参考：

```bash
bash /www/server/panel/install/install_soft.sh 4 install opcache 81
bash /www/server/panel/install/install_soft.sh 4 install fileinfo 81
bash /www/server/panel/install/install_soft.sh 4 install redis 81
```

注意：PHP 主程序用 Fast install。部分扩展安装脚本仍会用 `phpize` 编译 `.so`，这是扩展构建，不等于 PHP 主程序 Compile install。

删除禁用函数：

```text
aaPanel -> App Store -> PHP-8.1 -> Setting -> Disabled functions
```

至少删除：

```text
putenv
proc_open
pcntl_alarm
pcntl_signal
```

建议同时删除所有 `pcntl_*`，否则 Horizon / Workerman 类服务容易异常。

检查：

```bash
php -v
php -m | grep -E 'fileinfo|redis|pcntl|pdo_mysql|openssl|mbstring'
php -r 'echo ini_get("disable_functions"), PHP_EOL;'
redis-cli ping
```

期望：

```text
PHP 8.1.x
PONG
```

## 5. 设置 PHP CLI 版本

这是最容易出错的一步。网站使用 PHP 8.1，不代表 SSH 里的 `php` 也是 PHP 8.1。

检查：

```bash
which php
php -v
```

如果不是 aaPanel PHP 8.1，修正：

```bash
rm -f /usr/bin/php
ln -s /www/server/php/81/bin/php /usr/bin/php
php -v
```

## 6. 创建网站目录和数据库

aaPanel 手动创建：

```text
Website -> Add site
```

填写：

- Domain：你的域名
- PHP Version：PHP-81
- Database：MySQL
- Root：`/www/wwwroot/你的域名`

也可以命令行创建数据库。先准备一个 root 私密文件保存安装信息，不要提交到 GitHub：

```bash
install -m 600 /dev/null /root/v2board-install-secrets.txt
```

示例：

```bash
V2BOARD_DB_DATABASE=v2board
V2BOARD_DB_USERNAME=v2board
V2BOARD_DB_PASSWORD='换成强密码'

mysql -uroot -p -e "CREATE DATABASE ${V2BOARD_DB_DATABASE} CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
mysql -uroot -p -e "CREATE USER '${V2BOARD_DB_USERNAME}'@'localhost' IDENTIFIED BY '${V2BOARD_DB_PASSWORD}';"
mysql -uroot -p -e "GRANT ALL PRIVILEGES ON ${V2BOARD_DB_DATABASE}.* TO '${V2BOARD_DB_USERNAME}'@'localhost'; FLUSH PRIVILEGES;"
```

把数据库信息写入：

```bash
cat >> /root/v2board-install-secrets.txt <<EOF
V2BOARD_DB_DATABASE=v2board
V2BOARD_DB_USERNAME=v2board
V2BOARD_DB_PASSWORD=换成强密码
EOF
chmod 600 /root/v2board-install-secrets.txt
```

MySQL 5.7 如果 root 密码不可用，需要重置时，`init-file` 不要放 `/root`，MySQL 可能没有权限读取。放 `/tmp`：

```bash
cat >/tmp/mysql-reset.sql <<EOF
ALTER USER 'root'@'localhost' IDENTIFIED BY '新的root密码';
FLUSH PRIVILEGES;
EOF
chmod 644 /tmp/mysql-reset.sql
```

然后用 aaPanel 或 MySQL 启动参数加载这个文件。重置成功后立即删除：

```bash
rm -f /tmp/mysql-reset.sql
```

## 7. 配置 GitHub 私有仓库权限

自用仓库：

```text
git@github.com:OxO-51888/V2b-.git
```

服务器生成只读 deploy key：

```bash
ssh-keygen -t ed25519 -f /root/.ssh/v2board_deploy_ed25519 -C "v2board deploy $(hostname)" -N ""
cat /root/.ssh/v2board_deploy_ed25519.pub
```

到 GitHub 添加：

```text
Repository -> Settings -> Deploy keys -> Add deploy key
```

只勾选读权限即可，不要勾选写权限。

服务器配置 SSH：

```bash
cat >> /root/.ssh/config <<'EOF'
Host github.com
    HostName github.com
    User git
    IdentityFile /root/.ssh/v2board_deploy_ed25519
    IdentitiesOnly yes
EOF
chmod 600 /root/.ssh/config
ssh -T git@github.com
```

看到 GitHub 认证成功提示后继续。

## 8. 拉取面板代码

进入站点目录：

```bash
DOMAIN=你的域名
mkdir -p /www/wwwroot/$DOMAIN
cd /www/wwwroot/$DOMAIN
chattr -i .user.ini 2>/dev/null || true
rm -rf .htaccess 404.html index.html .user.ini
git clone git@github.com:OxO-51888/V2b-.git .
```

如果后续把目录属主改成 `www`，root 执行 Git 可能遇到 dubious ownership。提前加：

```bash
git config --global --add safe.directory /www/wwwroot/$DOMAIN
```

确认：

```bash
git remote -v
git branch --show-current
```

## 9. 执行 V2Board 安装

再次确认 PHP：

```bash
cd /www/wwwroot/你的域名
php -v
php -m | grep -E 'fileinfo|redis|pcntl|pdo_mysql'
```

执行官方安装脚本：

```bash
COMPOSER_ALLOW_SUPERUSER=1 sh init.sh
```

根据提示填写：

- 数据库地址：`localhost`
- 数据库端口：`3306`
- 数据库名
- 数据库用户名
- 数据库密码
- 管理员邮箱
- 管理员密码
- 网站 URL：`https://你的域名`

安装后确认：

```bash
ls -la .env
php artisan config:clear
php artisan config:cache
```

如果忘记管理员密码：

```bash
php artisan reset:password 管理员邮箱
```

建议把管理员邮箱和密码也写入服务器私密文件：

```bash
cat >> /root/v2board-install-secrets.txt <<EOF
V2BOARD_ADMIN_EMAIL=管理员邮箱
V2BOARD_ADMIN_PASSWORD=管理员密码
EOF
chmod 600 /root/v2board-install-secrets.txt
```

## 10. 配置 `.env`

重点检查：

```env
APP_URL=https://你的域名
APP_DEBUG=false
DB_HOST=localhost
DB_PORT=3306
CACHE_DRIVER=redis
QUEUE_CONNECTION=redis
SESSION_DRIVER=redis
REDIS_HOST=127.0.0.1
REDIS_PORT=6379
```

修改后刷新：

```bash
php artisan config:clear
php artisan config:cache
```

## 11. 配置 Nginx

aaPanel 界面设置：

```text
Website -> 你的站点 -> Site directory -> Running directory -> /public
Website -> 你的站点 -> URL rewrite
```

URL rewrite：

```nginx
location /downloads {
}

location / {
    try_files $uri $uri/ /index.php$is_args$query_string;
}
```

命令行完整配置示例：

下面这份完整配置要等第 12 步证书申请成功后再覆盖使用。申请证书前先保留 aaPanel 生成的 80 站点配置，保证 `/.well-known/acme-challenge/` 能正常访问。

```bash
DOMAIN=你的域名
cat > /www/server/panel/vhost/nginx/$DOMAIN.conf <<EOF
server
{
    listen 80;
    server_name $DOMAIN;
    root /www/wwwroot/$DOMAIN/public;

    location ^~ /.well-known/acme-challenge/ {
        root /www/wwwroot/$DOMAIN/public;
        try_files \$uri =404;
    }

    location / {
        return 301 https://\$host\$request_uri;
    }
}

server
{
    listen 443 ssl;
    http2 on;
    server_name $DOMAIN;
    index index.php index.html index.htm default.php default.htm default.html;
    root /www/wwwroot/$DOMAIN/public;

    ssl_certificate /etc/letsencrypt/live/$DOMAIN/fullchain.pem;
    ssl_certificate_key /etc/letsencrypt/live/$DOMAIN/privkey.pem;
    ssl_protocols TLSv1.2 TLSv1.3;
    ssl_ciphers HIGH:!aNULL:!MD5;
    ssl_prefer_server_ciphers on;
    ssl_session_cache shared:SSL:10m;
    ssl_session_timeout 10m;

    include /www/server/nginx/conf/enable-php-81.conf;

    location /downloads {
    }

    location / {
        try_files \$uri \$uri/ /index.php\$is_args\$query_string;
    }

    location ~ ^/(\\.user.ini|\\.htaccess|\\.git|\\.env|\\.svn|\\.project|LICENSE|README.md|readme.md) {
        return 404;
    }

    location ~ .*\\.(gif|jpg|jpeg|png|bmp|swf)$ {
        expires 30d;
        error_log /dev/null;
        access_log /dev/null;
    }

    location ~ .*\\.(js|css)?$ {
        expires 1h;
        error_log /dev/null;
        access_log /dev/null;
    }

    access_log /www/wwwlogs/$DOMAIN.log;
    error_log /www/wwwlogs/$DOMAIN.error.log;
}
EOF

nginx -t
systemctl reload nginx || /etc/init.d/nginx reload || service nginx reload
```

注意：Nginx 1.28 开始不建议写 `listen 443 ssl http2;`，用 `listen 443 ssl;` 加 `http2 on;`。

## 12. 配置 SSL

如果 Cloudflare HTTP 正常，但 HTTPS 返回 `521`，通常是源站没有监听 `443`。

安装 certbot：

```bash
apt-get update -y
apt-get install -y certbot
```

先测试 challenge 路径：

```bash
DOMAIN=你的域名
mkdir -p /www/wwwroot/$DOMAIN/public/.well-known/acme-challenge
echo ok > /www/wwwroot/$DOMAIN/public/.well-known/acme-challenge/test.txt
curl http://$DOMAIN/.well-known/acme-challenge/test.txt
rm -f /www/wwwroot/$DOMAIN/public/.well-known/acme-challenge/test.txt
```

申请证书：

```bash
certbot certonly --webroot \
  -w /www/wwwroot/$DOMAIN/public \
  -d $DOMAIN \
  -m admin@$DOMAIN \
  --agree-tos --no-eff-email --non-interactive
```

证书申请成功后，回到第 11 步套完整 Nginx 配置并 reload。

自动续期会由 `certbot.timer` 处理。补一个续期后 reload Nginx 的 hook：

```bash
mkdir -p /etc/letsencrypt/renewal-hooks/deploy
cat > /etc/letsencrypt/renewal-hooks/deploy/reload-aapanel-nginx.sh <<'EOF'
#!/bin/sh
/usr/bin/nginx -t >/dev/null 2>&1 || exit 0
systemctl reload nginx >/dev/null 2>&1 || /etc/init.d/nginx reload >/dev/null 2>&1 || service nginx reload >/dev/null 2>&1 || true
EOF
chmod +x /etc/letsencrypt/renewal-hooks/deploy/reload-aapanel-nginx.sh
```

## 13. 配置权限

不要对整个项目无脑 `chmod -R 777`。

推荐：

```bash
DOMAIN=你的域名
cd /www/wwwroot/$DOMAIN
chown -R www:www /www/wwwroot/$DOMAIN
find storage bootstrap/cache -type d -exec chmod 775 {} \;
find storage bootstrap/cache -type f -exec chmod 664 {} \;
git config --global --add safe.directory /www/wwwroot/$DOMAIN
```

不要执行：

```bash
chmod -R 755 storage bootstrap/cache
```

这会把 `.gitignore` 等普通文件加上执行位，导致 `git status` 显示文件权限变更。

## 14. 配置 Cron

aaPanel 界面：

```text
Cron -> Add Task
```

填写：

- Type of Task：Shell Script
- Name of Task：v2board
- Period：N Minutes / 1 Minute
- Script content：

```bash
cd /www/wwwroot/你的域名 && /www/server/php/81/bin/php artisan schedule:run >> /dev/null 2>&1
```

命令行：

```bash
DOMAIN=你的域名
(crontab -l 2>/dev/null | grep -v 'artisan schedule:run'; echo "* * * * * cd /www/wwwroot/$DOMAIN && /www/server/php/81/bin/php artisan schedule:run >> /dev/null 2>&1") | crontab -
crontab -l | grep 'artisan schedule:run'
```

## 15. 配置 Horizon 队列

V2Board 必须启动队列。

aaPanel 官方方式：

```text
App Store -> Supervisor Manager -> Install
App Store -> Supervisor Manager -> Add Daemon
```

填写：

- Name：V2Board
- Run User：`www`
- Run Dir：`/www/wwwroot/你的域名`
- Start Command：`/www/server/php/81/bin/php artisan horizon`
- Processes：`1`

如果不用 Supervisor Manager，可用 systemd：

```bash
DOMAIN=你的域名
cat > /etc/systemd/system/v2board-horizon.service <<EOF
[Unit]
Description=V2Board Horizon Queue Worker
After=network.target redis.service mysqld.service mysql.service

[Service]
Type=simple
User=www
Group=www
WorkingDirectory=/www/wwwroot/$DOMAIN
ExecStart=/www/server/php/81/bin/php artisan horizon
Restart=always
RestartSec=5
KillSignal=SIGTERM
TimeoutStopSec=60

[Install]
WantedBy=multi-user.target
EOF

systemctl daemon-reload
systemctl enable --now v2board-horizon
systemctl is-active v2board-horizon
cd /www/wwwroot/$DOMAIN && sudo -u www /www/server/php/81/bin/php artisan horizon:status
```

## 16. Webman 说明

xiao 分支包含 `webman.php`，会监听：

```text
127.0.0.1:6600
```

按本教程的 aaPanel + Nginx + PHP-FPM 部署方式，主站不依赖 Webman。只有你明确要使用 Workerman / Adapterman 模式时再启动：

```bash
cd /www/wwwroot/你的域名
/www/server/php/81/bin/php webman.php start -d
```

停止：

```bash
/www/server/php/81/bin/php webman.php stop
```

## 17. 验证安装

源站测试：

```bash
DOMAIN=你的域名
curl -I -H "Host: $DOMAIN" http://127.0.0.1/
curl -k -I --resolve $DOMAIN:443:127.0.0.1 https://$DOMAIN/
```

公网测试：

```bash
curl -I http://$DOMAIN/
curl -I https://$DOMAIN/
```

期望：

```text
HTTP -> 301
HTTPS -> 200
```

检查服务：

```bash
redis-cli ping
systemctl is-active v2board-horizon
crontab -l | grep 'artisan schedule:run'
php artisan schedule:list
tail -n 80 storage/logs/laravel*.log
tail -n 80 /www/wwwlogs/你的域名.error.log
```

## 18. 后续更新

进入站点目录：

```bash
cd /www/wwwroot/你的域名
git status --short
sh update.sh
php artisan config:clear
php artisan config:cache
php artisan horizon:terminate
systemctl restart v2board-horizon 2>/dev/null || true
```

如果使用 Webman：

```bash
php webman.php restart
```

## 19. 常见问题

### PHP 8.1 仍然在编译

面板安装时确认选择 Fast install。命令行安装 PHP 8.1 用：

```bash
bash /www/server/panel/install/install_soft.sh 4 install php 8.1
```

### Composer 用错 PHP 版本

表现：

```text
Composer detected issues in your platform
```

处理：

```bash
rm -f /usr/bin/php
ln -s /www/server/php/81/bin/php /usr/bin/php
php -v
```

### 网站 500

检查：

- Nginx root 是否为 `/www/wwwroot/你的域名/public`
- URL rewrite 是否有 `try_files`
- `fileinfo`、`redis`、`pcntl` 是否加载
- Redis 是否 `PONG`
- `proc_open`、`putenv`、`pcntl_*` 是否仍被禁用
- `storage` 和 `bootstrap/cache` 权限
- `storage/logs/laravel*.log`

### Cloudflare HTTPS 521

说明 Cloudflare 连不上源站 443。

处理：

- 源站 Nginx 必须监听 `443`
- 源站证书路径必须正确
- 防火墙必须放行 `443`
- `curl -k -I --resolve 你的域名:443:127.0.0.1 https://你的域名/` 必须返回 `200`

### GitHub 私有仓库拉取失败

检查：

```bash
ssh -T git@github.com
git remote -v
```

如果 SSH 未授权，重新添加服务器 deploy key。

### root 执行 Git 报 dubious ownership

这是因为项目目录属主是 `www`。处理：

```bash
git config --global --add safe.directory /www/wwwroot/你的域名
```

## 上游来源

- 官方原版：`https://github.com/v2board/v2board`
- xiao 分支：`https://github.com/wyx2685/v2board`
- 自用仓库：`https://github.com/OxO-51888/V2b-`
- aaPanel 下载页：`https://www.aapanel.com/new/download.html`
- aaPanel 快速开始：`https://www.aapanel.com/docs/guide/quickstart.html`
- V2Board aaPanel 教程：`https://v2board.com/deploy/aapanel`
