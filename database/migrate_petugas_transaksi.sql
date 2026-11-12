DROP PROCEDURE IF EXISTS sp_kendaraan_masuk;
DROP PROCEDURE IF EXISTS sp_kendaraan_keluar;

DELIMITER $$

CREATE PROCEDURE sp_kendaraan_masuk(
    IN p_plat_nomor VARCHAR(15),
    IN p_jenis_kendaraan VARCHAR(20),
    IN p_warna VARCHAR(20),
    IN p_pemilik VARCHAR(100),
    IN p_id_user INT,
    IN p_id_area INT
)
BEGIN
    DECLARE v_id_kendaraan INT DEFAULT NULL;
    DECLARE v_jenis_kendaraan VARCHAR(20) DEFAULT NULL;
    DECLARE v_id_tarif INT DEFAULT NULL;
    DECLARE v_kapasitas INT DEFAULT NULL;
    DECLARE v_terisi INT DEFAULT NULL;
    DECLARE v_tidak_ditemukan BOOLEAN DEFAULT FALSE;

    DECLARE CONTINUE HANDLER FOR NOT FOUND SET v_tidak_ditemukan = TRUE;

    SET v_tidak_ditemukan = FALSE;
    SELECT kapasitas, terisi
      INTO v_kapasitas, v_terisi
      FROM tb_area_parkir
     WHERE id_area = p_id_area
     FOR UPDATE;

    IF v_tidak_ditemukan THEN
        SIGNAL SQLSTATE '45000' SET MYSQL_ERRNO = 30007, MESSAGE_TEXT = 'AREA_TIDAK_DITEMUKAN';
    END IF;

    IF v_terisi >= v_kapasitas THEN
        SIGNAL SQLSTATE '45000' SET MYSQL_ERRNO = 30001, MESSAGE_TEXT = 'AREA_PENUH';
    END IF;

    SET v_tidak_ditemukan = FALSE;
    SELECT id_kendaraan, jenis_kendaraan
      INTO v_id_kendaraan, v_jenis_kendaraan
      FROM tb_kendaraan
     WHERE plat_nomor = p_plat_nomor
     LIMIT 1
     FOR UPDATE;

    IF v_tidak_ditemukan THEN
        IF p_jenis_kendaraan IS NULL OR p_warna IS NULL OR p_pemilik IS NULL
           OR TRIM(p_jenis_kendaraan) = '' OR TRIM(p_warna) = '' OR TRIM(p_pemilik) = '' THEN
            SIGNAL SQLSTATE '45000' SET MYSQL_ERRNO = 30006, MESSAGE_TEXT = 'DATA_KENDARAAN_TIDAK_LENGKAP';
        END IF;

        SET v_jenis_kendaraan = p_jenis_kendaraan;
        SET v_tidak_ditemukan = FALSE;
        SELECT id_tarif
          INTO v_id_tarif
          FROM tb_tarif
         WHERE jenis_kendaraan = v_jenis_kendaraan
         LIMIT 1;
        IF v_tidak_ditemukan THEN
            SIGNAL SQLSTATE '45000' SET MYSQL_ERRNO = 30005, MESSAGE_TEXT = 'TARIF_TIDAK_DITEMUKAN';
        END IF;

        INSERT INTO tb_kendaraan (plat_nomor, jenis_kendaraan, warna, pemilik, id_user)
        VALUES (p_plat_nomor, v_jenis_kendaraan, p_warna, p_pemilik, p_id_user);
        SET v_id_kendaraan = LAST_INSERT_ID();
    ELSE
        SET v_tidak_ditemukan = FALSE;
        SELECT id_tarif
          INTO v_id_tarif
          FROM tb_tarif
         WHERE jenis_kendaraan = v_jenis_kendaraan
         LIMIT 1;
        IF v_tidak_ditemukan THEN
            SIGNAL SQLSTATE '45000' SET MYSQL_ERRNO = 30005, MESSAGE_TEXT = 'TARIF_TIDAK_DITEMUKAN';
        END IF;
    END IF;

    IF EXISTS (
        SELECT 1
          FROM tb_transaksi
         WHERE id_kendaraan = v_id_kendaraan
           AND status = 'masuk'
         LIMIT 1
    ) THEN
        SIGNAL SQLSTATE '45000' SET MYSQL_ERRNO = 30002, MESSAGE_TEXT = 'KENDARAAN_SUDAH_MASUK';
    END IF;

    UPDATE tb_area_parkir
       SET terisi = terisi + 1
     WHERE id_area = p_id_area
       AND terisi < kapasitas;
    IF ROW_COUNT() <> 1 THEN
        SIGNAL SQLSTATE '45000' SET MYSQL_ERRNO = 30001, MESSAGE_TEXT = 'AREA_PENUH';
    END IF;

    INSERT INTO tb_transaksi (
        id_kendaraan, waktu_masuk, id_tarif, status, id_user, id_area
    ) VALUES (
        v_id_kendaraan, NOW(), v_id_tarif, 'masuk', p_id_user, p_id_area
    );

    SELECT LAST_INSERT_ID() AS id_parkir;
