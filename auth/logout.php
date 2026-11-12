<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/log.php';

app_session_start();

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    header('Allow: POST');
    http_response_code(405);
    exit('Metode tidak diizinkan.');
}

if (!csrf_check()) {
    http_response_code(403);
    exit('Permintaan tidak valid.');
}

$pengguna = current_user();
$idPengguna = $pengguna === null ? null : (int) $pengguna['id_user'];

$_SESSION = [];
$parameterCookie = session_get_cookie_params();
if (ini_get('session.use_cookies')) {
    setcookie(session_name(), '', [
        'expires' => time() - 42000,
        'path' => $parameterCookie['path'],
        'domain' => $parameterCookie['domain'],
        'secure' => $parameterCookie['secure'],
        'httponly' => $parameterCookie['httponly'],
        'samesite' => $parameterCookie['samesite'] ?? 'Lax',
    ]);
}

if (!session_destroy()) {
    throw new RuntimeException('Sesi gagal dihancurkan.');
}

if ($idPengguna !== null) {
    catat_log($idPengguna, 'Logout');
}

redirect('/auth/login.php');
