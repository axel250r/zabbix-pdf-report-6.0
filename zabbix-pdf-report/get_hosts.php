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

try {
    $api = new ZabbixApi(ZABBIX_API_URL, $_SESSION['zbx_user'], $_SESSION['zbx_pass']);
    
    // REVERTIDO: Se elimina selectGroups para obtener una lista simple de hosts
    $hosts = $api->call('host.get', [
        'output' => ['hostid', 'name'],
        'sortfield' => 'name'
    ]);
    
    if (!is_array($hosts)) {
        echo json_encode(['error' => t('get_hosts_error')]);
        exit;
    }
    
    echo json_encode($hosts);
} catch (Throwable $e) {
    error_log("Zabbix PDF Report - API Error en get_hosts.php: " . $e->getMessage());
    echo json_encode(['error' => t('error_server_error')]);
}