END$$

CREATE PROCEDURE sp_kendaraan_keluar(
    IN p_id_parkir INT,
    IN p_id_user INT
)
BEGIN
    DECLARE v_id_kendaraan INT DEFAULT NULL;
    DECLARE v_id_area INT DEFAULT NULL;
    DECLARE v_waktu_masuk DATETIME DEFAULT NULL;
    DECLARE v_status VARCHAR(10) DEFAULT NULL;
    DECLARE v_menit INT DEFAULT 0;
    DECLARE v_durasi_jam INT DEFAULT 1;
    DECLARE v_tarif DECIMAL(10,0) DEFAULT NULL;
    DECLARE v_biaya DECIMAL(10,0) DEFAULT 0;
    DECLARE v_waktu_keluar DATETIME DEFAULT NULL;
    DECLARE v_tidak_ditemukan BOOLEAN DEFAULT FALSE;

    DECLARE CONTINUE HANDLER FOR NOT FOUND SET v_tidak_ditemukan = TRUE;

    SET v_tidak_ditemukan = FALSE;
    SELECT id_kendaraan, id_area, waktu_masuk, status
      INTO v_id_kendaraan, v_id_area, v_waktu_masuk, v_status
      FROM tb_transaksi
     WHERE id_parkir = p_id_parkir
     FOR UPDATE;

    IF v_tidak_ditemukan THEN
        SIGNAL SQLSTATE '45000' SET MYSQL_ERRNO = 30003, MESSAGE_TEXT = 'TRANSAKSI_TIDAK_DITEMUKAN';
    END IF;

    IF v_status <> 'masuk' THEN
        SIGNAL SQLSTATE '45000' SET MYSQL_ERRNO = 30004, MESSAGE_TEXT = 'KENDARAAN_SUDAH_KELUAR';
    END IF;

    SET v_tidak_ditemukan = FALSE;
    SELECT tarif_per_jam
      INTO v_tarif
      FROM tb_tarif
      INNER JOIN tb_transaksi ON tb_transaksi.id_tarif = tb_tarif.id_tarif
     WHERE tb_transaksi.id_parkir = p_id_parkir;
    IF v_tidak_ditemukan THEN
        SIGNAL SQLSTATE '45000' SET MYSQL_ERRNO = 30005, MESSAGE_TEXT = 'TARIF_TIDAK_DITEMUKAN';
    END IF;

    SET v_waktu_keluar = NOW();
    SET v_menit = GREATEST(TIMESTAMPDIFF(MINUTE, v_waktu_masuk, v_waktu_keluar), 0);
    SET v_durasi_jam = GREATEST(1, CEILING(v_menit / 60));
    SET v_biaya = v_durasi_jam * v_tarif;

    UPDATE tb_transaksi
       SET waktu_keluar = v_waktu_keluar,
           durasi_jam = v_durasi_jam,
           biaya_total = v_biaya,
           status = 'keluar',
           id_user = p_id_user
     WHERE id_parkir = p_id_parkir
       AND status = 'masuk';

    IF ROW_COUNT() <> 1 THEN
        SIGNAL SQLSTATE '45000' SET MYSQL_ERRNO = 30004, MESSAGE_TEXT = 'KENDARAAN_SUDAH_KELUAR';
    END IF;

    UPDATE tb_area_parkir
       SET terisi = GREATEST(terisi - 1, 0)
     WHERE id_area = v_id_area;

    SELECT v.plat_nomor, v.jenis_kendaraan, a.nama_area,
           t.waktu_masuk, t.waktu_keluar, t.durasi_jam,
           r.tarif_per_jam, t.biaya_total
      FROM tb_transaksi AS t
      INNER JOIN tb_kendaraan AS v ON v.id_kendaraan = t.id_kendaraan
      INNER JOIN tb_area_parkir AS a ON a.id_area = t.id_area
      INNER JOIN tb_tarif AS r ON r.id_tarif = t.id_tarif
     WHERE t.id_parkir = p_id_parkir;
END$$

DELIMITER ;
