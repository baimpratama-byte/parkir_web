<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../config/database.php';

require_role(['owner']);

$hariIni = new DateTimeImmutable('today');
$tanggalDefaultMulai = $hariIni->modify('-6 days')->format('Y-m-d');
$tanggalDefaultAkhir = $hariIni->format('Y-m-d');
$mulaiMasukan = $_GET['mulai'] ?? $tanggalDefaultMulai;
$akhirMasukan = $_GET['akhir'] ?? $tanggalDefaultAkhir;
$mulai = is_string($mulaiMasukan) ? trim($mulaiMasukan) : '';
$akhir = is_string($akhirMasukan) ? trim($akhirMasukan) : '';
$pesanError = null;

$tanggalValid = static function (string $tanggal): bool {
    $hasil = DateTimeImmutable::createFromFormat('!Y-m-d', $tanggal);
    $kesalahan = DateTimeImmutable::getLastErrors();

    return $hasil !== false
        && ($kesalahan === false || ($kesalahan['warning_count'] === 0 && $kesalahan['error_count'] === 0))
        && $hasil->format('Y-m-d') === $tanggal;
};

if (!$tanggalValid($mulai) || !$tanggalValid($akhir)) {
    http_response_code(400);
    $pesanError = 'Tanggal rekap tidak valid.';
    $mulai = $tanggalDefaultMulai;
    $akhir = $tanggalDefaultAkhir;
}

$tanggalMulai = new DateTimeImmutable($mulai);
$tanggalAkhir = new DateTimeImmutable($akhir);
$akhirMaksimal = $tanggalMulai->modify('+1 year')->modify('-1 day');
if ($tanggalAkhir < $tanggalMulai) {
    http_response_code(400);
    $pesanError = 'Tanggal akhir tidak boleh sebelum tanggal mulai.';
    $mulai = $tanggalDefaultMulai;
    $akhir = $tanggalDefaultAkhir;
} elseif ($tanggalAkhir > $akhirMaksimal) {
    http_response_code(400);
    $pesanError = 'Rentang rekap maksimal satu tahun.';
    $mulai = $tanggalDefaultMulai;
    $akhir = $tanggalDefaultAkhir;
}

$jenisMasukan = $_GET['jenis'] ?? '';
$jenisKendaraan = is_string($jenisMasukan) ? $jenisMasukan : '';
$jenisTersedia = db()->query(
    'SELECT DISTINCT jenis_kendaraan FROM tb_kendaraan ORDER BY jenis_kendaraan'
)->fetchAll(PDO::FETCH_COLUMN);
if ($jenisKendaraan !== '' && !in_array($jenisKendaraan, $jenisTersedia, true)) {
    http_response_code(400);
    $pesanError = 'Jenis kendaraan tidak tersedia.';
    $jenisKendaraan = '';
}

$areaMasukan = $_GET['area'] ?? '';
$areaFilter = is_string($areaMasukan) ? $areaMasukan : '';
$daftarArea = db()->query(
    'SELECT id_area, nama_area FROM tb_area_parkir ORDER BY nama_area'
)->fetchAll();
$areaId = null;
if ($areaFilter !== '') {
    $areaId = filter_var($areaFilter, FILTER_VALIDATE_INT);
    $idAreaTersedia = array_map(static fn(array $area): string => (string) $area['id_area'], $daftarArea);
    if ($areaId === false || !in_array((string) $areaId, $idAreaTersedia, true)) {
        http_response_code(400);
        $pesanError = 'Area parkir tidak tersedia.';
        $areaFilter = '';
        $areaId = null;
    }
}

$kondisi = [
    "t.status = 'keluar'",
    't.waktu_keluar >= :mulai',
    't.waktu_keluar < :akhir',
];
$parameter = [
    'mulai' => $mulai . ' 00:00:00',
    'akhir' => (new DateTimeImmutable($akhir))->modify('+1 day')->format('Y-m-d') . ' 00:00:00',
];
if ($jenisKendaraan !== '') {
    $kondisi[] = 'v.jenis_kendaraan = :jenis';
    $parameter['jenis'] = $jenisKendaraan;
}
if ($areaId !== null) {
    $kondisi[] = 't.id_area = :id_area';
    $parameter['id_area'] = $areaId;
}
$where = ' WHERE ' . implode(' AND ', $kondisi);
$fromJoin = ' FROM tb_transaksi AS t FORCE INDEX (idx_transaksi_rekap)
              INNER JOIN tb_kendaraan AS v ON v.id_kendaraan = t.id_kendaraan
              INNER JOIN tb_area_parkir AS a ON a.id_area = t.id_area';

