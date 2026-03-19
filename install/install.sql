-- ============================================================
--  AESAN Checker – Schema completo v2
-- ============================================================
SET NAMES utf8mb4;
SET foreign_key_checks = 0;

CREATE TABLE IF NOT EXISTS `usuarios` (
  `id`         INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `nombre`     VARCHAR(100) NOT NULL,
  `email`      VARCHAR(150) NOT NULL UNIQUE,
  `password`   VARCHAR(255) NOT NULL,
  `rol`        ENUM('admin','editor') NOT NULL DEFAULT 'editor',
  `activo`     TINYINT(1)   NOT NULL DEFAULT 1,
  `created_at` DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `importaciones` (
  `id`             INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `usuario_id`     INT UNSIGNED NOT NULL,
  `nombre_archivo` VARCHAR(255) NOT NULL,
  `fecha`          DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `total`              INT          NOT NULL DEFAULT 0,
  `ok`                 INT          NOT NULL DEFAULT 0,
  `incompletos`        INT          NOT NULL DEFAULT 0,
  `operador_nombre`    VARCHAR(200) DEFAULT NULL,
  `operador_direccion` VARCHAR(300) DEFAULT NULL,
  PRIMARY KEY (`id`),
  CONSTRAINT `fk_imp_usr` FOREIGN KEY (`usuario_id`) REFERENCES `usuarios`(`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `productos` (
  `id`                  INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `importacion_id`      INT UNSIGNED NOT NULL,
  `ps_id`               VARCHAR(50)  DEFAULT NULL,
  `referencia`          VARCHAR(100) DEFAULT NULL,
  `nombre`              VARCHAR(255) NOT NULL,
  `tipo_detectado`      VARCHAR(50)  DEFAULT NULL,
  `tipo_validado`       VARCHAR(50)  DEFAULT NULL,
  `desc_corta_original` MEDIUMTEXT   DEFAULT NULL,
  `desc_corta_sugerida` MEDIUMTEXT   DEFAULT NULL,
  `desc_corta_usar`     TINYINT(1)   NOT NULL DEFAULT 0,
  `desc_larga_original` MEDIUMTEXT   DEFAULT NULL,
  `estado`              ENUM('pendiente','incompleto','ok') NOT NULL DEFAULT 'pendiente',
  `campos_json`         MEDIUMTEXT   DEFAULT NULL,
  `exportado`           TINYINT(1)   NOT NULL DEFAULT 0,
  `created_at`          DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`          DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_imp` (`importacion_id`),
  KEY `idx_estado` (`estado`),
  CONSTRAINT `fk_prod_imp` FOREIGN KEY (`importacion_id`) REFERENCES `importaciones`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `historial_productos` (
  `id`          INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `producto_id` INT UNSIGNED NOT NULL,
  `usuario_id`  INT UNSIGNED NOT NULL,
  `accion`      VARCHAR(50)  NOT NULL,
  `detalle`     VARCHAR(1000) DEFAULT NULL,
  `ip`          VARCHAR(45)  DEFAULT NULL,
  `created_at`  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_hist_prod` (`producto_id`),
  CONSTRAINT `fk_hist_prod` FOREIGN KEY (`producto_id`) REFERENCES `productos`(`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_hist_usr`  FOREIGN KEY (`usuario_id`)  REFERENCES `usuarios`(`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `producto_bloqueos` (
  `producto_id`    INT UNSIGNED NOT NULL,
  `usuario_id`     INT UNSIGNED NOT NULL,
  `usuario_nombre` VARCHAR(100) NOT NULL,
  `updated_at`     DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`producto_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `importacion_colaboradores` (
  `id`             INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `importacion_id` INT UNSIGNED NOT NULL,
  `usuario_id`     INT UNSIGNED NOT NULL,
  `creado_at`      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_imp_usr` (`importacion_id`, `usuario_id`),
  CONSTRAINT `fk_ic_imp` FOREIGN KEY (`importacion_id`) REFERENCES `importaciones`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

SET foreign_key_checks = 1;
