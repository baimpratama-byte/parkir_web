<?php
declare(strict_types=1);

function e(mixed $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function app_base_path(): string
{
    $scriptPath = str_replace('\\', '/', (string) ($_SERVER['SCRIPT_NAME'] ?? ''));
    $directory = trim(dirname($scriptPath), '/');

    if ($directory === '' || $directory === '.') {
        return '';
    }

    $segments = explode('/', $directory);
    $roleFolders = ['auth', 'admin', 'petugas', 'owner'];

    foreach ($segments as $index => $segment) {
        if (in_array($segment, $roleFolders, true)) {
            $segments = array_slice($segments, 0, $index);
            break;
        }
    }

    $basePath = implode('/', $segments);

    return $basePath === '' ? '' : '/' . $basePath;
}

function app_url(string $path): string
{
    if ($path === '' || $path[0] !== '/' || str_starts_with($path, '//')) {
        throw new InvalidArgumentException('Alamat aplikasi harus berupa path lokal yang diawali garis miring tunggal.');
    }

    return app_base_path() . $path;
}

function app_session_start(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }

    if (headers_sent($file, $line)) {
        throw new RuntimeException(sprintf('Sesi tidak dapat dimulai karena header sudah dikirim di %s:%d.', $file, $line));
    }

    session_name('parkir_session');
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/',
        'secure' => isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== '' && $_SERVER['HTTPS'] !== 'off',
        'httponly' => true,
        'samesite' => 'Lax',
    ]);

    if (!session_start()) {
        throw new RuntimeException('Sesi aplikasi gagal dimulai.');
    }
}

function redirect(string $url, int $statusCode = 302): never
{
    if ($url === '' || $url[0] !== '/' || str_starts_with($url, '//') || preg_match('/[\r\n]/', $url) === 1) {
        throw new InvalidArgumentException('Tujuan pengalihan harus berupa path lokal yang valid.');
    }

    if ($statusCode < 300 || $statusCode > 399) {
        throw new InvalidArgumentException('Kode status pengalihan harus berada di antara 300 dan 399.');
    }

    header('Location: ' . app_url($url), true, $statusCode);
    exit;
}

function flash_set(string $jenis, string $pesan): void
{
    if (preg_match('/\A[a-zA-Z0-9_-]+\z/', $jenis) !== 1) {
        throw new InvalidArgumentException('Jenis pesan flash tidak valid.');
    }

    app_session_start();
    $_SESSION['_flash'][$jenis] = $pesan;
}

function flash_get(string $jenis): ?string
{
    app_session_start();

    $pesan = $_SESSION['_flash'][$jenis] ?? null;
    unset($_SESSION['_flash'][$jenis]);

    if (empty($_SESSION['_flash'])) {
        unset($_SESSION['_flash']);
    }

    return is_string($pesan) ? $pesan : null;
}

function csrf_token(): string
{
    app_session_start();

    if (!isset($_SESSION['_csrf_token']) || !is_string($_SESSION['_csrf_token'])) {
        $_SESSION['_csrf_token'] = bin2hex(random_bytes(32));
    }

    return $_SESSION['_csrf_token'];
}

function csrf_check(?string $token = null): bool
{
    app_session_start();

    $expected = $_SESSION['_csrf_token'] ?? null;
    $submitted = $token ?? ($_POST['_csrf'] ?? null);

    return is_string($expected)
        && is_string($submitted)
        && hash_equals($expected, $submitted);
}

function format_rupiah(int|float|string $jumlah): string
{
    if (!is_numeric($jumlah) || !is_finite((float) $jumlah)) {
        throw new InvalidArgumentException('Jumlah rupiah harus berupa angka yang valid.');
    }

    return 'Rp ' . number_format((float) $jumlah, 0, ',', '.');
}

function format_tanggal(DateTimeInterface|string|null $tanggal, string $format = 'd/m/Y H:i'): string
{
    if ($tanggal === null) {
        return '—';
    }

    $date = $tanggal instanceof DateTimeInterface
        ? DateTimeImmutable::createFromInterface($tanggal)
        : new DateTimeImmutable($tanggal);

    return $date->format($format);
}

function format_tanggal_indonesia(DateTimeInterface|string|null $tanggal): string
{
    if ($tanggal === null) {
        return '—';
    }

    $date = $tanggal instanceof DateTimeInterface
        ? DateTimeImmutable::createFromInterface($tanggal)
        : new DateTimeImmutable($tanggal);
    $bulan = [
        1 => 'Jan',
        2 => 'Feb',
        3 => 'Mar',
        4 => 'Apr',
        5 => 'Mei',
        6 => 'Jun',
        7 => 'Jul',
        8 => 'Agu',
        9 => 'Sep',
        10 => 'Okt',
        11 => 'Nov',
        12 => 'Des',
    ];

    return $date->format('d') . ' ' . $bulan[(int) $date->format('n')]
        . $date->format(' Y, H.i');
}

function waktu_berjalan(int $menit): string
{
    if ($menit < 0) {
        throw new InvalidArgumentException('Durasi parkir tidak boleh negatif.');
    }

    $jam = intdiv($menit, 60);
    $sisaMenit = $menit % 60;

    if ($jam === 0) {
        return $sisaMenit . ' menit';
    }
    if ($sisaMenit === 0) {
        return $jam . ' jam';
    }

    return $jam . ' jam ' . $sisaMenit . ' menit';
}

/**
 * @return array{total: int, per_page: int, current_page: int, total_pages: int, offset: int}
 */
function paginate(int $total, int $perPage, int $currentPage = 1): array
{
    if ($total < 0 || $perPage < 1 || $currentPage < 1) {
        throw new InvalidArgumentException('Jumlah data harus positif atau nol, sedangkan batas dan halaman harus lebih dari nol.');
    }

    $totalPages = max(1, (int) ceil($total / $perPage));
    $currentPage = min($currentPage, $totalPages);

    return [
        'total' => $total,
        'per_page' => $perPage,
        'current_page' => $currentPage,
        'total_pages' => $totalPages,
        'offset' => ($currentPage - 1) * $perPage,
    ];
}
