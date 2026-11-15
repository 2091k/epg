# 电视猫 EPG · 盒子端（纯 PHP，不依赖 GitHub）

把电视猫（tvmao.com）的**央视 + 卫视**节目单，抓到自己盒子上跑，供电视端当 EPG 用。
架构只有两段：

```
tvmao.com  ──(盒子上的 PHP，每 6 小时抓一次)──►  /var/www/iptv/data  ──(nginx:6002 + index.php)──►  电视端
                                                      ▲
                                              cloudflared 隧道 (epg.2091k.cn)
```

抓完就落盘，电视端访问时**只读本地文件**，不会触发重新抓取；只有定时器到点才刷新。

---

## 一、装之前先确认

| 项 | 要求 |
|---|---|
| 盒子 | 已装 nginx（6002）、PHP 7.4 + FPM、cloudflared |
| PHP 扩展 | json、zlib 必需；curl 可选（**实测盒子上没装 php-curl，脚本自动走 `file_get_contents` 也正常**，只要带浏览器 UA） |
| 出网 | 盒子能直连 `www.tvmao.com`（国内可直连，不需要 CF 代理） |
| 注意 | 电视猫**按 UA 拦请求**：不带浏览器 UA 的 HTTPS 请求会直接空响应（curl 退出码 52），脚本已内置 UA + Referer |
| 磁盘 | 约 15 MB（JSON + XMLTV） |

## 二、安装（3 条命令）

```bash
# 1. 把整个 tvmao-box 目录传到盒子上（放 /root/tvmao-box，不要解压到 /var/www/iptv）
cd /root/tvmao-box
chmod +x install.sh

# 2. 安装 + 立即抓一次（58 频道 × 9 天，约 10~12 分钟）
bash install.sh

# 想先只装不跑：
# SKIP_FIRST=1 bash install.sh

# 推荐这么跑首轮：后台执行，关掉 SSH 窗口也不会中断
systemctl start --no-block tvmao-sync.service
tail -f /var/log/tvmao-sync.log
```

> ⚠️ **别在前台直接等那 12 分钟**：`bash install.sh` 里的 `php tvmao_sync.php` 是前台进程，SSH 一断就收到 SIGHUP 被杀。数据不会坏（写的是 `.data-build`，抓完才原子切换），但这一轮白跑。要断开就断开、让它跑完，用上面那条 `systemctl start --no-block`。

安装脚本做的事，逐条可核对：

1. 检查 php / curl / json / zlib、nginx、php-fpm socket；
2. 复制 `tvmao_sync.php` + `channels.json` 到 `/opt/tvmao-sync/`；
3. `php -l` 语法检查（三个 PHP 文件，失败就停，不往下走）；
4. 建 `/var/www/iptv/data`，并把旧的 GitHub 数据备份成 `data.gh-backup`；
5. 装网页接口 `index.php` + `config.php` 到 `/var/www/iptv/`（已存在且不同则先备份）；
6. 装 nginx 6002 站点并 `nginx -t` + `reload`（只加 `iptv-6002`，别的站点不动）；
7. 装 `tvmao-sync.service` + `tvmao-sync.timer`，启用；
   **同时停用旧的 `epg-sync.timer`**——它是从 GitHub Pages 拉数据的，不关掉会每 30 分钟把电视猫数据覆盖掉；
8. 装 `/etc/logrotate.d/tvmao-sync`（日志封顶 1 MB + 4 份压缩）；
9. 跑一次同步。

> 不动盒子上原有的 `default` / `nginx_any168` / `nginx_radio` / `nginx_webdav` / `vqq` 等站点，也不改 6002 的 vhost（只往 `data/` 里写数据）。

## 三、抓什么

`channels.json` 里 58 个频道：

