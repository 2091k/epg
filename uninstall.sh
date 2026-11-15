#!/usr/bin/env bash
# 电视猫 EPG 盒子端 —— 一键卸载
# 原则：install.sh 装了什么，这里就删什么；别的站点、别的目录一个都不碰。
#
# 用法：
#   bash uninstall.sh                  # 卸载程序 + 定时器 + 日志 + 数据目录
#   KEEP_DATA=1 bash uninstall.sh       # 保留 /var/www/iptv/data（节目数据留着）
#   KEEP_LOG=1  bash uninstall.sh       # 保留 /var/log/tvmao-sync.log
#   PURGE_NGINX=1   bash uninstall.sh   # 连 6002 的 vhost 一起删（默认不删，怕影响同机其它站点）
#   PURGE_INGRESS=1 bash uninstall.sh   # 连 cloudflared 里回源 6002 的 ingress 一起删
#   DRY_RUN=1 bash uninstall.sh         # 只打印要删什么，不动手

set -uo pipefail

APP_DIR=/opt/tvmao-sync
DATA_DIR=/var/www/iptv/data
BUILD_DIR=/var/www/iptv/.data-build
OLD_DIR=/var/www/iptv/data.old
LOG_FILE=/var/log/tvmao-sync.log
LOCK_FILE=/tmp/tvmao-sync.lock
SVC=/etc/systemd/system/tvmao-sync.service
TMR=/etc/systemd/system/tvmao-sync.timer
NGINX_SA=/etc/nginx/sites-available/iptv-6002
NGINX_SE=/etc/nginx/sites-enabled/iptv-6002
CFG=/etc/cloudflared/config.yml

DRY_RUN="${DRY_RUN:-0}"
KEEP_DATA="${KEEP_DATA:-0}"
KEEP_LOG="${KEEP_LOG:-0}"
PURGE_NGINX="${PURGE_NGINX:-0}"
PURGE_INGRESS="${PURGE_INGRESS:-0}"

say() { printf '%s\n' "$*"; }

# 删文件/目录：DRY_RUN 时只打印
del() {
    local p="$1"
    if [ ! -e "$p" ] && [ ! -L "$p" ]; then
        say "  - 跳过（不存在）：$p"
        return 0
    fi
    if [ "$DRY_RUN" = "1" ]; then
        say "  - 将删除：$p"
        return 0
    fi
    rm -rf -- "$p" && say "  - 已删除：$p"
}

# 安全闸：数据目录必须就是那一个固定路径，防止变量为空时误删
case "$DATA_DIR" in
    /var/www/iptv/data) ;;
    *) say "数据目录路径异常（$DATA_DIR），中止"; exit 1 ;;
esac

say "== 电视猫 EPG 卸载 $(date '+%Y-%m-%d %H:%M:%S') =="
[ "$DRY_RUN" = "1" ] && say "（DRY_RUN：只演练，不真删）"

say "==> 1/8 停止并禁用定时器/服务"
if [ "$DRY_RUN" = "1" ]; then
    say "  将执行：systemctl disable --now tvmao-sync.timer && systemctl stop tvmao-sync.service"
elif command -v systemctl >/dev/null; then
    systemctl disable --now tvmao-sync.timer >/dev/null 2>&1 || true
    systemctl stop tvmao-sync.service >/dev/null 2>&1 || true
    say "  timer 已停用，service 已停止"
fi

say "==> 2/8 删除 systemd 单元"
del "$TMR"
del "$SVC"
if [ "$DRY_RUN" != "1" ]; then
    systemctl daemon-reload >/dev/null 2>&1 || true
    systemctl reset-failed tvmao-sync.service >/dev/null 2>&1 || true
fi

say "==> 3/8 删除程序目录 $APP_DIR（tvmao_sync.php + channels.json）"
del "$APP_DIR"

say "==> 4/8 处理数据目录"
if [ "$KEEP_DATA" = "1" ]; then
    say "  KEEP_DATA=1，保留 $DATA_DIR（$(du -sh "$DATA_DIR" 2>/dev/null | cut -f1)）"
else
    del "$DATA_DIR"
fi
del "$BUILD_DIR"
del "$OLD_DIR"

say "==> 5/8 处理日志与锁文件"
if [ "$KEEP_LOG" = "1" ]; then
    say "  KEEP_LOG=1，保留 $LOG_FILE"
else
    del "$LOG_FILE"
