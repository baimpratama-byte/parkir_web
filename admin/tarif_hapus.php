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
$idTarif = filter_var($_POST['id_tarif'] ?? null, FILTER_VALIDATE_INT);
if ($idTarif === false || $idTarif < 1) {
    http_response_code(400);
    exit('ID tarif tidak valid.');
}

$pdo = db();
$pdo->beginTransaction();
try {
    $statement = $pdo->prepare('SELECT jenis_kendaraan FROM tb_tarif WHERE id_tarif = :id FOR UPDATE');
    $statement->execute(['id' => $idTarif]);
    $tarif = $statement->fetch();
    if (!is_array($tarif)) {
        $pdo->rollBack();
        flash_set('error', 'Tarif tidak ditemukan.');
        redirect('/admin/tarif.php');
    }
    $statement = $pdo->prepare('SELECT COUNT(*) FROM tb_transaksi WHERE id_tarif = :id');
    $statement->execute(['id' => $idTarif]);
    if ((int) $statement->fetchColumn() > 0) {
        $pdo->rollBack();
        flash_set('error', 'Tarif tidak dapat dihapus karena masih digunakan pada transaksi.');
        redirect('/admin/tarif.php');
    }
    catat_log((int) current_user()['id_user'], 'Hapus tarif: ' . $tarif['jenis_kendaraan']);
    $statement = $pdo->prepare('DELETE FROM tb_tarif WHERE id_tarif = :id');
    $statement->execute(['id' => $idTarif]);
    $pdo->commit();
} catch (Throwable $exception) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    throw $exception;
}
flash_set('sukses', 'Tarif berhasil dihapus.');
redirect('/admin/tarif.php');
