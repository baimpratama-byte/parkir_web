<?php
declare(strict_types=1);

require_once __DIR__ . '/functions.php';

if (!isset($judul, $role) || !is_string($judul) || !is_string($role)) {
    throw new InvalidArgumentException('Variabel $judul dan $role wajib diisi sebelum layout dimuat.');
}

$navigasiPeran = [
    'admin' => [
        ['Ringkasan', '/admin/index.php'],
        ['Pengguna', '/admin/user.php'],
        ['Log aktivitas', '/admin/log.php'],
        ['Tarif', '/admin/tarif.php'],
        ['Area parkir', '/admin/area_parkir.php'],
        ['Kendaraan', '/admin/kendaraan.php'],
    ],
    'petugas' => [
        ['Transaksi parkir', '/petugas/transaksi.php'],
    ],
    'owner' => [
        ['Rekap transaksi', '/owner/rekap.php'],
    ],
];

$halamanAwalPeran = [
    'admin' => '/admin/index.php',
    'petugas' => '/petugas/transaksi.php',
    'owner' => '/owner/rekap.php',
];

$namaPeran = [
    'admin' => 'Administrator',
    'petugas' => 'Petugas loket',
    'owner' => 'Pemilik',
];

if (!array_key_exists($role, $navigasiPeran)) {
    throw new InvalidArgumentException('Peran pengguna tidak dikenal.');
}
?>
<!doctype html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= e($judul) ?> | Operasional Parkir</title>
    <link rel="stylesheet" href="<?= e(app_url('/assets/css/app.css')) ?>">
</head>
<body class="app-layout<?= isset($layoutClass) && is_string($layoutClass) ? ' ' . e($layoutClass) : '' ?>">
    <header class="app-sidebar">
        <div class="app-sidebar-top">
            <a class="app-brand" href="<?= e(app_url($halamanAwalPeran[$role])) ?>">Operasional Parkir</a>
            <button class="app-menu-toggle" type="button" aria-expanded="false" aria-controls="app-navigation">
                Menu
            </button>
        </div>
        <p class="app-role"><?= e($namaPeran[$role]) ?></p>
        <nav class="app-nav" id="app-navigation" aria-label="Navigasi utama">
            <ul>
                <?php foreach ($navigasiPeran[$role] as [$label, $path]): ?>
                    <li>
                        <a href="<?= e(app_url($path)) ?>"<?= ($_SERVER['SCRIPT_NAME'] ?? '') === app_url($path) ? ' aria-current="page"' : '' ?>>
                            <?= e($label) ?>
                        </a>
                    </li>
                <?php endforeach; ?>
            </ul>
        </nav>
        <form class="logout-form" method="post" action="<?= e(app_url('/auth/logout.php')) ?>">
            <input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>">
            <button class="button button-secondary" type="submit">Keluar</button>
        </form>
    </header>
    <main class="app-main">
        <div class="app-content">
        <h1><?= e($judul) ?></h1>
