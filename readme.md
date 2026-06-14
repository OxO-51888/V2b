# V2b 面板 aaPanel 搭建教程

这是自用 V2Board / xiao 分支整合版仓库，基于 `wyx2685/v2board`，并保留本地主题、客户端与认证服务相关定制。

本教程面向 aaPanel，也就是海外版宝塔。以后重新搭建时，直接拉取自己的 GitHub 仓库，不需要再从官方仓库迁移。

## 0. 版本选择说明

官方 V2Board 文档较早，示例环境常写 PHP 7.4。现在 aaPanel 在新系统上安装 PHP 7.4 时可能出现编译失败、扩展安装失败、CLI 版本不一致等问题。

这份仓库的 `composer.json` 支持：

```text
php ^7.3.0 || ^8.0
```

并且 `init.sh` 在检测到 PHP 8 时会自动补充：

```bash
php composer.phar require joanhey/adapterman
```

所以新服务器建议：

- 首选：PHP 8.1
- 次选：PHP 8.0
- 不建议新装：PHP 7.4，除非你的系统和 aaPanel 确认能稳定安装

关键点：

- 网站 PHP 版本要选 PHP 8.1
- SSH 里执行 `php -v` 也要是 PHP 8.1
- `redis`、`fileinfo`、`pcntl` 必须可用
- `proc_open`、`putenv`、`pcntl_alarm`、`pcntl_signal` 不能被禁用

## 1. 准备服务器

建议配置：

- 系统：Debian 12、Ubuntu 22.04、CentOS 9、OpenCloudOS 9
- 内存：最低 1 GB，建议 2 GB+
- 硬盘：20 GB+
- 域名：提前解析到服务器 IP

安全组放行：

- `80`
- `443`
- `8888`，aaPanel 默认面板端口，实际以你的面板端口为准

## 2. 安装 aaPanel

SSH 登录服务器，使用 `root` 执行。

### CentOS / OpenCloudOS / Alibaba Cloud Linux

```bash
yum install -y wget && wget -O install.sh http://www.aapanel.com/script/install_6.0_en.sh && bash install.sh
```

### Ubuntu / Debian

```bash
wget -O install.sh http://www.aapanel.com/script/install-ubuntu_6.0_en.sh && bash install.sh
```

安装完成后保存终端显示的：

- aaPanel 登录地址
- username
- password

登录 aaPanel 后先完成安全入口、账号绑定等基础设置。

## 3. 安装 LNMP 环境

aaPanel 首页选择 LNMP：

- Nginx：1.24+ 或面板推荐版本
- MySQL：5.7 或 8.0
- PHP：8.1
- Redis：安装

也可以在 App Store 手动安装：

```text
App Store -> Nginx
App Store -> MySQL
App Store -> PHP-8.1
App Store -> Redis
App Store -> Supervisor Manager
```

PHP 8.1 建议选择编译安装。如果 Fast install 后扩展列表异常，卸载后改用 Compile install。

## 4. 配置 PHP 8.1

进入：

```text
aaPanel -> App Store -> PHP-8.1 -> Setting
```

安装扩展：

```text
redis
fileinfo
opcache
```

检查禁用函数：

```text
aaPanel -> App Store -> PHP-8.1 -> Setting -> Disabled functions
```

删除以下函数：

```text
putenv
proc_open
pcntl_alarm
pcntl_signal
```

保存后重启 PHP 8.1。

## 5. 设置 PHP CLI 版本

这是最容易出错的一步。

aaPanel 里网站选择 PHP 8.1 后，SSH 里的 `php` 命令可能仍然是 PHP 7.4 或系统自带 PHP。这样执行 `sh init.sh` 时 Composer 会用错 PHP 版本。

先检查：

```bash
php -v
which php
```

如果不是 PHP 8.1，进入：

```text
aaPanel -> Website -> PHP CLI version
```

选择 PHP 8.1。

