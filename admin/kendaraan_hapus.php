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
$idKendaraan = filter_var($_POST['id_kendaraan'] ?? null, FILTER_VALIDATE_INT);
if ($idKendaraan === false || $idKendaraan < 1) {
    http_response_code(400);
    exit('ID kendaraan tidak valid.');
}

$pdo = db();
$pdo->beginTransaction();
try {
    $statement = $pdo->prepare('SELECT plat_nomor FROM tb_kendaraan WHERE id_kendaraan = :id FOR UPDATE');
    $statement->execute(['id' => $idKendaraan]);
    $kendaraan = $statement->fetch();
    if (!is_array($kendaraan)) {
        $pdo->rollBack();
        flash_set('error', 'Kendaraan tidak ditemukan.');
        redirect('/admin/kendaraan.php');
    }
    $statement = $pdo->prepare('SELECT COUNT(*) FROM tb_transaksi WHERE id_kendaraan = :id');
    $statement->execute(['id' => $idKendaraan]);
    if ((int) $statement->fetchColumn() > 0) {
        $pdo->rollBack();
        flash_set('error', 'Kendaraan tidak dapat dihapus karena masih digunakan pada transaksi parkir.');
        redirect('/admin/kendaraan.php');
    }
    catat_log((int) current_user()['id_user'], 'Hapus kendaraan: ' . $kendaraan['plat_nomor']);
    $statement = $pdo->prepare('DELETE FROM tb_kendaraan WHERE id_kendaraan = :id');
    $statement->execute(['id' => $idKendaraan]);
    $pdo->commit();
} catch (Throwable $exception) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    throw $exception;
}
flash_set('sukses', 'Kendaraan berhasil dihapus.');
redirect('/admin/kendaraan.php');
