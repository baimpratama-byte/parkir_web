<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';

require_role(['admin']);
$masukanCari = $_GET['q'] ?? '';
$kataKunci = is_string($masukanCari) ? substr(trim($masukanCari), 0, 100) : '';
$halamanDiminta = filter_var($_GET['halaman'] ?? 1, FILTER_VALIDATE_INT);
$halamanDiminta = $halamanDiminta === false || $halamanDiminta < 1 ? 1 : $halamanDiminta;
$where = $kataKunci === ''
    ? ''
    : ' WHERE k.plat_nomor LIKE :plat OR k.pemilik LIKE :pemilik OR k.warna LIKE :warna
         OR k.jenis_kendaraan LIKE :jenis OR u.nama_lengkap LIKE :pencatat';
$parameter = $kataKunci === '' ? [] : [
    'plat' => '%' . $kataKunci . '%',
    'pemilik' => '%' . $kataKunci . '%',
    'warna' => '%' . $kataKunci . '%',
    'jenis' => '%' . $kataKunci . '%',
    'pencatat' => '%' . $kataKunci . '%',
];
$fromJoin = ' FROM tb_kendaraan k INNER JOIN tb_user u ON u.id_user = k.id_user';
$statement = db()->prepare('SELECT COUNT(*)' . $fromJoin . $where);
$statement->execute($parameter);
$pagination = paginate((int) $statement->fetchColumn(), 10, $halamanDiminta);
$statement = db()->prepare(
    'SELECT k.id_kendaraan, k.plat_nomor, k.jenis_kendaraan, k.warna, k.pemilik,
            u.nama_lengkap AS nama_pencatat' . $fromJoin . $where . '
     ORDER BY k.plat_nomor ASC
     LIMIT :batas OFFSET :offset'
);
foreach ($parameter as $nama => $nilai) {
    $statement->bindValue(':' . $nama, $nilai, PDO::PARAM_STR);
}
$statement->bindValue(':batas', $pagination['per_page'], PDO::PARAM_INT);
$statement->bindValue(':offset', $pagination['offset'], PDO::PARAM_INT);
$statement->execute();
$kendaraan = $statement->fetchAll();
$flashSukses = flash_get('sukses');
$flashError = flash_get('error');
$judul = 'Kendaraan';
$role = 'admin';
require __DIR__ . '/../includes/layout_header.php';
?>
<?php if ($flashSukses !== null): ?><p class="flash" role="status"><?= e($flashSukses) ?></p><?php endif; ?>
<?php if ($flashError !== null): ?><p class="flash" role="alert"><?= e($flashError) ?></p><?php endif; ?>
<div class="page-actions"><a class="button" href="<?= e(app_url('/admin/kendaraan_form.php')) ?>">Tambah kendaraan</a></div>
<form class="filter-form" method="get" action="<?= e(app_url('/admin/kendaraan.php')) ?>" role="search">
    <div class="form-field"><label for="q">Cari plat, pemilik, jenis, warna, atau pencatat</label><input id="q" name="q" type="search" value="<?= e($kataKunci) ?>" maxlength="100"></div>
    <div class="form-actions"><button class="button" type="submit">Cari</button><a class="button button-secondary" href="<?= e(app_url('/admin/kendaraan.php')) ?>">Atur ulang</a></div>
</form>
<div class="table-scroll" role="region" aria-label="Daftar kendaraan" tabindex="0">
    <table class="data-table">
        <thead><tr><th scope="col">Plat nomor</th><th scope="col">Jenis</th><th scope="col">Warna</th><th scope="col">Pemilik</th><th scope="col">Dicatat oleh</th><th scope="col">Aksi</th></tr></thead>
        <tbody>
            <?php if ($kendaraan === []): ?><tr><td colspan="6">Belum ada kendaraan yang cocok.</td></tr><?php endif; ?>
            <?php foreach ($kendaraan as $item): ?>
                <tr>
                    <td><span class="plate-number"><?= e($item['plat_nomor']) ?></span></td>
                    <td><?= e(ucfirst((string) $item['jenis_kendaraan'])) ?></td>
                    <td><?= e($item['warna']) ?></td>
                    <td><?= e($item['pemilik']) ?></td>
                    <td><?= e($item['nama_pencatat']) ?></td>
                    <td><div class="table-actions">
                        <a class="button button-secondary" href="<?= e(app_url('/admin/kendaraan_form.php?id=' . (int) $item['id_kendaraan'])) ?>">Ubah</a>
                        <form method="post" action="<?= e(app_url('/admin/kendaraan_hapus.php')) ?>" data-confirm-delete data-confirm-message="<?= e('Hapus kendaraan ' . $item['plat_nomor'] . '?') ?>">
                            <input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>">
                            <input type="hidden" name="id_kendaraan" value="<?= (int) $item['id_kendaraan'] ?>">
                            <button class="button button-secondary" type="submit">Hapus</button>
                        </form>
                    </div></td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</div>
<nav class="pagination" aria-label="Halaman kendaraan">
    <?php if ($pagination['current_page'] > 1): ?><a href="<?= e(app_url('/admin/kendaraan.php?' . http_build_query(['q' => $kataKunci, 'halaman' => $pagination['current_page'] - 1]))) ?>">Sebelumnya</a><?php endif; ?>
    <span>Halaman <?= $pagination['current_page'] ?> dari <?= $pagination['total_pages'] ?></span>
    <?php if ($pagination['current_page'] < $pagination['total_pages']): ?><a href="<?= e(app_url('/admin/kendaraan.php?' . http_build_query(['q' => $kataKunci, 'halaman' => $pagination['current_page'] + 1]))) ?>">Berikutnya</a><?php endif; ?>
</nav>
<?php require __DIR__ . '/../includes/layout_footer.php'; ?>
