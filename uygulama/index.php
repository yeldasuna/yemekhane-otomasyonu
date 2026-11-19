<?php
require_once __DIR__ . '/src/Kontrolcu.php';

header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Oturum-Token');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

$yol = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
$metot = $_SERVER['REQUEST_METHOD'] ?? 'GET';

$kontrolcu = new Kontrolcu();
$kontrolcu->yonlendir($yol, $metot);
?>