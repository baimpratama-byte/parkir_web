<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/log.php';
require_once __DIR__ . '/../includes/admin_crud_helpers.php';

require_role(['admin']);
$idMasukan = $_GET['id'] ?? $_POST['id_area'] ?? null;
$idArea = null;
if ($idMasukan !== null) {
    $idArea = filter_var($idMasukan, FILTER_VALIDATE_INT);
    if ($idArea === false || $idArea < 1) {
        http_response_code(400);
        exit('ID area tidak valid.');
    }
}
$data = ['nama_area' => '', 'kapasitas' => ''];
$terisi = 0;
$pesanError = null;
if ($idArea !== null) {
    $statement = db()->prepare('SELECT id_area, nama_area, kapasitas, terisi FROM tb_area_parkir WHERE id_area = :id');
    $statement->execute(['id' => $idArea]);
    $area = $statement->fetch();
    if (!is_array($area)) {
        http_response_code(404);
        exit('Area tidak ditemukan.');
    }
    $data = $area;
    $terisi = (int) $area['terisi'];
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    if (!csrf_check()) {
        http_response_code(403);
        $pesanError = 'Permintaan tidak valid. Muat ulang halaman dan coba lagi.';
    } else {
        $nama = $_POST['nama_area'] ?? null;
        $kapasitasMasukan = $_POST['kapasitas'] ?? null;
        $data['nama_area'] = is_string($nama) ? trim($nama) : '';
        $data['kapasitas'] = is_string($kapasitasMasukan) ? trim($kapasitasMasukan) : '';
        $kesalahan = [];
        if ($data['nama_area'] === '' || mb_strlen($data['nama_area']) > 50) {
            $kesalahan[] = 'Nama area wajib diisi dan maksimal 50 karakter.';
        }
        if (preg_match('/\A[0-9]{1,10}\z/', $data['kapasitas']) !== 1 || (int) $data['kapasitas'] < 1 || (int) $data['kapasitas'] > 2147483647) {
            $kesalahan[] = 'Kapasitas harus berupa bilangan bulat minimal 1.';
        }
        if ($idArea !== null && (int) $data['kapasitas'] < $terisi) {
            $kesalahan[] = 'Kapasitas tidak boleh lebih kecil daripada jumlah tempat yang sedang terisi.';
        }
        if ($kesalahan === []) {
            $statement = db()->prepare(
                'SELECT id_area FROM tb_area_parkir WHERE nama_area = :nama AND id_area <> :id LIMIT 1'
            );
            $statement->execute(['nama' => $data['nama_area'], 'id' => $idArea ?? 0]);
            if ($statement->fetchColumn() !== false) {
                $kesalahan[] = 'Nama area sudah digunakan.';
            }
        }
        if ($kesalahan !== []) {
            $pesanError = implode(' ', $kesalahan);
        } else {
            $pdo = db();
            $pdo->beginTransaction();
            try {
                if ($idArea === null) {
                    $statement = $pdo->prepare('INSERT INTO tb_area_parkir (nama_area, kapasitas) VALUES (:nama, :kapasitas)');
                    $statement->execute(['nama' => $data['nama_area'], 'kapasitas' => $data['kapasitas']]);
                    catat_log((int) current_user()['id_user'], 'Tambah area parkir: ' . $data['nama_area']);
                    $pesanSukses = 'Area parkir berhasil ditambahkan.';
                } else {
                    $statement = $pdo->prepare('SELECT terisi FROM tb_area_parkir WHERE id_area = :id FOR UPDATE');
                    $statement->execute(['id' => $idArea]);
                    $terisiTerkini = $statement->fetchColumn();
                    if ($terisiTerkini === false) {
                        throw new RuntimeException('Area tidak ditemukan.');
                    }
                    if ((int) $data['kapasitas'] < (int) $terisiTerkini) {
                        throw new DomainException('Kapasitas tidak boleh lebih kecil daripada jumlah tempat yang sedang terisi.');
                    }
                    $statement = $pdo->prepare('UPDATE tb_area_parkir SET nama_area = :nama, kapasitas = :kapasitas WHERE id_area = :id');
                    $statement->execute(['nama' => $data['nama_area'], 'kapasitas' => $data['kapasitas'], 'id' => $idArea]);
                    catat_log((int) current_user()['id_user'], 'Ubah area parkir: ' . $data['nama_area']);
                    $pesanSukses = 'Area parkir berhasil diperbarui.';
                }
                $pdo->commit();
            } catch (Throwable $exception) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                if ($exception instanceof PDOException && (int) ($exception->errorInfo[1] ?? 0) === 1062) {
                    $pesanError = 'Nama area sudah digunakan.';
                } elseif ($exception instanceof DomainException) {
                    $pesanError = $exception->getMessage();
                } else {
                    throw $exception;
                }
            }
            if ($pesanError === null) {
                flash_set('sukses', $pesanSukses);
                redirect('/admin/area_parkir.php');
            }
        }
    }
}

$judul = $idArea === null ? 'Tambah area parkir' : 'Ubah area parkir';
$role = 'admin';
$tokenCsrf = csrf_token();
require __DIR__ . '/../includes/layout_header.php';
?>
<?php if ($pesanError !== null): ?><p class="flash" role="alert"><?= e($pesanError) ?></p><?php endif; ?>
<?php if ($idArea !== null): ?><p class="field-help">Tempat terisi saat ini: <?= $terisi ?>. Nilai ini hanya berubah melalui transaksi.</p><?php endif; ?>
<?php
render_admin_crud_form(
    '/admin/area_parkir_form.php',
    $tokenCsrf,
    $data,
    [
        ['name' => 'nama_area', 'label' => 'Nama area', 'type' => 'text', 'required' => true, 'maxlength' => 50],
        ['name' => 'kapasitas', 'label' => 'Kapasitas', 'type' => 'number', 'required' => true, 'min' => '1', 'max' => '2147483647', 'step' => '1'],
    ],
    '/admin/area_parkir.php',
    $idArea,
    'id_area'
);
require __DIR__ . '/../includes/layout_footer.php';
?>
