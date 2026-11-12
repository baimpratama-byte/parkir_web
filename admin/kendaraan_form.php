<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/log.php';
require_once __DIR__ . '/../includes/admin_crud_helpers.php';

require_role(['admin']);
$admin = current_user();
$jenisKendaraan = [];
$statement = db()->query('SELECT DISTINCT jenis_kendaraan FROM tb_tarif ORDER BY jenis_kendaraan');
foreach ($statement->fetchAll(PDO::FETCH_COLUMN) as $jenis) {
    $jenisKendaraan[(string) $jenis] = ucfirst((string) $jenis);
}

$idMasukan = $_GET['id'] ?? $_POST['id_kendaraan'] ?? null;
$idKendaraan = null;
if ($idMasukan !== null) {
    $idKendaraan = filter_var($idMasukan, FILTER_VALIDATE_INT);
    if ($idKendaraan === false || $idKendaraan < 1) {
        http_response_code(400);
        exit('ID kendaraan tidak valid.');
    }
}
$data = ['plat_nomor' => '', 'jenis_kendaraan' => '', 'warna' => '', 'pemilik' => ''];
$pesanError = null;
if ($idKendaraan !== null) {
    $statement = db()->prepare('SELECT id_kendaraan, plat_nomor, jenis_kendaraan, warna, pemilik FROM tb_kendaraan WHERE id_kendaraan = :id');
    $statement->execute(['id' => $idKendaraan]);
    $record = $statement->fetch();
    if (!is_array($record)) {
        http_response_code(404);
        exit('Kendaraan tidak ditemukan.');
    }
    $data = $record;
    if (!array_key_exists($data['jenis_kendaraan'], $jenisKendaraan)) {
        $jenisKendaraan[$data['jenis_kendaraan']] = ucfirst((string) $data['jenis_kendaraan']) . ' (tarif tidak tersedia)';
    }
}
if ($data['jenis_kendaraan'] === '' && $jenisKendaraan !== []) {
    $data['jenis_kendaraan'] = (string) array_key_first($jenisKendaraan);
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    if (!csrf_check()) {
        http_response_code(403);
        $pesanError = 'Permintaan tidak valid. Muat ulang halaman dan coba lagi.';
    } else {
        $platMasukan = $_POST['plat_nomor'] ?? null;
        $jenisMasukan = $_POST['jenis_kendaraan'] ?? null;
        $warnaMasukan = $_POST['warna'] ?? null;
        $pemilikMasukan = $_POST['pemilik'] ?? null;
        $plat = is_string($platMasukan) ? preg_replace('/\s+/', ' ', trim($platMasukan)) : '';
        $data['plat_nomor'] = strtoupper($plat ?? '');
        $data['jenis_kendaraan'] = is_string($jenisMasukan) ? $jenisMasukan : '';
        $data['warna'] = is_string($warnaMasukan) ? trim($warnaMasukan) : '';
        $data['pemilik'] = is_string($pemilikMasukan) ? trim($pemilikMasukan) : '';
        $kesalahan = [];
        if ($data['plat_nomor'] === '' || mb_strlen($data['plat_nomor']) > 15) {
            $kesalahan[] = 'Plat nomor wajib diisi dan maksimal 15 karakter.';
        }
        if (!array_key_exists($data['jenis_kendaraan'], $jenisKendaraan)) {
            $kesalahan[] = 'Jenis kendaraan harus dipilih dari jenis yang memiliki tarif.';
        }
        if ($data['warna'] === '' || mb_strlen($data['warna']) > 20) {
            $kesalahan[] = 'Warna wajib diisi dan maksimal 20 karakter.';
        }
        if ($data['pemilik'] === '' || mb_strlen($data['pemilik']) > 100) {
            $kesalahan[] = 'Nama pemilik wajib diisi dan maksimal 100 karakter.';
        }
        if ($kesalahan === []) {
            $statement = db()->prepare(
                'SELECT id_kendaraan FROM tb_kendaraan WHERE plat_nomor = :plat AND id_kendaraan <> :id LIMIT 1'
            );
            $statement->execute(['plat' => $data['plat_nomor'], 'id' => $idKendaraan ?? 0]);
            if ($statement->fetchColumn() !== false) {
                $kesalahan[] = 'Plat nomor sudah terdaftar.';
            }
        }
        if ($kesalahan !== []) {
            $pesanError = implode(' ', $kesalahan);
        } else {
            $pdo = db();
            $pdo->beginTransaction();
            try {
                if ($idKendaraan === null) {
                    $statement = $pdo->prepare(
                        'INSERT INTO tb_kendaraan (plat_nomor, jenis_kendaraan, warna, pemilik, id_user)
                         VALUES (:plat, :jenis, :warna, :pemilik, :id_user)'
                    );
                    $statement->execute([
                        'plat' => $data['plat_nomor'],
                        'jenis' => $data['jenis_kendaraan'],
                        'warna' => $data['warna'],
                        'pemilik' => $data['pemilik'],
                        'id_user' => (int) $admin['id_user'],
                    ]);
                    catat_log((int) $admin['id_user'], 'Tambah kendaraan: ' . $data['plat_nomor']);
                    $pesanSukses = 'Kendaraan berhasil ditambahkan.';
                } else {
                    $statement = $pdo->prepare(
                        'UPDATE tb_kendaraan
                         SET plat_nomor = :plat, jenis_kendaraan = :jenis, warna = :warna, pemilik = :pemilik
                         WHERE id_kendaraan = :id'
                    );
                    $statement->execute([
                        'plat' => $data['plat_nomor'],
                        'jenis' => $data['jenis_kendaraan'],
                        'warna' => $data['warna'],
                        'pemilik' => $data['pemilik'],
                        'id' => $idKendaraan,
                    ]);
                    catat_log((int) $admin['id_user'], 'Ubah kendaraan: ' . $data['plat_nomor']);
                    $pesanSukses = 'Kendaraan berhasil diperbarui.';
                }
                $pdo->commit();
            } catch (Throwable $exception) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                if ($exception instanceof PDOException && (int) ($exception->errorInfo[1] ?? 0) === 1062) {
                    $pesanError = 'Plat nomor sudah terdaftar.';
                } else {
                    throw $exception;
                }
            }
            if ($pesanError === null) {
                flash_set('sukses', $pesanSukses);
                redirect('/admin/kendaraan.php');
            }
        }
    }
}