也可以手动修正：

```bash
rm -f /usr/bin/php
ln -s /www/server/php/81/bin/php /usr/bin/php
php -v
```

确认输出是 PHP 8.1 后再继续。

## 6. 检查 PHP 扩展

SSH 执行：

```bash
php -m | grep redis
php -m | grep fileinfo
php -m | grep pcntl
```

如果没有输出，说明 CLI 环境没有加载对应扩展。需要回到 aaPanel 安装扩展，或检查 CLI 是否指向 PHP 8.1。

## 7. 添加网站和数据库

进入：

```text
aaPanel -> Website -> Add site
```

填写：

- Domain：你的面板域名
- Database：MySQL
- PHP Version：PHP-81

示例目录：

```text
/www/wwwroot/你的域名
```

记下数据库信息：

- 数据库名
- 数据库用户名
- 数据库密码

安装面板时会用到。

## 8. 配置 GitHub 私有仓库权限

你的自用仓库是私有仓库：

```text
https://github.com/OxO-51888/V2b-
```

推荐用 SSH Key 拉取。

服务器执行：

```bash
ssh-keygen -t ed25519 -C "v2board-server"
cat ~/.ssh/id_ed25519.pub
```

复制公钥，添加到 GitHub：

```text
GitHub -> Settings -> SSH and GPG keys -> New SSH key
```

测试：

```bash
ssh -T git@github.com
```

看到 GitHub 认证成功提示后继续。

## 9. 清空站点默认文件

进入站点目录：

```bash
cd /www/wwwroot/你的域名
```

删除 aaPanel 默认文件：

```bash
chattr -i .user.ini 2>/dev/null
rm -rf .htaccess 404.html index.html .user.ini
```

## 10. 拉取面板代码

在站点目录执行：

```bash
git clone git@github.com:OxO-51888/V2b-.git ./
```

确认代码：

```bash
git branch
git remote -v
```

应在 `master` 分支，`origin` 指向你的自用仓库。

## 11. 执行安装

安装前再次确认：

```bash
php -v
php -m | grep redis
php -m | grep fileinfo
php -m | grep pcntl
```

确认无误后执行：

```bash
sh init.sh
```

`init.sh` 会自动：

- 下载 Composer
- 安装 PHP 依赖
- PHP 8 环境补充 `joanhey/adapterman`
- 执行 `php artisan v2board:install`
- aaPanel 环境下设置目录用户为 `www`

根据提示填写：

- 数据库地址：通常是 `127.0.0.1`
- 数据库名
- 数据库用户名
- 数据库密码
- 管理员邮箱
- 管理员密码
- 网站 URL

安装完成后确认：

```bash
ls -la .env
```

## 12. 配置 Redis

编辑 `.env`：

```bash
vim .env
```

确认：

```env
CACHE_DRIVER=redis
QUEUE_CONNECTION=redis
SESSION_DRIVER=redis
```

刷新缓存：

```bash
php artisan config:clear
php artisan config:cache
```

## 13. 设置网站运行目录

进入：

```text
aaPanel -> Website -> 你的站点 -> Setting -> Site directory
```

Running directory 选择：

```text
/public
```

保存。

## 14. 设置 URL Rewrite

进入：

