<?php
/**
 * 电视猫 EPG 盒子端 —— 网页接口配置
 * 只改这里，不要把域名写进代码（内网穿透域名随时可换）
 */

// 数据目录（tvmao_sync.php 写出的目录，两者必须一致）
define('EPG_DATA_DIR', '/var/www/iptv/data');

// 时区
define('EPG_TZ', 'Asia/Shanghai');

// 成功响应的浏览器缓存秒数（数据每 12 小时才变，60 秒足够）
define('EPG_CACHE_SECONDS', 60);
