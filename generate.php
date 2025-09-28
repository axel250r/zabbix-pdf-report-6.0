<?php
declare(strict_types=1);

session_start();

// Validar token CSRF al principio de todo
if (empty($_POST['csrf_token']) || empty($_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {
    http_response_code(403);
    unset($_SESSION['csrf_token']);
    die('Error: Petici�n inv�lida o token CSRF incorrecto. Por favor, recargue la p�gina anterior y vuelva a intentarlo.');
}
// Destruir el token despu�s de un uso exitoso
unset($_SESSION['csrf_token']);

if (empty($_SESSION['zbx_auth_ok'])) {
    http_response_code(403);
    header('Content-Type: text/html; charset=UTF-8');
    echo 'Sesi�n inv�lida';
    exit;
}
require_once __DIR__ . '/lib/i18n.php';
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/lib/PdfBuilder.php';
require_once __DIR__ . '/lib/ZabbixApi.php';

if (!defined('TMP_DIR'))            define('TMP_DIR', __DIR__ . '/tmp');
if (!defined('CHART_WIDTH'))        define('CHART_WIDTH', 1600);
if (!defined('CHART_HEIGHT'))       define('CHART_HEIGHT', 400);
if (!defined('ZABBIX_TZ'))          define('ZABBIX_TZ', 'America/Santiago');
if (!defined('GEN_DEBUG_TRACE'))    define('GEN_DEBUG_TRACE', false);

@is_dir(TMP_DIR) || @mkdir(TMP_DIR, 0777, true);
mb_internal_encoding('UTF-8');

header('Content-Type: text/html; charset=UTF-8');

function ztrace($msg) { if (!GEN_DEBUG_TRACE) return; @file_put_contents(TMP_DIR.'/generate_trace.log', '['.date('Y-m-d H:i:s')."] $msg\n", FILE_APPEND); }
function dump_save($name, $content, $ext='txt'){ if (!GEN_DEBUG_TRACE) return; @file_put_contents(TMP_DIR."/dump_{$name}_".date('His').".$ext", $content); }

function array_flat($v) {
    $out = [];
    $stack = is_array($v) ? $v : [$v];
    foreach ($stack as $x) {
        if ($x === null || $x === '') continue;
        if (is_array($x)) {
            foreach ($x as $y) $out[] = $y;
        } else {
            $out[] = $x;
        }
    }
    return $out;
}

function collect_values(array $names) {
    $vals = [];
    foreach ($names as $k) {
        if (isset($_POST[$k])) {
            $v = $_POST[$k];
        } elseif (isset($_REQUEST[$k])) {
            $v = $_REQUEST[$k];
        } else {
            continue;
        }
        if (is_string($v)) {
            $v = trim($v);
            if ($v === '') continue;
            if ($v[0] === '[') {
                $tmp = json_decode($v, true);
                if (is_array($tmp)) $v = $tmp;
            }
        }
        $vals = array_merge($vals, array_flat($v));
    }

    $out = [];
    foreach ($vals as $x) {
        if ($x === null) continue;
        if (is_string($x)) {
            $x = trim($x);
            if ($x === '') continue;
            if (strpos($x, ',') !== false || strpos($x, "\n") !== false || strpos($x, "\r") !== false) {
                $parts = preg_split('/[,\r\n]+/', $x);
                foreach ($parts as $p) { $p = trim($p); if ($p !== '') $out[] = $p; }
                continue;
            }
        }
        $out[] = $x;
    }

    $out2 = [];
    foreach ($out as $x) {
        if (is_scalar($x)) {
            $out2[] = (string)$x;
        }
    }
    return $out2;
}

function web_login_cookiejar($baseUrl, $user, $pass, $cookieJar) {
    $base = rtrim($baseUrl, '/');
    $opt = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS      => 10,
        CURLOPT_COOKIEJAR      => $cookieJar,
        CURLOPT_COOKIEFILE     => $cookieJar,
        CURLOPT_USERAGENT      => 'Mozilla/5.0',
        CURLOPT_HTTPHEADER     => ['Accept: text/html, */*'],
        CURLOPT_TIMEOUT        => 30,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => false,
    ];
    $ch = curl_init($base . '/index.php');
    curl_setopt_array($ch, $opt);
    $html = curl_exec($ch);
    curl_close($ch);
    $sid = null;
    if (is_string($html) && preg_match('/name="sid"\s+value="([^"]+)"/i', $html, $m)) $sid = $m[1];
    $posts = [
        ['url' => $base . '/index.php', 'data' => ['name'=>$user, 'password'=>$pass, 'enter'=>1]],
        ['url' => $base . '/login.php', 'data' => ['name'=>$user, 'password'=>$pass, 'autologin'=>1]],
    ];
    if ($sid) { $posts[0]['data']['sid'] = $sid; $posts[1]['data']['sid'] = $sid; }
    foreach ($posts as $p) {
        $ch = curl_init($p['url']);
        curl_setopt_array($ch, $opt + [
            CURLOPT_POST       => true,
            CURLOPT_POSTFIELDS => http_build_query($p['data'], '', '&', PHP_QUERY_RFC3986),
            CURLOPT_REFERER    => $p['url'],
        ]);
        $resp = curl_exec($ch);
        $err  = curl_error($ch);
        curl_close($ch);
        if ($err) ztrace('web login curl err: '.$err);
        $cj = @file_get_contents($cookieJar);
        if ($cj && preg_match('/\tzbx_session/', $cj)) return true;
    }
    return false;
}

function fetch_chart_png($cookieJar, $url, &$ctOut = null) {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS      => 10,
        CURLOPT_COOKIEFILE     => $cookieJar,
        CURLOPT_COOKIEJAR      => $cookieJar,
        CURLOPT_USERAGENT      => 'Mozilla/5.0',
        CURLOPT_HTTPHEADER     => ['Accept: */*'],
        CURLOPT_TIMEOUT        => 60,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => false,
    ]);
    $bin = curl_exec($ch);
    $ct  = curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
    $hc  = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $er  = curl_error($ch);
    curl_close($ch);
    $ctOut = $ct;
    ztrace("fetch_chart_png hc=$hc ct=$ct er=" . ($er ?: 'none'));
    if ($er || $hc >= 400) return null;
    return $bin;
}