```text
aaPanel -> Website -> 你的站点 -> Setting -> URL rewrite
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

## 15. 配置 SSL

进入：

```text
aaPanel -> Website -> 你的站点 -> SSL
```

申请 Let's Encrypt 证书，并开启 Force HTTPS。

## 16. 配置 Cron

进入：

```text
aaPanel -> Cron -> Add Task
```

填写：

- Type of Task：Shell Script
- Name of Task：v2board
- Period：N Minutes / 1 Minute
- Script content：

```bash
php /www/wwwroot/你的域名/artisan schedule:run
```

保存。

## 17. 启动队列服务

V2Board 必须启动队列。

进入：

```text
aaPanel -> App Store -> Supervisor Manager -> Add Daemon
```

填写：

- Name：V2Board
- Run User：`www`
- Run Dir：`/www/wwwroot/你的域名`
- Start Command：

```bash
php artisan horizon
```

- Processes：`1`

保存并启动。

如果不用 Supervisor，也可以用仓库里的 PM2 配置：

```bash
pm2 start pm2.yaml
pm2 save
```

## 18. 启动 Webman

xiao 分支包含 Webman 入口。

启动：

```bash
cd /www/wwwroot/你的域名
php webman.php start -d
```

重启：

```bash
php webman.php restart
```

停止：

```bash
php webman.php stop
```

如果 Webman 报错，优先检查 CLI PHP：

```bash
php -v
php -m | grep pcntl
php -m | grep redis
```

## 19. 设置权限

```bash
chown -R www:www /www/wwwroot/你的域名
chmod -R 755 /www/wwwroot/你的域名
chmod -R 755 /www/wwwroot/你的域名/storage /www/wwwroot/你的域名/bootstrap/cache
```

## 20. 后台收尾

打开面板域名，登录后台后检查：

- 系统设置
- 主题配置
- 邮件配置
- 支付配置
- 节点配置
- Cron 是否每分钟执行
- Supervisor 队列是否运行
- Webman 是否运行

如果主题异常，进入后台重新保存主题配置。

## 21. 后续更新

进入站点目录：

```bash
cd /www/wwwroot/你的域名
sh update.sh
```

更新后执行：

```bash
php artisan config:clear
php artisan config:cache
php artisan horizon:terminate
php webman.php restart
```

## 22. PHP 7.4 报错排查

如果你坚持使用 PHP 7.4，常见问题如下。

### 1. PHP 7.4 安装失败

新系统上 PHP 7.4 可能因为系统源、证书、编译依赖问题安装失败。

可先执行：

```bash
apt install -y ca-certificates
echo "ca_certificate=/etc/ssl/certs/ca-certificates.crt" >> /etc/wgetrc
```

然后重新安装 PHP 7.4。

如果仍失败，建议直接改用 PHP 8.1。

### 2. Composer 用错 PHP 版本

表现：

```text
Composer detected issues in your platform
```

检查：

```bash
php -v
which php
```

修正 CLI 到 aaPanel PHP 8.1：

```bash
rm -f /usr/bin/php
ln -s /www/server/php/81/bin/php /usr/bin/php
php -v
```

### 3. fileinfo 安装失败

小内存服务器编译 `fileinfo` 容易失败。

建议：

- 升级到 2 GB 内存
- 或在 aaPanel 安装 Linux Tools 后添加 1 GB+ Swap
- 再重新安装 `fileinfo`

### 4. redis 扩展装了但 CLI 没识别

通常是网站 PHP 和 CLI PHP 不一致。

检查：

```bash
php -v
php -m | grep redis
```

确认 `/usr/bin/php` 指向：

```bash
/www/server/php/81/bin/php
```

## 23. 常见问题

### 访问 500

检查：

- Running directory 是否为 `/public`
- PHP 扩展 `redis`、`fileinfo` 是否安装
- Redis 服务是否运行
- 禁用函数是否删除
- `storage/logs` 下的错误日志
- 目录权限是否为 `www`

### GitHub 私有仓库拉取失败

检查：

```bash
ssh -T git@github.com
git remote -v
```

如果 SSH 未授权，重新添加服务器公钥。

### 更新后页面没变化

检查：

- CDN 缓存
- 浏览器缓存
- aaPanel Nginx 是否 Reload
- 是否执行 `php artisan config:clear`
- 队列和 Webman 是否重启

## 上游来源

- 官方原版：`https://github.com/v2board/v2board`
- xiao 分支：`https://github.com/wyx2685/v2board`
- 自用仓库：`https://github.com/OxO-51888/V2b-`
