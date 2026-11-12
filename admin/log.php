<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../config/database.php';

require_role(['admin']);

$tanggalMulai = $_GET['mulai'] ?? '';
$tanggalSelesai = $_GET['sampai'] ?? '';
$userMasukan = $_GET['user'] ?? '';
$kataMasukan = $_GET['q'] ?? '';
$tanggalMulai = is_string($tanggalMulai) ? trim($tanggalMulai) : '';
$tanggalSelesai = is_string($tanggalSelesai) ? trim($tanggalSelesai) : '';
$filterUser = is_string($userMasukan) ? substr(trim($userMasukan), 0, 50) : '';
$kataKunci = is_string($kataMasukan) ? substr(trim($kataMasukan), 0, 100) : '';
$pesanError = null;

$validasiTanggal = static function (string $tanggal): bool {
    if ($tanggal === '') {
        return true;
    }

    $hasil = DateTimeImmutable::createFromFormat('!Y-m-d', $tanggal);
    $kesalahan = DateTimeImmutable::getLastErrors();

    return $hasil !== false
        && ($kesalahan === false || ($kesalahan['warning_count'] === 0 && $kesalahan['error_count'] === 0))
        && $hasil->format('Y-m-d') === $tanggal;
};

if (!$validasiTanggal($tanggalMulai) || !$validasiTanggal($tanggalSelesai)) {
    http_response_code(400);
    $pesanError = 'Format tanggal tidak valid.';
    $tanggalMulai = '';
    $tanggalSelesai = '';
}
if ($tanggalMulai !== '' && $tanggalSelesai !== '' && $tanggalMulai > $tanggalSelesai) {
    http_response_code(400);
    $pesanError = 'Tanggal mulai tidak boleh melewati tanggal selesai.';
}

$parameter = [];
$kondisi = [];
if ($tanggalMulai !== '') {
    $kondisi[] = 'l.waktu_aktivitas >= :mulai';
    $parameter['mulai'] = $tanggalMulai . ' 00:00:00';
}
if ($tanggalSelesai !== '') {
    $tanggalBerikutnya = (new DateTimeImmutable($tanggalSelesai))->modify('+1 day')->format('Y-m-d');
    $kondisi[] = 'l.waktu_aktivitas < :sampai';
    $parameter['sampai'] = $tanggalBerikutnya . ' 00:00:00';
}
if ($filterUser !== '') {
    if (ctype_digit($filterUser) && (int) $filterUser > 0) {
        $kondisi[] = 'l.id_user = :id_user';
        $parameter['id_user'] = (int) $filterUser;
    } else {
        $kondisi[] = 'l.id_user = (SELECT id_user FROM tb_user WHERE username = :username)';
        $parameter['username'] = $filterUser;
    }
}
if ($kataKunci !== '') {
    $kondisi[] = 'l.aktivitas LIKE :aktivitas';
    $parameter['aktivitas'] = '%' . $kataKunci . '%';
}
$where = $kondisi === [] ? '' : ' WHERE ' . implode(' AND ', $kondisi);

$halamanMasukan = filter_var($_GET['halaman'] ?? 1, FILTER_VALIDATE_INT);
$halamanMasukan = $halamanMasukan === false || $halamanMasukan < 1 ? 1 : $halamanMasukan;
$statement = db()->prepare(
    'SELECT COUNT(*)
     FROM tb_log_aktivitas AS l FORCE INDEX (idx_log_waktu)' . $where
);
$statement->execute($parameter);
$pagination = paginate((int) $statement->fetchColumn(), 25, $halamanMasukan);

$statement = db()->prepare(
    'SELECT l.waktu_aktivitas, l.aktivitas, u.nama_lengkap, u.username
     FROM (
         SELECT id_log, id_user, waktu_aktivitas, aktivitas
         FROM tb_log_aktivitas FORCE INDEX (idx_log_waktu)' . $where . '
         ORDER BY waktu_aktivitas DESC, id_log DESC
         LIMIT :batas OFFSET :offset
     ) AS l
     LEFT JOIN tb_user AS u ON u.id_user = l.id_user
     ORDER BY l.waktu_aktivitas DESC, l.id_log DESC'
);
foreach ($parameter as $nama => $nilai) {
    $statement->bindValue(':' . $nama, $nilai, is_int($nilai) ? PDO::PARAM_INT : PDO::PARAM_STR);
}
$statement->bindValue(':batas', $pagination['per_page'], PDO::PARAM_INT);
$statement->bindValue(':offset', $pagination['offset'], PDO::PARAM_INT);
$statement->execute();
$log = $statement->fetchAll();

