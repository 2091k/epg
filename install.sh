#!/usr/bin/env bash
# 电视猫 EPG 盒子端安装脚本（央视 + 卫视，每天 2 次自动抓：前7天 + 今天 + 未来2天）
#
# 用法：
#   bash install.sh              # 常规安装 + 立即跑一次同步
#   SKIP_FIRST=1 bash install.sh # 只装不跑（稍后手动跑，或交给 systemd）
#
# 装的东西（uninstall.sh 一一对应删掉）：
#   抓取器      /opt/tvmao-sync/{tvmao_sync.php,channels.json}
#   网页接口    /var/www/iptv/{index.php,config.php}
#   nginx 站点  /etc/nginx/sites-available/iptv-6002 + sites-enabled 软链
#   定时器      /etc/systemd/system/tvmao-sync.{service,timer}
#   日志轮转     /etc/logrotate.d/tvmao-sync
#   数据目录    /var/www/iptv/data（运行时产生）
#
# 不动盒子上已有的 default / nginx_any168 / nginx_radio / nginx_webdav / vqq / cloud.2091k.cn 等站点。

set -euo pipefail

SRC_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
APP_DIR=/opt/tvmao-sync
WEB_DIR=/var/www/iptv
DATA_DIR=$WEB_DIR/data

echo "==> 1/9 检查依赖"
command -v php >/dev/null || { echo "缺少 php，先装：apt-get install -y php-cli"; exit 1; }
php -v | head -1
php -m | grep -qi curl || echo "  ℹ️  PHP 没装 curl 扩展，自动退回 file_get_contents（实测可用，速度略慢）"
php -m | grep -qi json || { echo "缺少 json 扩展"; exit 1; }
php -m | grep -qi zlib || echo "  ⚠️  没有 zlib，pp.xml.gz 会生成失败"
systemctl is-active --quiet nginx && echo "  nginx: active" || echo "  ⚠️  nginx 未运行"
[ -S /run/php/php7.4-fpm.sock ] && echo "  php7.4-fpm: socket 正常" || echo "  ⚠️  没找到 /run/php/php7.4-fpm.sock，网页接口会 502"

echo "==> 2/9 安装抓取器到 $APP_DIR"
mkdir -p "$APP_DIR"
install -m 0644 "$SRC_DIR/opt-tvmao-sync/tvmao_sync.php" "$APP_DIR/tvmao_sync.php"
install -m 0644 "$SRC_DIR/opt-tvmao-sync/channels.json" "$APP_DIR/channels.json"

echo "==> 3/9 PHP 语法检查"
php -l "$APP_DIR/tvmao_sync.php"
php -l "$SRC_DIR/web/index.php"
php -l "$SRC_DIR/web/config.php"

echo "==> 4/9 建数据目录"
mkdir -p "$DATA_DIR"
# 旧数据（GitHub 那套）先留一份备份，出问题能回退
if [ -f "$DATA_DIR/meta.json" ] && [ ! -d "$DATA_DIR.gh-backup" ]; then
    cp -a "$DATA_DIR" "$DATA_DIR.gh-backup"
    echo "  旧数据已备份到 $DATA_DIR.gh-backup"
fi

echo "==> 5/9 安装网页接口到 $WEB_DIR"
mkdir -p "$WEB_DIR"
for f in index.php config.php; do
    if [ -f "$WEB_DIR/$f" ] && ! cmp -s "$SRC_DIR/web/$f" "$WEB_DIR/$f"; then
        cp -a "$WEB_DIR/$f" "$WEB_DIR/$f.bak.$(date +%Y%m%d%H%M%S)"
        echo "  已备份原有 $f"
    fi
    install -m 0644 "$SRC_DIR/web/$f" "$WEB_DIR/$f"
    echo "  $WEB_DIR/$f"
done