$statement = db()->prepare(
    'SELECT COUNT(*) AS total_transaksi,
            COALESCE(SUM(t.biaya_total), 0) AS total_pendapatan,
            COALESCE(AVG(t.durasi_jam), 0) AS rata_rata_durasi' .
    $fromJoin . $where
);
$statement->execute($parameter);
$ringkasan = $statement->fetch();
if (!is_array($ringkasan)) {
    throw new RuntimeException('Ringkasan transaksi tidak dapat dihitung.');
}

$pagination = paginate((int) $ringkasan['total_transaksi'], 20, max(
    1,
    (int) (filter_var($_GET['halaman'] ?? 1, FILTER_VALIDATE_INT) ?: 1)
));

$queryTransaksi = static function (PDO $pdo, string $where, array $parameter, ?array $pagination): PDOStatement {
    $sql = 'SELECT t.id_parkir, t.waktu_masuk, t.waktu_keluar, t.durasi_jam, t.biaya_total,
                   v.plat_nomor, v.jenis_kendaraan, a.nama_area
            FROM tb_transaksi AS t FORCE INDEX (idx_transaksi_rekap)
            INNER JOIN tb_kendaraan AS v ON v.id_kendaraan = t.id_kendaraan
            INNER JOIN tb_area_parkir AS a ON a.id_area = t.id_area' . $where . '
            ORDER BY t.waktu_keluar DESC, t.id_parkir DESC';
    if ($pagination !== null) {
        $sql .= ' LIMIT :batas OFFSET :offset';
    }

    $statement = $pdo->prepare($sql);
    foreach ($parameter as $nama => $nilai) {
        $statement->bindValue(
            ':' . $nama,
            $nilai,
            is_int($nilai) ? PDO::PARAM_INT : PDO::PARAM_STR
        );
    }
    if ($pagination !== null) {
        $statement->bindValue(':batas', $pagination['per_page'], PDO::PARAM_INT);
        $statement->bindValue(':offset', $pagination['offset'], PDO::PARAM_INT);
    }
    $statement->execute();

    return $statement;
};

$csvDiminta = ($_GET['unduh'] ?? '') === 'csv';
if ($csvDiminta) {
    $statement = $queryTransaksi(db(), $where, $parameter, null);
    header('Content-Type: text/csv; charset=UTF-8');
    header(
        'Content-Disposition: attachment; filename="rekap-parkir-' .
        $mulai . '-sampai-' . $akhir . '.csv"'
    );
    header('X-Content-Type-Options: nosniff');
    $keluaran = fopen('php://output', 'wb');
    if ($keluaran === false) {
        throw new RuntimeException('Berkas CSV tidak dapat dibuat.');
    }
    fwrite($keluaran, "\xEF\xBB\xBF");
    fputcsv($keluaran, ['Nomor transaksi', 'Plat', 'Jenis', 'Area', 'Waktu keluar', 'Durasi (jam)', 'Total biaya'], ',', '"', '');
    while ($transaksi = $statement->fetch()) {
        $nilaiCsv = [
            $transaksi['id_parkir'],
            $transaksi['plat_nomor'],
            $transaksi['jenis_kendaraan'],
            $transaksi['nama_area'],
            format_tanggal_indonesia($transaksi['waktu_keluar']),
            $transaksi['durasi_jam'],
            $transaksi['biaya_total'],
        ];
        foreach ($nilaiCsv as &$nilai) {
            $nilai = (string) $nilai;
            if (preg_match('/\A[\s]*[=+\-@]/u', $nilai) === 1) {
                $nilai = "'" . $nilai;
            }
        }
        unset($nilai);
        fputcsv($keluaran, $nilaiCsv, ',', '"', '');
    }
    fclose($keluaran);
    exit;
}

