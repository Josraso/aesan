-- ============================================================
--  AESAN Checker – Migración v2: colaboración y bloqueos
--  Ejecutar sobre la base de datos existente (no destructivo)
-- ============================================================
SET NAMES utf8mb4;

-- Bloqueos de edición (un registro por producto en edición activa)
CREATE TABLE IF NOT EXISTS `producto_bloqueos` (
  `producto_id`    INT UNSIGNED NOT NULL,
  `usuario_id`     INT UNSIGNED NOT NULL,
  `usuario_nombre` VARCHAR(100) NOT NULL,
  `updated_at`     DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`producto_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Colaboradores de importación (importaciones compartidas)
CREATE TABLE IF NOT EXISTS `importacion_colaboradores` (
  `id`             INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `importacion_id` INT UNSIGNED NOT NULL,
  `usuario_id`     INT UNSIGNED NOT NULL,
  `creado_at`      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_imp_usr` (`importacion_id`, `usuario_id`),
  CONSTRAINT `fk_ic_imp` FOREIGN KEY (`importacion_id`) REFERENCES `importaciones`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Si la tabla ya existía sin FK, añadirla (instancias actualizadas desde v1)
-- Ejecutar sólo si no existe ya:
-- ALTER TABLE `importacion_colaboradores` ADD CONSTRAINT `fk_ic_imp` FOREIGN KEY (`importacion_id`) REFERENCES `importaciones`(`id`) ON DELETE CASCADE;
