# V2b 面板

这是自用 V2Board / xiao 分支整合版仓库，基于 `wyx2685/v2board`，并保留本地主题、客户端与认证服务相关定制。

## 全新搭建

> 仓库目前是私有仓库。服务器需要先配置 GitHub 访问权限，例如使用 SSH Key、GitHub CLI 登录，或使用带权限的 HTTPS token。

### 1. 拉取代码

推荐使用 SSH：

```bash
git clone git@github.com:OxO-51888/V2b-.git /www/wwwroot/v2board
cd /www/wwwroot/v2board
```

如果使用 HTTPS：

```bash
git clone https://github.com/OxO-51888/V2b-.git /www/wwwroot/v2board
cd /www/wwwroot/v2board
```

### 2. 安装依赖

```bash
composer install --no-dev
```

如果服务器没有 Composer：

```bash
wget https://github.com/composer/composer/releases/latest/download/composer.phar -O composer.phar
php composer.phar install --no-dev
```

### 3. 初始化配置

```bash
cp .env.example .env
php artisan key:generate
```

然后编辑 `.env`，配置数据库、Redis、站点地址等信息。

建议缓存和队列使用 Redis：

```bash
sed -i 's/^CACHE_DRIVER=.*/CACHE_DRIVER=redis/' .env
sed -i 's/^QUEUE_CONNECTION=.*/QUEUE_CONNECTION=redis/' .env
sed -i 's/^SESSION_DRIVER=.*/SESSION_DRIVER=redis/' .env
```

### 4. 导入数据库

新库导入：

```bash
mysql -u 数据库用户名 -p 数据库名 < database/install.sql
```

如果已有旧库，再按需执行：

```bash
mysql -u 数据库用户名 -p 数据库名 < database/update.sql
```

### 5. 权限与缓存

```bash
chmod -R 755 storage bootstrap/cache
php artisan config:clear
php artisan config:cache
php artisan route:cache
```

### 6. 启动队列 / Webman

按你的服务器环境选择进程管理方式。常见命令：

```bash
php artisan horizon
php webman.php start -d
```

如果使用 PM2：

```bash
pm2 start pm2.yaml
```

### 7. Web 站点目录

网站运行目录指向：

```text
/www/wwwroot/v2board/public
```

伪静态使用 Laravel 规则。

## 从旧面板迁移

如果旧面板已经是 Git 部署，可以把远程切到本仓库：

```bash
git remote set-url origin git@github.com:OxO-51888/V2b-.git
git checkout master
git pull origin master
./update.sh
```

更新后刷新缓存并重启队列：

```bash
php artisan config:clear
php artisan config:cache
php artisan horizon:terminate
php webman.php restart
```

最后进入后台重新保存主题配置。

## 上游来源

- 官方原版：`https://github.com/v2board/v2board`
- xiao 分支：`https://github.com/wyx2685/v2board`
- 自用仓库：`https://github.com/OxO-51888/V2b-`

## 环境要求

- PHP 7.3+ / PHP 8.x
- Composer
- MySQL 5.7+ 或 MariaDB
- Redis
- Nginx / Apache
