<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/log.php';

app_session_start();

$tujuanPeran = [
    'admin' => '/admin/index.php',
    'petugas' => '/petugas/transaksi.php',
    'owner' => '/owner/rekap.php',
];
$pesanError = null;
$namaPengguna = '';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    if (!csrf_check()) {
        http_response_code(400);
        $pesanError = 'Permintaan tidak valid. Muat ulang halaman dan coba lagi.';
    } else {
        $sekarang = time();
        $statusPercobaan = $_SESSION['_login_attempts'] ?? ['jumlah' => 0, 'terkunci_sampai' => 0];
        $jumlahPercobaan = is_array($statusPercobaan) && is_int($statusPercobaan['jumlah'] ?? null)
            ? $statusPercobaan['jumlah']
            : 0;
        $terkunciSampai = is_array($statusPercobaan) && is_int($statusPercobaan['terkunci_sampai'] ?? null)
            ? $statusPercobaan['terkunci_sampai']
            : 0;

        if ($terkunciSampai > $sekarang) {
            $sisaWaktu = $terkunciSampai - $sekarang;
            $pesanError = sprintf('Terlalu banyak percobaan masuk. Coba lagi dalam %d detik.', $sisaWaktu);
            catat_log(null, 'Login gagal');
        } else {
            if ($terkunciSampai !== 0) {
                $jumlahPercobaan = 0;
            }

            $namaMasukan = $_POST['username'] ?? null;
            $kataSandi = $_POST['password'] ?? null;
            $namaPengguna = is_string($namaMasukan) ? trim($namaMasukan) : '';
            $kataSandiValid = is_string($kataSandi) && $kataSandi !== '' && strlen($kataSandi) <= 4096;
            $masukanValid = $namaPengguna !== ''
                && strlen($namaPengguna) <= 200
                && $kataSandiValid;

            $pengguna = false;
            if ($masukanValid) {
                $statement = db()->prepare(
                    'SELECT id_user, nama_lengkap, username, password, role, status_aktif
                     FROM tb_user
                     WHERE username = :username
                     LIMIT 1'
                );
                $statement->execute(['username' => $namaPengguna]);
                $pengguna = $statement->fetch();
            }

            $kataSandiUntukDiperiksa = $kataSandiValid ? $kataSandi : '';
            $hashPembanding = is_array($pengguna)
                ? (string) $pengguna['password']
                : '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2uheWG/igi.';
            $kataSandiCocok = password_verify($kataSandiUntukDiperiksa, $hashPembanding);

            $loginValid = is_array($pengguna)
                && $kataSandiCocok
                && (int) $pengguna['status_aktif'] === 1
                && isset($tujuanPeran[$pengguna['role']]);

            if ($loginValid) {
                if (!session_regenerate_id(true)) {
                    throw new RuntimeException('ID sesi gagal diperbarui.');
                }

                catat_log((int) $pengguna['id_user'], 'Login berhasil');
                unset($_SESSION['_login_attempts'], $_SESSION['_csrf_token']);
                $_SESSION['user'] = [
                    'id_user' => (int) $pengguna['id_user'],
                    'nama_lengkap' => (string) $pengguna['nama_lengkap'],
                    'username' => (string) $pengguna['username'],
                    'role' => (string) $pengguna['role'],
                ];

                redirect($tujuanPeran[$pengguna['role']]);
            }

            $jumlahPercobaan++;
            $terkunciSampai = $jumlahPercobaan >= 5 ? $sekarang + 60 : 0;
            $_SESSION['_login_attempts'] = [
                'jumlah' => $jumlahPercobaan,
                'terkunci_sampai' => $terkunciSampai,
            ];
            catat_log(is_array($pengguna) ? (int) $pengguna['id_user'] : null, 'Login gagal');
            $pesanError = $terkunciSampai > 0
                ? 'Terlalu banyak percobaan masuk. Coba lagi dalam 60 detik.'
                : 'Username atau kata sandi tidak valid.';
        }
    }
}

$tokenCsrf = csrf_token();
?>
<!doctype html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Masuk | Operasional Parkir</title>
    <link rel="stylesheet" href="<?= e(app_url('/assets/css/app.css')) ?>">
</head>
<body class="login-page">
    <main class="login-main">
        <section class="login-panel" aria-labelledby="login-heading">
            <h1 id="login-heading">Operasional Parkir</h1>
            <p class="login-description">Sistem pencatatan kendaraan parkir.</p>

            <?php if ($pesanError !== null): ?>
                <p class="login-error" role="alert"><?= e($pesanError) ?></p>
            <?php endif; ?>

            <form class="login-form" method="post" action="<?= e(app_url('/auth/login.php')) ?>">
                <input type="hidden" name="_csrf" value="<?= e($tokenCsrf) ?>">
                <div class="form-field">
                    <label for="username">Username</label>
                    <input id="username" name="username" type="text" value="<?= e($namaPengguna) ?>" autocomplete="username" maxlength="50" required autofocus>
                </div>
                <div class="form-field">
                    <label for="password">Kata sandi</label>
                    <input id="password" name="password" type="password" autocomplete="current-password" required>
                </div>
                <button class="button login-submit" type="submit">Masuk</button>
            </form>
        </section>
    </main>
</body>
</html>
