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
    "SELECT t.id_parkir, t.waktu_masuk, v.plat_nomor, v.jenis_kendaraan,
            a.nama_area, u.nama_lengkap AS nama_petugas
     FROM tb_transaksi AS t
     INNER JOIN tb_kendaraan AS v ON v.id_kendaraan = t.id_kendaraan
     INNER JOIN tb_area_parkir AS a ON a.id_area = t.id_area
     INNER JOIN tb_user AS u ON u.id_user = t.id_user
     WHERE t.id_parkir = :id AND t.id_user = :id_user AND t.status = 'masuk'"
);
$statement->execute([
    'id' => $idParkir,
    'id_user' => (int) $petugas['id_user'],
]);
$karcis = $statement->fetch();
if (!is_array($karcis)) {
    http_response_code(404);
    exit('Karcis masuk tidak tersedia.');
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
        'Cetak karcis masuk: ' . (string) $karcis['plat_nomor']
    );
    redirect('/petugas/struk_masuk.php?id=' . (int) $idParkir . '&cetak=1', 303);
}

$tokenCsrf = csrf_token();
$judul = 'Karcis masuk';
$role = 'petugas';
require __DIR__ . '/../includes/layout_header.php';
?>

<section class="receipt-page">
    <article class="receipt" aria-label="Karcis masuk kendaraan">
        <h2 class="receipt-title">KARCIS PARKIR</h2>
        <p class="receipt-place">Operasional Parkir</p>
        <div class="receipt-divider" aria-hidden="true"></div>
        <p class="receipt-row"><span>Nomor transaksi</span><strong class="receipt-value"><?= (int) $karcis['id_parkir'] ?></strong></p>
        <p class="receipt-row"><span>Plat</span><strong class="receipt-value"><?= e($karcis['plat_nomor']) ?></strong></p>
        <p class="receipt-row"><span>Jenis</span><span class="receipt-value"><?= e(ucfirst((string) $karcis['jenis_kendaraan'])) ?></span></p>
        <p class="receipt-row"><span>Area</span><span class="receipt-value"><?= e($karcis['nama_area']) ?></span></p>
        <p class="receipt-row"><span>Waktu masuk</span><span class="receipt-value"><?= e(format_tanggal_indonesia($karcis['waktu_masuk'])) ?></span></p>
        <p class="receipt-row"><span>Petugas</span><span class="receipt-value"><?= e($karcis['nama_petugas']) ?></span></p>
        <div class="receipt-divider" aria-hidden="true"></div>
        <p class="receipt-closing">Simpan karcis ini sampai kendaraan keluar.</p>
    </article>
    <div class="receipt-actions no-print">
        <form method="post" action="<?= e(app_url('/petugas/struk_masuk.php?id=' . (int) $idParkir)) ?>">
            <input type="hidden" name="_csrf" value="<?= e($tokenCsrf) ?>">
            <input type="hidden" name="aksi" value="cetak">
            <button class="button" type="submit">Cetak</button>
        </form>
        <a class="button button-secondary" href="<?= e(app_url('/petugas/transaksi.php')) ?>">Kembali ke transaksi</a>
    </div>
</section>

<script src="<?= e(app_url('/assets/js/struk.js')) ?>" defer></script>
<?php require __DIR__ . '/../includes/layout_footer.php'; ?>
