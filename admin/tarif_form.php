<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/log.php';
require_once __DIR__ . '/../includes/admin_crud_helpers.php';

require_role(['admin']);
$jenisTarif = ['motor' => 'Motor', 'mobil' => 'Mobil', 'lainnya' => 'Kendaraan lainnya'];
$idMasukan = $_GET['id'] ?? $_POST['id_tarif'] ?? null;
$idTarif = null;
if ($idMasukan !== null) {
    $idTarif = filter_var($idMasukan, FILTER_VALIDATE_INT);
    if ($idTarif === false || $idTarif < 1) {
        http_response_code(400);
        exit('ID tarif tidak valid.');
    }
}

$data = ['jenis_kendaraan' => 'motor', 'tarif_per_jam' => ''];
$pesanError = null;
if ($idTarif !== null) {
    $statement = db()->prepare('SELECT id_tarif, jenis_kendaraan, tarif_per_jam FROM tb_tarif WHERE id_tarif = :id');
    $statement->execute(['id' => $idTarif]);
    $tarif = $statement->fetch();
    if (!is_array($tarif)) {
        http_response_code(404);
        exit('Tarif tidak ditemukan.');
    }
    $data = $tarif;
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    if (!csrf_check()) {
        http_response_code(403);
        $pesanError = 'Permintaan tidak valid. Muat ulang halaman dan coba lagi.';
    } else {
        $jenis = $_POST['jenis_kendaraan'] ?? null;
        $jumlahMasukan = $_POST['tarif_per_jam'] ?? null;
        $data['jenis_kendaraan'] = is_string($jenis) ? $jenis : '';
        $data['tarif_per_jam'] = is_string($jumlahMasukan) ? trim($jumlahMasukan) : '';
        $kesalahan = [];
        if (!array_key_exists($data['jenis_kendaraan'], $jenisTarif)) {
            $kesalahan[] = 'Jenis kendaraan tidak valid.';
        }
        if (preg_match('/\A[0-9]{1,10}\z/', $data['tarif_per_jam']) !== 1 || (int) $data['tarif_per_jam'] < 1) {
            $kesalahan[] = 'Tarif harus berupa angka bulat lebih dari 0.';
        }
        if ($kesalahan === []) {
            $statement = db()->prepare(
                'SELECT id_tarif FROM tb_tarif
                 WHERE jenis_kendaraan = :jenis AND id_tarif <> :id LIMIT 1'
            );
            $statement->execute(['jenis' => $data['jenis_kendaraan'], 'id' => $idTarif ?? 0]);
            if ($statement->fetchColumn() !== false) {
                $kesalahan[] = 'Tarif untuk jenis kendaraan ini sudah ada.';
            }
        }

        if ($kesalahan !== []) {
            $pesanError = implode(' ', $kesalahan);
        } else {
            $pdo = db();
            $pdo->beginTransaction();
            try {
                if ($idTarif === null) {
                    $statement = $pdo->prepare(
                        'INSERT INTO tb_tarif (jenis_kendaraan, tarif_per_jam)
                         VALUES (:jenis, :jumlah)'
                    );
                    $statement->execute(['jenis' => $data['jenis_kendaraan'], 'jumlah' => $data['tarif_per_jam']]);
                    catat_log((int) current_user()['id_user'], 'Tambah tarif: ' . $data['jenis_kendaraan']);
                    $pesanSukses = 'Tarif berhasil ditambahkan.';
                } else {
                    $statement = $pdo->prepare(
                        'UPDATE tb_tarif SET jenis_kendaraan = :jenis, tarif_per_jam = :jumlah
                         WHERE id_tarif = :id'
                    );
                    $statement->execute([
                        'jenis' => $data['jenis_kendaraan'],
                        'jumlah' => $data['tarif_per_jam'],
                        'id' => $idTarif,
                    ]);
                    catat_log((int) current_user()['id_user'], 'Ubah tarif: ' . $data['jenis_kendaraan']);
                    $pesanSukses = 'Tarif berhasil diperbarui.';
                }
                $pdo->commit();
            } catch (Throwable $exception) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                if ($exception instanceof PDOException && (int) ($exception->errorInfo[1] ?? 0) === 1062) {
                    $pesanError = 'Tarif untuk jenis kendaraan ini sudah ada.';
                } else {
                    throw $exception;
                }
            }
            if ($pesanError === null) {
                flash_set('sukses', $pesanSukses);
                redirect('/admin/tarif.php');
            }
        }
    }
}

$judul = $idTarif === null ? 'Tambah tarif' : 'Ubah tarif';
$role = 'admin';
$tokenCsrf = csrf_token();
require __DIR__ . '/../includes/layout_header.php';
?>
<?php if ($pesanError !== null): ?><p class="flash" role="alert"><?= e($pesanError) ?></p><?php endif; ?>
<?php
render_admin_crud_form(
    '/admin/tarif_form.php',
    $tokenCsrf,
    $data,
    [
        ['name' => 'jenis_kendaraan', 'label' => 'Jenis kendaraan', 'type' => 'select', 'required' => true, 'options' => $jenisTarif],
        ['name' => 'tarif_per_jam', 'label' => 'Tarif per jam (Rp)', 'type' => 'number', 'required' => true, 'min' => '1', 'max' => '9999999999', 'step' => '1', 'help' => 'Masukkan angka bulat tanpa pemisah ribuan.'],
    ],
    '/admin/tarif.php',
    $idTarif,
    'id_tarif'
);
require __DIR__ . '/../includes/layout_footer.php';
?>