- **央视 18 个**：CCTV1–CCTV17、CCTV5+、CCTV-16 奥林匹克
- **卫视 40 个**：湖南、浙江、江苏、东方、北京、广东、深圳、安徽、山东、河南、湖北、河北、山西、陕西、辽宁、吉林、黑龙江、四川、重庆、云南、贵州、广西、江西、福建东南、天津、海南、甘肃、青海、宁夏、新疆、西藏、内蒙古、厦门、三沙、海峡、康巴、延边、兵团、黄河、湖南金鹰卡通

> 电视猫的"南方卫视（上星版）"页面上根本没有节目表（实测 `p_show` 数量为 0），所以这一栏换成了有数据的 **康巴卫视**；想换回来自己在 `channels.json` 改一行即可。

想加/减频道，直接改 `/opt/tvmao-sync/channels.json`，一条一个：

```json
{ "id": "江苏卫视", "name": "江苏卫视", "url": "https://www.tvmao.com/program_satellite/JSTV1-w{W}.html" }
```

- `id` 和 `name` 是电视端填的那个名字（`id` 还决定 JSON 文件名）；
- `url` 里的 `{W}` 是占位符，别改；央视用 `/program/CCTV/<台>-w{W}.html`，卫视用 `/program_satellite/<台>-w{W}.html`；
- 想知道某台的 `w` 路径怎么写：浏览器打开 `https://www.tvmao.com/program/CCTV`（央视）或 `https://www.tvmao.com/program_satellite/AHTV1-w7.html`（卫视），看链接里的台代号。

## 四、抓哪几天（**前 7 天 + 今天 + 未来 2 天**）

`{W}` 的 1–7 是**本周七天**（周一…周日），8–14 是**下周七天**。脚本按"今天"自动换算，默认窗口：

```
前 7 天（w 往回数） + 今天（w7） + 未来 2 天（w8、w9） = 9 天
```

```bash
php /opt/tvmao-sync/tvmao_sync.php                            # 默认：前7 + 今天 + 未来2
php /opt/tvmao-sync/tvmao_sync.php --past=3 --future=1        # 前3 + 今天 + 明天
php /opt/tvmao-sync/tvmao_sync.php --days=3                   # 兼容老用法＝今天 + 未来2
php /opt/tvmao-sync/tvmao_sync.php --only=CCTV1,湖南卫视       # 只抓某几台试
php /opt/tvmao-sync/tvmao_sync.php --out=/tmp/t               # 落盘到别处，不动线上数据
```

**重要限制（电视猫的数据边界）**：站点只有"本周 + 下周"两页（w1–w14），**没有上周**。所以"前 7 天"实际只能回到**今天所在周的周一**：

| 今天是 | 能拿到的历史 | 说明 |
|---|---|---|
| 周日（w7） | 前 6 天（w1–w6） | 最接近"前 7 天"的情况 |
| 周三（w3） | 前 2 天（w1–w2） | 本周一二 |
| 周一（w1） | 0 天 | 上周页面已经翻掉了 |

所以历史是**滚动累积**的：脚本每次把当天的数据落盘后就留着，第二天变成"昨天"继续用；站点上周的页面翻掉之后，本地这份还能接着对外提供——**本机保留 14 天**（`--retain=30` 可调），超过保留期的才会被清掉。也就是说从装上这套开始每天跑，最多 14 天后你就恒有"前 7 天"了。

**过去的日期抓过一次就不再重复请求**：每次开跑先把上一版 `data/` 整体拷进构建目录，过去的日子里已有文件的直接复用，只重抓"今天 + 未来 2 天"。所以：

| 场景 | 请求数 | 耗时（实测） |
|---|---|---|
| 首次（要补 6 个历史日） | 522 页 | ~11 分钟 |
| 之后每轮 | 174 页 | ~4 分钟 |

每次请求之间 1.2 秒（`--delay` 可调），**别把 delay 调到 800 以下**，电视猫会 520/522。

## 五、数据长什么样

