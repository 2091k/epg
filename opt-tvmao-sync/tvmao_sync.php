<?php
/**
 * 电视猫（tvmao.com）节目单抓取器 — 盒子端
 *
 * 作用：每 6 小时（由 systemd timer 触发）抓一次电视猫的央视 + 卫视节目单，
 *      落地成 112114 同款目录结构，交给 nginx(6002) + index.php 分发给电视端。
 *
 * 产物（默认 /var/www/iptv/data）：
 *   json/<YYYY-MM-DD>/<频道>.json   单频道单日（DIYP/112114 格式）
 *   json/<频道>.json                今天的快捷路径
 *   channels.json / channels.txt    频道清单
 *   meta.json                       同步元信息
 *   pp.xml / pp.xml.gz              XMLTV
 *
 * 用法：
 *   php tvmao_sync.php                     # 默认：前 7 天 + 今天 + 未来 2 天
 *   php tvmao_sync.php --past=3 --future=1 # 前 3 天 + 今天 + 明天
 *   php tvmao_sync.php --retain=30         # 本地历史保留 30 天（默认 14）
 *   php tvmao_sync.php --days=3            # 兼容老用法：今天 + 未来 2 天
 *   php tvmao_sync.php --only=CCTV1,湖南卫视
 *   php tvmao_sync.php --out=/tmp/epgtest  # 输出到别处试跑
 *   php tvmao_sync.php --dry-run           # 只抓和解析，不落盘
 *
 * 说明：电视猫只有「本周 7 天(w1-w7) + 下周 7 天(w8-w14)」两页，所以"前 7 天"
 *      实际最多只能回到今天所在周的周一；更早的日期站点上没有数据。
 *      过去的日期抓过一次就复用旧文件，不再重复请求。
 */

declare(strict_types=1);

error_reporting(E_ALL);
date_default_timezone_set('Asia/Shanghai');

const DATA_DIR  = '/var/www/iptv/data';
const BUILD_DIR = '/var/www/iptv/.data-build';
const LOCK_FILE = '/tmp/tvmao-sync.lock';
const BASE_URL  = 'https://www.tvmao.com';

const UA = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0 Safari/537.36';

/** 频道表：与 tvmao_sync.php 同目录的 channels.json */
const CHANNEL_FILE = __DIR__ . '/channels.json';

function say(string $msg): void
{
    echo '[' . date('H:i:s') . '] ' . $msg . PHP_EOL;
}

/* ============================ HTTP ============================ */

function http_get(string $url, int $timeout = 25, int $retry = 3): ?string
{
    $lastErr = '';
    for ($i = 1; $i <= $retry; $i++) {
        $body = null;
        if (function_exists('curl_init')) {
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_MAXREDIRS      => 3,
                CURLOPT_TIMEOUT        => $timeout,
                CURLOPT_CONNECTTIMEOUT => 10,
                CURLOPT_ENCODING       => '',
                CURLOPT_SSL_VERIFYPEER => false,
                CURLOPT_SSL_VERIFYHOST => 0,
                CURLOPT_USERAGENT      => UA,
                CURLOPT_REFERER        => BASE_URL . '/program/',
                CURLOPT_HTTPHEADER     => ['Accept-Language: zh-CN,zh;q=0.9'],
            ]);
            $body = curl_exec($ch);
            $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $err  = curl_error($ch);
            curl_close($ch);
            if ($body === false || $code !== 200) {
                $lastErr = $code . ' ' . $err;
                $body = null;
            }
        } else {
            $ctx = stream_context_create(['http' => [
                'timeout' => $timeout,
                'header'  => "User-Agent: " . UA . "\r\nReferer: " . BASE_URL . "/program/\r\n",
            ]]);
            $body = @file_get_contents($url, false, $ctx);
            if ($body === false) {
                $lastErr = 'file_get_contents failed';
                $body = null;
            }
        }
        if (is_string($body) && strlen($body) > 2000) {
            return $body;
        }
        if ($i < $retry) {
            sleep(2 * $i); // 电视猫有风控，失败必须退避
        }
    }
    say("  抓取失败 $url ($lastErr)");
    return null;
}

/* ============================ 解析 ============================ */

/**
 * 解析电视猫节目页。
 * 返回 [['time' => 'HH:MM', 'title' => '…', 'date' => 'YYYY-MM-DD'|null], …]
 * 页面上有的版本带 res="2026-11-15 01:24_xxx"（日期直接给了），有的没有（靠外层 wN 推算）。
 */
