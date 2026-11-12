<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';

function catat_log(?int $id_user, string $aktivitas): void
{
    if ($id_user !== null && $id_user < 1) {
        throw new InvalidArgumentException('ID pengguna harus lebih dari nol.');
    }

    if (trim($aktivitas) === '') {
        throw new InvalidArgumentException('Aktivitas log tidak boleh kosong.');
    }

    $statement = db()->prepare(
        'INSERT INTO tb_log_aktivitas (id_user, aktivitas) VALUES (:id_user, :aktivitas)'
    );
    $statement->execute([
        'id_user' => $id_user,
        'aktivitas' => $aktivitas,
    ]);
}
