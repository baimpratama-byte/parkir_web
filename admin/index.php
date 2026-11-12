<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../config/database.php';

require_role(['admin']);

$jumlahUserAktif = (int) db()->query(
    'SELECT COUNT(*) FROM tb_user WHERE status_aktif = 1'
)->fetchColumn();

$kendaraanTerparkir = (int) db()->query(
    "SELECT COUNT(*) FROM tb_transaksi WHERE status = 'masuk'"
)->fetchColumn();

$jumlahAreaHampirPenuh = (int) db()->query(
    'SELECT COUNT(*)
     FROM tb_area_parkir
     WHERE kapasitas > 0 AND terisi >= kapasitas * 0.9'
)->fetchColumn();

$areaHampirPenuh = db()->query(
    'SELECT id_area, nama_area, kapasitas, terisi
     FROM tb_area_parkir
     WHERE kapasitas > 0 AND terisi >= kapasitas * 0.9
     ORDER BY (terisi / kapasitas) DESC, nama_area ASC
     LIMIT 5'
)->fetchAll();

$logTerbaru = db()->query(
    'SELECT l.waktu_aktivitas, l.aktivitas, u.nama_lengkap
     FROM (
         SELECT id_log, id_user, waktu_aktivitas, aktivitas
         FROM tb_log_aktivitas FORCE INDEX (idx_log_waktu)
         ORDER BY waktu_aktivitas DESC, id_log DESC
         LIMIT 5
     ) AS l
     LEFT JOIN tb_user AS u ON u.id_user = l.id_user
     ORDER BY l.waktu_aktivitas DESC, l.id_log DESC'
)->fetchAll();

$judul = 'Ringkasan';
$role = 'admin';
require __DIR__ . '/../includes/layout_header.php';
?>

<section class="summary-section" aria-labelledby="operasional-heading">
    <h2 id="operasional-heading">Operasional</h2>
    <div class="table-scroll" role="region" aria-label="Ringkasan operasional" tabindex="0">
        <table class="data-table">
            <tbody>
                <tr>
                    <th scope="row"><a href="<?= e(app_url('/admin/user.php')) ?>">Pengguna aktif</a></th>
                    <td class="numeric"><a href="<?= e(app_url('/admin/user.php')) ?>"><?= $jumlahUserAktif ?></a></td>
                </tr>
                <tr>
                    <th scope="row"><a href="<?= e(app_url('/admin/kendaraan.php')) ?>">Kendaraan terparkir sekarang</a></th>
                    <td class="numeric"><a href="<?= e(app_url('/admin/kendaraan.php')) ?>"><?= $kendaraanTerparkir ?></a></td>
                </tr>
                <tr>
                    <th scope="row"><a href="<?= e(app_url('/admin/area_parkir.php')) ?>">Area hampir penuh (90% atau lebih)</a></th>
                    <td class="numeric"><a href="<?= e(app_url('/admin/area_parkir.php')) ?>"><?= $jumlahAreaHampirPenuh ?></a></td>
                </tr>
            </tbody>
        </table>
    </div>
</section>

<section class="summary-section" aria-labelledby="area-hampir-penuh-heading">
    <h2 id="area-hampir-penuh-heading">5 area hampir penuh</h2>
    <?php if ($areaHampirPenuh === []): ?>
        <p>Tidak ada area yang terisi 90% atau lebih.</p>
    <?php else: ?>
        <div class="table-scroll" role="region" aria-label="Area hampir penuh" tabindex="0">
            <table class="data-table">
                <thead><tr><th scope="col">Area</th><th scope="col">Terisi</th><th scope="col">Kapasitas</th><th scope="col">Persentase</th></tr></thead>
                <tbody>
                    <?php foreach ($areaHampirPenuh as $area): ?>
                        <?php $persentase = (int) round(((int) $area['terisi'] / (int) $area['kapasitas']) * 100); ?>
                        <tr>
                            <td><a href="<?= e(app_url('/admin/area_parkir.php')) ?>"><?= e($area['nama_area']) ?></a></td>
                            <td><?= (int) $area['terisi'] ?></td>
                            <td><?= (int) $area['kapasitas'] ?></td>
                            <td><?= $persentase ?>%</td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php if ($jumlahAreaHampirPenuh > count($areaHampirPenuh)): ?>
            <p><a href="<?= e(app_url('/admin/area_parkir.php')) ?>">Lihat semua <?= $jumlahAreaHampirPenuh ?> area yang hampir penuh</a></p>
        <?php endif; ?>
    <?php endif; ?>
</section>

<section class="summary-section" aria-labelledby="log-terbaru-heading">
    <h2 id="log-terbaru-heading"><a href="<?= e(app_url('/admin/log.php')) ?>">5 log terbaru</a></h2>
    <?php if ($logTerbaru === []): ?>
        <p>Belum ada aktivitas tercatat.</p>
    <?php else: ?>
        <div class="table-scroll" role="region" aria-label="Lima log aktivitas terbaru" tabindex="0">
            <table class="data-table">
                <thead><tr><th scope="col">Waktu</th><th scope="col">Nama user</th><th scope="col">Aktivitas</th></tr></thead>
                <tbody>
                    <?php foreach ($logTerbaru as $item): ?>
                        <tr>
                            <td><a href="<?= e(app_url('/admin/log.php')) ?>"><time datetime="<?= e((new DateTimeImmutable($item['waktu_aktivitas']))->format('Y-m-d\TH:i:s')) ?>"><?= e(format_tanggal_indonesia($item['waktu_aktivitas'])) ?></time></a></td>
                            <td><?= $item['nama_lengkap'] === null ? 'Pengguna tidak tersedia' : e($item['nama_lengkap']) ?></td>
                            <td><?= e($item['aktivitas']) ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</section>

<?php require __DIR__ . '/../includes/layout_footer.php'; ?>
