<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/log.php';
require_once __DIR__ . '/../config/database.php';

require_role(['petugas']);
$petugas = current_user();
if ($petugas === null) {
    redirect('/auth/login.php');
}

$idParkir = filter_var($_GET['id'] ?? null, FILTER_VALIDATE_INT);
if ($idParkir === false || $idParkir < 1) {
    http_response_code(400);
    exit('Nomor parkir tidak valid.');
}

$statement = db()->prepare(
    "SELECT t.id_parkir, t.waktu_masuk, t.waktu_keluar, t.durasi_jam, t.biaya_total,
            v.plat_nomor, v.jenis_kendaraan, a.nama_area,
            r.tarif_per_jam, u.nama_lengkap AS nama_petugas
     FROM tb_transaksi AS t
     INNER JOIN tb_kendaraan AS v ON v.id_kendaraan = t.id_kendaraan
     INNER JOIN tb_area_parkir AS a ON a.id_area = t.id_area
     INNER JOIN tb_tarif AS r ON r.id_tarif = t.id_tarif
     INNER JOIN tb_user AS u ON u.id_user = t.id_user
     WHERE t.id_parkir = :id AND t.status = 'keluar'"
);
$statement->execute(['id' => $idParkir]);
$struk = $statement->fetch();
if (!is_array($struk)) {
    http_response_code(404);
    exit('Struk hanya tersedia setelah kendaraan dicatat keluar.');
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    if (($_POST['aksi'] ?? '') !== 'cetak') {
        http_response_code(400);
        exit('Pilihan tindakan tidak dikenal.');
    }
    if (!csrf_check()) {
        http_response_code(403);
        exit('Permintaan cetak sudah kedaluwarsa. Muat ulang halaman.');
    }

    catat_log(
        (int) $petugas['id_user'],
        'Cetak struk: ' . (string) $struk['plat_nomor']
    );
    redirect('/petugas/struk.php?id=' . (int) $idParkir . '&cetak=1', 303);
}

$tokenCsrf = csrf_token();
$judul = 'Struk parkir';
$role = 'petugas';
require __DIR__ . '/../includes/layout_header.php';
?>

<section class="receipt-page">
    <article class="receipt" aria-label="Struk parkir kendaraan">
        <h2 class="receipt-title">BUKTI PARKIR</h2>
        <p class="receipt-place">Operasional Parkir</p>
        <div class="receipt-divider" aria-hidden="true"></div>
        <p class="receipt-row"><span>Nomor transaksi</span><strong class="receipt-value"><?= (int) $struk['id_parkir'] ?></strong></p>
        <p class="receipt-row"><span>Plat</span><strong class="receipt-value"><?= e($struk['plat_nomor']) ?></strong></p>
        <p class="receipt-row"><span>Jenis</span><span class="receipt-value"><?= e(ucfirst((string) $struk['jenis_kendaraan'])) ?></span></p>
        <p class="receipt-row"><span>Area</span><span class="receipt-value"><?= e($struk['nama_area']) ?></span></p>
        <p class="receipt-row"><span>Waktu masuk</span><span class="receipt-value"><?= e(format_tanggal_indonesia($struk['waktu_masuk'])) ?></span></p>
        <p class="receipt-row"><span>Waktu keluar</span><span class="receipt-value"><?= e(format_tanggal_indonesia($struk['waktu_keluar'])) ?></span></p>
        <p class="receipt-row"><span>Durasi</span><span class="receipt-value"><?= (int) $struk['durasi_jam'] ?> jam</span></p>
        <p class="receipt-row"><span>Tarif/jam</span><span class="receipt-value"><?= e(format_rupiah($struk['tarif_per_jam'])) ?></span></p>
        <div class="receipt-divider" aria-hidden="true"></div>
        <p class="receipt-row receipt-total"><strong>Total biaya</strong><strong class="receipt-value"><?= e(format_rupiah($struk['biaya_total'])) ?></strong></p>
        <p class="receipt-row"><span>Petugas</span><span class="receipt-value"><?= e($struk['nama_petugas']) ?></span></p>
        <div class="receipt-divider" aria-hidden="true"></div>
        <p class="receipt-closing">Terima kasih. Hati-hati di jalan.</p>
    </article>
    <div class="receipt-actions no-print">
        <form method="post" action="<?= e(app_url('/petugas/struk.php?id=' . (int) $idParkir)) ?>">
            <input type="hidden" name="_csrf" value="<?= e($tokenCsrf) ?>">
            <input type="hidden" name="aksi" value="cetak">
            <button class="button" type="submit">Cetak</button>
        </form>
        <a class="button button-secondary" href="<?= e(app_url('/petugas/transaksi.php')) ?>">Kembali ke transaksi</a>
    </div>
</section>

<script src="<?= e(app_url('/assets/js/struk.js')) ?>" defer></script>
<?php require __DIR__ . '/../includes/layout_footer.php'; ?>