```
/var/www/iptv/data/
├── json/
│   ├── 2026-11-09/CCTV1.json          # 历史（周一）
│   ├── 2026-11-14/湖南卫视.json       # 历史（昨天）
│   ├── 2026-11-15/CCTV1.json          # 今天
│   ├── 2026-11-16/CCTV1.json          # 明天
│   ├── 2026-11-17/CCTV1.json          # 后天
│   ├── CCTV1.json                     # 今天的快捷路径
├── channels.json  channels.txt        # 频道清单（index.php 的别名解析就吃这个）
├── meta.json                          # 同步时间 / 条数 / 日期 / 窗口 / 抓取页数
└── pp.xml  pp.xml.gz                  # XMLTV（给填 /pp.xml.gz 的客户端）
```

单频道 JSON 与 112114 完全一致：

```json
{"date":"2026-11-16","channel_name":"CCTV-1 综合","url":"",
 "epg_data":[{"start":"00:52","end":"01:36","title":"生活早参考-特别节目(生活圈)2026-314","desc":""}, …]}
```

> 电视猫只给**开始时间**，`end` 用下一条的开始时间补；当天最后一条按 +30 分钟收尾。这是和 112114 唯一的字段差异。

## 六、电视端怎么填

两种都支持：

| 客户端类型 | 填 | 说明 |
|---|---|---|
| DIYP / 影视仓这类要 `?ch=` 的 | `https://epg.2091k.cn/` | 服务端会按 `?ch=CCTV1` 或 `?ch=湖南卫视` 返回 JSON |
| 要 XMLTV 的 | `https://epg.2091k.cn/pp.xml.gz` | 全量合并，58 频道 × 9 天 |

另外用浏览器直接打开 `https://epg.2091k.cn/` 会看到一个**网页首页**（不是 JSON）：

- 顶部四个卡片：频道数（央视 18 + 卫视 40）、节目条数、覆盖天数、上次更新时间；
- 一排日期按钮（今天/明天有标记），点某个日期后再点频道，链接自动带上 `&date=`；
- 央视、卫视两栏频道格，点进去就是那台那天的节目 JSON；
- 底部有 `/status`、`/channels`、`/pp.xml.gz` 三个直达链接。
- 需要纯 JSON 的接口信息时用 `/?format=json`（或请求头带 `Accept: application/json`），否则浏览器一律给网页。

频道名支持模糊：`CCTV1` / `CCTV-1` / `cctv1` / `ＣＣＴＶ１` / `CCTV-1 综合` / `CCTV1高清` / `湖南卫视高清` 都能命中（三级降级解析，不认识就返回 `channel_not_found`，不会串台）。

域名侧要做的只有两件事（代码里没有写死任何域名）：

1. `cloudflared` 隧道加一条 ingress：`epg.2091k.cn` → `http://127.0.0.1:6002`；**盒子上已经加好了**（`/etc/cloudflared/config.yml`，tunnel id `4835af20-8be2-4419-8e47-3746bd6a9298`）；
2. 6002 的 vhost 保持 `server_name _;`（通配），任何 Host 都能进 —— 现状就是这样，不用改。

剩下只有 **DNS 一条记录**要你自己在 Cloudflare 后台改（2091k.cn 这个域不在盒子上 cert.pem 所属的账号里，脚本没法代改）：

| 项 | 值 |
|---|---|
| 类型 | `CNAME` |
| 名称 | `epg` |
| 内容 | `4835af20-8be2-4419-8e47-3746bd6a9298.cfargotunnel.com` |
| 代理状态 | **已代理（橙云）** |

改完等 1~2 分钟，`curl -sI https://epg.2091k.cn/status` 看到 `server: cloudflare` 且返回 `"service":"tvmao-box"` 就成了。
> 原来那条指向 Vercel（`*.vercel-dns-017.com`）的 CNAME 会被覆盖掉，之后 2091k.cn 的 EPG 全部由盒子提供。
> 另外顺手把 CF 里 `521156.xyz` 那条多出来的 `epg.2091k.cn` 记录删掉（`cloudflared tunnel route dns` 误建的）。