function buildItemChartEntry($hostName, $itemid, $title, $from_text, $to_text, $cookieJar) {
    $base = rtrim(ZABBIX_URL, '/');
    $q1 = [
        'from'       => $from_text,
        'to'         => $to_text,
        'itemids'    => [$itemid],
        'type'       => 0,
        'profileIdx' => 'web.item.graph.filter',
        'profileIdx2'=> $itemid,
        'width'      => CHART_WIDTH,
        'height'     => CHART_HEIGHT,
    ];
    $url1 = $base . '/chart.php?' . http_build_query($q1, '', '&', PHP_QUERY_RFC3986);
    $ct = null; $png = fetch_chart_png($cookieJar, $url1, $ct);
    if ($png && strlen($png) > 12000) {
        $f = TMP_DIR . '/zbx_g_' . uniqid('', true) . '.png';
        file_put_contents($f, $png);
        return ['title' => $hostName . ' - ' . $title, 'png' => $f];
    }
    $dur = max(300, abs(strtotime($to_text) - strtotime($from_text)));
    $q2 = [
        'from'       => 'now-' . $dur . 's',
        'to'         => 'now',
        'itemids'    => [$itemid],
        'type'       => 0,
        'profileIdx' => 'web.item.graph.filter',
        'profileIdx2'=> $itemid,
        'width'      => CHART_WIDTH,
        'height'     => CHART_HEIGHT,
    ];
    $url2 = $base . '/chart.php?' . http_build_query($q2, '', '&', PHP_QUERY_RFC3986);
    $png2 = fetch_chart_png($cookieJar, $url2, $ct);
    if ($png2 && strlen($png2) > 12000) {
        $f = TMP_DIR . '/zbx_g_' . uniqid('', true) . '.png';
        file_put_contents($f, $png2);
        return ['title' => $hostName . ' - ' . $title, 'png' => $f];
    }
    ztrace("buildItemChartEntry: sin PNG v�lido para item=$itemid host=$hostName");
    return null;
}

// === Inputs robustos ===
$WEB_USER = isset($_SESSION['zbx_user']) ? (string)$_SESSION['zbx_user'] : null;
$WEB_PASS = isset($_SESSION['zbx_pass']) ? (string)$_SESSION['zbx_pass'] : null;

$input_host_ids = collect_values(['hostids', 'host_ids', 'hosts_ids', 'hostsId', 'host_ids[]', 'hostids[]', 'host_id', 'hosts_id']);
$input_host_names = collect_values(['hosts', 'host_names', 'hostnames', 'hosts_names', 'hosts[]']);
$input_hg_ids = collect_values(['hostgroupids', 'groupids', 'hostgroups', 'host_groups', 'hostgroups_ids', 'groupids[]']);
$input_item_keys_raw = collect_values(['items_keys', 'item_keys', 'items', 'items[]', 'keys', 'keys[]', 'itemsKeys', 'template_and_items_txt']);
$input_item_ids = collect_values(['itemids', 'items_id', 'item_ids', 'itemids[]']);

