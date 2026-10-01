-- Base de origen para tests/f14_volcados.php: se crea en MySQL, se vuelca con mysqldump y
-- el volcado se importa en jsonSQLDB. Los datos tienen que llegar iguales.
CREATE TABLE z_clientes (
  id int(11) unsigned NOT NULL AUTO_INCREMENT,
  email varchar(120) COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'correo',
  nombre varchar(80) DEFAULT NULL,
  saldo decimal(10,2) NOT NULL DEFAULT 0.00,
  ratio double DEFAULT NULL,
  activo tinyint(1) NOT NULL DEFAULT 1,
  tipo enum('particular','empresa') DEFAULT 'particular',
  notas text,
  alta datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_email (email),
  KEY ix_nombre (nombre(20)),
  CONSTRAINT ck_saldo CHECK (saldo > -100000)
) ENGINE=InnoDB AUTO_INCREMENT=10 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE a_pedidos (
  id bigint(20) NOT NULL AUTO_INCREMENT,
  cliente_id int(11) unsigned NOT NULL,
  total decimal(12,2) DEFAULT NULL,
  creado timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (id),
  KEY fk_cliente (cliente_id),
  CONSTRAINT fk_pedidos_cliente FOREIGN KEY (cliente_id) REFERENCES z_clientes (id) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
INSERT INTO z_clientes (email, nombre, saldo, ratio, activo, tipo, notas, alta) VALUES
 ('ana@e.es', 'Ana O''Brien', 10.50, 0.333333333333333, 1, 'particular', 'línea 1\nlínea 2', '2026-01-02 03:04:05'),
 ('bea@e.es', 'Bea "comillas" \\ barra', -3.00, NULL, 0, 'empresa', NULL, '2026-02-03 04:05:06'),
 ('cris@e.es', 'Cris 😀 emoji', 0.00, 1e20, 1, NULL, 'tab\there\r\nCRLF', '2026-03-04 05:06:07'),
 ('dani@e.es', NULL, 99999.99, -2.5, 1, 'particular', '%_ comodines y ;punto y coma', '2026-04-05 06:07:08');
INSERT INTO a_pedidos (cliente_id, total, creado) VALUES (10, 120.50, '2026-05-01 10:00:00'), (11, 0.10, '2026-05-02 11:00:00'), (10, NULL, '2026-05-03 12:00:00');
CREATE VIEW v_grandes AS SELECT id, total FROM a_pedidos WHERE total > 100;
CREATE TRIGGER trg_pedidos BEFORE INSERT ON a_pedidos FOR EACH ROW BEGIN IF NEW.total < 0 THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'negativo'; END IF; END;
