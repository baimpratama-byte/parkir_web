<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/log.php';

require_role(['admin']);
$admin = current_user();
if ($admin === null) {
    redirect('/auth/login.php');
}

$idMasukan = $_GET['id'] ?? $_POST['id_user'] ?? null;
$idUser = null;
if ($idMasukan !== null) {
    $idUser = filter_var($idMasukan, FILTER_VALIDATE_INT);
    if ($idUser === false || $idUser < 1) {
        http_response_code(400);
        exit('ID pengguna tidak valid.');
    }
}

$data = [
    'nama_lengkap' => '',
    'username' => '',
    'role' => 'petugas',
    'status_aktif' => 1,
];
$pesanError = null;

if ($idUser !== null) {
    $statement = db()->prepare(
        'SELECT id_user, nama_lengkap, username, role, status_aktif
         FROM tb_user WHERE id_user = :id_user'
    );
    $statement->execute(['id_user' => $idUser]);
    $pengguna = $statement->fetch();
    if (!is_array($pengguna)) {
        http_response_code(404);
        exit('Pengguna tidak ditemukan.');
    }
    $data = $pengguna;
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    if (!csrf_check()) {
        http_response_code(403);
        $pesanError = 'Permintaan tidak valid. Muat ulang halaman dan coba lagi.';
    } else {
        $namaMasukan = $_POST['nama_lengkap'] ?? null;
        $usernameMasukan = $_POST['username'] ?? null;
        $roleMasukan = $_POST['role'] ?? null;
        $passwordMasukan = $_POST['password'] ?? null;
        $statusMasukan = $_POST['status_aktif'] ?? null;

        $data['nama_lengkap'] = is_string($namaMasukan) ? trim($namaMasukan) : '';
        $data['username'] = is_string($usernameMasukan) ? trim($usernameMasukan) : '';
        $data['role'] = is_string($roleMasukan) ? $roleMasukan : '';
        $data['status_aktif'] = $statusMasukan === '1' ? 1 : 0;

        $kesalahan = [];
        if ($data['nama_lengkap'] === '' || mb_strlen($data['nama_lengkap']) > 50) {
            $kesalahan[] = 'Nama lengkap wajib diisi dan maksimal 50 karakter.';
        }
        if (preg_match('/\A[a-zA-Z0-9._-]{3,50}\z/', $data['username']) !== 1) {
            $kesalahan[] = 'Username harus 3–50 karakter dan hanya boleh berisi huruf, angka, titik, garis bawah, atau tanda hubung.';
        }
        if (!in_array($data['role'], ['admin', 'petugas', 'owner'], true)) {
            $kesalahan[] = 'Role yang dipilih tidak valid.';
        }
        if ($idUser === null && (!is_string($passwordMasukan) || strlen($passwordMasukan) < 8 || strlen($passwordMasukan) > 4096)) {
            $kesalahan[] = 'Kata sandi wajib diisi, minimal 8 karakter.';
        } elseif ($idUser !== null && $passwordMasukan !== '' && (!is_string($passwordMasukan) || strlen($passwordMasukan) < 8 || strlen($passwordMasukan) > 4096)) {
            $kesalahan[] = 'Kata sandi baru harus minimal 8 karakter.';
        }
        if ($idUser !== null && (int) $idUser === (int) $admin['id_user'] && $data['status_aktif'] !== 1) {
            $kesalahan[] = 'Admin tidak dapat menonaktifkan akunnya sendiri.';
        }

        if ($kesalahan === []) {
            $statement = db()->prepare(
                'SELECT id_user FROM tb_user WHERE username = :username AND id_user <> :id_user LIMIT 1'
            );
            $statement->execute([
                'username' => $data['username'],
                'id_user' => $idUser ?? 0,
            ]);
            if ($statement->fetchColumn() !== false) {
                $kesalahan[] = 'Username sudah digunakan.';
            }
        }

        if ($kesalahan !== []) {
            $pesanError = implode(' ', $kesalahan);
        } else {
            $passwordDiisi = is_string($passwordMasukan) && $passwordMasukan !== '';
            $pdo = db();
            $pdo->beginTransaction();
            try {
                if ($idUser === null) {
                    $statement = $pdo->prepare(
                        'INSERT INTO tb_user (nama_lengkap, username, password, role, status_aktif)
                         VALUES (:nama_lengkap, :username, :password, :role, :status_aktif)'
                    );
                    $statement->execute([
                        'nama_lengkap' => $data['nama_lengkap'],
                        'username' => $data['username'],
                        'password' => password_hash($passwordMasukan, PASSWORD_DEFAULT),
                        'role' => $data['role'],
                        'status_aktif' => $data['status_aktif'],
                    ]);
                    catat_log((int) $admin['id_user'], 'Tambah pengguna: ' . $data['username']);
                    $pesanSukses = 'Pengguna berhasil ditambahkan.';
                } else {
                    $sql = 'UPDATE tb_user
                            SET nama_lengkap = :nama_lengkap,
                                username = :username,
                                role = :role,
                                status_aktif = :status_aktif';
                    $parameter = [
                        'nama_lengkap' => $data['nama_lengkap'],
                        'username' => $data['username'],
                        'role' => $data['role'],
                        'status_aktif' => $data['status_aktif'],
                        'id_user' => $idUser,
                    ];
                    if ($passwordDiisi) {
                        $sql .= ', password = :password';
                        $parameter['password'] = password_hash($passwordMasukan, PASSWORD_DEFAULT);
                    }
                    $sql .= ' WHERE id_user = :id_user';
                    $statement = $pdo->prepare($sql);
                    $statement->execute($parameter);
                    catat_log((int) $admin['id_user'], 'Ubah pengguna: ' . $data['username']);
                    $pesanSukses = 'Perubahan pengguna berhasil disimpan.';
                }
                $pdo->commit();
            } catch (Throwable $exception) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                if ($exception instanceof PDOException && (int) ($exception->errorInfo[1] ?? 0) === 1062) {
                    $pesanError = 'Username sudah digunakan.';
                } else {
                    throw $exception;
                }
            }

            if ($pesanError === null) {
                flash_set('sukses', $pesanSukses);
                redirect('/admin/user.php');
            }
        }
    }
}

