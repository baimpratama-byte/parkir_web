ALTER TABLE tb_transaksi
  DROP INDEX idx_transaksi_kendaraan,
  DROP INDEX idx_transaksi_status,
  ADD INDEX idx_transaksi_kendaraan_status (id_kendaraan, status),
  ADD INDEX idx_transaksi_status_waktu (status, waktu_masuk);
