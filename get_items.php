<?php
declare(strict_types=1);

session_start();
if (empty($_SESSION['zbx_auth_ok'])) {
    http_response_code(403);
    echo json_encode(['error' => 'Sesión inválida']);
    exit;
}

require_once __DIR__ . '/lib/i18n.php';
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/lib/ZabbixApi.php';

header('Content-Type: application/json; charset=utf-8');

$input = json_decode(file_get_contents('php://input'), true);
$templateids = $input['templateids'] ?? [];

if (empty($templateids)) {
    echo json_encode([]);
    exit;
}

try {
    $api = new ZabbixApi(ZABBIX_API_URL, $_SESSION['zbx_user'], $_SESSION['zbx_pass']);
    
    $items = $api->call('item.get', [
        'output' => ['itemid', 'name', 'key_', 'templateid'],
        'templateids' => $templateids,
        'sortfield' => 'name'
    ]);
    
    if (!is_array($items)) {
        echo json_encode(['error' => t('get_items_error')]);
        exit;
    }
    
    echo json_encode($items);
} catch (Throwable $e) {
    error_log("Zabbix PDF Report - API Error en get_items.php: " . $e->getMessage());
    echo json_encode(['error' => t('error_server_error')]);
}