fi
del "$LOCK_FILE"
del "/etc/logrotate.d/tvmao-sync"
# 轮转出来的历史日志（logrotate 命名 tvmao-sync.log.1 / .2.gz …）
if [ "$KEEP_LOG" != "1" ]; then
    for f in /var/log/tvmao-sync.log.[0-9]*; do
        [ -e "$f" ] && del "$f"
    done
    say "  - 已清理轮转出的历史日志"
fi

say "==> 6/8 网页接口"
if [ "${KEEP_WEB:-0}" = "1" ]; then
    say "  KEEP_WEB=1，保留 /var/www/iptv 下的 index.php / config.php"
else
    del "/var/www/iptv/index.php"
    del "/var/www/iptv/config.php"
    for f in /var/www/iptv/index.php.bak.* /var/www/iptv/config.php.bak.* /var/www/iptv/data.gh-backup /var/www/iptv/.nojekyll; do
        [ -e "$f" ] && del "$f"
    done
fi

say "==> 7/8 nginx 站点"
if [ "$PURGE_NGINX" = "1" ]; then
    del "$NGINX_SE"
    del "$NGINX_SA"
    if [ "$DRY_RUN" != "1" ]; then
        if nginx -t >/dev/null 2>&1; then
            systemctl reload nginx && say "  nginx 已 reload"
        else
            say "  ⚠️  nginx 配置检查未通过，未 reload，请手动看 nginx -t"
        fi
    fi
else
    say "  保留 $NGINX_SA（默认不动，免得影响同一台机器上其它站点）"
    say "  想一起删：PURGE_NGINX=1 bash uninstall.sh"
fi

say "==> 8/8 cloudflared ingress"
if [ "$PURGE_INGRESS" = "1" ]; then
    if [ -f "$CFG" ] && command -v python3 >/dev/null; then
        if [ "$DRY_RUN" = "1" ]; then
            say "  将删除 $CFG 中 service 指向 127.0.0.1:6002 的 ingress 块（保持其余 hostname 不变）"
        else
            cp -a "$CFG" "$CFG.bak.$(date +%Y%m%d%H%M%S)"
            python3 - "$CFG" <<'PY'
import re, sys
path = sys.argv[1]
lines = open(path, encoding='utf-8').read().splitlines()
out, i = [], 0
while i < len(lines):
    line = lines[i]
    if line.strip().startswith('- hostname:') and i + 1 < len(lines):
        svc = lines[i + 1]
        if '127.0.0.1:6002' in svc:
            i += 2                      # 丢掉 hostname + 对应的 service 两行
            continue
    out.append(line)
    i += 1
open(path, 'w', encoding='utf-8').write('\n'.join(out).rstrip('\n') + '\n')
print('  已从 config.yml 移除回源 6002 的 ingress')
PY
            if cloudflared tunnel ingress validate >/dev/null 2>&1; then
                systemctl restart cloudflared && say "  cloudflared 已重启"
            else
                say "  ⚠️  ingress 校验未通过，未重启；备份在 $CFG.bak.*"
            fi
        fi
    else
        say "  跳过（没有 $CFG 或没有 python3）"
    fi
else
    say "  保留 cloudflared 配置（EPG 域名仍会回源 6002，卸载后访问会 502/404）"
    say "  想一起删：PURGE_INGRESS=1 bash uninstall.sh"
fi

say
say "== 完成。剩余检查 =="
if [ "$DRY_RUN" = "1" ]; then
    say "（演练模式，上面列出的都还没真删）"
fi
say "tvmao-sync 定时器：$(systemctl list-timers --no-pager 2>/dev/null | grep -c 'tvmao-sync' ) 条"
if [ -d "$APP_DIR" ]; then say "程序目录 $APP_DIR：还在"; else say "程序目录 $APP_DIR：已清空"; fi
if [ -d "$DATA_DIR" ]; then say "数据目录 $DATA_DIR：还在"; else say "数据目录 $DATA_DIR：已清空"; fi
if [ -f "$SVC" ] || [ -f "$TMR" ]; then say "systemd 单元：还在"; else say "systemd 单元：已清空"; fi
say
say "提示："
say "  - 想回到旧的 GitHub 抓取方案，需自己重新启用：systemctl enable --now epg-sync.timer（前提是它还装着）"
say "  - 网页接口 /var/www/iptv/index.php、config.php 与 nginx 6002 站点不属于本安装脚本，未做改动"