$judul = $idKendaraan === null ? 'Tambah kendaraan' : 'Ubah kendaraan';
$role = 'admin';
$tokenCsrf = csrf_token();
require __DIR__ . '/../includes/layout_header.php';
?>
<?php if ($pesanError !== null): ?><p class="flash" role="alert"><?= e($pesanError) ?></p><?php endif; ?>
<?php if ($jenisKendaraan === []): ?><p class="flash" role="alert">Belum ada tarif kendaraan. Tambahkan tarif sebelum mendaftarkan kendaraan.</p><?php endif; ?>
<?php
render_admin_crud_form(
    '/admin/kendaraan_form.php',
    $tokenCsrf,
    $data,
    [
        ['name' => 'plat_nomor', 'label' => 'Plat nomor', 'type' => 'text', 'required' => true, 'maxlength' => 15, 'help' => 'Spasi dirapikan dan huruf otomatis diubah menjadi kapital.'],
        ['name' => 'jenis_kendaraan', 'label' => 'Jenis kendaraan', 'type' => 'select', 'required' => true, 'options' => $jenisKendaraan],
        ['name' => 'warna', 'label' => 'Warna', 'type' => 'text', 'required' => true, 'maxlength' => 20],
        ['name' => 'pemilik', 'label' => 'Nama pemilik', 'type' => 'text', 'required' => true, 'maxlength' => 100],
    ],
    '/admin/kendaraan.php',
    $idKendaraan,
    'id_kendaraan'
);
require __DIR__ . '/../includes/layout_footer.php';
?>