$statement = db()->prepare(
    'SELECT DATE(t.waktu_keluar) AS tanggal,
            COUNT(*) AS jumlah_transaksi,
            COALESCE(SUM(t.biaya_total), 0) AS pendapatan' .
    $fromJoin . $where . '
     GROUP BY DATE(t.waktu_keluar)
     ORDER BY tanggal ASC'
);
$statement->execute($parameter);
$rekapHarian = $statement->fetchAll();

$statement = $queryTransaksi(db(), $where, $parameter, $pagination);
$transaksi = $statement->fetchAll();
$queryFilter = [
    'mulai' => $mulai,
    'akhir' => $akhir,
    'jenis' => $jenisKendaraan,
    'area' => $areaFilter,
];
$queryCetak = $queryFilter + ['cetak' => '1'];
$periodeTeks = format_tanggal_indonesia($mulai . ' 00:00:00') . ' — '
    . format_tanggal_indonesia($akhir . ' 00:00:00');
$layoutClass = 'rekap-layout';
$judul = 'Rekap transaksi';
$role = 'owner';
require __DIR__ . '/../includes/layout_header.php';
?>

<header class="rekap-print-header">
    <h2>Rekap transaksi parkir</h2>
    <p>Periode: <?= e($periodeTeks) ?></p>
</header>

<?php if ($pesanError !== null): ?><p class="flash" role="alert"><?= e($pesanError) ?></p><?php endif; ?>

<form class="filter-form rekap-filter no-print" method="get" action="<?= e(app_url('/owner/rekap.php')) ?>">
    <div class="form-field">
        <label for="mulai">Tanggal mulai</label>
        <input id="mulai" name="mulai" type="date" value="<?= e($mulai) ?>" required>
    </div>
    <div class="form-field">
        <label for="akhir">Tanggal akhir</label>
        <input id="akhir" name="akhir" type="date" value="<?= e($akhir) ?>" required>
    </div>
    <div class="form-field">
        <label for="jenis">Jenis kendaraan</label>
        <select id="jenis" name="jenis">
            <option value="">Semua jenis</option>
            <?php foreach ($jenisTersedia as $jenis): ?>
                <option value="<?= e($jenis) ?>"<?= $jenisKendaraan === $jenis ? ' selected' : '' ?>><?= e(ucfirst((string) $jenis)) ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="form-field">
        <label for="area">Area parkir</label>
        <select id="area" name="area">
            <option value="">Semua area</option>
            <?php foreach ($daftarArea as $area): ?>
                <option value="<?= (int) $area['id_area'] ?>"<?= (string) $areaId === (string) $area['id_area'] ? ' selected' : '' ?>><?= e($area['nama_area']) ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="form-actions">
        <button class="button" type="submit">Terapkan filter</button>
        <a class="button button-secondary" href="<?= e(app_url('/owner/rekap.php')) ?>">Reset</a>
    </div>
</form>

<div class="rekap-actions no-print">
    <a class="button button-secondary" href="<?= e(app_url('/owner/rekap.php?' . http_build_query($queryCetak))) ?>">Cetak rekap</a>
    <a class="button button-secondary" href="<?= e(app_url('/owner/rekap.php?' . http_build_query($queryFilter + ['unduh' => 'csv']))) ?>">Unduh CSV</a>
</div>

<section class="rekap-summary" aria-label="Ringkasan periode">
    <div><span>Total transaksi</span><strong><?= number_format((int) $ringkasan['total_transaksi'], 0, ',', '.') ?></strong></div>
    <div><span>Total pendapatan</span><strong><?= e(format_rupiah($ringkasan['total_pendapatan'])) ?></strong></div>
    <div><span>Rata-rata durasi</span><strong><?= e(number_format((float) $ringkasan['rata_rata_durasi'], 1, ',', '.')) ?> jam</strong></div>
</section>

<?php if ((int) $ringkasan['total_transaksi'] === 0): ?>
    <div class="empty-state">
        <h2>Belum ada transaksi keluar pada periode ini</h2>
        <p>Ubah rentang tanggal atau filter jenis kendaraan dan area untuk melihat rekap.</p>
        <a class="button button-secondary no-print" href="<?= e(app_url('/owner/rekap.php')) ?>">Reset filter</a>
    </div>
