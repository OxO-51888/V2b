# V2b 面板宝塔搭建教程

这是自用 V2Board / xiao 分支整合版仓库，基于 `wyx2685/v2board`，并保留本地主题、客户端与认证服务相关定制。

本教程按宝塔面板手动搭建来写。以后重新搭建时，直接拉取自己的 GitHub 仓库，不需要再从官方仓库迁移。

## 1. 准备服务器

建议配置：

- 系统：Debian 12、Ubuntu 22.04、CentOS 9、OpenCloudOS 9
- 内存：最低 512 MB，建议 1 GB+
- Web：Nginx
- PHP：推荐 PHP 7.4
- 数据库：MySQL 5.7 / MariaDB
- Redis：必须安装

先解析域名到服务器 IP，并在服务器安全组放行：

- `80`
- `443`
- `8888`，宝塔面板端口，按你的面板设置为准

## 2. 安装宝塔面板

SSH 登录服务器，使用 `root` 执行。

### CentOS / OpenCloudOS / Alibaba Cloud Linux

```bash
yum install -y wget && wget -O install.sh http://download.bt.cn/install/install_6.0.sh && sh install.sh
```

### Ubuntu / Debian

```bash
wget -O install.sh http://download.bt.cn/install/install-ubuntu_6.0.sh && bash install.sh
```

安装完成后，终端会显示宝塔登录地址、账号和密码。登录宝塔后先绑定账号并进入面板。

## 3. 安装运行环境

宝塔首页选择 LNMP 环境：

- Nginx：1.24+ 或面板推荐版本
- MySQL：5.7
- PHP：7.4
- Redis：安装

如果已经进入软件商店，也可以手动安装：

```text
软件商店 -> Nginx
软件商店 -> MySQL
软件商店 -> PHP-7.4
软件商店 -> Redis
软件商店 -> Supervisor 管理器
```

## 4. 配置 PHP

进入：

```text
宝塔 -> 软件商店 -> PHP-7.4 -> 设置
```

安装扩展：

```text
redis
fileinfo
opcache
```

进入禁用函数，删除：

```text
putenv
proc_open
pcntl_alarm
pcntl_signal
```

保存后重启 PHP。

## 5. 添加网站和数据库

进入：

```text
宝塔 -> 网站 -> 添加站点
```

填写：

- 域名：你的面板域名
- 数据库：MySQL
- PHP 版本：PHP-7.4

示例站点目录：

```text
/www/wwwroot/你的域名
```

记下数据库名、数据库用户名、数据库密码，安装时会用到。

## 6. 配置 GitHub 私有仓库权限

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

复制输出的公钥，添加到 GitHub：

```text
GitHub -> Settings -> SSH and GPG keys -> New SSH key
```

测试：

```bash
ssh -T git@github.com
```

能看到 GitHub 认证成功提示即可。

## 7. 清空站点默认文件

SSH 进入站点目录：

```bash
cd /www/wwwroot/你的域名
```

删除宝塔默认文件：

```bash
chattr -i .user.ini 2>/dev/null
rm -rf .htaccess 404.html index.html .user.ini
```

## 8. 拉取面板代码

在站点目录执行：

```bash
git clone git@github.com:OxO-51888/V2b-.git ./
```

如果你不用 SSH，也可以用 HTTPS：

```bash
git clone https://github.com/OxO-51888/V2b-.git ./
```

确认分支：

```bash
git branch
```

应显示在 `master` 分支。

## 9. 执行安装

在站点目录执行：

```bash
sh init.sh
```

安装脚本会自动：

- 下载 Composer
- 安装 PHP 依赖
- PHP 8 环境补充 `joanhey/adapterman`
- 执行 `php artisan v2board:install`
- 宝塔环境下设置目录用户为 `www`

根据提示填写：

- 数据库地址：通常是 `127.0.0.1`
- 数据库名
- 数据库用户名
- 数据库密码
- 管理员邮箱
- 管理员密码
- 网站 URL

安装完成后确认 `.env` 已生成。

## 10. 配置 Redis

编辑 `.env`，建议改成：

```env
CACHE_DRIVER=redis
QUEUE_CONNECTION=redis
SESSION_DRIVER=redis
```

也可以用命令修改：

```bash
sed -i 's/^CACHE_DRIVER=.*/CACHE_DRIVER=redis/' .env
sed -i 's/^QUEUE_CONNECTION=.*/QUEUE_CONNECTION=redis/' .env
sed -i 's/^SESSION_DRIVER=.*/SESSION_DRIVER=redis/' .env
```

刷新缓存：

```bash
php artisan config:clear
php artisan config:cache
```

## 11. 设置网站运行目录

进入：

```text
宝塔 -> 网站 -> 你的站点 -> 设置 -> 网站目录
```

运行目录选择：

```text
/public
```

保存。

## 12. 设置伪静态

进入：

```text
宝塔 -> 网站 -> 你的站点 -> 设置 -> 伪静态
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

保存后重载 Nginx。

## 13. 配置 SSL

进入：

```text
宝塔 -> 网站 -> 你的站点 -> SSL
```

申请 Let's Encrypt 证书，开启强制 HTTPS。

## 14. 配置计划任务

进入：

```text
宝塔 -> 计划任务 -> 添加任务
```

填写：

- 任务类型：Shell 脚本
- 任务名称：`v2board`
- 执行周期：每 1 分钟
- 脚本内容：

```bash
php /www/wwwroot/你的域名/artisan schedule:run
```

保存。

## 15. 启动队列服务

V2Board 必须启动队列，否则订单、邮件、统计等功能会异常。

### 推荐：Supervisor 管理器

进入：

```text
宝塔 -> 软件商店 -> Supervisor 管理器 -> 添加守护进程
```

填写：

- 名称：`V2Board`
- 启动用户：`www`
- 运行目录：`/www/wwwroot/你的域名`
- 启动命令：

```bash
php artisan horizon
```

- 进程数量：`1`

保存并启动。

### 可选：PM2

仓库带有 `pm2.yaml`：

```bash
pm2 start pm2.yaml
pm2 save
```

## 16. 启动 Webman

xiao 分支带 Webman 入口。

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

如果启动失败，检查 PHP CLI 扩展：

```bash
php -m | grep redis
php -m | grep pcntl
```

## 17. 设置目录权限

执行：

```bash
chown -R www:www /www/wwwroot/你的域名
chmod -R 755 /www/wwwroot/你的域名
chmod -R 755 /www/wwwroot/你的域名/storage /www/wwwroot/你的域名/bootstrap/cache
```

## 18. 后台收尾

打开面板域名，登录后台后检查：

- 系统设置
- 主题配置
- 邮件配置
- 支付配置
- 节点配置
- 计划任务是否执行
- 队列是否运行
- Webman 是否运行

如果主题异常，进入后台重新保存主题配置。

## 19. 后续更新

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

## 20. 旧面板迁移到自用仓库

如果旧面板已经是 Git 部署：

```bash
cd /www/wwwroot/你的域名
git remote set-url origin git@github.com:OxO-51888/V2b-.git
git checkout master
git pull origin master
sh update.sh
```

然后进入后台重新保存主题配置。

## 21. 常见问题

### 访问 500

检查：

- 网站运行目录是否为 `/public`
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
- 宝塔 Nginx 是否重载
- 是否执行 `php artisan config:clear`
- 队列和 Webman 是否重启

## 上游来源

- 官方原版：`https://github.com/v2board/v2board`
- xiao 分支：`https://github.com/wyx2685/v2board`
- 自用仓库：`https://github.com/OxO-51888/V2b-`