function parse_schedule(string $html): array
{
    $rows = [];
    $pat = '~<span class="(?:am|pm)">\s*(\d{2}:\d{2})\s*</span>\s*<span class="p_show">(.+?)</span>~su';
    if (!preg_match_all($pat, $html, $m, PREG_SET_ORDER)) {
        return $rows;
    }
    foreach ($m as $one) {
        $time  = $one[1];
        $inner = $one[2];
        $date  = null;
        if (preg_match('~res="(\d{4}-\d{2}-\d{2})~u', $inner, $dm)) {
            $date = $dm[1];
        }
        $title = '';
        if (preg_match('~title="([^"]*)"~u', $inner, $tm)) {
            $title = trim($tm[1]);
        }
        if ($title === '') {
            $title = trim(strip_tags($inner));
        }
        $title = clean_title($title);
        if ($title === '') {
            continue;
        }
        $rows[] = ['time' => $time, 'title' => $title, 'date' => $date];
    }
    return $rows;
}

/** 清掉电视猫页面上的噪音：实体、多余空白、尾部“剧情介绍” */
function clean_title(string $t): string
{
    $t = html_entity_decode($t, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $t = str_replace(["\xc2\xa0", '&nbsp;', "\u{3000}"], ' ', $t);
    $t = preg_replace('~\s+~u', ' ', $t) ?? $t;
    $t = trim($t);
    // 卫视页常把“XX剧情介绍”当名字，去掉尾巴
    $t = preg_replace('~剧情介绍$~u', '', $t) ?? $t;
    $t = preg_replace('~\s*[-—]\s*$~u', '', $t) ?? $t;
    return trim($t);
}

/** 与 index.php 一致的落盘文件名规则 */
function safe_name(string $raw): string
{
    $s = preg_replace('/[^0-9A-Za-z\x{4e00}-\x{9fff}._-]+/u', '_', trim($raw));
    if (!is_string($s)) {
        return '';
    }
    return trim($s, '_');
}

/* ============================ 组装 ============================ */

/** 'HH:MM' + 分钟 */
function add_minutes(string $hm, int $min): string
{
    [$h, $m] = array_map('intval', explode(':', $hm));
    $total = $h * 60 + $m + $min;
    if ($total >= 24 * 60) {
        return '23:59';
    }
    return sprintf('%02d:%02d', intdiv($total, 60), $total % 60);
}

/**
 * 一组当天的节目 → DIYP 格式 rows（补 end、排序、去重）
 * @param array<int,array{time:string,title:string}> $items
 */
function to_rows(array $items): array
{
    // 同一时间多条（页面偶发重复）只留第一条
    $byTime = [];
    foreach ($items as $it) {
        $t = $it['time'];
        if (!preg_match('~^\d{2}:\d{2}$~', $t)) {
            continue;
        }
        if (!isset($byTime[$t])) {
            $byTime[$t] = $it['title'];
        }
    }
    ksort($byTime);

    $times = array_keys($byTime);
    $rows  = [];
    $n     = count($times);
    for ($i = 0; $i < $n; $i++) {
        $start = $times[$i];
        $end   = ($i + 1 < $n) ? $times[$i + 1] : add_minutes($start, 30);
        if ($end === $start) {
            $end = add_minutes($start, 30);
        }
        $rows[] = [
            'start' => $start,
            'end'   => $end,
            'title' => $byTime[$start],
            'desc'  => '',
        ];
    }
    return $rows;
}

function channel_json(string $date, string $name, string $icon, array $rows): array
{
    return [
        'date'         => $date,
        'channel_name' => $name,
        'url'          => $icon,
        'epg_data'     => $rows,
    ];
}

/* ============================ XMLTV ============================ */

function xmltv_escape(string $s): string
{
    return htmlspecialchars($s, ENT_XML1 | ENT_QUOTES, 'UTF-8');
}

/** @param array<string,array<string,array<string,array>>> $store [channelId][date] = raw rows */
function build_xmltv(array $channels, array $store, string $outFile, string $gzFile): array
{
    $xml  = '<?xml version="1.0" encoding="UTF-8"?>' . PHP_EOL;
    $xml .= '<tv generator-info-name="tvmao-sync">' . PHP_EOL;

    foreach ($channels as $ch) {
        $id   = $ch['id'];
        $name = $ch['name'];
        if (!isset($store[$id]) || !$store[$id]) {
            continue;
        }
        $xml .= '  <channel id="' . xmltv_escape($id) . '">'
              . '<display-name lang="zh">' . xmltv_escape($name) . '</display-name>'
              . '</channel>' . PHP_EOL;
    }

    $count = 0;
    foreach ($channels as $ch) {
        $id = $ch['id'];
        if (!isset($store[$id])) {
            continue;
        }
        $dates = $store[$id];
        ksort($dates);
        foreach ($dates as $date => $rows) {
            // 跨零点的下一条，stop 可能是次日
            foreach ($rows as $row) {
                $start = str_replace('-', '', $date) . str_replace(':', '', $row['start']) . '00 +0800';
                $stop  = str_replace('-', '', $date) . str_replace(':', '', $row['end']) . '00 +0800';
                if ($row['end'] === '23:59') {
                    $stop = str_replace('-', '', $date) . '235900 +0800';
                }
                $xml .= '  <programme channel="' . xmltv_escape($id) . '"'
                      . ' start="' . $start . '" stop="' . $stop . '">'
                      . '<title lang="zh">' . xmltv_escape($row['title']) . '</title>'
                      . '</programme>' . PHP_EOL;
                $count++;
            }
        }
    }
    $xml .= '</tv>' . PHP_EOL;

    file_put_contents($outFile, $xml);
    file_put_contents($gzFile, gzencode($xml, 6));
    return ['programmes' => $count, 'bytes' => strlen($xml), 'gz_bytes' => filesize($gzFile)];
}

/* ============================ 主流程 ============================ */

function main(array $args): int
{
    $opt = parse_args($args);
    // 抓取窗口：过去 $past 天 + 今天 + 未来 $future 天（电视猫只有"本周 + 下周"两页，
    // 所以过去最多到本周一，更早的日期它也没有）
    if (isset($opt['days'])) {
        // 兼容老用法：--days=N 等价于 今天 + 未来 N-1 天
        $past   = 0;
        $future = max(0, min(13, (int)$opt['days'] - 1));
    } else {
        $past   = max(0, min(13, (int)($opt['past'] ?? 7)));
        $future = max(0, min(13, (int)($opt['future'] ?? 2)));
    }
    $outDir  = (string)($opt['out'] ?? BUILD_DIR);
    $dryRun  = isset($opt['dry-run']);
    $delayMs = (int)($opt['delay'] ?? 1200);
    $only    = isset($opt['only']) ? array_filter(array_map('trim', explode(',', (string)$opt['only']))) : [];
    $limit   = isset($opt['limit']) ? (int)$opt['limit'] : 0;

    $started = microtime(true);
    say('电视猫同步开始：' . date('Y-m-d H:i:s') . '，窗口 = 前 ' . $past . ' 天 + 今天 + 未来 ' . $future . ' 天');

    $channels = load_channels();
    if (!$channels) {
        say('频道表为空，退出');
        return 2;
    }
    if ($only) {
        $tmp = [];
        foreach ($channels as $ch) {
            if (in_array($ch['id'], $only, true) || in_array($ch['name'], $only, true)) {
                $tmp[] = $ch;
            }
        }
        $channels = $tmp;
        if (!$channels) {
            say('--only 没匹配到任何频道');
            return 2;
        }
    }
    if ($limit > 0) {
        $channels = array_slice($channels, 0, $limit);
    }

    // 输出目录准备：默认是构建目录 → 先把上一版数据整体拷过来（过去的日期不用再抓），
    // 别处指定（--out）则只建目录，不拷贝也不清空
    if ($outDir === BUILD_DIR) {
        if (!is_dir($outDir) && !mkdir($outDir, 0755, true)) {
            say('构建目录创建失败：' . $outDir);
            return 1;
        }
        if (is_dir(DATA_DIR)) {
            copy_dir(DATA_DIR, $outDir);
        }
    } elseif (!is_dir($outDir)) {
        if (!mkdir($outDir, 0755, true)) {
            say('输出目录创建失败：' . $outDir);
            return 1;
        }
    }

    // 今天是本周第几天（周一=1 … 周日=7），wN 里 N 就是这个序号；下周从 8 开始
    $today     = new DateTimeImmutable('today');
    $todayN    = (int)$today->format('N');
    $todayStr  = $today->format('Y-m-d');
    say('频道 ' . count($channels) . ' 个，今日 w 序号 = ' . $todayN . '，日期基准 ' . $todayStr);

    // 把"第几天"换算成电视猫的 wN 页码，并算出目标日期表
    $targets = [];   // 每项：offset / date / w / past
    for ($o = -$past; $o <= $future; $o++) {
        $w = $todayN + $o;
        if ($w < 1) {
            continue;                       // 电视猫没有上周的页面
        }
        if ($w > 14) {
            continue;                       // 超过下周最后一天
        }
        $targets[] = [
            'offset' => $o,
            'date'   => $today->modify(($o < 0 ? '' : '+') . $o . ' day')->format('Y-m-d'),
            'w'      => $w,
            'past'   => $o < 0,
        ];
    }
    $dates = array_column($targets, 'date');
    say('实际可抓 ' . count($targets) . ' 天：'
        . implode(', ', array_map(static function (array $t): string {
            return $t['date'] . '(w' . $t['w'] . ')';
        }, $targets)));
    if (count($targets) < $past + 1 + $future) {
        say('  ⚠️  电视猫只提供本周 + 下周，过去最多到今天所在周的周一（今天 w' . $todayN . '，故只有 ' . ($todayN - 1) . ' 天历史）');
    }

    // 清掉过期日期目录：只保留 [今天-retain, 今天+future]，别让盒子越攒越满
    $retain  = max(0, min(90, (int)($opt['retain'] ?? 14)));
    $minKeep = $today->modify('-' . $retain . ' day')->format('Y-m-d');
    $maxKeep = $today->modify('+' . $future . ' day')->format('Y-m-d');
    foreach (@scandir($outDir . '/json') ?: [] as $item) {
        if ($item === '.' || $item === '..') {
            continue;
        }
        $path = $outDir . '/json/' . $item;
        if (is_dir($path)) {
            if (!preg_match('~^\d{4}-\d{2}-\d{2}$~', $item) || $item < $minKeep || $item > $maxKeep) {
                rmrf($path);
            }
        } else {
            @unlink($path);                 // 快捷文件稍后重写
        }
    }

    $store   = [];   // [id][date] => [ ['time','title'], … ]
    $cached  = 0;    // 复用上一版数据的天数
    $stats   = ['pages' => 0, 'ok' => 0, 'fail' => 0, 'programmes' => 0];
    $missing = [];

    foreach ($channels as $ch) {
        $id     = $ch['id'];
        $fname  = safe_name($id);
        $got    = 0;
        foreach ($targets as $t) {
            $date = $t['date'];
            $w    = $t['w'];

            // 过去的日期抓过一次就不会再变，直接沿用上一版的结果
            if ($t['past']) {
                $old = $outDir . '/json/' . $date . '/' . $fname . '.json';
                if (is_file($old)) {
                    $got++;
                    $cached++;
                    continue;
                }
            }

            $url  = str_replace('{W}', (string)$w, (string)$ch['url']);
            $html = http_get($url);
            $stats['pages']++;
            if ($html === null) {
                $stats['fail']++;
                continue;
            }
            $rows = parse_schedule($html);
            if (!$rows) {
                $stats['fail']++;
                say("  [空页面] $id w$w 没有解析到节目");
                continue;
            }
            $stats['ok']++;
            foreach ($rows as $r) {
                // 页面自带 res 日期时以它为准，否则用 wN 推算的那天
                $d = $r['date'] ?? $date;
                $store[$id][$d][] = ['time' => $r['time'], 'title' => $r['title']];
            }
            $got++;
            usleep($delayMs * 1000);
        }
        if ($got === 0) {
            $missing[] = $id . '(' . $ch['name'] . ')';
        }
        say(sprintf('  %-10s %d/%d 天 完成', $ch['id'], $got, count($targets)));
    }

    // 组装：只写窗口内的日期（$targets 里的顺序即 前N天 → 今天 → 未来N天）
    $index      = [];
    $written    = 0;
    $perChannel = [];
    $datesPresent = [];

    foreach ($channels as $ch) {
        $id    = $ch['id'];
        $name  = $ch['name'];
        $icon  = (string)($ch['icon'] ?? '');
        $fname = safe_name($id);

        $rowsByDate = [];
        foreach ($targets as $t) {
            $date = $t['date'];
            $rows = [];
            if (!empty($store[$id][$date])) {
                $rows = to_rows($store[$id][$date]);
            }
            if (!$rows) {
                // 过去的日期本来就靠旧文件；今天/未来某一页抓失败时也退回旧文件，别把整天丢掉
                $rows = read_json_rows($outDir . '/json/' . $date . '/' . $fname . '.json');
            }
            if (!$rows) {
                continue;
            }
            $rowsByDate[$date] = $rows;
            $stats['programmes'] += count($rows);
            $datesPresent[$date] = true;

            if ($dryRun) {
                continue;
            }
            $dir = $outDir . '/json/' . $date;
            if (!is_dir($dir)) {
                mkdir($dir, 0755, true);
            }
            file_put_contents(
                $dir . '/' . $fname . '.json',
                json_encode(channel_json($date, $name, $icon, $rows), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
            );
            $written++;
        }

        if (!$rowsByDate) {
            continue;
        }

        // 电视猫没有"上周"的页面，但本地已经攒下的历史日期继续对外提供（超出保留期的早被清掉）
        foreach (@scandir($outDir . '/json') ?: [] as $d) {
            if (!preg_match('~^\d{4}-\d{2}-\d{2}$~', $d) || isset($rowsByDate[$d]) || $d > $todayStr || $d < $minKeep) {
                continue;
            }
            $rows = read_json_rows($outDir . '/json/' . $d . '/' . $fname . '.json');
            if (!$rows) {
                continue;
            }
            $rowsByDate[$d] = $rows;
            $datesPresent[$d] = true;
            $stats['programmes'] += count($rows);
        }
        ksort($rowsByDate);
        $perChannel[$id] = $rowsByDate;

        $perDate = [];
        foreach (array_keys($rowsByDate) as $date) {
            $perDate[$date] = 'json/' . $date . '/' . $fname . '.json';
        }
        $quick = $perDate[$todayStr] ?? reset($perDate);
        $index[] = [
            'id'    => $id,
            'name'  => $name,
            'icon'  => $icon,
            'json'  => $quick,
            'dates' => $perDate,
        ];

        if ($dryRun) {
            continue;
        }
        // 快捷路径 json/<频道>.json —— 优先今天的，没有就用窗口里最近的一天
        $qd = $todayStr;
        if (!isset($rowsByDate[$qd])) {
            $keys = array_keys($rowsByDate);
            $qd   = (string)end($keys);
        }
        file_put_contents(
            $outDir . '/json/' . $fname . '.json',
            json_encode(channel_json($qd, $name, $icon, $rowsByDate[$qd]), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
        );
        $written++;
    }

    $dates = array_keys($datesPresent);
    sort($dates);

    if ($dryRun) {
        say('--dry-run：解析完成，不落盘');
        say('  频道 ' . count($index) . ' / 节目 ' . $stats['programmes'] . ' / 日期 ' . implode(', ', $dates));
        return 0;
    }

    if (!$index) {
        say('全部频道都没抓到数据，保留旧数据，退出');
        return 3;
    }

    // 索引与元信息
    file_put_contents($outDir . '/channels.json', json_encode($index, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT));
    $names = [];
    foreach ($index as $item) {
        $names[] = $item['name'];
    }
    file_put_contents($outDir . '/channels.txt', implode("\n", $names) . "\n");
    file_put_contents($outDir . '/.nojekyll', '');

    $xmlInfo = build_xmltv($index, $perChannel, $outDir . '/pp.xml', $outDir . '/pp.xml.gz');

    $meta = [
        'updated_utc'      => gmdate('Y-m-d\TH:i:s\Z'),
        'updated_cst'      => date('Y-m-d H:i:s'),
        'source'           => BASE_URL . '/program_satellite/',
        'source_domain'    => 'tvmao.com',
        'window'           => ['past' => $past, 'today' => $todayStr, 'future' => $future],
        'channels'         => count($index),
        'programmes'       => $stats['programmes'],
        'dates'            => $dates,
        'pp_xml_bytes'     => $xmlInfo['bytes'],
        'pp_xml_gz_bytes'  => $xmlInfo['gz_bytes'],
        'pages'            => ['total' => $stats['pages'], 'ok' => $stats['ok'], 'fail' => $stats['fail']],
        'pages_cached'     => $cached,
        'channels_empty'   => $missing,
        'json_files'       => $written,
        'build_seconds'    => round(microtime(true) - $started, 1),
    ];
    file_put_contents($outDir . '/meta.json', json_encode($meta, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));

    say('写出 JSON ' . $written . ' 个 / 节目 ' . $stats['programmes'] . ' 条 / 日期 ' . implode(', ', $dates)
        . '（本轮请求 ' . $stats['pages'] . ' 页，复用旧数据 ' . $cached . ' 天）');
    say('XMLTV ' . round($xmlInfo['bytes'] / 1048576, 2) . ' MB（gz ' . round($xmlInfo['gz_bytes'] / 1024) . ' KB）');
    if ($missing) {
        say('这些频道一条都没抓到：' . implode(', ', $missing));
    }

    if ($outDir === BUILD_DIR) {
        swap_into_place($outDir);
    }
    say('完成，用时 ' . $meta['build_seconds'] . ' 秒');
    return 0;
}

/** 原子切换：data 目录整体替换，避免电视端读到写了一半的数据 */
function swap_into_place(string $buildDir): void
{
    $old = DATA_DIR . '.old';
    if (is_dir($old)) {
        rmrf($old);
    }
    if (is_dir(DATA_DIR)) {
        if (!rename(DATA_DIR, $old)) {
            say('旧数据目录改名失败，跳过切换');
            return;
        }
    }
    if (!rename($buildDir, DATA_DIR)) {
        say('新数据目录切换失败，回滚');
        if (is_dir($old)) {
            rename($old, DATA_DIR);
        }
        return;
    }
    if (is_dir($old)) {
        rmrf($old);
    }
    say('数据已切换：' . DATA_DIR);
}

function rmrf(string $path): void
{
    if (is_dir($path) && !is_link($path)) {
        foreach (scandir($path) ?: [] as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            rmrf($path . '/' . $item);
        }
        @rmdir($path);
        return;
    }
    @unlink($path);
}

/** 递归拷贝目录（用于把上一版数据搬进构建目录，过去的日期不用重抓） */
function copy_dir(string $src, string $dst): void
{
    if (!is_dir($src)) {
        return;
    }
    if (!is_dir($dst)) {
        @mkdir($dst, 0755, true);
    }
    foreach (scandir($src) ?: [] as $item) {
        if ($item === '.' || $item === '..') {
            continue;
        }
        $s = $src . '/' . $item;
        $d = $dst . '/' . $item;
        if (is_dir($s) && !is_link($s)) {
            copy_dir($s, $d);
        } elseif (is_file($s)) {
            @copy($s, $d);
        }
    }
}

/** 读一个已落盘的频道 JSON，取回 rows（形状与 to_rows 一致） */
function read_json_rows(string $file): array
{
    if (!is_file($file)) {
        return [];
    }
    $raw = @file_get_contents($file);
    $j   = json_decode((string)$raw, true);
    if (!is_array($j) || empty($j['epg_data']) || !is_array($j['epg_data'])) {
        return [];
    }
    $rows = [];
    foreach ($j['epg_data'] as $r) {
        if (empty($r['start']) || empty($r['title'])) {
            continue;
        }
        $rows[] = [
            'start' => (string)$r['start'],
            'end'   => (string)($r['end'] ?? $r['start']),
            'title' => (string)$r['title'],
            'desc'  => (string)($r['desc'] ?? ''),
        ];
    }
    return $rows;
}

function load_channels(): array
{
    if (!is_file(CHANNEL_FILE)) {
        say('找不到频道表：' . CHANNEL_FILE);
        return [];
    }
    $raw = file_get_contents(CHANNEL_FILE);
    $list = json_decode((string)$raw, true);
    if (!is_array($list)) {
        say('频道表 JSON 解析失败：' . CHANNEL_FILE);
        return [];
    }
    $out = [];
    foreach ($list as $item) {
        if (!is_array($item) || empty($item['id']) || empty($item['url'])) {
            continue;
        }
        $out[] = [
            'id'   => (string)$item['id'],
            'name' => (string)($item['name'] ?? $item['id']),
            'url'  => (string)$item['url'],
            'icon' => (string)($item['icon'] ?? ''),
        ];
    }
    return $out;
}

function parse_args(array $argv): array
{
    $opt = [];
    foreach (array_slice($argv, 1) as $arg) {
        if (preg_match('~^--([a-z-]+)(?:=(.*))?$~i', $arg, $m)) {
            $opt[strtolower($m[1])] = $m[2] ?? '1';
        }
    }
    return $opt;
}

/* ============================ 入口 ============================ */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    echo "CLI only\n";
    exit;
}

$lock = fopen(LOCK_FILE, 'c');
if ($lock === false || !flock($lock, LOCK_EX | LOCK_NB)) {
    say('已有一个同步进程在跑，退出');
    exit(0);
}

try {
    $code = main($argv);
} catch (Throwable $e) {
    say('异常：' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
    $code = 1;
}

flock($lock, LOCK_UN);
fclose($lock);
exit($code);
