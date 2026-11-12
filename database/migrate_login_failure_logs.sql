ALTER TABLE tb_log_aktivitas
  MODIFY id_user int(11) DEFAULT NULL;

ALTER TABLE tb_log_aktivitas
  DROP FOREIGN KEY fk_log_user,
  ADD CONSTRAINT fk_log_user
    FOREIGN KEY (id_user) REFERENCES tb_user (id_user)
    ON DELETE SET NULL ON UPDATE CASCADE;