$tokenCsrf = csrf_token();
$judul = $idUser === null ? 'Tambah pengguna' : 'Ubah pengguna';
$role = 'admin';
require __DIR__ . '/../includes/layout_header.php';
?>

<?php if ($pesanError !== null): ?>
    <p class="flash" role="alert"><?= e($pesanError) ?></p>
<?php endif; ?>

<form class="user-form" method="post" action="<?= e(app_url('/admin/user_form.php' . ($idUser === null ? '' : '?id=' . $idUser))) ?>">
    <input type="hidden" name="_csrf" value="<?= e($tokenCsrf) ?>">
    <?php if ($idUser !== null): ?>
        <input type="hidden" name="id_user" value="<?= (int) $idUser ?>">
    <?php endif; ?>
    <div class="form-field">
        <label for="nama-lengkap">Nama lengkap</label>
        <input id="nama-lengkap" name="nama_lengkap" type="text" value="<?= e($data['nama_lengkap']) ?>" maxlength="50" required>
    </div>
    <div class="form-field">
        <label for="username">Username</label>
        <input id="username" name="username" type="text" value="<?= e($data['username']) ?>" maxlength="50" autocomplete="username" required>
    </div>
    <div class="form-field">
        <label for="password"><?= $idUser === null ? 'Kata sandi' : 'Kata sandi baru (opsional)' ?></label>
        <input id="password" name="password" type="password" autocomplete="new-password"<?= $idUser === null ? ' required minlength="8"' : ' minlength="8"' ?>>
    </div>
    <div class="form-field">
        <label for="role">Role</label>
        <select id="role" name="role" required>
            <option value="admin"<?= $data['role'] === 'admin' ? ' selected' : '' ?>>Admin</option>
            <option value="petugas"<?= $data['role'] === 'petugas' ? ' selected' : '' ?>>Petugas</option>
            <option value="owner"<?= $data['role'] === 'owner' ? ' selected' : '' ?>>Owner</option>
        </select>
    </div>
    <div class="form-field">
        <label class="checkbox-label" for="status-aktif">
            <input id="status-aktif" name="status_aktif" type="checkbox" value="1"<?= (int) $data['status_aktif'] === 1 ? ' checked' : '' ?>>
            Akun aktif
        </label>
    </div>
    <div class="form-actions">
        <button class="button" type="submit">Simpan</button>
        <a class="button button-secondary" href="<?= e(app_url('/admin/user.php')) ?>">Batal</a>
    </div>
</form>

<?php require __DIR__ . '/../includes/layout_footer.php'; ?>
