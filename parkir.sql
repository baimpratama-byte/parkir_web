-- phpMyAdmin SQL Dump
-- version 5.2.1
-- https://www.phpmyadmin.net/
--
-- Host: 127.0.0.1
-- Generation Time: Nov 11, 2026 at 03:31 AM
-- Server version: 10.4.32-MariaDB
-- PHP Version: 8.2.12

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";


/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;

--
-- Database: `parkir`
--

DELIMITER $$
--
-- Procedures
--
CREATE DEFINER=`root`@`localhost` PROCEDURE `sp_rekap_transaksi` (IN `p_tanggal_mulai` DATE, IN `p_tanggal_selesai` DATE)   BEGIN
    SELECT
        COUNT(*) AS total_transaksi,
        COALESCE(SUM(biaya_total), 0) AS total_pendapatan,
        COALESCE(SUM(durasi_jam), 0) AS total_durasi
    FROM tb_transaksi
    WHERE status = 'keluar'
      AND waktu_keluar >= p_tanggal_mulai
      AND waktu_keluar < DATE_ADD(p_tanggal_selesai, INTERVAL 1 DAY);
END$$

DROP PROCEDURE IF EXISTS `sp_kendaraan_masuk`$$
CREATE PROCEDURE `sp_kendaraan_masuk`(
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
    SELECT kapasitas, terisi INTO v_kapasitas, v_terisi
      FROM tb_area_parkir WHERE id_area = p_id_area FOR UPDATE;
    IF v_tidak_ditemukan THEN
        SIGNAL SQLSTATE '45000' SET MYSQL_ERRNO = 30007, MESSAGE_TEXT = 'AREA_TIDAK_DITEMUKAN';
    END IF;
    IF v_terisi >= v_kapasitas THEN
        SIGNAL SQLSTATE '45000' SET MYSQL_ERRNO = 30001, MESSAGE_TEXT = 'AREA_PENUH';
    END IF;

    SET v_tidak_ditemukan = FALSE;
    SELECT id_kendaraan, jenis_kendaraan INTO v_id_kendaraan, v_jenis_kendaraan
      FROM tb_kendaraan WHERE plat_nomor = p_plat_nomor LIMIT 1 FOR UPDATE;
    IF v_tidak_ditemukan THEN
        IF p_jenis_kendaraan IS NULL OR p_warna IS NULL OR p_pemilik IS NULL
           OR TRIM(p_jenis_kendaraan) = '' OR TRIM(p_warna) = '' OR TRIM(p_pemilik) = '' THEN
            SIGNAL SQLSTATE '45000' SET MYSQL_ERRNO = 30006, MESSAGE_TEXT = 'DATA_KENDARAAN_TIDAK_LENGKAP';
        END IF;
        SET v_jenis_kendaraan = p_jenis_kendaraan;
        SET v_tidak_ditemukan = FALSE;
        SELECT id_tarif INTO v_id_tarif FROM tb_tarif
         WHERE jenis_kendaraan = v_jenis_kendaraan LIMIT 1;
        IF v_tidak_ditemukan THEN
            SIGNAL SQLSTATE '45000' SET MYSQL_ERRNO = 30005, MESSAGE_TEXT = 'TARIF_TIDAK_DITEMUKAN';
        END IF;
        INSERT INTO tb_kendaraan (plat_nomor, jenis_kendaraan, warna, pemilik, id_user)
        VALUES (p_plat_nomor, v_jenis_kendaraan, p_warna, p_pemilik, p_id_user);
        SET v_id_kendaraan = LAST_INSERT_ID();
    ELSE
        SET v_tidak_ditemukan = FALSE;
        SELECT id_tarif INTO v_id_tarif FROM tb_tarif
         WHERE jenis_kendaraan = v_jenis_kendaraan LIMIT 1;
        IF v_tidak_ditemukan THEN
            SIGNAL SQLSTATE '45000' SET MYSQL_ERRNO = 30005, MESSAGE_TEXT = 'TARIF_TIDAK_DITEMUKAN';
        END IF;
    END IF;

    IF EXISTS (SELECT 1 FROM tb_transaksi
                WHERE id_kendaraan = v_id_kendaraan AND status = 'masuk' LIMIT 1) THEN
        SIGNAL SQLSTATE '45000' SET MYSQL_ERRNO = 30002, MESSAGE_TEXT = 'KENDARAAN_SUDAH_MASUK';
    END IF;

    UPDATE tb_area_parkir SET terisi = terisi + 1
     WHERE id_area = p_id_area AND terisi < kapasitas;
    IF ROW_COUNT() <> 1 THEN
        SIGNAL SQLSTATE '45000' SET MYSQL_ERRNO = 30001, MESSAGE_TEXT = 'AREA_PENUH';
    END IF;

    INSERT INTO tb_transaksi (id_kendaraan, waktu_masuk, id_tarif, status, id_user, id_area)
    VALUES (v_id_kendaraan, NOW(), v_id_tarif, 'masuk', p_id_user, p_id_area);
    SELECT LAST_INSERT_ID() AS id_parkir;
END$$

DROP PROCEDURE IF EXISTS `sp_kendaraan_keluar`$$
CREATE PROCEDURE `sp_kendaraan_keluar`(IN p_id_parkir INT, IN p_id_user INT)
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
      FROM tb_transaksi WHERE id_parkir = p_id_parkir FOR UPDATE;
    IF v_tidak_ditemukan THEN
        SIGNAL SQLSTATE '45000' SET MYSQL_ERRNO = 30003, MESSAGE_TEXT = 'TRANSAKSI_TIDAK_DITEMUKAN';
    END IF;
    IF v_status <> 'masuk' THEN
        SIGNAL SQLSTATE '45000' SET MYSQL_ERRNO = 30004, MESSAGE_TEXT = 'KENDARAAN_SUDAH_KELUAR';
    END IF;

    SET v_tidak_ditemukan = FALSE;
    SELECT r.tarif_per_jam INTO v_tarif
      FROM tb_tarif AS r
      INNER JOIN tb_transaksi AS t ON t.id_tarif = r.id_tarif
     WHERE t.id_parkir = p_id_parkir;
    IF v_tidak_ditemukan THEN
        SIGNAL SQLSTATE '45000' SET MYSQL_ERRNO = 30005, MESSAGE_TEXT = 'TARIF_TIDAK_DITEMUKAN';
    END IF;

    SET v_waktu_keluar = NOW();
    SET v_menit = GREATEST(TIMESTAMPDIFF(MINUTE, v_waktu_masuk, v_waktu_keluar), 0);
    SET v_durasi_jam = GREATEST(1, CEILING(v_menit / 60));
    SET v_biaya = v_durasi_jam * v_tarif;

    UPDATE tb_transaksi
       SET waktu_keluar = v_waktu_keluar, durasi_jam = v_durasi_jam,
           biaya_total = v_biaya, status = 'keluar', id_user = p_id_user
     WHERE id_parkir = p_id_parkir AND status = 'masuk';
    IF ROW_COUNT() <> 1 THEN
        SIGNAL SQLSTATE '45000' SET MYSQL_ERRNO = 30004, MESSAGE_TEXT = 'KENDARAAN_SUDAH_KELUAR';
    END IF;

    UPDATE tb_area_parkir SET terisi = GREATEST(terisi - 1, 0)
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

-- --------------------------------------------------------

--
-- Table structure for table `tb_area_parkir`
--

CREATE TABLE `tb_area_parkir` (
  `id_area` int(11) NOT NULL,
  `nama_area` varchar(50) NOT NULL,
  `kapasitas` int(5) NOT NULL,
  `terisi` int(5) NOT NULL DEFAULT 0
) ;

--
-- Dumping data for table `tb_area_parkir`
--

INSERT INTO `tb_area_parkir` (`id_area`, `nama_area`, `kapasitas`, `terisi`) VALUES
(1, 'Area Motor', 100, 0),
(2, 'Area Mobil', 50, 0),
(3, 'Area Kendaraan Lainnya', 20, 0);

-- --------------------------------------------------------

--
-- Table structure for table `tb_kendaraan`
--

CREATE TABLE `tb_kendaraan` (
  `id_kendaraan` int(11) NOT NULL,
  `plat_nomor` varchar(15) NOT NULL,
  `jenis_kendaraan` varchar(20) NOT NULL,
  `warna` varchar(20) NOT NULL,
  `pemilik` varchar(100) NOT NULL,
  `id_user` int(11) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `tb_log_aktivitas`
--

CREATE TABLE `tb_log_aktivitas` (
  `id_log` int(11) NOT NULL,
  `id_user` int(11) DEFAULT NULL,
  `aktivitas` varchar(100) NOT NULL,
  `waktu_aktivitas` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `tb_tarif`
--

CREATE TABLE `tb_tarif` (
  `id_tarif` int(11) NOT NULL,
  `jenis_kendaraan` enum('motor','mobil','lainnya') NOT NULL,
  `tarif_per_jam` decimal(10,0) NOT NULL
) ;

--
-- Dumping data for table `tb_tarif`
--

INSERT INTO `tb_tarif` (`id_tarif`, `jenis_kendaraan`, `tarif_per_jam`) VALUES
(1, 'motor', 2000),
(2, 'mobil', 5000),
(3, 'lainnya', 7000);

-- --------------------------------------------------------

--
-- Table structure for table `tb_transaksi`
--

CREATE TABLE `tb_transaksi` (
  `id_parkir` int(11) NOT NULL,
  `id_kendaraan` int(11) NOT NULL,
  `waktu_masuk` datetime NOT NULL DEFAULT current_timestamp(),
  `waktu_keluar` datetime DEFAULT NULL,
  `id_tarif` int(11) NOT NULL,
  `durasi_jam` int(5) NOT NULL DEFAULT 0,
  `biaya_total` decimal(10,0) NOT NULL DEFAULT 0,
  `status` enum('masuk','keluar') NOT NULL DEFAULT 'masuk',
  `id_user` int(11) NOT NULL,
  `id_area` int(11) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `tb_user`
--

CREATE TABLE `tb_user` (
  `id_user` int(11) NOT NULL,
  `nama_lengkap` varchar(50) NOT NULL,
  `username` varchar(50) NOT NULL,
  `password` varchar(100) NOT NULL,
  `role` enum('admin','petugas','owner') NOT NULL,
  `status_aktif` tinyint(1) NOT NULL DEFAULT 1
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Indexes for dumped tables
--

--
-- Indexes for table `tb_area_parkir`
--
ALTER TABLE `tb_area_parkir`
  ADD PRIMARY KEY (`id_area`),
  ADD UNIQUE KEY `uk_nama_area` (`nama_area`);

--
-- Indexes for table `tb_kendaraan`
--
ALTER TABLE `tb_kendaraan`
  ADD PRIMARY KEY (`id_kendaraan`),
  ADD UNIQUE KEY `uk_plat_nomor` (`plat_nomor`),
  ADD KEY `idx_kendaraan_user` (`id_user`);

--
-- Indexes for table `tb_log_aktivitas`
--
ALTER TABLE `tb_log_aktivitas`
  ADD PRIMARY KEY (`id_log`),
  ADD KEY `idx_log_user` (`id_user`),
  ADD KEY `idx_log_waktu` (`waktu_aktivitas`);

--
-- Indexes for table `tb_tarif`
--
ALTER TABLE `tb_tarif`
  ADD PRIMARY KEY (`id_tarif`),
  ADD UNIQUE KEY `uk_jenis_tarif` (`jenis_kendaraan`);

--
-- Indexes for table `tb_transaksi`
--
ALTER TABLE `tb_transaksi`
  ADD PRIMARY KEY (`id_parkir`),
  ADD KEY `idx_transaksi_kendaraan_status` (`id_kendaraan`,`status`),
  ADD KEY `idx_transaksi_tarif` (`id_tarif`),
  ADD KEY `idx_transaksi_user` (`id_user`),
  ADD KEY `idx_transaksi_area` (`id_area`),
  ADD KEY `idx_transaksi_waktu` (`waktu_masuk`),
  ADD KEY `idx_transaksi_rekap` (`status`,`waktu_keluar`),
  ADD KEY `idx_transaksi_status_waktu` (`status`,`waktu_masuk`);

--
-- Indexes for table `tb_user`
--
ALTER TABLE `tb_user`
  ADD PRIMARY KEY (`id_user`),
  ADD UNIQUE KEY `uk_username` (`username`);

--
-- AUTO_INCREMENT for dumped tables
--

--
-- AUTO_INCREMENT for table `tb_area_parkir`
--
ALTER TABLE `tb_area_parkir`
  MODIFY `id_area` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `tb_kendaraan`
--
ALTER TABLE `tb_kendaraan`
  MODIFY `id_kendaraan` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `tb_log_aktivitas`
--
ALTER TABLE `tb_log_aktivitas`
  MODIFY `id_log` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `tb_tarif`
--
ALTER TABLE `tb_tarif`
  MODIFY `id_tarif` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `tb_transaksi`
--
ALTER TABLE `tb_transaksi`
  MODIFY `id_parkir` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `tb_user`
--
ALTER TABLE `tb_user`
  MODIFY `id_user` int(11) NOT NULL AUTO_INCREMENT;

--
-- Constraints for dumped tables
--

--
-- Constraints for table `tb_kendaraan`
--
ALTER TABLE `tb_kendaraan`
  ADD CONSTRAINT `fk_kendaraan_user` FOREIGN KEY (`id_user`) REFERENCES `tb_user` (`id_user`) ON UPDATE CASCADE;

--
-- Constraints for table `tb_log_aktivitas`
--
ALTER TABLE `tb_log_aktivitas`
  ADD CONSTRAINT `fk_log_user` FOREIGN KEY (`id_user`) REFERENCES `tb_user` (`id_user`) ON DELETE SET NULL ON UPDATE CASCADE;

--
-- Constraints for table `tb_transaksi`
--
ALTER TABLE `tb_transaksi`
  ADD CONSTRAINT `fk_transaksi_area` FOREIGN KEY (`id_area`) REFERENCES `tb_area_parkir` (`id_area`) ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_transaksi_kendaraan` FOREIGN KEY (`id_kendaraan`) REFERENCES `tb_kendaraan` (`id_kendaraan`) ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_transaksi_tarif` FOREIGN KEY (`id_tarif`) REFERENCES `tb_tarif` (`id_tarif`) ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_transaksi_user` FOREIGN KEY (`id_user`) REFERENCES `tb_user` (`id_user`) ON UPDATE CASCADE;
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