<?php else: ?>
    <section class="rekap-section" aria-labelledby="transaksi-heading">
        <h2 id="transaksi-heading">Rincian transaksi</h2>
        <div class="table-scroll" role="region" aria-label="Rincian transaksi keluar" tabindex="0">
            <table class="data-table">
                <thead>
                    <tr>
                        <th scope="col">No. transaksi</th>
                        <th scope="col">Plat</th>
                        <th scope="col">Jenis</th>
                        <th scope="col">Area</th>
                        <th scope="col">Waktu keluar</th>
                        <th scope="col" class="numeric">Durasi</th>
                        <th scope="col" class="numeric">Biaya</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($transaksi as $item): ?>
                        <tr>
                            <td><?= (int) $item['id_parkir'] ?></td>
                            <td><span class="plate-number"><?= e($item['plat_nomor']) ?></span></td>
                            <td><?= e(ucfirst((string) $item['jenis_kendaraan'])) ?></td>
                            <td><?= e($item['nama_area']) ?></td>
                            <td><?= e(format_tanggal_indonesia($item['waktu_keluar'])) ?></td>
                            <td class="numeric"><?= (int) $item['durasi_jam'] ?> jam</td>
                            <td class="numeric"><?= e(format_rupiah($item['biaya_total'])) ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
                <tfoot>
                    <tr>
                        <th colspan="5" scope="row">Total seluruh hasil filter (<?= number_format((int) $ringkasan['total_transaksi'], 0, ',', '.') ?> transaksi)</th>
                        <td class="numeric"><?= e(number_format((float) $ringkasan['rata_rata_durasi'], 1, ',', '.')) ?> jam rata-rata</td>
                        <td class="numeric"><?= e(format_rupiah($ringkasan['total_pendapatan'])) ?></td>
                    </tr>
                </tfoot>
            </table>
        </div>
        <nav class="pagination no-print" aria-label="Halaman transaksi">
            <?php if ($pagination['current_page'] > 1): ?>
                <a href="<?= e(app_url('/owner/rekap.php?' . http_build_query($queryFilter + ['halaman' => $pagination['current_page'] - 1]))) ?>">Sebelumnya</a>
            <?php endif; ?>
            <span>Halaman <?= $pagination['current_page'] ?> dari <?= $pagination['total_pages'] ?></span>
            <?php if ($pagination['current_page'] < $pagination['total_pages']): ?>
                <a href="<?= e(app_url('/owner/rekap.php?' . http_build_query($queryFilter + ['halaman' => $pagination['current_page'] + 1]))) ?>">Berikutnya</a>
            <?php endif; ?>
        </nav>
    </section>

    <section class="rekap-section" aria-labelledby="harian-heading">
        <h2 id="harian-heading">Rekap per hari</h2>
        <div class="table-scroll" role="region" aria-label="Rekap transaksi harian" tabindex="0">
            <table class="data-table">
                <thead><tr><th scope="col">Tanggal</th><th scope="col" class="numeric">Jumlah</th><th scope="col" class="numeric">Pendapatan</th></tr></thead>
                <tbody>
                    <?php foreach ($rekapHarian as $harian): ?>
                        <tr>
                            <td><?= e(format_tanggal_indonesia($harian['tanggal'] . ' 00:00:00')) ?></td>
                            <td class="numeric"><?= number_format((int) $harian['jumlah_transaksi'], 0, ',', '.') ?></td>
                            <td class="numeric"><?= e(format_rupiah($harian['pendapatan'])) ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
                <tfoot>
                    <tr>
                        <th scope="row">Total</th>
                        <td class="numeric"><?= number_format((int) $ringkasan['total_transaksi'], 0, ',', '.') ?></td>
                        <td class="numeric"><?= e(format_rupiah($ringkasan['total_pendapatan'])) ?></td>
                    </tr>
                </tfoot>
            </table>
        </div>
    </section>
<?php endif; ?>

<script src="<?= e(app_url('/assets/js/rekap.js')) ?>" defer></script>
<?php require __DIR__ . '/../includes/layout_footer.php'; ?>
