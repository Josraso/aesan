-- migrate_v3.sql — Operador responsable a nivel de importación
-- Ejecutar en instalaciones existentes (v1 o v2)
-- MySQL 8.0.3+ / MariaDB 10.1+: soporta IF NOT EXISTS en ADD COLUMN

ALTER TABLE `importaciones`
  ADD COLUMN IF NOT EXISTS `operador_nombre`    VARCHAR(200) DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS `operador_direccion` VARCHAR(300) DEFAULT NULL;