$queryHalaman = [
    'mulai' => $tanggalMulai,
    'sampai' => $tanggalSelesai,
    'user' => $filterUser,
    'q' => $kataKunci,
];
$judul = 'Log aktivitas';
$role = 'admin';
require __DIR__ . '/../includes/layout_header.php';
?>

<?php if ($pesanError !== null): ?><p class="flash" role="alert"><?= e($pesanError) ?></p><?php endif; ?>

<form class="filter-form log-filter-form" method="get" action="<?= e(app_url('/admin/log.php')) ?>">
    <div class="form-field">
        <label for="mulai">Tanggal mulai</label>
        <input id="mulai" name="mulai" type="date" value="<?= e($tanggalMulai) ?>">
    </div>
    <div class="form-field">
        <label for="sampai">Tanggal selesai</label>
        <input id="sampai" name="sampai" type="date" value="<?= e($tanggalSelesai) ?>">
    </div>
    <div class="form-field">
        <label for="user">ID atau username pengguna</label>
        <input id="user" name="user" type="search" value="<?= e($filterUser) ?>" maxlength="50">
        <small class="field-help">Masukkan ID atau username secara tepat, atau kosongkan untuk semua pengguna.</small>
    </div>
    <div class="form-field">
        <label for="q">Kata kunci aktivitas</label>
        <input id="q" name="q" type="search" value="<?= e($kataKunci) ?>" maxlength="100">
    </div>
    <div class="form-actions">
        <button class="button" type="submit">Terapkan filter</button>
        <a class="button button-secondary" href="<?= e(app_url('/admin/log.php')) ?>">Reset filter</a>
    </div>
</form>

<?php if ($log === []): ?>
    <div class="empty-state">
        <h2>Tidak ada log aktivitas</h2>
        <p>Tidak ditemukan aktivitas yang sesuai dengan filter.</p>
        <div class="form-actions">
            <a class="button button-secondary" href="<?= e(app_url('/admin/log.php')) ?>">Reset filter</a>
        </div>
    </div>
<?php else: ?>
    <div class="table-scroll" role="region" aria-label="Log aktivitas" tabindex="0">
        <table class="data-table">
            <thead>
                <tr>
                    <th scope="col">Waktu</th>
                    <th scope="col">Nama user</th>
                    <th scope="col">Aktivitas</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($log as $item): ?>
                    <tr>
                        <td><time datetime="<?= e((new DateTimeImmutable($item['waktu_aktivitas']))->format('Y-m-d\TH:i:s')) ?>"><?= e(format_tanggal_indonesia($item['waktu_aktivitas'])) ?></time></td>
                        <td><?= $item['nama_lengkap'] === null ? 'Pengguna tidak tersedia' : e($item['nama_lengkap']) ?></td>
                        <td><?= e($item['aktivitas']) ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <nav class="pagination" aria-label="Halaman log aktivitas">
        <?php if ($pagination['current_page'] > 1): ?>
            <a href="<?= e(app_url('/admin/log.php?' . http_build_query($queryHalaman + ['halaman' => $pagination['current_page'] - 1]))) ?>">Sebelumnya</a>
        <?php endif; ?>
        <span>Halaman <?= $pagination['current_page'] ?> dari <?= $pagination['total_pages'] ?></span>
        <?php if ($pagination['current_page'] < $pagination['total_pages']): ?>
            <a href="<?= e(app_url('/admin/log.php?' . http_build_query($queryHalaman + ['halaman' => $pagination['current_page'] + 1]))) ?>">Berikutnya</a>
        <?php endif; ?>
    </nav>
<?php endif; ?>

<?php require __DIR__ . '/../includes/layout_footer.php'; ?>
