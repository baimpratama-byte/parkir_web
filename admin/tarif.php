<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';

require_role(['admin']);

$masukanCari = $_GET['q'] ?? '';
$kataKunci = is_string($masukanCari) ? trim($masukanCari) : '';
$kataKunci = substr($kataKunci, 0, 100);
$halamanDiminta = filter_var($_GET['halaman'] ?? 1, FILTER_VALIDATE_INT);
$halamanDiminta = $halamanDiminta === false || $halamanDiminta < 1 ? 1 : $halamanDiminta;
$where = $kataKunci === '' ? '' : ' WHERE jenis_kendaraan LIKE :jenis';
$parameter = $kataKunci === '' ? [] : ['jenis' => '%' . $kataKunci . '%'];

$statement = db()->prepare('SELECT COUNT(*) FROM tb_tarif' . $where);
$statement->execute($parameter);
$pagination = paginate((int) $statement->fetchColumn(), 10, $halamanDiminta);
$statement = db()->prepare(
    'SELECT id_tarif, jenis_kendaraan, tarif_per_jam
     FROM tb_tarif' . $where . '
     ORDER BY jenis_kendaraan ASC
     LIMIT :batas OFFSET :offset'
);
foreach ($parameter as $nama => $nilai) {
    $statement->bindValue(':' . $nama, $nilai, PDO::PARAM_STR);
}
$statement->bindValue(':batas', $pagination['per_page'], PDO::PARAM_INT);
$statement->bindValue(':offset', $pagination['offset'], PDO::PARAM_INT);
$statement->execute();
$tarif = $statement->fetchAll();

$flashSukses = flash_get('sukses');
$flashError = flash_get('error');
$judul = 'Tarif parkir';
$role = 'admin';
require __DIR__ . '/../includes/layout_header.php';
?>

<?php if ($flashSukses !== null): ?><p class="flash" role="status"><?= e($flashSukses) ?></p><?php endif; ?>
<?php if ($flashError !== null): ?><p class="flash" role="alert"><?= e($flashError) ?></p><?php endif; ?>

<div class="page-actions">
    <a class="button" href="<?= e(app_url('/admin/tarif_form.php')) ?>">Tambah tarif</a>
</div>
<form class="filter-form" method="get" action="<?= e(app_url('/admin/tarif.php')) ?>" role="search">
    <div class="form-field">
        <label for="q">Cari jenis kendaraan</label>
        <input id="q" name="q" type="search" value="<?= e($kataKunci) ?>" maxlength="100">
    </div>
    <div class="form-actions">
        <button class="button" type="submit">Cari</button>
        <a class="button button-secondary" href="<?= e(app_url('/admin/tarif.php')) ?>">Atur ulang</a>
    </div>
</form>

<div class="table-scroll" role="region" aria-label="Daftar tarif parkir" tabindex="0">
    <table class="data-table">
        <thead><tr><th scope="col">Jenis kendaraan</th><th scope="col">Tarif per jam</th><th scope="col">Aksi</th></tr></thead>
        <tbody>
            <?php if ($tarif === []): ?>
                <tr><td colspan="3">Belum ada tarif yang cocok.</td></tr>
            <?php else: ?>
                <?php foreach ($tarif as $item): ?>
                    <tr>
                        <td><?= e(ucfirst((string) $item['jenis_kendaraan'])) ?></td>
                        <td><?= e(format_rupiah($item['tarif_per_jam'])) ?></td>
                        <td><div class="table-actions">
                            <a class="button button-secondary" href="<?= e(app_url('/admin/tarif_form.php?id=' . (int) $item['id_tarif'])) ?>">Ubah</a>
                            <form method="post" action="<?= e(app_url('/admin/tarif_hapus.php')) ?>" data-confirm-delete data-confirm-message="<?= e('Hapus tarif ' . $item['jenis_kendaraan'] . '?') ?>">
                                <input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>">
                                <input type="hidden" name="id_tarif" value="<?= (int) $item['id_tarif'] ?>">
                                <button class="button button-secondary" type="submit">Hapus</button>
                            </form>
                        </div></td>
                    </tr>
                <?php endforeach; ?>
            <?php endif; ?>
        </tbody>
    </table>
</div>
<nav class="pagination" aria-label="Halaman tarif">
    <?php if ($pagination['current_page'] > 1): ?><a href="<?= e(app_url('/admin/tarif.php?' . http_build_query(['q' => $kataKunci, 'halaman' => $pagination['current_page'] - 1]))) ?>">Sebelumnya</a><?php endif; ?>
    <span>Halaman <?= $pagination['current_page'] ?> dari <?= $pagination['total_pages'] ?></span>
    <?php if ($pagination['current_page'] < $pagination['total_pages']): ?><a href="<?= e(app_url('/admin/tarif.php?' . http_build_query(['q' => $kataKunci, 'halaman' => $pagination['current_page'] + 1]))) ?>">Berikutnya</a><?php endif; ?>
</nav>
<?php require __DIR__ . '/../includes/layout_footer.php'; ?>
