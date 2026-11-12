ALTER TABLE tb_transaksi
  ADD INDEX idx_transaksi_rekap (status, waktu_keluar);
