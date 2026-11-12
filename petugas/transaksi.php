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

$aksi = $_POST['aksi'] ?? '';
$platInput = '';
$qInput = $_GET['q'] ?? '';
$kataKunci = is_string($qInput) ? substr(trim($qInput), 0, 30) : '';
$pesanError = null;

if (($_GET['aksi'] ?? '') === 'cari_kendaraan') {
    header('Content-Type: application/json; charset=utf-8');
    $platMasukan = $_GET['plat'] ?? '';
    $plat = is_string($platMasukan) ? strtoupper((string) preg_replace('/\s+/', ' ', trim($platMasukan))) : '';
    if ($plat === '' || strlen($plat) > 15) {
        http_response_code(400);
        echo json_encode(['error' => 'Plat nomor tidak valid.'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $statement = db()->prepare(
        'SELECT id_kendaraan, plat_nomor, jenis_kendaraan, warna, pemilik
         FROM tb_kendaraan WHERE plat_nomor = :plat LIMIT 1'
    );
    $statement->execute(['plat' => $plat]);
    $kendaraan = $statement->fetch();
    echo json_encode(
        is_array($kendaraan)
            ? ['terdaftar' => true, 'kendaraan' => $kendaraan]
            : ['terdaftar' => false],
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
    );
    exit;
}

$pesanKesalahanSp = static function (PDOException $exception): ?string {
    $pesanPerKode = [
        30001 => 'Area yang dipilih baru saja penuh. Pilih area lain yang masih tersedia.',
        30002 => 'Kendaraan ini masih tercatat masuk. Catat keluar lebih dahulu.',
        30003 => 'Data parkir tidak ditemukan.',
        30004 => 'Kendaraan sudah dicatat keluar.',
        30005 => 'Tarif untuk jenis kendaraan ini belum tersedia.',
        30006 => 'Lengkapi jenis kendaraan, warna, dan nama pemilik.',
        30007 => 'Area parkir yang dipilih tidak ditemukan.',
    ];

    $kode = (int) ($exception->errorInfo[1] ?? 0);
    if ($kode === 1062) {
        return 'Plat nomor sudah terdaftar. Periksa kendaraan yang sedang parkir sebelum mencatat masuk lagi.';
    }

    return $pesanPerKode[$kode] ?? null;
};

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    if (!csrf_check()) {
        http_response_code(403);
        $pesanError = 'Form sudah kedaluwarsa. Muat ulang halaman dan coba lagi.';
    } elseif ($aksi === 'masuk') {
        $platMasukan = $_POST['plat_nomor'] ?? null;
        $platBersih = is_string($platMasukan)
            ? preg_replace('/\s+/', ' ', trim($platMasukan))
            : '';
        $plat = strtoupper($platBersih ?? '');
        $platInput = $plat;
        $jenisMasukan = $_POST['jenis_kendaraan'] ?? null;
        $warnaMasukan = $_POST['warna'] ?? null;
        $pemilikMasukan = $_POST['pemilik'] ?? null;
        $jenis = is_string($jenisMasukan) ? $jenisMasukan : '';
        $warna = is_string($warnaMasukan) ? trim($warnaMasukan) : '';
        $pemilik = is_string($pemilikMasukan) ? trim($pemilikMasukan) : '';
        $idArea = filter_var($_POST['id_area'] ?? null, FILTER_VALIDATE_INT);

        if ($plat === '' || mb_strlen($plat) > 15) {
            $pesanError = 'Masukkan plat nomor yang benar (maksimal 15 karakter).';
        } elseif (
            ($jenis !== '' && !in_array($jenis, ['motor', 'mobil', 'lainnya'], true))
            || mb_strlen($warna) > 20
            || mb_strlen($pemilik) > 100
        ) {
            $pesanError = 'Periksa kembali jenis kendaraan, warna, dan nama pemilik.';
        } elseif ($idArea === false || $idArea < 1) {
            $pesanError = 'Pilih area parkir yang masih memiliki tempat.';
        } else {
            $pdo = db();
            $pdo->beginTransaction();
            try {
                $statement = $pdo->prepare(
                    'CALL sp_kendaraan_masuk(:plat, :jenis, :warna, :pemilik, :id_user, :id_area)'
                );
                $statement->execute([
                    'plat' => $plat,
                    'jenis' => $jenis === '' ? null : $jenis,
                    'warna' => $warna === '' ? null : $warna,
                    'pemilik' => $pemilik === '' ? null : $pemilik,
                    'id_user' => (int) $petugas['id_user'],
                    'id_area' => $idArea,
                ]);
                $hasil = $statement->fetch(PDO::FETCH_ASSOC);
                $statement->closeCursor();
                if (!is_array($hasil) || !isset($hasil['id_parkir'])) {
                    throw new RuntimeException('Prosedur masuk tidak mengembalikan nomor parkir.');
                }

                catat_log((int) $petugas['id_user'], 'Kendaraan masuk: ' . $plat);
                $pdo->commit();
                flash_set('sukses', 'Kendaraan ' . $plat . ' berhasil dicatat masuk.');
                redirect('/petugas/transaksi.php?masuk=' . (int) $hasil['id_parkir']);
            } catch (PDOException $exception) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                $pesanError = $pesanKesalahanSp($exception);
                if ($pesanError === null) {
                    throw $exception;
                }
            } catch (Throwable $exception) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                throw $exception;
            }
        }
    } elseif ($aksi === 'keluar') {
        $idParkir = filter_var($_POST['id_parkir'] ?? null, FILTER_VALIDATE_INT);
        if ($idParkir === false || $idParkir < 1) {
            $pesanError = 'Data kendaraan tidak valid. Muat ulang daftar kendaraan.';
        } else {
            $pdo = db();
            $pdo->beginTransaction();
            try {
                $statement = $pdo->prepare('CALL sp_kendaraan_keluar(:id_parkir, :id_user)');
                $statement->execute([
                    'id_parkir' => $idParkir,
                    'id_user' => (int) $petugas['id_user'],
                ]);
                $rincian = $statement->fetch(PDO::FETCH_ASSOC);
                $statement->closeCursor();
                if (!is_array($rincian) || !isset($rincian['plat_nomor'])) {
                    throw new RuntimeException('Prosedur keluar tidak mengembalikan rincian struk.');
                }

                catat_log(
                    (int) $petugas['id_user'],
                    'Kendaraan keluar: ' . (string) $rincian['plat_nomor']
                );
                $pdo->commit();
                redirect('/petugas/struk.php?id=' . (int) $idParkir);
            } catch (PDOException $exception) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                $pesanError = $pesanKesalahanSp($exception);
                if ($pesanError === null) {
                    throw $exception;
                }
            } catch (Throwable $exception) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                throw $exception;
            }
        }
    } else {
        http_response_code(400);
        $pesanError = 'Pilihan tindakan tidak dikenal.';
    }
}

