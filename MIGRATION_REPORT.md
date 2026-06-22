# Exam 在线考试刷题系统 - 环境迁移与排障报告

**迁移日期**：2026-06-22  
**项目名称**：大学在线考试与刷题工具 (Exam System)  
**执行角色**：Antigravity AI (全栈与系统运维专家)  

本报告记录了项目从旧物理服务器迁移至独立虚拟机的详细拓扑和配置变更，用于后续的系统迭代及紧急排障。

---

## 📂 一、 新旧环境参数对比 (Environment Analysis)

| 属性维度 | 源服务器 (Source) | 新虚拟机 (Target) | 备注说明 |
| :--- | :--- | :--- | :--- |
| **物理主机** | 局域网物理服务器 (`192.168.1.40`) | 独立虚拟机 VMware (`192.168.1.147`) | 实现了应用级物理隔离 |
| **操作系统 (OS)** | Debian (基于宝塔面板管理) | **Ubuntu 24.04 LTS** (纯净版) | 去除了面板层的系统污染 |
| **Web 服务器** | Nginx | **Nginx 1.24.0** (系统原生) | 站点文件路径：`/etc/nginx/sites-available/exam` |
| **PHP 引擎** | PHP 8.2-FPM | **PHP 8.3-FPM** | 兼容性无损，执行效率更佳 |
| **PHP-FPM 通信** | Unix Socket (`/tmp/php-cgi-82.sock`) | **Unix Socket (`/run/php/php8.3-fpm.sock`)** | 彻底解决了 systemd 私有临时目录隔离导致的 502 错误 |
| **数据库 (DB)** | MySQL 8.0 (表空间存储) | **MariaDB 10.11** | 已于导入前将不支持的 `utf8mb4_0900_ai_ci` 字符排序规则批量转换为兼容的 `utf8mb4_general_ci` |
| **DB 连接方式** | Unix Domain Socket | **TCP Socket (`127.0.0.1:3306`)** | 避免了数据库 Socket 路径变更引起的连接报错 |
| **部署根目录** | `/www/wwwroot/ibubble.vicp.net/Projects/Exam` | **/www/exam/** | 目录拥有权已调整为 `gemini:gemini` (无须 sudo 读写) |

---

## 🌐 二、 全链路网络与内网穿透拓扑 (Network Topology)

为了降低架构耦合，我们废弃了原先在云端进行的复杂 URL 重写（Rewrite）与 Fallback 回退逻辑，重构为了以下标准的穿透链条：

```text
  [ 公网用户 ] 
       │ 
       ▼ (HTTPS:443 访问请求)
  [ FRP云服务器: Caddy网关 ] ──> 执行 SSL 证书卸载
       │ 
       ▼ (内部转发 HTTP 到 127.0.0.1:8080)
  [ FRP服务端: frps ]
       │ 
       ▼ (FRP 穿透隧道加密流)
  [ 本地虚拟机: frpc ] ──> 读取本地 /etc/frp/frpc.toml 映射
       │ 
       ▼ (本地中转 HTTP 到 127.0.0.1:80)
  [ 本地虚拟机: Nginx ]
       │ 
       ▼ (反向代理 fastcgi_pass)
  [ 本地虚拟机: PHP-FPM 8.3 ] ──> 调用 MariaDB (127.0.0.1:3306)
       │ 
       ▼ (物理读取)
  [ 物理站点目录: /www/exam/ ]
```

---

## 🛠️ 三、 常用排障与日常运维命令 (Troubleshooting & Maintenance)

在新虚拟机（`192.168.1.147`）上执行日常运维排查时，可直接参考以下指令：

### 1. 核心服务管理命令
当网站发生异常时，可以通过以下命令检查或重启相关服务进程：

* **Nginx 服务**：
  * 状态检查：`systemctl status nginx`
  * 配置语法检测：`sudo nginx -t`
  * 重新加载（不中断服务）：`sudo systemctl reload nginx`
  * 重启服务：`sudo systemctl restart nginx`
* **PHP-FPM 8.3 服务**：
  * 状态检查：`systemctl status php8.3-fpm`
  * 重启服务：`sudo systemctl restart php8.3-fpm`
* **MariaDB 数据库服务**：
  * 状态检查：`systemctl status mariadb`
  * 重启服务：`sudo systemctl restart mariadb`
* **FRP 客户端 (frpc)**：
  * 状态检查：`systemctl status frpc`
  * 重启服务：`sudo systemctl restart frpc`

### 2. 日志分析定位 (Log Analysis)
出现 404、502 或 PHP 运行报错时，请通过以下日志进行故障定位：

* **Nginx 错误日志**：
  * `/var/log/nginx/error.log` (可通过 `tail -f /var/log/nginx/error.log` 实时监控)
* **Nginx 访问日志**：
  * `/var/log/nginx/access.log`
* **PHP-FPM 错误日志**：
  * `/var/log/php8.3-fpm.log`
* **项目运行日志**：
  * 位于项目网站目录下的 `/www/exam/logs/php_errors.log` (记录了 PHP 运行时的各类 Warning/Error 错误)

### 3. 数据库备份与还原 (Database Backup)
根据项目配置，可在本地定时运行以下命令备份数据库：

* **手动备份**：
  `mysqldump -uexam -pGl5181081 --no-tablespaces exam > /www/exam/backups/exam_manual_$(date +%Y%m%d).sql`
* **数据还原**：
  `mysql -uexam -pGl5181081 exam < /www/exam/backups/备份文件名.sql`

---

## 🔒 四、 安全加固与配置备注 (Security Policies)
1. **Session 隔离与生命周期**：
   * 学生端会话已配置为 **100 分钟 (6000秒)** 超时时间。
   * 项目目录下的 `.user.ini` 锁定了 `session.cookie_httponly = 1` 与 `session.cookie_samesite = Strict`，防范 Session 劫持与 CSRF (Cross-Site Request Forgery, 跨站请求伪造) 攻击。
2. **敏感目录保护**：
   * 本地 Nginx 已默认禁止解析 `uploads/` 目录下的 `.php` 文件，确保即使上传非法脚本也无法被执行。
   * Nginx 配置了对隐藏文件（如 `.git`、`.env`、`.user.ini`）的全局 `deny all` 拦截。
