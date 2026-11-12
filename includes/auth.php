<?php
declare(strict_types=1);

require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/../config/database.php';

function current_user(): ?array
{
    app_session_start();

    static $checked = false;
    static $currentUser = null;

    if ($checked) {
        return $currentUser;
    }

    $checked = true;
    $user = $_SESSION['user'] ?? null;

    if (!is_array($user) || !isset($user['id_user'], $user['role'])) {
        return null;
    }

    $idUser = filter_var($user['id_user'], FILTER_VALIDATE_INT);
    if ($idUser === false || $idUser < 1 || !is_string($user['role'])) {
        unset($_SESSION['user']);
        if (!session_regenerate_id(true)) {
            throw new RuntimeException('ID sesi gagal diperbarui setelah data pengguna tidak valid.');
        }

        return null;
    }

    $statement = db()->prepare(
        'SELECT id_user, nama_lengkap, username, role, status_aktif
         FROM tb_user
         WHERE id_user = :id_user
         LIMIT 1'
    );
    $statement->execute(['id_user' => $idUser]);
    $record = $statement->fetch();

    if (!is_array($record) || (int) $record['status_aktif'] !== 1) {
        unset($_SESSION['user']);
        if (!session_regenerate_id(true)) {
            throw new RuntimeException('ID sesi gagal diperbarui setelah akun tidak lagi aktif.');
        }

        return null;
    }

    $currentUser = [
        'id_user' => (int) $record['id_user'],
        'nama_lengkap' => (string) $record['nama_lengkap'],
        'username' => (string) $record['username'],
        'role' => (string) $record['role'],
    ];
    $_SESSION['user'] = $currentUser;

    return $currentUser;
}

function require_login(): void
{
    if (current_user() === null) {
        redirect('/auth/login.php');
    }
}

/**
 * @param array<int, string> $roles
 */
function require_role(array $roles): void
{
    require_login();

    $user = current_user();
    if ($user === null) {
        redirect('/auth/login.php');
    }

    if (!in_array($user['role'], $roles, true)) {
        http_response_code(403);
        exit('Akses ditolak.');
    }
}
