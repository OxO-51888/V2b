-- Adminer 4.8.1 MySQL 5.7.29 dump

SET NAMES utf8;
SET time_zone = '+00:00';
SET foreign_key_checks = 0;
SET sql_mode = 'NO_AUTO_VALUE_ON_ZERO';

SET NAMES utf8mb4;

DROP TABLE IF EXISTS `failed_jobs`;
CREATE TABLE `failed_jobs` (
                               `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
                               `connection` text COLLATE utf8mb4_unicode_ci NOT NULL,
                               `queue` text COLLATE utf8mb4_unicode_ci NOT NULL,
                               `payload` longtext COLLATE utf8mb4_unicode_ci NOT NULL,
                               `exception` longtext COLLATE utf8mb4_unicode_ci NOT NULL,
                               `failed_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
                               PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


DROP TABLE IF EXISTS `v2_commission_log`;
CREATE TABLE `v2_commission_log` (
                                     `id` int(11) NOT NULL AUTO_INCREMENT,
                                     `invite_user_id` int(11) NOT NULL,
                                     `user_id` int(11) NOT NULL,
                                     `trade_no` char(36) NOT NULL,
                                     `order_amount` int(11) NOT NULL,
                                     `get_amount` int(11) NOT NULL,
                                     `created_at` int(11) NOT NULL,
                                     `updated_at` int(11) NOT NULL,
                                     PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;


DROP TABLE IF EXISTS `v2_coupon`;
CREATE TABLE `v2_coupon` (
                             `id` int(11) NOT NULL AUTO_INCREMENT,
                             `code` varchar(255) NOT NULL,
                             `name` varchar(255) CHARACTER SET utf8mb4 NOT NULL,
                             `type` tinyint(1) NOT NULL,
                             `value` int(11) NOT NULL,
                             `show` tinyint(1) NOT NULL DEFAULT '0',
                             `limit_use` int(11) DEFAULT NULL,
                             `limit_use_with_user` int(11) DEFAULT NULL,
                             `limit_plan_ids` varchar(255) DEFAULT NULL,
                             `limit_period` varchar(255) DEFAULT NULL,
                             `started_at` int(11) NOT NULL,
                             `ended_at` int(11) NOT NULL,
                             `created_at` int(11) NOT NULL,
                             `updated_at` int(11) NOT NULL,
                             PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8;


DROP TABLE IF EXISTS `v2_giftcard`;
CREATE TABLE `v2_giftcard` (
                             `id` int(11) NOT NULL AUTO_INCREMENT,
                             `code` varchar(255) NOT NULL,
                             `name` varchar(255) CHARACTER SET utf8mb4 NOT NULL,
                             `type` tinyint(1) NOT NULL,
                             `value` int(11) DEFAULT NULL,
                             `plan_id` int(11) DEFAULT NULL,
                             `limit_use` int(11) DEFAULT NULL,
                             `used_user_ids` varchar(16384) DEFAULT NULL,
                             `started_at` int(11) NOT NULL,
                             `ended_at` int(11) NOT NULL,
                             `created_at` int(11) NOT NULL,
                             `updated_at` int(11) NOT NULL,
                             PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8;


DROP TABLE IF EXISTS `v2_invite_code`;
CREATE TABLE `v2_invite_code` (
                                  `id` int(11) NOT NULL AUTO_INCREMENT,
                                  `user_id` int(11) NOT NULL,
                                  `code` char(32) NOT NULL,
                                  `status` tinyint(1) NOT NULL DEFAULT '0',
                                  `pv` int(11) NOT NULL DEFAULT '0',
                                  `created_at` int(11) NOT NULL,
                                  `updated_at` int(11) NOT NULL,
                                  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8;


DROP TABLE IF EXISTS `v2_knowledge`;
CREATE TABLE `v2_knowledge` (
                                `id` int(11) NOT NULL AUTO_INCREMENT,
                                `language` char(5) NOT NULL COMMENT '語言',
                                `category` varchar(255) NOT NULL COMMENT '分類名',
                                `title` varchar(255) NOT NULL COMMENT '標題',
                                `body` text NOT NULL COMMENT '內容',
                                `sort` int(11) DEFAULT NULL COMMENT '排序',
                                `show` tinyint(1) NOT NULL DEFAULT '0' COMMENT '顯示',
                                `created_at` int(11) NOT NULL COMMENT '創建時間',
                                `updated_at` int(11) NOT NULL COMMENT '更新時間',
                                PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='知識庫';


DROP TABLE IF EXISTS `v2_log`;
CREATE TABLE `v2_log` (
                          `id` int(11) NOT NULL AUTO_INCREMENT,
                          `title` text NOT NULL,
                          `level` varchar(11) DEFAULT NULL,
                          `host` varchar(255) DEFAULT NULL,
                          `uri` varchar(255) NOT NULL,
                          `method` varchar(11) NOT NULL,
                          `data` text,
                          `ip` varchar(128) DEFAULT NULL,
                          `context` text,
                          `created_at` int(11) NOT NULL,
                          `updated_at` int(11) NOT NULL,
                          PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;


DROP TABLE IF EXISTS `v2_mail_log`;
CREATE TABLE `v2_mail_log` (
                               `id` int(11) NOT NULL AUTO_INCREMENT,
                               `email` varchar(64) NOT NULL,
                               `subject` varchar(255) NOT NULL,
                               `template_name` varchar(255) NOT NULL,
                               `error` text,
                               `created_at` int(11) NOT NULL,
                               `updated_at` int(11) NOT NULL,
                               PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;


DROP TABLE IF EXISTS `v2_notice`;
CREATE TABLE `v2_notice` (
                             `id` int(11) NOT NULL AUTO_INCREMENT,
                             `title` varchar(255) NOT NULL,
                             `content` text NOT NULL,
                             `show` tinyint(1) NOT NULL DEFAULT '0',
                             `img_url` varchar(255) DEFAULT NULL,
                             `tags` varchar(255) DEFAULT NULL,
                             `created_at` int(11) NOT NULL,
                             `updated_at` int(11) NOT NULL,
                             PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8;


DROP TABLE IF EXISTS `v2_order`;
CREATE TABLE `v2_order` (
                            `id` int(11) NOT NULL AUTO_INCREMENT,
                            `invite_user_id` int(11) DEFAULT NULL,
                            `user_id` int(11) NOT NULL,
                            `plan_id` int(11) NOT NULL,
                            `coupon_id` int(11) DEFAULT NULL,
                            `payment_id` int(11) DEFAULT NULL,
                            `type` int(11) NOT NULL COMMENT '1新购2续费3升级',
                            `period` varchar(255) NOT NULL,
                            `trade_no` varchar(36) NOT NULL,
                            `callback_no` varchar(255) DEFAULT NULL,
                            `total_amount` int(11) NOT NULL,
                            `handling_amount` int(11) DEFAULT NULL,
                            `discount_amount` int(11) DEFAULT NULL,
                            `surplus_amount` int(11) DEFAULT NULL COMMENT '剩余价值',
                            `refund_amount` int(11) DEFAULT NULL COMMENT '退款金额',
                            `balance_amount` int(11) DEFAULT NULL COMMENT '使用余额',
                            `surplus_order_ids` text COMMENT '折抵订单',
                            `status` tinyint(1) NOT NULL DEFAULT '0' COMMENT '0待支付1开通中2已取消3已完成4已折抵',
                            `commission_status` tinyint(1) NOT NULL DEFAULT '0' COMMENT '0待确认1发放中2有效3无效',
                            `commission_balance` int(11) NOT NULL DEFAULT '0',
                            `actual_commission_balance` int(11) DEFAULT NULL COMMENT '实际支付佣金',
                            `paid_at` int(11) DEFAULT NULL,
                            `created_at` int(11) NOT NULL,
                            `updated_at` int(11) NOT NULL,
                            PRIMARY KEY (`id`),
                            UNIQUE KEY `trade_no` (`trade_no`),
                            INDEX idx_user (`user_id`),
                            INDEX idx_user_status (`user_id`, `status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8;


DROP TABLE IF EXISTS `v2_payment`;
CREATE TABLE `v2_payment` (
                              `id` int(11) NOT NULL AUTO_INCREMENT,
                              `uuid` char(32) NOT NULL,
                              `payment` varchar(16) NOT NULL,
                              `name` varchar(255) NOT NULL,
                              `icon` varchar(255) DEFAULT NULL,
                              `config` text NOT NULL,
                              `notify_domain` varchar(128) DEFAULT NULL,
                              `handling_fee_fixed` int(11) DEFAULT NULL,
                              `handling_fee_percent` decimal(5,2) DEFAULT NULL,
                              `enable` tinyint(1) NOT NULL DEFAULT '0',
                              `sort` int(11) DEFAULT NULL,
                              `created_at` int(11) NOT NULL,
                              `updated_at` int(11) NOT NULL,
                              PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;


DROP TABLE IF EXISTS `v2_plan`;
CREATE TABLE `v2_plan` (
                           `id` int(11) NOT NULL AUTO_INCREMENT,
                           `group_id` int(11) NOT NULL,
                           `transfer_enable` int(11) NOT NULL,
                           `device_limit` int(11) DEFAULT NULL,
                           `name` varchar(255) NOT NULL,
                           `speed_limit` int(11) DEFAULT NULL,
                           `show` tinyint(1) NOT NULL DEFAULT '0',
                           `sort` int(11) DEFAULT NULL,
                           `renew` tinyint(1) NOT NULL DEFAULT '1',
                           `content` text,
                           `month_price` int(11) DEFAULT NULL,
                           `quarter_price` int(11) DEFAULT NULL,
                           `half_year_price` int(11) DEFAULT NULL,
                           `year_price` int(11) DEFAULT NULL,
                           `two_year_price` int(11) DEFAULT NULL,
                           `three_year_price` int(11) DEFAULT NULL,
                           `onetime_price` int(11) DEFAULT NULL,
                           `reset_price` int(11) DEFAULT NULL,
                           `reset_traffic_method` tinyint(1) DEFAULT NULL,
                           `capacity_limit` int(11) DEFAULT NULL,
                           `created_at` int(11) NOT NULL,
                           `updated_at` int(11) NOT NULL,
                           PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;


DROP TABLE IF EXISTS `v2_subscription_rule`;
CREATE TABLE `v2_subscription_rule` (
                                        `id` int(11) NOT NULL AUTO_INCREMENT,
                                        `name` varchar(255) NOT NULL,
                                        `type` varchar(64) NOT NULL DEFAULT 'pull_frequency',
                                        `condition_value` int(11) DEFAULT NULL,
                                        `action` varchar(32) NOT NULL DEFAULT 'no_nodes',
                                        `enabled` tinyint(1) NOT NULL DEFAULT '0',
                                        `sort` int(11) NOT NULL DEFAULT '0',
                                        `remark` text DEFAULT NULL,
                                        `created_at` int(11) NOT NULL,
                                        `updated_at` int(11) NOT NULL,
                                        PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT INTO `v2_subscription_rule` (`name`, `type`, `condition_value`, `action`, `enabled`, `sort`, `remark`, `created_at`, `updated_at`) VALUES
('5分钟同订阅超过10次', 'pull_frequency', 10, 'no_nodes', 1, 10, '同一用户订阅链接在5分钟内拉取次数超过阈值时拦截，处理脚本轮询和异常客户端刷新。', UNIX_TIMESTAMP(), UNIX_TIMESTAMP()),
('10分钟同订阅超过5个真实IP', 'ip_spread', 5, 'ai_review', 1, 20, '同一订阅链接在10分钟内出现多个真实IP时标记审核；确认真实IP可靠后可改为重置订阅。', UNIX_TIMESTAMP(), UNIX_TIMESTAMP()),
('10分钟同IP拉取超过5个用户', 'ip_multi_user', 5, 'ai_review', 1, 30, '同一个真实IP在10分钟内拉取多个用户订阅时拦截，适合处理批量拉取。', UNIX_TIMESTAMP(), UNIX_TIMESTAMP()),
('同账号节点在线IP超过10个', 'node_alive_ip_over_limit', 10, 'reset_subscribe', 1, 35, '节点上报同一账号同时在线真实IP超过阈值时重置订阅和节点凭证，用于处理单个节点被复制分享。', UNIX_TIMESTAMP(), UNIX_TIMESTAMP()),
('直连IP或本地Host访问订阅', 'direct_ip_host', NULL, 'ai_review', 1, 38, 'Host为服务器IP、localhost或空Host时标记审核，帮助发现绕过域名的订阅访问。', UNIX_TIMESTAMP(), UNIX_TIMESTAMP()),
('HEAD/OPTIONS探测订阅接口', 'head_method_probe', NULL, 'ai_review', 1, 39, '使用HEAD或OPTIONS探测订阅接口时标记审核，识别探测器或异常监控。', UNIX_TIMESTAMP(), UNIX_TIMESTAMP()),
('Censys/Shodan等扫描器UA', 'ua_scanner', NULL, 'no_nodes', 1, 40, '命中 CensysInspect、Shodan、zgrab、masscan、nmap、nuclei 等扫描器特征时拦截。', UNIX_TIMESTAMP(), UNIX_TIMESTAMP()),
('curl/wget/PowerShell命令行抓取', 'ua_cli_fetch', NULL, 'no_nodes', 1, 50, '命中 curl、wget、httpie、aria2、PowerShell 等命令行工具时拦截。', UNIX_TIMESTAMP(), UNIX_TIMESTAMP()),
('Python/Go/Node接口工具抓取', 'ua_api_fetch', NULL, 'no_nodes', 1, 60, '命中 python-requests、Go-http-client、axios、undici、reqwest、Postman 等接口工具时拦截。', UNIX_TIMESTAMP(), UNIX_TIMESTAMP()),
('空User-Agent请求订阅', 'empty_user_agent', NULL, 'no_nodes', 1, 70, '订阅请求没有 User-Agent 时拦截。', UNIX_TIMESTAMP(), UNIX_TIMESTAMP()),
('浏览器直接打开订阅链接', 'ua_browser', NULL, 'no_nodes', 1, 80, 'Chrome、Safari、Firefox、Edge 等浏览器直接打开订阅时拦截，减少泄露。', UNIX_TIMESTAMP(), UNIX_TIMESTAMP()),
('微信QQ/Telegram内置打开订阅', 'ua_social', NULL, 'no_nodes', 1, 90, '微信、QQ、Telegram、Discord 等内置浏览器打开订阅时拦截。', UNIX_TIMESTAMP(), UNIX_TIMESTAMP()),
('订阅转换器UA访问', 'ua_converter', NULL, 'ai_review', 1, 100, 'subconverter、Sub-Store、subweb 等转换器 UA 命中时标记审核，避免误伤正常用户自用转换。', UNIX_TIMESTAMP(), UNIX_TIMESTAMP()),
('厂商/电商App内置打开订阅', 'ua_vendor', NULL, 'no_nodes', 1, 105, '淘宝、京东、百度等厂商或电商 App 内置浏览器打开订阅时标记审核。', UNIX_TIMESTAMP(), UNIX_TIMESTAMP()),
('订阅转换器参数访问', 'converter_query', NULL, 'ai_review', 1, 110, '请求参数出现 target、url、config、upload、ruleset、groups 等转换器特征时标记审核。', UNIX_TIMESTAMP(), UNIX_TIMESTAMP()),
('浏览器上下文Header', 'header_browser_context', NULL, 'no_nodes', 1, 120, '出现 sec-fetch 或 referer 等浏览器上下文头时拦截，可识别伪装UA的网页打开。', UNIX_TIMESTAMP(), UNIX_TIMESTAMP()),
('flag与User-Agent客户端不一致', 'flag_ua_mismatch', NULL, 'no_nodes', 1, 130, 'flag 参数声明的客户端与 User-Agent 识别结果不一致时标记审核。', UNIX_TIMESTAMP(), UNIX_TIMESTAMP()),
('不可信代理转发头', 'untrusted_proxy_header', NULL, 'ai_review', 1, 140, '请求带 X-Forwarded-For、X-Real-IP 等转发头，但来源IP不在可信代理列表时标记审核。', UNIX_TIMESTAMP(), UNIX_TIMESTAMP()),
('非代理客户端黑名单兜底', 'ua_blacklist', NULL, 'ai_review', 1, 150, '命中任意黑名单分类但没有更具体规则时标记审核，确认后再改强动作。', UNIX_TIMESTAMP(), UNIX_TIMESTAMP());

DROP TABLE IF EXISTS `v2_subscription_rule_log`;
CREATE TABLE `v2_subscription_rule_log` (
                                            `id` int(11) NOT NULL AUTO_INCREMENT,
                                            `rule_id` int(11) DEFAULT NULL,
                                            `user_id` int(11) DEFAULT NULL,
                                            `token_hash` char(64) DEFAULT NULL,
                                            `rule_type` varchar(64) NOT NULL,
                                            `action` varchar(32) NOT NULL,
                                            `reason` varchar(64) DEFAULT NULL,
                                            `matched_value` varchar(255) DEFAULT NULL,
                                            `client_ip` varchar(45) DEFAULT NULL,
                                            `proxy_ip` varchar(45) DEFAULT NULL,
                                            `x_forwarded_for` varchar(255) DEFAULT NULL,
                                            `user_agent` varchar(512) DEFAULT NULL,
                                            `path` varchar(255) DEFAULT NULL,
                                            `method` varchar(16) DEFAULT NULL,
                                            `flag` varchar(64) DEFAULT NULL,
                                            `referer` varchar(512) DEFAULT NULL,
                                            `accept` varchar(255) DEFAULT NULL,
                                            `ai_decision` varchar(16) DEFAULT NULL,
                                            `ai_score` int(11) DEFAULT NULL,
                                            `ai_reason` varchar(255) DEFAULT NULL,
                                            `created_at` int(11) NOT NULL,
                                            `updated_at` int(11) NOT NULL,
                                            PRIMARY KEY (`id`),
                                            KEY `idx_user_id` (`user_id`),
                                            KEY `idx_rule_id` (`rule_id`),
                                            KEY `idx_client_ip` (`client_ip`),
                                            KEY `idx_created_at` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;


DROP TABLE IF EXISTS `v2_server_group`;
CREATE TABLE `v2_server_group` (
                                   `id` int(11) NOT NULL AUTO_INCREMENT,
                                   `name` varchar(255) NOT NULL,
                                   `created_at` int(11) NOT NULL,
                                   `updated_at` int(11) NOT NULL,
                                   PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8;


DROP TABLE IF EXISTS `v2_server_tuic`;
CREATE TABLE `v2_server_tuic` (
                                      `id` int(11) NOT NULL AUTO_INCREMENT,
                                      `group_id` varchar(255) NOT NULL,
                                      `route_id` varchar(255) DEFAULT NULL,
                                      `name` varchar(255) NOT NULL,
                                      `parent_id` int(11) DEFAULT NULL,
                                      `host` varchar(255) NOT NULL,
                                      `port` varchar(11) NOT NULL,
                                      `server_port` int(11) NOT NULL,
                                      `tags` varchar(255) DEFAULT NULL,
                                      `rate` varchar(11) NOT NULL,
                                      `show` tinyint(1) NOT NULL DEFAULT '0',
                                      `sort` int(11) DEFAULT NULL,
                                      `server_name` varchar(64) DEFAULT NULL,
                                      `insecure` tinyint(1) NOT NULL DEFAULT '0',
                                      `disable_sni` tinyint(1) NOT NULL DEFAULT '0',
                                      `udp_relay_mode` varchar(64) DEFAULT NULL,
                                      `zero_rtt_handshake` tinyint(1) NOT NULL DEFAULT '0',
                                      `congestion_control` varchar(64) DEFAULT NULL,
                                      `created_at` int(11) NOT NULL,
                                      `updated_at` int(11) NOT NULL,
                                      PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;


DROP TABLE IF EXISTS `v2_server_hysteria`;
CREATE TABLE `v2_server_hysteria` (
                                      `id` int(11) NOT NULL AUTO_INCREMENT,
                                      `version` int(11) NOT NULL,
                                      `group_id` varchar(255) NOT NULL,
                                      `route_id` varchar(255) DEFAULT NULL,
                                      `name` varchar(255) NOT NULL,
                                      `parent_id` int(11) DEFAULT NULL,
                                      `host` varchar(255) NOT NULL,
                                      `port` varchar(11) NOT NULL,
                                      `server_port` int(11) NOT NULL,
                                      `tags` varchar(255) DEFAULT NULL,
                                      `rate` varchar(11) NOT NULL,
                                      `show` tinyint(1) NOT NULL DEFAULT '0',
                                      `sort` int(11) DEFAULT NULL,
                                      `up_mbps` int(11) NOT NULL,
                                      `down_mbps` int(11) NOT NULL,
                                      `obfs` varchar(64) DEFAULT NULL,
                                      `obfs_password` varchar(255) DEFAULT NULL,
                                      `server_name` varchar(64) DEFAULT NULL,
                                      `insecure` tinyint(1) NOT NULL DEFAULT '0',
                                      `created_at` int(11) NOT NULL,
                                      `updated_at` int(11) NOT NULL,
                                      PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;


DROP TABLE IF EXISTS `v2_server_route`;
CREATE TABLE `v2_server_route` (
                                   `id` int(11) NOT NULL AUTO_INCREMENT,
                                   `remarks` varchar(255) NOT NULL,
                                   `match` text NOT NULL,
                                   `action` varchar(11) NOT NULL,
                                   `action_value` text DEFAULT NULL,
                                   `created_at` int(11) NOT NULL,
                                   `updated_at` int(11) NOT NULL,
                                   PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;


DROP TABLE IF EXISTS `v2_server_shadowsocks`;
CREATE TABLE `v2_server_shadowsocks` (
                                         `id` int(11) NOT NULL AUTO_INCREMENT,
                                         `group_id` varchar(255) NOT NULL,
                                         `route_id` varchar(255) DEFAULT NULL,
                                         `parent_id` int(11) DEFAULT NULL,
                                         `tags` varchar(255) DEFAULT NULL,
                                         `name` varchar(255) NOT NULL,
                                         `rate` varchar(11) NOT NULL,
                                         `host` varchar(255) NOT NULL,
                                         `port` varchar(11) NOT NULL,
                                         `server_port` int(11) NOT NULL,
                                         `cipher` varchar(255) NOT NULL,
                                         `obfs` char(11) DEFAULT NULL,
                                         `obfs_settings` varchar(255) DEFAULT NULL,
                                         `show` tinyint(4) NOT NULL DEFAULT '0',
                                         `sort` int(11) DEFAULT NULL,
                                         `created_at` int(11) NOT NULL,
                                         `updated_at` int(11) NOT NULL,
                                         PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;


DROP TABLE IF EXISTS `v2_server_trojan`;
CREATE TABLE `v2_server_trojan` (
                                    `id` int(11) NOT NULL AUTO_INCREMENT COMMENT '节点ID',
                                    `group_id` varchar(255) NOT NULL COMMENT '节点组',
                                    `route_id` varchar(255) DEFAULT NULL,
                                    `parent_id` int(11) DEFAULT NULL COMMENT '父节点',
                                    `tags` varchar(255) DEFAULT NULL COMMENT '节点标签',
                                    `name` varchar(255) NOT NULL COMMENT '节点名称',
                                    `rate` varchar(11) NOT NULL COMMENT '倍率',
                                    `host` varchar(255) NOT NULL COMMENT '主机名',
                                    `port` varchar(11) NOT NULL COMMENT '连接端口',
                                    `server_port` int(11) NOT NULL COMMENT '服务端口',
                                    `network` varchar(11) DEFAULT NULL COMMENT '传输方式',
                                    `network_settings` text COMMENT '传输配置',
                                    `allow_insecure` tinyint(1) NOT NULL DEFAULT '0' COMMENT '是否允许不安全',
                                    `server_name` varchar(255) DEFAULT NULL,
                                    `show` tinyint(1) NOT NULL DEFAULT '0' COMMENT '是否显示',
                                    `sort` int(11) DEFAULT NULL,
                                    `created_at` int(11) NOT NULL,
                                    `updated_at` int(11) NOT NULL,
                                    PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='trojan伺服器表';


DROP TABLE IF EXISTS `v2_server_vless`;
CREATE TABLE `v2_server_vless` (
                                   `id` int(11) NOT NULL AUTO_INCREMENT,
                                   `group_id` text NOT NULL,
                                   `route_id` text,
                                   `name` varchar(255) NOT NULL,
                                   `parent_id` int(11) DEFAULT NULL,
                                   `host` varchar(255) NOT NULL,
                                   `port` int(11) NOT NULL,
                                   `server_port` int(11) NOT NULL,
                                   `tls` tinyint(1) NOT NULL,
                                   `tls_settings` text,
                                   `flow` varchar(64) DEFAULT NULL,
                                   `network` varchar(11) NOT NULL,
                                   `network_settings` text,
                                   `encryption` varchar(64) DEFAULT NULL,
                                   `encryption_settings` text,
                                   `tags` text,
                                   `rate` varchar(11) NOT NULL,
                                   `show` tinyint(1) NOT NULL DEFAULT '0',
                                   `sort` int(11) DEFAULT NULL,
                                   `created_at` int(11) NOT NULL,
                                   `updated_at` int(11) NOT NULL,
                                   PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;


DROP TABLE IF EXISTS `v2_server_vmess`;
CREATE TABLE `v2_server_vmess` (
                                   `id` int(11) NOT NULL AUTO_INCREMENT,
                                   `group_id` varchar(255) NOT NULL,
                                   `route_id` varchar(255) DEFAULT NULL,
                                   `name` varchar(255) NOT NULL,
                                   `parent_id` int(11) DEFAULT NULL,
                                   `host` varchar(255) NOT NULL,
                                   `port` varchar(11) NOT NULL,
                                   `server_port` int(11) NOT NULL,
                                   `tls` tinyint(4) NOT NULL DEFAULT '0',
                                   `tags` varchar(255) DEFAULT NULL,
                                   `rate` varchar(11) NOT NULL,
                                   `network` varchar(11) NOT NULL,
                                   `rules` text,
                                   `networkSettings` text,
                                   `tlsSettings` text,
                                   `ruleSettings` text,
                                   `dnsSettings` text,
                                   `show` tinyint(1) NOT NULL DEFAULT '0',
                                   `sort` int(11) DEFAULT NULL,
                                   `created_at` int(11) NOT NULL,
                                   `updated_at` int(11) NOT NULL,
                                   PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

DROP TABLE IF EXISTS `v2_server_anytls`;
CREATE TABLE `v2_server_anytls` (
                                      `id` int(11) NOT NULL AUTO_INCREMENT,
                                      `group_id` varchar(255) NOT NULL,
                                      `route_id` varchar(255) DEFAULT NULL,
                                      `name` varchar(255) NOT NULL,
                                      `parent_id` int(11) DEFAULT NULL,
                                      `host` varchar(255) NOT NULL,
                                      `port` varchar(11) NOT NULL,
                                      `server_port` int(11) NOT NULL,
                                      `tags` varchar(255) DEFAULT NULL,
                                      `rate` varchar(11) NOT NULL,
                                      `show` tinyint(1) NOT NULL DEFAULT '0',
                                      `sort` int(11) DEFAULT NULL,
                                      `server_name` varchar(64) DEFAULT NULL,
                                      `insecure` tinyint(1) NOT NULL DEFAULT '0',
                                      `padding_scheme` text,
                                      `created_at` int(11) NOT NULL,
                                      `updated_at` int(11) NOT NULL,
                                      PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

DROP TABLE IF EXISTS `v2_server_v2node`;
CREATE TABLE `v2_server_v2node` (
                                    `id` int(11) NOT NULL AUTO_INCREMENT,
                                    `group_id` varchar(255) NOT NULL,
                                    `route_id` varchar(255) DEFAULT NULL,
                                    `name` varchar(255) NOT NULL,
                                    `parent_id` int(11) DEFAULT NULL,
                                    `host` varchar(255) NOT NULL,
                                    `listen_ip` varchar(255) NOT NULL DEFAULT '0.0.0.0',
                                    `port` varchar(11) NOT NULL,
                                    `server_port` int(11) NOT NULL,
                                    `tags` varchar(255) DEFAULT NULL,
                                    `rate` varchar(11) NOT NULL,
                                    `show` tinyint(1) NOT NULL DEFAULT '0',
                                    `sort` int(11) DEFAULT NULL,
                                    `protocol` varchar(24) NOT NULL COMMENT '协议类型',
                                    `tls` tinyint(1) NOT NULL COMMENT 'tls类型',
                                    `tls_settings` text COMMENT 'tls配置',
                                    `flow` varchar(64) DEFAULT NULL COMMENT 'vless流控',
                                    `network` varchar(11) NOT NULL COMMENT '传输类型',
                                    `network_settings` text COMMENT '传输配置',
                                    `encryption` varchar(64) DEFAULT NULL COMMENT 'vless加密',
                                    `encryption_settings` text COMMENT 'vless加密配置',
                                    `disable_sni` tinyint(1) NOT NULL DEFAULT '0' COMMENT 'tuic禁用sni',
                                    `udp_relay_mode` varchar(64) DEFAULT NULL COMMENT 'tuic udp中继模式',
                                    `zero_rtt_handshake` tinyint(1) NOT NULL DEFAULT '0' COMMENT 'tuic 0rtt握手',
                                    `congestion_control` varchar(64) DEFAULT NULL COMMENT 'tuic拥塞控制',
                                    `cipher` varchar(64) DEFAULT NULL COMMENT 'shadowsocks加密方式',
                                    `up_mbps` int(11) NOT NULL COMMENT 'hysteria上行带宽',
                                    `down_mbps` int(11) NOT NULL COMMENT 'hysteria下行带宽',
                                    `obfs` varchar(64) DEFAULT NULL COMMENT 'hysteria1混淆密码/hysteria2混淆类型',
                                    `obfs_password` varchar(255) DEFAULT NULL COMMENT 'hysteria2混淆密码',
                                    `padding_scheme` text COMMENT 'anytls填充配置',
                                    `created_at` int(11) NOT NULL,
                                    `updated_at` int(11) NOT NULL,
                                    PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

DROP TABLE IF EXISTS `v2_stat`;
CREATE TABLE `v2_stat` (
                           `id` int(11) NOT NULL AUTO_INCREMENT,
                           `record_at` int(11) NOT NULL,
                           `record_type` char(1) NOT NULL,
                           `order_count` int(11) NOT NULL COMMENT '订单数量',
                           `order_total` int(11) NOT NULL COMMENT '订单合计',
                           `commission_count` int(11) NOT NULL,
                           `commission_total` int(11) NOT NULL COMMENT '佣金合计',
                           `paid_count` int(11) NOT NULL,
                           `paid_total` int(11) NOT NULL,
                           `register_count` int(11) NOT NULL,
                           `invite_count` int(11) NOT NULL,
                           `transfer_used_total` varchar(32) NOT NULL,
                           `created_at` int(11) NOT NULL,
                           `updated_at` int(11) NOT NULL,
                           PRIMARY KEY (`id`),
                           UNIQUE KEY `record_at` (`record_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8 COMMENT='订单统计';


DROP TABLE IF EXISTS `v2_stat_server`;
CREATE TABLE `v2_stat_server` (
                                  `id` int(11) NOT NULL AUTO_INCREMENT,
                                  `server_id` int(11) NOT NULL COMMENT '节点id',
                                  `server_type` char(11) NOT NULL COMMENT '节点类型',
                                  `u` bigint(20) NOT NULL,
                                  `d` bigint(20) NOT NULL,
                                  `record_type` char(1) NOT NULL COMMENT 'd day m month',
                                  `record_at` int(11) NOT NULL COMMENT '记录时间',
                                  `created_at` int(11) NOT NULL,
                                  `updated_at` int(11) NOT NULL,
                                  PRIMARY KEY (`id`),
                                  UNIQUE KEY `server_id_server_type_record_at` (`server_id`,`server_type`,`record_at`),
                                  KEY `record_at` (`record_at`),
                                  KEY `server_id` (`server_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8 COMMENT='节点数据统计';


DROP TABLE IF EXISTS `v2_stat_user`;
CREATE TABLE `v2_stat_user` (
                                `id` int(11) NOT NULL AUTO_INCREMENT,
                                `user_id` int(11) NOT NULL,
                                `server_rate` decimal(10,2) NOT NULL,
                                `u` bigint(20) NOT NULL,
                                `d` bigint(20) NOT NULL,
                                `record_type` char(2) NOT NULL,
                                `record_at` int(11) NOT NULL,
                                `created_at` int(11) NOT NULL,
                                `updated_at` int(11) NOT NULL,
                                PRIMARY KEY (`id`),
                                UNIQUE KEY `server_rate_user_id_record_at` (`server_rate`,`user_id`,`record_at`),
                                KEY `user_id` (`user_id`),
                                KEY `record_at` (`record_at`),
                                KEY `server_rate` (`server_rate`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;


DROP TABLE IF EXISTS `v2_ticket`;
CREATE TABLE `v2_ticket` (
                             `id` int(11) NOT NULL AUTO_INCREMENT,
                             `user_id` int(11) NOT NULL,
                             `subject` varchar(255) NOT NULL,
                             `level` tinyint(1) NOT NULL,
                             `status` tinyint(1) NOT NULL DEFAULT '0' COMMENT '0:已开启 1:已关闭',
                             `reply_status` tinyint(1) NOT NULL DEFAULT '0' COMMENT '0:待回复 1:已回复',
                             `created_at` int(11) NOT NULL,
                             `updated_at` int(11) NOT NULL,
                             PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8;


DROP TABLE IF EXISTS `v2_ticket_message`;
CREATE TABLE `v2_ticket_message` (
                                     `id` int(11) NOT NULL AUTO_INCREMENT,
                                     `user_id` int(11) NOT NULL,
                                     `ticket_id` int(11) NOT NULL,
                                     `message` text CHARACTER SET utf8mb4 NOT NULL,
                                     `created_at` int(11) NOT NULL,
                                     `updated_at` int(11) NOT NULL,
                                     PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8;


DROP TABLE IF EXISTS `v2_user`;
CREATE TABLE `v2_user` (
                           `id` int(11) NOT NULL AUTO_INCREMENT,
                           `invite_user_id` int(11) DEFAULT NULL,
                           `telegram_id` bigint(20) DEFAULT NULL,
                           `email` varchar(64) NOT NULL,
                           `password` varchar(64) NOT NULL,
                           `password_algo` char(10) DEFAULT NULL,
                           `password_salt` char(10) DEFAULT NULL,
                           `balance` int(11) NOT NULL DEFAULT '0',
                           `discount` int(11) DEFAULT NULL,
                           `commission_type` tinyint(4) NOT NULL DEFAULT '0' COMMENT '0: system 1: period 2: onetime',
                           `commission_rate` int(11) DEFAULT NULL,
                           `commission_balance` int(11) NOT NULL DEFAULT '0',
                           `t` int(11) NOT NULL DEFAULT '0',
                           `u` bigint(20) NOT NULL DEFAULT '0',
                           `d` bigint(20) NOT NULL DEFAULT '0',
                           `transfer_enable` bigint(20) NOT NULL DEFAULT '0',
                           `device_limit` int(11) DEFAULT NULL,
                           `banned` tinyint(1) NOT NULL DEFAULT '0',
                           `is_admin` tinyint(1) NOT NULL DEFAULT '0',
                           `last_login_at` int(11) DEFAULT NULL,
                           `is_staff` tinyint(1) NOT NULL DEFAULT '0',
                           `last_login_ip` int(11) DEFAULT NULL,
                           `uuid` varchar(36) NOT NULL,
                           `group_id` int(11) DEFAULT NULL,
                           `plan_id` int(11) DEFAULT NULL,
                           `speed_limit` int(11) DEFAULT NULL,
                           `auto_renewal` tinyint(4) DEFAULT '0',
                           `remind_expire` tinyint(4) DEFAULT '1',
                           `remind_traffic` tinyint(4) DEFAULT '1',
                           `token` char(32) NOT NULL,
                           `expired_at` bigint(20) DEFAULT '0',
                           `remarks` text,
                           `created_at` int(11) NOT NULL,
                           `updated_at` int(11) NOT NULL,
                           PRIMARY KEY (`id`),
                           UNIQUE KEY `email` (`email`),
                           UNIQUE KEY `token` (`token`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8;


-- 2025-09-12 10:05:00