## 七、自测（装完就跑这几条）

```bash
curl -s localhost:6002/status | head -c 400
curl -s 'localhost:6002/?ch=CCTV1' | head -c 300
curl -s 'localhost:6002/?ch=湖南卫视' | head -c 300
curl -s 'localhost:6002/?ch=CCTV1&date=2026-11-16' | head -c 300   # 明天的
curl -s -o /dev/null -w '%{http_code} %{size_download}\n' localhost:6002/pp.xml.gz
```

### 2026-11-15 盒子真机实测结果（窗口＝前7天+今天+未来2天）

| 项 | 结果 |
|---|---|
| `php -l` | No syntax errors detected（PHP 7.4.3，无 php-curl，走 file_get_contents） |
| 首次全量 | 9 天 × 58 频道 = **522 页 / 754 秒（12.5 分钟）**，0 个请求失败 |
| 落盘 | **580 个 JSON / 18,210 条节目 / 9 个日期（11-09 ~ 11-17）** |
| `meta.json` | `window {past:7,today:2026-11-15,future:2}`，`pages.total 522 / ok 522 / fail 0` |
| 耗时（单页） | ≈1.3 秒/页（`--delay=1200`） |
| CPU | 整机 **90% 空闲**；本进程平均 2%、瞬时峰值约 10%（Nice=19 + ionice idle） |
| 内存 | 本进程 **27 MB**（整机 655 MB，占 4%），抓完进程退出，无常驻 |
| `?ch=CCTV1` | 200，34 条 01:02→23:10 |
| `?ch=湖南卫视` | 200，33 条 |
| `?ch=CCTV-1` / `?ch=湖南卫视高清` | 200，别名命中 |
| `?ch=CCTV1&date=2026-11-12` | 200，历史当天完整节目（00:10 今日说法 …） |
| `?ch=CCTV1&date=2026-11-18` | 200（112114 对这一天只回"精彩节目"占位） |
| `/pp.xml.gz` | 200，171 KB，`application/gzip` |
| 通过隧道 `https://epg.521156.xyz/` | 200，内容同本地 |

> 之后每轮只抓"今天 + 未来 2 天"＝**174 页 ≈ 4 分钟**（历史日期复用本地文件，不重复请求）。


## 八、定时与日志

```bash
systemctl list-timers tvmao-sync.timer --no-pager   # 下次什么时候跑
systemctl start tvmao-sync.service                  # 立刻跑一次（后台等它结束）
tail -f /var/log/tvmao-sync.log                     # 实时日志
```

**每天跑 2 次**：`OnCalendar=*-*-* 00,12:17:00`（00:17 抓当天新一页，12:17 刷新一次），盒子上电错过的那次靠 `Persistent=true` 补跑。

## 九、已知限制（照实说）

1. **卫视数据比央视少**：实测（2026-11-15 盒子上真跑）CCTV1 当天 **34 条**（01:02→23:10，全天完整）、湖南卫视当天 **33 条**；有的卫视某些日期只列半天，缺的时段不会自动补。
2. **历史最多回到本周一**：电视猫只有本周 + 下周两页，没有上周。今天周日能拿前 6 天，今天周三只有前 2 天，今天周一就是 0 天——"前 7 天"是滚动累积出来的，不是永远都有 7 天。
3. **未来只给 2 天**：按你的要求只抓 +1、+2 天（w8、w9），再往后电视猫虽然排了但也常是空的（w13/w14 实测常空）。
4. **电视猫有风控**：短时间连发会被 520/522；脚本已做 1.2 s 间隔 + 失败重试退避，实测 58 频道 × 9 天 **0 个请求失败**。另外它**按 UA 拦请求**，不带浏览器 UA 直接空回。
5. **`end` 是推算的**：电视猫只给开始时间，末条 +30 分钟，个别节目时长会不准。
6. **频率**：每天 2 次；首次全量（补历史）约 11 分钟，之后每轮约 4 分钟；盒子 CPU 弱，服务里已设 `Nice=19` + `IOSchedulingClass=idle`。

