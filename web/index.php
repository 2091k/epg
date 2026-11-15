<?php
/**
 * 电视猫 EPG 盒子端 —— 只读接口
 *
 * 路由：
 *   /?ch=CCTV1                  今天（等价于 112114 的 /?ch=）
 *   /?ch=CCTV1&date=2026-11-12  指定日期（112114 没有的能力）
 *   /?ch=CCTV-1、?ch=湖南卫视高清  别名自动命中
 *   /channels                   频道清单（一行一个名字）
 *   /status                     运行状态（数据条数、上次更新时间）
 *   /pp.xml、/pp.xml.gz         全量 XMLTV（nginx 直接吐，走不到这里也能用）
 *
 * 数据全部来自本地磁盘，访问时**不回源电视猫**，也不触发任何抓取。
 */

require __DIR__ . '/config.php';

date_default_timezone_set(defined('EPG_TZ') ? EPG_TZ : 'Asia/Shanghai');

$DATA_DIR = defined('EPG_DATA_DIR') ? rtrim(EPG_DATA_DIR, '/') : __DIR__ . '/data';
$CACHE    = defined('EPG_CACHE_SECONDS') ? (int)EPG_CACHE_SECONDS : 60;

/** 输出 JSON 并结束 */
function json_out($payload, $code = 200, $cache = 0)
{
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    header('Access-Control-Allow-Origin: *');
    if ($cache > 0 && $code === 200) {
        header('Cache-Control: public, max-age=' . $cache);
    }
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

/** 频道索引（channels.json：id / name / icon / json / dates） */
function channel_index($dataDir)
{
    $file = $dataDir . '/channels.json';
    if (!is_file($file)) {
        return array();
    }
    $raw = json_decode((string)@file_get_contents($file), true);
    return is_array($raw) ? $raw : array();
}

/** 归一化：大写、去空格/连字符/符号、去清晰度后缀，便于别名匹配 */
function norm($s)
{
    $s = mb_strtoupper(trim((string)$s), 'UTF-8');
    $s = str_replace(array('＋', '+'), 'PLUS', $s);
    foreach (array('高清', '超清', '标清', '蓝光', '蓝光高清', 'HD', 'UHD', '4K', '8K', '频道') as $word) {
        $s = str_replace($word, '', $s);
    }
    return preg_replace('/[^0-9A-Z\p{Han}]+/u', '', $s);
}

/**
 * 把 TV 端传来的 ch 解析成频道索引条目
 * 支持：频道 id、频道中文名、加/不加高清后缀、CCTV-1 与 CCTV1 互换、CCTV5+ 与 CCTV5-PLUS
 */
function resolve_channel(array $index, $ch)
{
    $ch = trim((string)$ch);
    if ($ch === '' || !$index) {
        return null;
    }

    // 1) id 精确（大小写不敏感）
    foreach ($index as $item) {
        if (strcasecmp((string)$item['id'], $ch) === 0) {
            return $item;
        }
    }
    // 2) 中文名精确
    foreach ($index as $item) {
        if (isset($item['name']) && (string)$item['name'] === $ch) {
            return $item;
        }
    }

    $n = norm($ch);

    // 3) 归一化后相等
    foreach ($index as $item) {
        if (norm($item['id']) === $n || (isset($item['name']) && norm($item['name']) === $n)) {
            return $item;
        }
    }

    // 4) CCTV 数字/PLUS/OLY 形式：CCTV1 与 CCTV-1 综合、CCTV16 与 CCTV-15 音乐 之类
    if (preg_match('/^CCTV([0-9]{1,2})(PLUS|OLY)?$/', $n, $m)) {
        $digit = $m[1];
        $suffix = isset($m[2]) ? $m[2] : '';
        $best = null;
        foreach ($index as $item) {
            $cand = isset($item['name']) ? norm($item['name']) : norm($item['id']);
            if (!preg_match('/^CCTV' . $digit . '(PLUS|OLY)?([^\d]|$)/', $cand, $mm)) {
                continue;
            }
            $csuffix = isset($mm[1]) ? $mm[1] : '';
            if ($csuffix === $suffix) {
                return $item;                       // 完全对上，最优
            }
            if ($best === null) {
                $best = $item;
            }
        }
        if ($best !== null) {
            return $best;
        }
    }

    // 5) 归一化后互相包含（如「湖南卫视」对「湖南卫视高清」已被 3 处理，这里兜底长名）
    if (mb_strlen($n, 'UTF-8') >= 2) {
        foreach ($index as $item) {
            $cand = isset($item['name']) ? norm($item['name']) : norm($item['id']);
            if ($cand !== '' && (mb_strpos($cand, $n, 0, 'UTF-8') !== false || mb_strpos($n, $cand, 0, 'UTF-8') !== false)) {
                return $item;
            }
        }
    }

    return null;
}

/** 读某个频道的节目（$date 为空＝今天那条快捷路径） */
function read_programmes($dataDir, array $entry, $date)
{
    if ($date !== '') {
        if (!isset($entry['dates'][$date])) {
            return null;
        }
        $rel = (string)$entry['dates'][$date];
    } else {
        $rel = isset($entry['json']) ? (string)$entry['json'] : '';
    }
    if ($rel === '') {
        return null;
    }
    $file = $dataDir . '/' . ltrim($rel, '/');
    if (!is_file($file)) {
        return null;
    }
    $raw = json_decode((string)@file_get_contents($file), true);
    return is_array($raw) ? $raw : null;
}

$path  = parse_url(isset($_SERVER['REQUEST_URI']) ? $_SERVER['REQUEST_URI'] : '/', PHP_URL_PATH);
$path  = '/' . trim((string)$path, '/');

// ---------- /status ----------
if ($path === '/status') {
    $metaFile = $DATA_DIR . '/meta.json';
    $meta = is_file($metaFile) ? json_decode((string)@file_get_contents($metaFile), true) : null;
    if (!is_array($meta)) {
        json_out(array('ok' => false, 'error' => 'no_data', 'hint' => '还没跑过抓取，或 ' . $DATA_DIR . ' 为空'));
    }
    $stamp  = isset($meta['updated_cst']) ? strtotime($meta['updated_cst']) : 0;
    $meta['ok'] = true;
    $meta['service'] = 'tvmao-box';
    $meta['data_age_minutes'] = $stamp ? (int)floor((time() - $stamp) / 60) : null;
    json_out($meta, 200, 0);
}

// ---------- /channels ----------
if ($path === '/channels' || $path === '/channels.txt') {
    $rows = array();
    foreach (channel_index($DATA_DIR) as $item) {
        $rows[] = isset($item['name']) && $item['name'] !== '' ? $item['name'] : $item['id'];
    }
    if (!$rows) {
        json_out(array('ok' => false, 'error' => 'no_data'), 503);
    }
    header('Content-Type: text/plain; charset=utf-8');
    header('Cache-Control: public, max-age=600');
    echo implode("\n", $rows) . "\n";
    exit;
}

// ---------- /pp.xml、/pp.xml.gz ----------
if ($path === '/pp.xml' || $path === '/pp.xml.gz') {
    $file = $DATA_DIR . $path;
    if (!is_file($file)) {
        json_out(array('ok' => false, 'error' => 'no_data', 'file' => $path), 404);
    }
    header('Content-Type: ' . ($path === '/pp.xml.gz' ? 'application/gzip' : 'application/xml; charset=utf-8'));
    header('Content-Length: ' . filesize($file));
    header('Cache-Control: public, max-age=600');
    readfile($file);
    exit;
}

// ---------- 根路径：/?ch= ----------
$ch     = isset($_GET['ch']) ? (string)$_GET['ch'] : '';
$date   = isset($_GET['date']) ? trim((string)$_GET['date']) : '';
$format = isset($_GET['format']) ? strtolower((string)$_GET['format']) : '';

/** 首页：没有 ch、又不是明确要 JSON 时，给人看的网页 */
function html_home($dataDir)
{
    $index = channel_index($dataDir);
    $meta  = is_file($dataDir . '/meta.json') ? json_decode((string)@file_get_contents($dataDir . '/meta.json'), true) : null;
    if (!is_array($meta)) {
        $meta = array();
    }

    $today = date('Y-m-d');
    $dates = isset($meta['dates']) && is_array($meta['dates']) ? $meta['dates'] : array();
    if (!$dates) {
        foreach ($index as $item) {
            foreach (isset($item['dates']) ? (array)$item['dates'] : array() as $d => $_) {
                $dates[] = $d;
            }
        }
        $dates = array_values(array_unique($dates));
        sort($dates);
    }

    $cctv = array();
    $sat  = array();
    foreach ($index as $item) {
        $label = isset($item['name']) && $item['name'] !== '' ? $item['name'] : $item['id'];
        $isCctv = stripos($label, 'CCTV') === 0;
        if ($isCctv) {
            $cctv[] = $item;
        } else {
            $sat[] = $item;
        }
    }

    $updated = isset($meta['updated_cst']) ? $meta['updated_cst'] : '—';
    $channels = isset($meta['channels']) ? (int)$meta['channels'] : count($index);
    $progs = isset($meta['programmes']) ? (int)$meta['programmes'] : 0;
    $win = isset($meta['window']) && is_array($meta['window']) ? $meta['window'] : array();
    $winText = sprintf('前 %d 天 + 今天 + 未来 %d 天', isset($win['past']) ? (int)$win['past'] : 7, isset($win['future']) ? (int)$win['future'] : 2);

    $h = function ($s) {
        return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
    };

    header('Content-Type: text/html; charset=utf-8');
    header('Cache-Control: no-cache');

    echo '<!doctype html><html lang="zh-CN"><head><meta charset="utf-8">'
        . '<meta name="viewport" content="width=device-width,initial-scale=1">'
        . '<title>电视猫 EPG · 央视 + 卫视节目单</title><style>'
        . ':root{--bg:#0f1216;--card:#171c22;--line:#262d36;--fg:#e6edf3;--dim:#93a1b1;--acc:#3ea6ff}'
        . '*{box-sizing:border-box}body{margin:0;background:var(--bg);color:var(--fg);font:14px/1.6 -apple-system,BlinkMacSystemFont,"Segoe UI","Noto Sans SC",sans-serif}'
        . 'header{padding:26px 20px 10px;max-width:1180px;margin:0 auto}'
        . 'h1{margin:0;font-size:22px;font-weight:600;letter-spacing:.5px}'
        . '.sub{color:var(--dim);margin:6px 0 0;font-size:13px}'
        . '.cards{display:grid;grid-template-columns:repeat(auto-fit,minmax(150px,1fr));gap:10px;max-width:1180px;margin:18px auto 0;padding:0 20px}'
        . '.card{background:var(--card);border:1px solid var(--line);border-radius:10px;padding:12px 14px}'
        . '.card b{display:block;font-size:20px;color:var(--acc);font-weight:600}'
        . '.card span{color:var(--dim);font-size:12px}'
        . 'section{max-width:1180px;margin:22px auto 0;padding:0 20px}'
        . 'h2{font-size:15px;font-weight:600;margin:0 0 10px;color:var(--fg)}h2 em{color:var(--dim);font-style:normal;font-weight:400;font-size:12px;margin-left:6px}'
        . '.dates{display:flex;flex-wrap:wrap;gap:8px}'
        . '.dates button{background:var(--card);border:1px solid var(--line);color:var(--fg);border-radius:20px;padding:6px 14px;font-size:13px;cursor:pointer}'
        . '.dates button.on{border-color:var(--acc);color:var(--acc)}'
        . '.grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(132px,1fr));gap:8px}'
        . '.ch{display:block;background:var(--card);border:1px solid var(--line);border-radius:8px;padding:9px 12px;color:var(--fg);text-decoration:none;font-size:13px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}'
        . '.ch:hover{border-color:var(--acc);color:var(--acc)}'
        . 'footer{max-width:1180px;margin:26px auto 40px;padding:16px 20px;border-top:1px solid var(--line);color:var(--dim);font-size:12px}'
        . 'footer a{color:var(--acc);text-decoration:none}footer code{color:var(--fg)}'
        . '</style></head><body>';

    echo '<header><h1>电视猫 EPG · 央视 + 卫视</h1>'
        . '<p class="sub">盒子本地抓取，磁盘缓存，访问不回源 · 每天 00:17 / 12:17 自动更新</div></p></header>';

    echo '<div class="cards">'
        . '<div class="card"><b>' . $channels . '</b><span>频道（央视 ' . count($cctv) . ' + 卫视 ' . count($sat) . '）</span></div>'
        . '<div class="card"><b>' . number_format($progs) . '</b><span>节目条数</span></div>'
        . '<div class="card"><b>' . count($dates) . '</b><span>覆盖天数</span></div>'
        . '<div class="card"><b>' . $h($updated) . '</b><span>上次更新（' . $h($winText) . '）</span></div>'
        . '</div>';

    if ($dates) {
        echo '<section><h2>选择日期<em>点日期再点频道，或用 ?date=YYYY-MM-DD</em></h2><div class="dates" id="dates">';
        foreach ($dates as $d) {
            $label = $d;
            if ($d === $today) {
                $label .= ' 今天';
            } elseif ($d === date('Y-m-d', strtotime('+1 day'))) {
                $label .= ' 明天';
            }
            echo '<button type="button" data-date="' . $h($d) . '"' . ($d === $today ? ' class="on"' : '') . '>' . $h($label) . '</button>';
        }
        echo '</div></section>';
    }

    $renderGroup = function ($title, array $items, $h) {
        if (!$items) {
            return;
        }
        echo '<section><h2>' . $h($title) . '<em>' . count($items) . ' 个</em></h2><div class="grid">';
        foreach ($items as $item) {
            $label = isset($item['name']) && $item['name'] !== '' ? $item['name'] : $item['id'];
            echo '<a class="ch" data-id="' . $h($item['id']) . '" href="/?ch=' . rawurlencode($item['id']) . '" title="' . $h($item['id']) . '">' . $h($label) . '</a>';
        }
        echo '</div></section>';
    };
    $renderGroup('央视', $cctv, $h);
    $renderGroup('卫视', $sat, $h);

    echo '<footer>接口：<code>/?ch=频道名或id[&amp;date=YYYY-MM-DD]</code> · '
        . '<a href="/status">/status</a> · <a href="/channels">/channels</a> · '
        . '<a href="/pp.xml.gz">/pp.xml.gz</a>（XMLTV，DIYP/电视家类可直接填）<br>'
        . '数据源 电视猫，本地目录 <code>' . $h($dataDir) . '</code>，TV 端 EPG 地址填本域名根路径即可。</footer>'
        . '<script>(function(){var cur=document.getElementById("dates");if(!cur)return;'
        . 'var today=' . json_encode($today) . ';var chosen=today;'
        . 'function apply(){document.querySelectorAll("a.ch").forEach(function(a){var id=a.getAttribute("data-id");'
        . 'a.href="/?ch="+encodeURIComponent(id)+(chosen&&chosen!==today?"&date="+chosen:"");});}'
        . 'cur.addEventListener("click",function(e){var t=e.target.closest("button");if(!t)return;'
        . 'cur.querySelectorAll("button").forEach(function(b){b.classList.remove("on");});t.classList.add("on");'
        . 'chosen=t.getAttribute("data-date");apply();});apply();})();</script>'
        . '</body></html>';
    exit;
}

if ($ch === '' && ($format === 'json' || (isset($_SERVER['HTTP_ACCEPT']) && strpos((string)$_SERVER['HTTP_ACCEPT'], 'application/json') !== false))) {
    $index = channel_index($DATA_DIR);
    json_out(array(
        'service'  => 'tvmao-box',
        'usage'    => '/?ch=频道名或id[&date=YYYY-MM-DD]',
        'status'   => '/status',
        'channels' => '/channels',
        'xmltv'    => '/pp.xml.gz',
        'channels_total' => count($index),
    ), 200, 0);
}

if ($ch === '') {
    html_home($DATA_DIR);
}

if ($date !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
    json_out(array('error' => 'bad_date', 'hint' => '日期格式必须是 YYYY-MM-DD', 'ch' => $ch), 400);
}

$index = channel_index($DATA_DIR);
if (!$index) {
    json_out(array('error' => 'no_data', 'hint' => $DATA_DIR . '/channels.json 还没有生成，先跑一次抓取'), 503);
}

$entry = resolve_channel($index, $ch);
if ($entry === null) {
    json_out(array(
        'date'          => $date !== '' ? $date : date('Y-m-d'),
        'channel_name'  => $ch,
        'url'           => '',
        'epg_data'      => array(),
        'error'         => 'channel_not_found',
        'channels_total' => count($index),
    ), 404);
}

$prog = read_programmes($DATA_DIR, $entry, $date);

if ($prog === null) {
    // 这台这天没数据：跟 112114 不同，这里明确告诉 TV 端，别当成空表缓存
    json_out(array(
        'date'         => $date !== '' ? $date : date('Y-m-d'),
        'channel_name' => isset($entry['name']) && $entry['name'] !== '' ? $entry['name'] : $entry['id'],
        'channel_id'   => $entry['id'],
        'url'          => '',
        'epg_data'     => array(),
        'error'        => 'no_data_for_date',
        'available_dates' => array_keys(isset($entry['dates']) ? (array)$entry['dates'] : array()),
    ), 200, 0);
}

$rows = isset($prog['epg_data']) && is_array($prog['epg_data']) ? $prog['epg_data'] : array();
$prog['epg_data']    = $rows;
$prog['channel_id']  = $entry['id'];
$prog['channel_name'] = isset($prog['channel_name']) && $prog['channel_name'] !== ''
    ? $prog['channel_name']
    : (isset($entry['name']) ? $entry['name'] : $entry['id']);
$prog['source']      = 'tvmao';
json_out($prog, 200, $CACHE);
