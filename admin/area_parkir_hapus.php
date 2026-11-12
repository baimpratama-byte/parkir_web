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
$idArea = filter_var($_POST['id_area'] ?? null, FILTER_VALIDATE_INT);
if ($idArea === false || $idArea < 1) {
    http_response_code(400);
    exit('ID area tidak valid.');
}

$pdo = db();
$pdo->beginTransaction();
try {
    $statement = $pdo->prepare('SELECT nama_area, terisi FROM tb_area_parkir WHERE id_area = :id FOR UPDATE');
    $statement->execute(['id' => $idArea]);
    $area = $statement->fetch();
    if (!is_array($area)) {
        $pdo->rollBack();
        flash_set('error', 'Area tidak ditemukan.');
        redirect('/admin/area_parkir.php');
    }
    $statement = $pdo->prepare('SELECT COUNT(*) FROM tb_transaksi WHERE id_area = :id');
    $statement->execute(['id' => $idArea]);
    if ((int) $statement->fetchColumn() > 0) {
        $pdo->rollBack();
        flash_set('error', 'Area tidak dapat dihapus karena masih digunakan pada transaksi.');
        redirect('/admin/area_parkir.php');
    }
    if ((int) $area['terisi'] > 0) {
        $pdo->rollBack();
        flash_set('error', 'Area tidak dapat dihapus karena masih ada kendaraan yang tercatat terisi.');
        redirect('/admin/area_parkir.php');
    }
    catat_log((int) current_user()['id_user'], 'Hapus area parkir: ' . $area['nama_area']);
    $statement = $pdo->prepare('DELETE FROM tb_area_parkir WHERE id_area = :id');
    $statement->execute(['id' => $idArea]);
    $pdo->commit();
} catch (Throwable $exception) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    throw $exception;
}
flash_set('sukses', 'Area parkir berhasil dihapus.');
redirect('/admin/area_parkir.php');