## 十、排障

| 现象 | 做法 |
|---|---|
| `?ch=` 返回 `channel_not_found` | 频道名不在 `channels.json` 的 58 个里；加进 `channels.json` 再跑一次 |
| 某天某台 404 | 那台那天没抓到（看 `meta.json` 的 `channels_empty` 和日志 `[空页面]`） |
| `/pp.xml.gz` 404 | nginx 那几个 location 没加 `root /var/www/iptv/data;`（这是踩过的坑） |
| 日志全是 520/522 | 被限速了，等半小时再跑，或把 `--delay` 调大到 2000 |
| 数据没更新 | `systemctl list-timers` 看 timer 是否 active；`systemctl status tvmao-sync.service` 看上次结果 |

## 十一、装了什么 / 怎么一键卸载

### 安装（`install.sh`）会写的文件

| 路径 | 大小 | 是什么 | 卸载时 |
|---|---|---|---|
| `/opt/tvmao-sync/tvmao_sync.php` | 26 KB | 抓取器本体 | 删 |
| `/opt/tvmao-sync/channels.json` | 8 KB | 58 个频道表 | 删 |
| `/var/www/iptv/index.php` | 9 KB | 网页接口（`?ch=`、`?date=`、`/status`、`/channels`、`/pp.xml.gz`） | 删（`KEEP_WEB=1` 可留） |
| `/var/www/iptv/config.php` | 450 B | 接口配置（数据目录、时区、缓存秒数） | 删（`KEEP_WEB=1` 可留） |
| `/etc/nginx/sites-available/iptv-6002` | 2 KB | 监听 6002 的只读站点（`pp.xml.gz`/`json/` 走磁盘，PHP 走 fpm） | 删（`PURGE_NGINX=1`） |
| `/etc/nginx/sites-enabled/iptv-6002` | 软链 | 上面那个的启用链接 | 删（`PURGE_NGINX=1`） |
| `/etc/systemd/system/tvmao-sync.service` | 1 KB | 执行单元（oneshot） | 删 |
| `/etc/systemd/system/tvmao-sync.timer` | 1 KB | 每天 00:17 / 12:17 触发 | 删 |
| `/var/www/iptv/data/` | 5~7 MB | **产物**：`json/<日期>/<频道>.json`（580 个）、`json/<频道>.json`（今天的快捷文件）、`channels.json`、`channels.txt`、`meta.json`、`pp.xml`、`pp.xml.gz` | 删（`KEEP_DATA=1` 可留） |
| `/var/log/tvmao-sync.log` | 几十 KB | 每轮日志，append | 删（`KEEP_LOG=1` 可留） |
| `/etc/logrotate.d/tvmao-sync` | 200 B | 日志轮转规则（每周或超 1 MB 切一次，留 4 份，压缩） | 删 |

### 运行期自动出现/消失的临时文件

| 路径 | 说明 | 什么时候有 |
|---|---|---|
| `/var/www/iptv/.data-build/` | 本轮构建目录，抓完才整体换成 `data/` | 抓取进行中 |
| `/var/www/iptv/data.old/` | 切换时的旧目录，切换成功后自删 | 切换的那一两秒 |
| `/tmp/tvmao-sync.lock` | 防并发锁，进程退出即释放 | 抓取进行中 |

### 它**不动**的东西

- `cloudflared` 配置和隧道（install.sh 只是**停用** `epg-sync.timer`，不写 cloudflared）；
- 同机其它站点（default / nginx_any168 / nginx_radio / nginx_webdav / vqq / 8881 / cronweb …）；
- 已经存在的 `index.php`、`config.php`、`iptv-6002` 会先备份成 `*.bak.时间戳` 再覆盖（内容一致则完全不动）。

### 一键卸载