$tokenCsrf = csrf_token();
$statement = db()->query(
    'SELECT id_area, nama_area, kapasitas, terisi,
            GREATEST(kapasitas - terisi, 0) AS sisa
     FROM tb_area_parkir
     WHERE terisi < kapasitas
     ORDER BY nama_area'
);
$areaTersedia = $statement->fetchAll();
$jenisTersedia = db()->query(
    'SELECT jenis_kendaraan FROM tb_tarif ORDER BY jenis_kendaraan'
)->fetchAll(PDO::FETCH_COLUMN);

$parameter = [];
$kondisi = ["t.status = 'masuk'"];
if ($kataKunci !== '') {
    $kondisi[] = 'v.plat_nomor LIKE :plat';
    $parameter['plat'] = '%' . $kataKunci . '%';
}
$where = ' WHERE ' . implode(' AND ', $kondisi);
$statement = db()->prepare(
    'SELECT COUNT(*)
     FROM tb_transaksi AS t
     INNER JOIN tb_kendaraan AS v ON v.id_kendaraan = t.id_kendaraan' . $where
);
$statement->execute($parameter);
$halamanMasukan = filter_var($_GET['halaman'] ?? 1, FILTER_VALIDATE_INT);
$halamanMasukan = $halamanMasukan === false || $halamanMasukan < 1 ? 1 : $halamanMasukan;
$pagination = paginate((int) $statement->fetchColumn(), 10, $halamanMasukan);
$statement = db()->prepare(
    'SELECT t.id_parkir, t.waktu_masuk, t.id_area, v.plat_nomor,
            v.jenis_kendaraan, a.nama_area, r.tarif_per_jam
     FROM tb_transaksi AS t
     INNER JOIN tb_kendaraan AS v ON v.id_kendaraan = t.id_kendaraan
     INNER JOIN tb_area_parkir AS a ON a.id_area = t.id_area
     INNER JOIN tb_tarif AS r ON r.id_tarif = t.id_tarif' . $where . '
     ORDER BY t.waktu_masuk DESC, t.id_parkir DESC
     LIMIT :batas OFFSET :offset'
);
foreach ($parameter as $nama => $nilai) {
    $statement->bindValue(':' . $nama, $nilai, PDO::PARAM_STR);
}
$statement->bindValue(':batas', $pagination['per_page'], PDO::PARAM_INT);
$statement->bindValue(':offset', $pagination['offset'], PDO::PARAM_INT);
$statement->execute();
$sedangParkir = $statement->fetchAll();

