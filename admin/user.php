<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';

require_role(['admin']);

$masukanKataKunci = $_GET['q'] ?? '';
$kataKunci = is_string($masukanKataKunci) ? trim($masukanKataKunci) : '';
if (strlen($kataKunci) > 100) {
    $kataKunci = substr($kataKunci, 0, 100);
}

$roleFilter = $_GET['role'] ?? '';
$roleFilter = is_string($roleFilter) && in_array($roleFilter, ['admin', 'petugas', 'owner'], true)
    ? $roleFilter
    : '';
$halamanDiminta = filter_var($_GET['halaman'] ?? 1, FILTER_VALIDATE_INT);
$halamanDiminta = $halamanDiminta === false || $halamanDiminta < 1 ? 1 : $halamanDiminta;

$kondisi = [];
$parameter = [];
if ($kataKunci !== '') {
    $kondisi[] = '(nama_lengkap LIKE :nama OR username LIKE :username)';
    $parameter['nama'] = '%' . $kataKunci . '%';
    $parameter['username'] = '%' . $kataKunci . '%';
}
if ($roleFilter !== '') {
    $kondisi[] = 'role = :role';
    $parameter['role'] = $roleFilter;
}
$where = $kondisi === [] ? '' : ' WHERE ' . implode(' AND ', $kondisi);

$statement = db()->prepare('SELECT COUNT(*) FROM tb_user' . $where);
$statement->execute($parameter);
$pagination = paginate((int) $statement->fetchColumn(), 10, $halamanDiminta);

$statement = db()->prepare(
    'SELECT id_user, nama_lengkap, username, role, status_aktif
     FROM tb_user' . $where . '
     ORDER BY nama_lengkap ASC, id_user ASC
     LIMIT :batas OFFSET :offset'
);
foreach ($parameter as $nama => $nilai) {
    $statement->bindValue(':' . $nama, $nilai, PDO::PARAM_STR);
}
$statement->bindValue(':batas', $pagination['per_page'], PDO::PARAM_INT);
$statement->bindValue(':offset', $pagination['offset'], PDO::PARAM_INT);
$statement->execute();
$pengguna = $statement->fetchAll();

$queryHalaman = ['q' => $kataKunci, 'role' => $roleFilter];
$flashSukses = flash_get('sukses');
$flashError = flash_get('error');
$judul = 'Pengguna';
$role = 'admin';
require __DIR__ . '/../includes/layout_header.php';
?>

<?php if ($flashSukses !== null): ?>
    <p class="flash" role="status"><?= e($flashSukses) ?></p>
<?php endif; ?>
<?php if ($flashError !== null): ?>
    <p class="flash" role="alert"><?= e($flashError) ?></p>
<?php endif; ?>

<div class="page-actions">
    <a class="button" href="<?= e(app_url('/admin/user_form.php')) ?>">Tambah pengguna</a>
</div>

<form class="filter-form" method="get" action="<?= e(app_url('/admin/user.php')) ?>" role="search">
    <div class="form-field">
        <label for="q">Cari nama atau username</label>
        <input id="q" name="q" type="search" value="<?= e($kataKunci) ?>" maxlength="100">
    </div>
    <div class="form-field">
        <label for="role-filter">Role</label>
        <select id="role-filter" name="role">
            <option value="">Semua role</option>
            <option value="admin"<?= $roleFilter === 'admin' ? ' selected' : '' ?>>Admin</option>
            <option value="petugas"<?= $roleFilter === 'petugas' ? ' selected' : '' ?>>Petugas</option>
            <option value="owner"<?= $roleFilter === 'owner' ? ' selected' : '' ?>>Owner</option>
        </select>
    </div>
    <div class="form-actions">
        <button class="button" type="submit">Terapkan</button>
        <a class="button button-secondary" href="<?= e(app_url('/admin/user.php')) ?>">Atur ulang</a>
    </div>
</form>

<div class="table-scroll" role="region" aria-label="Daftar pengguna" tabindex="0">
    <table class="data-table">
        <thead>
            <tr>
                <th scope="col">Nama lengkap</th>
                <th scope="col">Username</th>
                <th scope="col">Role</th>
                <th scope="col">Status</th>
                <th scope="col">Aksi</th>
            </tr>
        </thead>
        <tbody>
            <?php if ($pengguna === []): ?>
                <tr><td colspan="5">Tidak ada pengguna yang cocok.</td></tr>
            <?php else: ?>
                <?php foreach ($pengguna as $item): ?>
                    <?php
                    $labelRole = ['admin' => 'Admin', 'petugas' => 'Petugas', 'owner' => 'Owner'][$item['role']];
                    $statusAktif = (int) $item['status_aktif'] === 1;
                    ?>
                    <tr>
                        <td><?= e($item['nama_lengkap']) ?></td>
                        <td><?= e($item['username']) ?></td>
                        <td><span class="badge badge-role"><?= e($labelRole) ?></span></td>
                        <td><span class="badge <?= $statusAktif ? 'badge-aktif' : 'badge-nonaktif' ?>"><?= $statusAktif ? 'Aktif' : 'Nonaktif' ?></span></td>
                        <td>
                            <div class="table-actions">
                                <a class="button button-secondary" href="<?= e(app_url('/admin/user_form.php?id=' . (int) $item['id_user'])) ?>">Ubah</a>
                                <form method="post" action="<?= e(app_url('/admin/user_hapus.php')) ?>" data-confirm-delete data-confirm-message="<?= e('Hapus pengguna ' . $item['username'] . '?') ?>">
                                    <input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>">
                                    <input type="hidden" name="id_user" value="<?= (int) $item['id_user'] ?>">
                                    <button class="button button-secondary" type="submit">Hapus</button>
                                </form>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; ?>
            <?php endif; ?>
        </tbody>
    </table>
</div>

<nav class="pagination" aria-label="Halaman daftar pengguna">
    <?php if ($pagination['current_page'] > 1): ?>
        <a href="<?= e(app_url('/admin/user.php?' . http_build_query($queryHalaman + ['halaman' => $pagination['current_page'] - 1]))) ?>">Sebelumnya</a>
    <?php endif; ?>
    <span>Halaman <?= $pagination['current_page'] ?> dari <?= $pagination['total_pages'] ?></span>
    <?php if ($pagination['current_page'] < $pagination['total_pages']): ?>
        <a href="<?= e(app_url('/admin/user.php?' . http_build_query($queryHalaman + ['halaman' => $pagination['current_page'] + 1]))) ?>">Berikutnya</a>
    <?php endif; ?>
</nav>

<?php require __DIR__ . '/../includes/layout_footer.php'; ?>
