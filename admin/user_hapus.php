<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/log.php';

require_role(['admin']);

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    header('Allow: POST');
    http_response_code(405);
    exit('Metode tidak diizinkan.');
}

if (!csrf_check()) {
    http_response_code(403);
    exit('Permintaan tidak valid.');
}

$admin = current_user();
$idUser = filter_var($_POST['id_user'] ?? null, FILTER_VALIDATE_INT);
if ($admin === null || $idUser === false || $idUser < 1) {
    http_response_code(400);
    exit('ID pengguna tidak valid.');
}
if ((int) $idUser === (int) $admin['id_user']) {
    flash_set('error', 'Admin tidak dapat menghapus akunnya sendiri.');
    redirect('/admin/user.php');
}

$pdo = db();
$pdo->beginTransaction();
try {
    $statement = $pdo->prepare('SELECT username FROM tb_user WHERE id_user = :id_user FOR UPDATE');
    $statement->execute(['id_user' => $idUser]);
    $pengguna = $statement->fetch();
    if (!is_array($pengguna)) {
        $pdo->rollBack();
        flash_set('error', 'Pengguna tidak ditemukan.');
        redirect('/admin/user.php');
    }

    $statement = $pdo->prepare('SELECT COUNT(*) FROM tb_transaksi WHERE id_user = :id_user');
    $statement->execute(['id_user' => $idUser]);
    if ((int) $statement->fetchColumn() > 0) {
        $pdo->rollBack();
        flash_set('error', 'Pengguna memiliki transaksi dan tidak dapat dihapus. Nonaktifkan akun sebagai gantinya.');
        redirect('/admin/user.php');
    }

    $statement = $pdo->prepare('SELECT COUNT(*) FROM tb_kendaraan WHERE id_user = :id_user');
    $statement->execute(['id_user' => $idUser]);
    if ((int) $statement->fetchColumn() > 0) {
        $pdo->rollBack();
        flash_set('error', 'Pengguna masih tercatat pada data kendaraan dan tidak dapat dihapus. Nonaktifkan akun sebagai gantinya.');
        redirect('/admin/user.php');
    }

    catat_log((int) $admin['id_user'], 'Hapus pengguna: ' . $pengguna['username']);
    $statement = $pdo->prepare('DELETE FROM tb_user WHERE id_user = :id_user');
    $statement->execute(['id_user' => $idUser]);
    $pdo->commit();
} catch (Throwable $exception) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    throw $exception;
}

flash_set('sukses', 'Pengguna berhasil dihapus.');
redirect('/admin/user.php');