echo "==> 6/9 安装 nginx 6002 站点"
if [ -f /etc/nginx/sites-available/iptv-6002 ]; then
    if cmp -s "$SRC_DIR/nginx/iptv-6002" /etc/nginx/sites-available/iptv-6002; then
        echo "  已存在且内容一致，不动"
    else
        cp -a /etc/nginx/sites-available/iptv-6002 "/etc/nginx/sites-available/iptv-6002.bak.$(date +%Y%m%d%H%M%S)"
        install -m 0644 "$SRC_DIR/nginx/iptv-6002" /etc/nginx/sites-available/iptv-6002
        echo "  已更新（旧文件已备份）"
    fi
else
    install -m 0644 "$SRC_DIR/nginx/iptv-6002" /etc/nginx/sites-available/iptv-6002
    echo "  已新建 /etc/nginx/sites-available/iptv-6002"
fi
ln -sfn /etc/nginx/sites-available/iptv-6002 /etc/nginx/sites-enabled/iptv-6002
if nginx -t 2>/dev/null; then
    systemctl reload nginx && echo "  nginx 已 reload，6002 生效"
else
    echo "  ⚠️  nginx -t 未通过，6002 没生效，先看 nginx -t 的报错（多半是 6002 端口被别的站占用）"
fi

echo "==> 7/9 安装定时器（每天 2 次：00:17 / 12:17）"
install -m 0644 "$SRC_DIR/systemd/tvmao-sync.service" /etc/systemd/system/tvmao-sync.service
install -m 0644 "$SRC_DIR/systemd/tvmao-sync.timer"   /etc/systemd/system/tvmao-sync.timer
touch /var/log/tvmao-sync.log

# 之前从 GitHub Pages 拉数据的那套定时器会覆盖本脚本的产物，这里关掉
if systemctl is-enabled epg-sync.timer >/dev/null 2>&1 || systemctl is-active --quiet epg-sync.timer; then
    systemctl disable --now epg-sync.timer >/dev/null 2>&1 || true
    echo "  已停用旧的 GitHub 同步定时器 epg-sync.timer（数据改由电视猫提供）"
fi

systemctl daemon-reload
systemctl enable --now tvmao-sync.timer >/dev/null
systemctl list-timers tvmao-sync.timer --no-pager | head -3

echo "==> 8/9 日志轮转（每周或超 1 MB 切一次，留 4 份，日志不会无限长大）"
if [ -d /etc/logrotate.d ]; then
    install -m 0644 "$SRC_DIR/etc/logrotate.d/tvmao-sync" /etc/logrotate.d/tvmao-sync
    echo "  已装 /etc/logrotate.d/tvmao-sync"
else
    echo "  ⚠️  没有 /etc/logrotate.d，跳过（日志会一直追加）"
fi

echo "==> 9/9 抓取"
if [ "${SKIP_FIRST:-0}" = "1" ]; then
    echo "  跳过首次同步。稍后想跑，用这条（后台跑，关掉 SSH 也不中断）："
    echo "    systemctl start --no-block tvmao-sync.service && tail -f /var/log/tvmao-sync.log"
else
    echo "  正在抓 $(date '+%H:%M:%S')，58 频道 × 9 天约 10~12 分钟"
    echo "  提示：直接前台跑，关掉 SSH 窗口会中断。想不中断就用 systemd："
    echo "    systemctl start --no-block tvmao-sync.service && tail -f /var/log/tvmao-sync.log"
    php "$APP_DIR/tvmao_sync.php" || echo "  ⚠️  首次同步失败，看 /var/log/tvmao-sync.log"
fi

echo
echo "==> 完成。自测命令："
echo "  curl -s localhost:6002/status | head -c 400"
echo "  curl -s 'localhost:6002/?ch=CCTV1' | head -c 300"
echo "  curl -s 'localhost:6002/?ch=湖南卫视' | head -c 300"
echo "  curl -s 'localhost:6002/?ch=CCTV1&date=$(date -d '+2 day' +%F)' | head -c 300"
echo "  curl -s -o /dev/null -w '%{http_code} %{size_download}\\n' localhost:6002/pp.xml.gz"
echo "  curl -s localhost:6002/channels | head -5"