$pesanSukses = flash_get('sukses');
$idMasukKarcis = filter_var($_GET['masuk'] ?? null, FILTER_VALIDATE_INT);
$transaksiMasukKarcis = null;
if ($idMasukKarcis !== false && $idMasukKarcis > 0) {
    $statement = db()->prepare(
        "SELECT t.id_parkir, v.plat_nomor
         FROM tb_transaksi AS t
         INNER JOIN tb_kendaraan AS v ON v.id_kendaraan = t.id_kendaraan
         WHERE t.id_parkir = :id AND t.id_user = :id_user AND t.status = 'masuk'"
    );
    $statement->execute([
        'id' => $idMasukKarcis,
        'id_user' => (int) $petugas['id_user'],
    ]);
    $transaksiMasukKarcis = $statement->fetch();
}
$judul = 'Transaksi parkir';
$role = 'petugas';
require __DIR__ . '/../includes/layout_header.php';
?>

<?php if ($pesanSukses !== null): ?>
    <p class="flash" role="status">
        <?= e($pesanSukses) ?>
        <?php if (is_array($transaksiMasukKarcis)): ?>
            <a href="<?= e(app_url('/petugas/struk_masuk.php?id=' . (int) $transaksiMasukKarcis['id_parkir'])) ?>">
                Cetak karcis masuk (opsional) untuk <?= e($transaksiMasukKarcis['plat_nomor']) ?>
            </a>
        <?php endif; ?>
    </p>
<?php endif; ?>
<?php if ($pesanError !== null): ?><p class="flash" role="alert"><?= e($pesanError) ?></p><?php endif; ?>

<section class="transaction-section" aria-labelledby="kendaraan-masuk-heading">
    <h2 id="kendaraan-masuk-heading">Kendaraan masuk</h2>
    <form class="transaction-form" method="post" action="<?= e(app_url('/petugas/transaksi.php')) ?>">
        <input type="hidden" name="_csrf" value="<?= e($tokenCsrf) ?>">
        <input type="hidden" name="aksi" value="masuk">
        <div class="form-field">
            <label for="plat-nomor">Plat nomor</label>
            <input id="plat-nomor" name="plat_nomor" type="text" value="<?= e($platInput) ?>" maxlength="15" autocomplete="off" autocapitalize="characters" required autofocus aria-describedby="plat-status">
            <small class="field-help" id="plat-status" aria-live="polite">Ketik plat nomor untuk mencari data kendaraan.</small>
        </div>
        <div class="vehicle-extra-fields" id="vehicle-extra-fields">
            <div class="form-field">
                <label for="jenis-kendaraan">Jenis kendaraan</label>
                <select id="jenis-kendaraan" name="jenis_kendaraan" required>
                    <option value="">Pilih jenis</option>
                    <?php foreach ($jenisTersedia as $jenis): ?>
                        <option value="<?= e($jenis) ?>"<?= ($_POST['jenis_kendaraan'] ?? '') === $jenis ? ' selected' : '' ?>><?= e(ucfirst((string) $jenis)) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-field">
                <label for="warna">Warna</label>
                <input id="warna" name="warna" type="text" value="<?= e(is_string($_POST['warna'] ?? null) ? $_POST['warna'] : '') ?>" maxlength="20" autocomplete="off">
            </div>
            <div class="form-field">
                <label for="pemilik">Nama pemilik</label>
                <input id="pemilik" name="pemilik" type="text" value="<?= e(is_string($_POST['pemilik'] ?? null) ? $_POST['pemilik'] : '') ?>" maxlength="100" autocomplete="off">
            </div>
        </div>
        <div class="form-field">
            <label for="id-area">Area parkir</label>
            <select id="id-area" name="id_area" required>
                <option value="">Pilih area</option>
                <?php foreach ($areaTersedia as $area): ?>
                    <option value="<?= (int) $area['id_area'] ?>">
                        <?= e($area['nama_area'] . ' — sisa ' . $area['sisa'] . ' slot') ?>
                    </option>
                <?php endforeach; ?>
            </select>
            <?php if ($areaTersedia === []): ?>
                <small class="field-error">Semua area parkir sedang penuh.</small>
            <?php endif; ?>
        </div>
        <div class="form-actions">
            <button class="button" type="submit"<?= $areaTersedia === [] || $jenisTersedia === [] ? ' disabled' : '' ?>>Catat masuk</button>
        </div>
    </form>
</section>

