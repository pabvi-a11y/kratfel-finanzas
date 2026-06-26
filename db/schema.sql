-- KRATFEL Finanzas — esquema MySQL v3
-- Ejecutar una vez en la BD de SiteGround.
SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS transacciones (
  id              BIGINT AUTO_INCREMENT PRIMARY KEY,
  qbo_id          VARCHAR(64) NULL,
  fecha           DATE NOT NULL,
  descripcion     VARCHAR(500),
  monto_canonico  DECIMAL(14,2) NOT NULL,
  cuenta          VARCHAR(120) NOT NULL,
  tipo_cuenta     ENUM('checking','credit_card') NOT NULL,
  categoria       VARCHAR(160) NOT NULL,
  subcategoria    VARCHAR(160) NULL,
  grupo_pnl       ENUM('operativo','distribucion','impuestos','transfer','reserve_draw') NOT NULL,
  origen          ENUM('qbo_api','qbo_xlsx') NOT NULL,
  import_batch_id INT NULL,
  dedupe_hash     CHAR(64) NOT NULL,
  creada_en       TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_qbo (qbo_id),
  UNIQUE KEY uq_dedupe (dedupe_hash),
  KEY idx_fecha (fecha), KEY idx_grupo (grupo_pnl), KEY idx_cat (categoria)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS saldos (
  id         INT AUTO_INCREMENT PRIMARY KEY,
  cuenta     VARCHAR(60) NOT NULL DEFAULT 'Cetera',
  fecha      DATE NOT NULL,
  saldo      DECIMAL(14,2) NOT NULL,
  fuente     ENUM('adviceworks_manual','csv') NOT NULL DEFAULT 'adviceworks_manual',
  nota       VARCHAR(255),
  creado_en  TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  KEY idx_fecha (fecha)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS supuestos_forecast (
  id            INT AUTO_INCREMENT PRIMARY KEY,
  categoria     VARCHAR(160) NULL,
  grupo         VARCHAR(40) NULL,
  monto_mensual DECIMAL(14,2) NOT NULL,
  desde DATE, hasta DATE, nota VARCHAR(255)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS escenarios (
  id             INT AUTO_INCREMENT PRIMARY KEY,
  nombre         VARCHAR(120) NOT NULL,
  json_overrides JSON NOT NULL,
  es_base        TINYINT(1) DEFAULT 0,
  creado_en      TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS usuarios (
  id         INT AUTO_INCREMENT PRIMARY KEY,
  email      VARCHAR(160) NOT NULL UNIQUE,
  pass_hash  VARCHAR(255) NOT NULL,
  nombre     VARCHAR(80),
  creado_en  TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS webauthn_credentials (
  id            INT AUTO_INCREMENT PRIMARY KEY,
  usuario_id    INT NOT NULL,
  credential_id VARBINARY(255) NOT NULL,
  public_key    BLOB NOT NULL,
  sign_count    BIGINT DEFAULT 0,
  transports    VARCHAR(120), apodo VARCHAR(80),
  creado_en     TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_cred (credential_id),
  FOREIGN KEY (usuario_id) REFERENCES usuarios(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS qbo_oauth (
  id                INT AUTO_INCREMENT PRIMARY KEY,
  realm_id          VARCHAR(40) NOT NULL,
  access_token_enc  VARBINARY(2048) NOT NULL,
  refresh_token_enc VARBINARY(2048) NOT NULL,
  expires_at        DATETIME NOT NULL,
  ultima_sync       DATETIME NULL,
  estado            ENUM('conectado','desconectado') NOT NULL DEFAULT 'conectado',
  actualizado_en    TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_realm (realm_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS import_batches (
  id           INT AUTO_INCREMENT PRIMARY KEY,
  origen       ENUM('qbo_api','qbo_xlsx') NOT NULL,
  archivo      VARCHAR(255) NULL,
  filas_nuevas INT DEFAULT 0, filas_dup INT DEFAULT 0,
  nota         VARCHAR(255) NULL,
  creado_en    TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