$input_item_keys = [];
foreach ($input_item_keys_raw as $raw_input) {
    if (strpos($raw_input, '| Items:') !== false) {
        $parts = explode('| Items:', $raw_input);
        if (isset($parts[1])) {
            $item_names_str = trim($parts[1]);
            $item_names = explode(',', $item_names_str);
            foreach ($item_names as $name) {
                $trimmed_name = trim($name);
                if ($trimmed_name !== '') {
                    $input_item_keys[] = $trimmed_name;
                }
            }
        }
    } else {
        $input_item_keys[] = $raw_input;
    }
}
$input_item_keys = array_unique($input_item_keys);


if (empty($input_host_ids) && !empty($input_host_names)) {
    $allNum = true;
    foreach ($input_host_names as $v) { if (!ctype_digit($v)) { $allNum = false; break; } }
    if ($allNum) { $input_host_ids = $input_host_names; $input_host_names = []; }
}

if ((empty($input_host_ids) && empty($input_host_names) && empty($input_hg_ids)) || (empty($input_item_keys) && empty($input_item_ids))) {
    http_response_code(400);
    echo "<h3>Error</h3><pre>" . t('generate_invalid_input') . "</pre>";
    if (GEN_DEBUG_TRACE) {
        ztrace('DEBUG INPUT hostids='.json_encode($input_host_ids).' hostnames='.json_encode($input_host_names).' hgids='.json_encode($input_hg_ids).' itemkeys='.json_encode($input_item_keys).' itemids='.json_encode($input_item_ids));
    }
    exit;
}

$from_dt  = isset($_POST['from_dt'])   ? (string)$_POST['from_dt']   : '';
$to_dt    = isset($_POST['to_dt'])     ? (string)$_POST['to_dt']     : '';
$clientTz = isset($_POST['client_tz']) ? (string)$_POST['client_tz'] : '';
$abs = ($from_dt !== '' && $to_dt !== '');
$tzClient = $clientTz ? new DateTimeZone($clientTz) : new DateTimeZone(ZABBIX_TZ);
$tzZbx    = new DateTimeZone(ZABBIX_TZ);
if (!$abs) {
    $period   = 24 * 3600; // Por defecto 24h si no se especifica
    $to_ts    = time();
    $from_ts  = $to_ts - $period;
} else {
    $from_obj = DateTime::createFromFormat('Y-m-d\TH:i', str_replace(' ', 'T', $from_dt), $tzClient);
    $to_obj   = DateTime::createFromFormat('Y-m-d\TH:i', str_replace(' ', 'T', 'Zabbix PDF Report'),   $tzClient);
    if (!$from_obj) $from_obj = new DateTime($from_dt, $tzClient);
    if (!$to_obj)   $to_obj   = new DateTime($to_dt,   $tzClient);
    $from_obj->setTimezone($tzZbx);
    $to_obj  ->setTimezone($tzZbx);
    $from_ts = $from_obj->getTimestamp();
    $to_ts   = $to_obj->getTimestamp();
    if ($to_ts <= $from_ts) { http_response_code(400); echo "<h3>Error</h3><pre>" . t('generate_invalid_range') . "</pre>"; exit; }
}
$from_text = (new DateTime('@' . $from_ts))->setTimezone($tzZbx)->format('Y-m-d H:i:s');
$to_text   = (new DateTime('@' . $to_ts))  ->setTimezone($tzZbx)->format('Y-m-d H:i:s');
ztrace("RANGO: $from_text -> $to_text (TZ=" . ZABBIX_TZ . ")");

try {
    $api = new ZabbixApi(ZABBIX_API_URL, (string)$_SESSION['zbx_user'], (string)$_SESSION['zbx_pass']);
} catch (Throwable $e) {
    http_response_code(500);
    echo "<h3>Error</h3><pre>No se pudo inicializar la API: " . htmlspecialchars($e->getMessage()) . "</pre>";
    exit;
}

$hostids = [];
if (!empty($input_host_ids)) {
    foreach ($input_host_ids as $id) if (ctype_digit($id)) $hostids[] = (string)$id;
}
if (!empty($input_host_names)) {
    $mapByName = $api->hostMapByNames($input_host_names);
    foreach ($mapByName as $n => $id) $hostids[] = (string)$id;
}
if (!empty($input_hg_ids)) {
    $idsFromGroups = $api->hostIdsByGroupIds($input_hg_ids);
    foreach ($idsFromGroups as $id) $hostids[] = (string)$id;
}
$hostids = array_values(array_unique($hostids));

if (empty($hostids) && empty($input_item_ids)) {
    http_response_code(400);
    echo "<h3>Error</h3><pre>" . t('generate_no_hosts_found') . "</pre>";
    if (GEN_DEBUG_TRACE) ztrace('hostids vac�os y tampoco llegaron itemids directos');
    exit;
}

$id2name  = [];
if (!empty($hostids)) {
    $hostInfo = $api->hostGetBasicByIds($hostids);
    foreach ($hostInfo as $h) {
        $key = isset($h['host']) && $h['host'] !== '' ? $h['host'] : (isset($h['name']) ? $h['name'] : '');
        $id2name[$h['hostid']] = $key;
    }
}