```bash
bash uninstall.sh                 # 默认：程序 + 定时器 + 日志 + 数据 + 网页接口，全清
DRY_RUN=1 bash uninstall.sh       # 先演练，只打印要删什么，一个文件都不动
KEEP_DATA=1 bash uninstall.sh     # 保留 /var/www/iptv/data（节目数据留着，方便下次直接接管）
KEEP_LOG=1  bash uninstall.sh     # 保留日志
KEEP_WEB=1  bash uninstall.sh     # 保留 index.php / config.php
PURGE_NGINX=1   bash uninstall.sh # 连 6002 的 vhost 一起删（同机没别的站点在 6002 时才用）
PURGE_INGRESS=1 bash uninstall.sh # 连 cloudflared 里回源 127.0.0.1:6002 的 ingress 一起删
```

卸载脚本的行为，逐条对得上上面的表：

1. `systemctl disable --now tvmao-sync.timer` + `stop tvmao-sync.service`；
2. 删两个 systemd 单元 + `daemon-reload` + `reset-failed`；
3. 删 `/opt/tvmao-sync`；
4. 删 `/var/www/iptv/data`（`KEEP_DATA=1` 则保留）、`.data-build`、`data.old`；
5. 删 `/var/log/tvmao-sync.log`（含轮转出的 `.1/.2.gz` 等）、`/tmp/tvmao-sync.lock`、`/etc/logrotate.d/tvmao-sync`；
6. 删 `index.php` / `config.php` 及其 `.bak.*`（`KEEP_WEB=1` 则保留）；
7. 可选删 nginx 6002 站点（`nginx -t` 通过才 reload）；
8. 可选删 cloudflared 回源 6002 的 ingress（改前自动备份 `config.yml.bak.时间戳`，`ingress validate` 通得过才重启）。

`DRY_RUN=1` 是真正的空跑：连 `systemctl disable` 都不会执行（实测演练后 timer 仍 `active`）。

### 会不会越攒越大？——不会，都有硬上限

| 东西 | 增长方式 | 上限 | 靠什么封顶 |
|---|---|---|---|
| `data/json/<日期>/` | 每跑一天多 1 个日期目录，**约 236 KB/天**（57 频道 × 4 KB） | 17 个日期目录 ≈ **4 MB** | `--retain=14`：只留 `今天-14` 到 `今天+2`，更早的目录每轮自动 `rmrf` |
| `data/json/<频道>.json` | 固定 58 个（今天的快捷文件），每轮重写 | ≈ **236 KB** | 固定数量，不追加 |
| `data/pp.xml` / `.gz` | 覆盖写，不追加；内容只覆盖保留窗口 | 17 天 ≈ **4.4 MB / 330 KB** | 同上，随保留期封顶 |
| `data/channels.json` / `channels.txt` / `meta.json` | 覆盖写 | ≈ **44 KB** | 固定 |
| `data/.data-build/`、`data.old/` | 只在抓取/切换时存在，抓完消失 | 峰值 ≈ 2 倍数据 ≈ **17 MB**，持续 4~12 分钟 | 每轮结束自动清 |
| `/var/log/tvmao-sync.log` | 每轮约 5 KB（2 次/天 ≈ 10 KB/天） | **1 MB** + 4 份压缩 | `logrotate`：weekly / maxsize 1M / rotate 4 / compress |
| `/tmp/tvmao-sync.lock` | 进程退出即释放 | 0 字节 | — |

稳态总占用：**数据约 9 MB、日志约 1 MB**，磁盘占 6.6 GB 的 0.15%，跑一年也不会变。

> 实测（2026-11-15 17:26）：`data/` 现为 5.1 MB / 9 个日期 / 每天 236 KB；日志 15,435 B 对应 3 轮；`logrotate -f` 强制轮转验证通过（`.log` 归零、`.log.1` 15,435 B）。该盒子 `/var/log` 是 `drwxrwxr-x root syslog`（组可写），所以规则里必须写 `su root root`，否则 logrotate 会拒绝处理。


