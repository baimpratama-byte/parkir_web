<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';

require_role(['admin']);
$masukanCari = $_GET['q'] ?? '';
$kataKunci = is_string($masukanCari) ? substr(trim($masukanCari), 0, 100) : '';
$halamanDiminta = filter_var($_GET['halaman'] ?? 1, FILTER_VALIDATE_INT);
$halamanDiminta = $halamanDiminta === false || $halamanDiminta < 1 ? 1 : $halamanDiminta;
$where = $kataKunci === '' ? '' : ' WHERE nama_area LIKE :nama';
$parameter = $kataKunci === '' ? [] : ['nama' => '%' . $kataKunci . '%'];
$statement = db()->prepare('SELECT COUNT(*) FROM tb_area_parkir' . $where);
$statement->execute($parameter);
$pagination = paginate((int) $statement->fetchColumn(), 10, $halamanDiminta);
$statement = db()->prepare(
    'SELECT id_area, nama_area, kapasitas, terisi FROM tb_area_parkir' . $where . '
     ORDER BY nama_area ASC LIMIT :batas OFFSET :offset'
);
foreach ($parameter as $nama => $nilai) {
    $statement->bindValue(':' . $nama, $nilai, PDO::PARAM_STR);
}
$statement->bindValue(':batas', $pagination['per_page'], PDO::PARAM_INT);
$statement->bindValue(':offset', $pagination['offset'], PDO::PARAM_INT);
$statement->execute();
$areaParkir = $statement->fetchAll();
$flashSukses = flash_get('sukses');
$flashError = flash_get('error');
$judul = 'Area parkir';
$role = 'admin';
require __DIR__ . '/../includes/layout_header.php';
?>
<?php if ($flashSukses !== null): ?><p class="flash" role="status"><?= e($flashSukses) ?></p><?php endif; ?>
<?php if ($flashError !== null): ?><p class="flash" role="alert"><?= e($flashError) ?></p><?php endif; ?>
<div class="page-actions"><a class="button" href="<?= e(app_url('/admin/area_parkir_form.php')) ?>">Tambah area</a></div>
<form class="filter-form" method="get" action="<?= e(app_url('/admin/area_parkir.php')) ?>" role="search">
    <div class="form-field"><label for="q">Cari nama area</label><input id="q" name="q" type="search" value="<?= e($kataKunci) ?>" maxlength="100"></div>
    <div class="form-actions"><button class="button" type="submit">Cari</button><a class="button button-secondary" href="<?= e(app_url('/admin/area_parkir.php')) ?>">Atur ulang</a></div>
</form>
<div class="table-scroll" role="region" aria-label="Daftar area parkir" tabindex="0">
    <table class="data-table">
        <thead><tr><th scope="col">Area</th><th scope="col">Kapasitas</th><th scope="col">Terisi</th><th scope="col">Sisa</th><th scope="col">Penggunaan</th><th scope="col">Aksi</th></tr></thead>
        <tbody>
            <?php if ($areaParkir === []): ?><tr><td colspan="6">Belum ada area yang cocok.</td></tr><?php endif; ?>
            <?php foreach ($areaParkir as $area): ?>
                <?php
                $kapasitas = max(1, (int) $area['kapasitas']);
                $terisi = max(0, (int) $area['terisi']);
                $sisa = max(0, $kapasitas - $terisi);
                $persentase = ($terisi / $kapasitas) * 100;
                $peringatan = $persentase >= 90;
                ?>
                <tr>
                    <td><?= e($area['nama_area']) ?></td>
                    <td><?= $kapasitas ?></td>
                    <td><?= $terisi ?></td>
                    <td><?= $sisa ?></td>
                    <td>
                        <progress class="capacity-progress<?= $peringatan ? ' capacity-warning' : '' ?>" value="<?= min($terisi, $kapasitas) ?>" max="<?= $kapasitas ?>" aria-label="<?= e(sprintf('%d dari %d tempat terisi', $terisi, $kapasitas)) ?>"></progress>
                        <span class="capacity-caption"><?= e(sprintf('%d%%', (int) round($persentase))) ?></span>
                    </td>
                    <td><div class="table-actions">
                        <a class="button button-secondary" href="<?= e(app_url('/admin/area_parkir_form.php?id=' . (int) $area['id_area'])) ?>">Ubah</a>
                        <form method="post" action="<?= e(app_url('/admin/area_parkir_hapus.php')) ?>" data-confirm-delete data-confirm-message="<?= e('Hapus area ' . $area['nama_area'] . '?') ?>">
                            <input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>">
                            <input type="hidden" name="id_area" value="<?= (int) $area['id_area'] ?>">
                            <button class="button button-secondary" type="submit">Hapus</button>
                        </form>
                    </div></td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</div>
<nav class="pagination" aria-label="Halaman area parkir">
    <?php if ($pagination['current_page'] > 1): ?><a href="<?= e(app_url('/admin/area_parkir.php?' . http_build_query(['q' => $kataKunci, 'halaman' => $pagination['current_page'] - 1]))) ?>">Sebelumnya</a><?php endif; ?>
    <span>Halaman <?= $pagination['current_page'] ?> dari <?= $pagination['total_pages'] ?></span>
    <?php if ($pagination['current_page'] < $pagination['total_pages']): ?><a href="<?= e(app_url('/admin/area_parkir.php?' . http_build_query(['q' => $kataKunci, 'halaman' => $pagination['current_page'] + 1]))) ?>">Berikutnya</a><?php endif; ?>
</nav>
<?php require __DIR__ . '/../includes/layout_footer.php'; ?>