$cookieJar = isset($_SESSION['zbx_cookiejar']) ? (string)$_SESSION['zbx_cookiejar'] : (TMP_DIR . '/zbx_cj_' . session_id() . '.txt');
if (!is_file($cookieJar) || filesize($cookieJar) < 10) {
    if (!web_login_cookiejar(ZABBIX_URL, $WEB_USER, $WEB_PASS, $cookieJar)) {
        http_response_code(500);
        echo "<h3>Error</h3><pre>" . t('generate_web_login_failed') . "</pre>";
        exit;
    }
}

$entries = [];

if (!empty($input_item_ids)) {
    foreach ($input_item_ids as $iid) {
        if (!ctype_digit($iid)) continue;
        $res = $api->call('item.get', ['itemids' => [(int)$iid],'output'  => ['itemid','name','key_','hostid'],'selectHosts' => ['host','name','hostid']]);
        if (!is_array($res) || empty($res)) continue;
        $it = $res[0];
        $hostName = '';
        if (isset($it['hosts'][0]['host'])) $hostName = $it['hosts'][0]['host'];
        elseif (isset($it['hostid']) && isset($id2name[$it['hostid']])) $hostName = $id2name[$it['hostid']];
        else $hostName = 'host';
        $title = isset($it['name']) ? (string)$it['name'] : (isset($it['key_']) ? (string)$it['key_'] : ('item:' . $iid));
        $e = buildItemChartEntry($hostName, (string)$iid, $title, $from_text, $to_text, $cookieJar);
        if ($e) $entries[] = $e;
    }
}

if (!empty($input_item_keys) && !empty($hostids)) {
    foreach ($hostids as $hid) {
        $hostName = isset($id2name[$hid]) ? $id2name[$hid] : ('hostid:' . $hid);
        
        $itemsByName = $api->call('item.get', [
            'output'    => ['itemid', 'name', 'key_'],
            'hostids'   => $hid,
            'filter'    => ['name' => $input_item_keys],
            'sortfield' => 'name'
        ]);

        $itemsByKey = $api->call('item.get', [
            'output'    => ['itemid', 'name', 'key_'],
            'hostids'   => $hid,
            'filter'    => ['key_' => $input_item_keys],
            'sortfield' => 'name'
        ]);

        $combinedItems = [];
        if (is_array($itemsByName)) $combinedItems = array_merge($combinedItems, $itemsByName);
        if (is_array($itemsByKey))  $combinedItems = array_merge($combinedItems, $itemsByKey);

        $itemInfo = [];
        $processedIds = [];
        foreach ($combinedItems as $item) {
            if (isset($item['itemid']) && !isset($processedIds[$item['itemid']])) {
                $itemInfo[] = $item;
                $processedIds[$item['itemid']] = true;
            }
        }

        if (empty($itemInfo)) { ztrace("Sin �tems en $hostName para la b�squeda exacta: " . json_encode($input_item_keys)); continue; }
        foreach ($itemInfo as $item) {
            if (!isset($item['itemid'])) continue;
            $itemid = (string)$item['itemid'];
            $title  = (string)($item['name'] ?? $item['key_'] ?? 'item:' . $itemid);
            $e = buildItemChartEntry($hostName, $itemid, $title, $from_text, $to_text, $cookieJar);
            if ($e) $entries[] = $e;
        }
    }
}

if (empty($entries)) {
    http_response_code(400);
    echo "<h3>Error</h3><pre>" . t('generate_no_graphs') . "</pre>";
    exit;
}

$outfile = TMP_DIR . '/zabbix_report_' . date('Ymd_His') . '.pdf';
try {
    PdfBuilder::build($entries, $outfile, defined('PDF_ENGINE') ? PDF_ENGINE : null);
    header('Content-Type: application/pdf');
    header('Content-Disposition: attachment; filename="' . basename($outfile) . '"');
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('Pragma: no-cache'); header('Expires: 0');
    
    readfile($outfile);
    
    // --- L�NEA A�ADIDA ---
    // Borra el archivo PDF despu�s de enviarlo.
    @unlink($outfile);

} catch (Throwable $e) {
    ztrace("FALLO GRAVE AL GENERAR PDF: " . $e->getMessage() . "\n" . $e->getTraceAsString());
    http_response_code(500);
    header('Content-Type: text/html; charset=UTF-8');
    echo "<h3>Error</h3><pre>" . t('generate_pdf_failed') . "</pre>";
} finally {
    // Limpieza de las im�genes PNG temporales
    if (!empty($entries)) {
        foreach ($entries as $e) {
            if (!empty($e['png']) && is_file($e['png'])) @unlink($e['png']);
        }
    }
}