<section class="transaction-section" aria-labelledby="sedang-parkir-heading">
    <h2 id="sedang-parkir-heading">Sedang parkir</h2>
    <form class="parking-search" method="get" action="<?= e(app_url('/petugas/transaksi.php')) ?>" role="search">
        <div class="form-field">
            <label for="cari-plat">Cari plat nomor</label>
            <input id="cari-plat" name="q" type="search" value="<?= e($kataKunci) ?>" maxlength="30" autocomplete="off">
        </div>
        <div class="form-actions">
            <button class="button" type="submit">Cari</button>
            <?php if ($kataKunci !== ''): ?><a class="button button-secondary" href="<?= e(app_url('/petugas/transaksi.php')) ?>">Reset</a><?php endif; ?>
        </div>
    </form>
    <div class="table-scroll" role="region" aria-label="Kendaraan yang sedang parkir" tabindex="0">
        <table class="data-table">
            <thead>
                <tr>
                    <th scope="col">Plat nomor</th>
                    <th scope="col">Jenis</th>
                    <th scope="col">Area</th>
                    <th scope="col">Waktu masuk</th>
                    <th scope="col">Lama parkir</th>
                    <th scope="col">Aksi</th>
                </tr>
            </thead>
            <tbody>
                <?php if ($sedangParkir === []): ?>
                    <tr><td colspan="6"><?= $kataKunci === '' ? 'Belum ada kendaraan yang sedang parkir.' : 'Plat nomor tidak ditemukan.' ?></td></tr>
                <?php else: ?>
                    <?php foreach ($sedangParkir as $kendaraan): ?>
                        <?php
                        $waktuMasuk = new DateTimeImmutable($kendaraan['waktu_masuk']);
                        $waktuMasukIso = $waktuMasuk->format('Y-m-d\TH:i:s');
                        $menitBerjalan = max(0, (int) floor((time() - $waktuMasuk->getTimestamp()) / 60));
                        ?>
                        <tr>
                            <td><span class="plate-number"><?= e($kendaraan['plat_nomor']) ?></span></td>
                            <td><?= e(ucfirst((string) $kendaraan['jenis_kendaraan'])) ?></td>
                            <td><?= e($kendaraan['nama_area']) ?></td>
                            <td><time datetime="<?= e($waktuMasukIso) ?>"><?= e(format_tanggal_indonesia($kendaraan['waktu_masuk'])) ?></time></td>
                            <td data-parking-duration data-start-time="<?= e($waktuMasukIso) ?>"><?= e(waktu_berjalan($menitBerjalan)) ?></td>
                            <td>
                                <form class="checkout-form" method="post" action="<?= e(app_url('/petugas/transaksi.php')) ?>"
                                      data-confirm-checkout
                                      data-plat="<?= e($kendaraan['plat_nomor']) ?>"
                                      data-waktu-masuk="<?= e($waktuMasukIso) ?>"
                                      data-tarif="<?= e((string) $kendaraan['tarif_per_jam']) ?>">
                                    <input type="hidden" name="_csrf" value="<?= e($tokenCsrf) ?>">
                                    <input type="hidden" name="aksi" value="keluar">
                                    <input type="hidden" name="id_parkir" value="<?= (int) $kendaraan['id_parkir'] ?>">
                                    <button class="button" type="submit">Catat keluar</button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
    <nav class="pagination" aria-label="Halaman kendaraan yang sedang parkir">
        <?php if ($pagination['current_page'] > 1): ?>
            <a href="<?= e(app_url('/petugas/transaksi.php?' . http_build_query(['q' => $kataKunci, 'halaman' => $pagination['current_page'] - 1]))) ?>">Sebelumnya</a>
        <?php endif; ?>
        <span>Halaman <?= $pagination['current_page'] ?> dari <?= $pagination['total_pages'] ?></span>
        <?php if ($pagination['current_page'] < $pagination['total_pages']): ?>
            <a href="<?= e(app_url('/petugas/transaksi.php?' . http_build_query(['q' => $kataKunci, 'halaman' => $pagination['current_page'] + 1]))) ?>">Berikutnya</a>
        <?php endif; ?>
    </nav>
</section>

<dialog class="confirm-dialog checkout-dialog" id="checkout-confirmation" aria-labelledby="checkout-title">
    <h2 id="checkout-title">Konfirmasi kendaraan keluar</h2>
    <dl class="checkout-details">
        <div><dt>Plat nomor</dt><dd data-checkout-plate></dd></div>
        <div><dt>Waktu masuk</dt><dd data-checkout-entry></dd></div>
        <div><dt>Durasi</dt><dd data-checkout-duration></dd></div>
        <div><dt>Tarif per jam</dt><dd data-checkout-rate></dd></div>
        <div><dt>Total biaya</dt><dd data-checkout-total></dd></div>
    </dl>
    <p>Pastikan plat nomor sesuai sebelum mencatat kendaraan keluar.</p>
    <div class="confirm-dialog-actions">
        <button class="button button-secondary" type="button" data-checkout-cancel>Kembali</button>
        <button class="button" type="button" data-checkout-accept>Ya, catat keluar</button>
    </div>
</dialog>

<script src="<?= e(app_url('/assets/js/transaksi.js')) ?>" defer></script>
<?php require __DIR__ . '/../includes/layout_footer.php'; ?>
