-- Base de origen para tests/f17_rutinas_importadas.php: tablas, vistas y
-- triggers escritos como se escriben en MySQL / MariaDB, con sus funciones y
-- su sintaxis. Se crea en el servidor, se vuelca con mysqldump, se importa en
-- jsonSQLDB y se comprueba que las vistas y los triggers hacen lo mismo.

CREATE TABLE clientes (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  nombre VARCHAR(60) NOT NULL,
  email VARCHAR(100) NULL,
  saldo DECIMAL(10,2) NOT NULL DEFAULT 0,
  alta DATETIME NULL,
  nivel VARCHAR(10) NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin;

CREATE TABLE pedidos (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  cliente_id INT UNSIGNED NOT NULL,
  total DECIMAL(10,2) NOT NULL,
  estado VARCHAR(20) NULL,
  creado DATETIME NULL,
  KEY ix_estado (estado),
  CONSTRAINT fk_cli FOREIGN KEY (cliente_id) REFERENCES clientes (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin;

CREATE TABLE registro (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  msg VARCHAR(200)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin;

CREATE VIEW v_resumen AS
  SELECT c.nombre, COUNT(p.id) AS n, IFNULL(SUM(p.total), 0) AS total, ROUND(AVG(p.total), 2) AS media
  FROM clientes c LEFT JOIN pedidos p ON p.cliente_id = c.id GROUP BY c.id, c.nombre;

CREATE VIEW v_textos AS
  SELECT id, UCASE(nombre) AS may, CHAR_LENGTH(nombre) AS largo, LEFT(nombre, 2) AS ini, RIGHT(nombre, 2) AS fin,
         CONCAT_WS(' - ', nombre, email) AS ficha, IF(email IS NULL, 'sin email', email) AS correo,
         LOCATE('a', nombre) AS pos, SUBSTRING(nombre FROM 2 FOR 3) AS trozo, CONCAT(nombre, '#', id) AS clave
  FROM clientes;

CREATE VIEW v_fechas AS
  SELECT id, DATE_FORMAT(creado, '%Y-%m') AS mes, DATE_ADD(creado, INTERVAL 1 DAY) AS manana,
         DATE_SUB(creado, INTERVAL 2 HOUR) AS antes, YEAR(creado) AS anio, MONTH(creado) AS m,
         DATEDIFF(creado, '2026-01-01') AS dias
  FROM pedidos WHERE creado IS NOT NULL;

CREATE VIEW v_calc AS
  SELECT id, GREATEST(total, 100) AS tope, LEAST(total, 100) AS suelo, FLOOR(total / 7) AS f, CEIL(total / 7) AS c,
         MOD(id, 3) AS resto, TRUNCATE(total / 3, 2) AS tercio,
         CASE WHEN total > 100 THEN 'alto' ELSE 'bajo' END AS tramo
  FROM pedidos;

CREATE VIEW v_top AS SELECT nombre, saldo FROM clientes ORDER BY saldo DESC LIMIT 0, 2;

CREATE VIEW v_grupos AS
  SELECT cliente_id, GROUP_CONCAT(DISTINCT estado ORDER BY estado SEPARATOR '|') AS estados
  FROM pedidos GROUP BY cliente_id;

CREATE VIEW v_de_vista AS SELECT nombre, total FROM v_resumen WHERE n > 0;

-- Lo que MySQL 8 reescribe a su manera al guardar la vista: NOT EXISTS y
-- NOT (x IN (…)) como «… is false», REGEXP como regexp_like(), y además
-- IS TRUE / IS NOT FALSE / IS UNKNOWN, <=> y CAST AS SIGNED (que redondea)
CREATE VIEW v_bool AS
  SELECT id, saldo IS TRUE AS t1, saldo IS NOT FALSE AS t2, (saldo > 100) IS UNKNOWN AS t3,
         email <=> NULL AS t4, nivel <=> 'oro' AS t5, CAST(saldo / 3 AS SIGNED) AS t6
  FROM clientes;

CREATE VIEW v_sin AS
  SELECT id, nombre FROM clientes
  WHERE NOT EXISTS (SELECT 1 FROM pedidos p WHERE p.cliente_id = clientes.id AND p.estado = 'cerrado')
    AND (NOT (id IN (SELECT cliente_id FROM pedidos WHERE total > 1000)) OR nombre REGEXP '^[A-Z]');

DELIMITER ;;
CREATE TRIGGER pedidos_bi BEFORE INSERT ON pedidos FOR EACH ROW
BEGIN
  IF NEW.total < 0 THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'total negativo';
  ELSEIF NEW.total > 1000 THEN
    SET NEW.estado = 'revisar';
  END IF;
  IF NEW.estado IS NULL THEN SET NEW.estado = 'nuevo'; END IF;
  SET NEW.creado = IFNULL(NEW.creado, '2026-01-01 00:00:00');
END;;

CREATE TRIGGER pedidos_ai AFTER INSERT ON pedidos FOR EACH ROW
BEGIN
  UPDATE clientes SET saldo = saldo + NEW.total WHERE id = NEW.cliente_id;
  INSERT INTO registro SET msg = CONCAT('alta ', NEW.id, ' de ', NEW.cliente_id);
END;;

CREATE TRIGGER pedidos_au AFTER UPDATE ON pedidos FOR EACH ROW
BEGIN
  IF OLD.total <> NEW.total THEN
    UPDATE clientes SET saldo = saldo - OLD.total + NEW.total WHERE id = NEW.cliente_id;
  END IF;
END;;

CREATE TRIGGER pedidos_bd BEFORE DELETE ON pedidos FOR EACH ROW
BEGIN
  IF OLD.estado = 'cerrado' THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'no se borra un pedido cerrado';
  END IF;
END;;

CREATE TRIGGER pedidos_ad AFTER DELETE ON pedidos FOR EACH ROW
  UPDATE clientes SET saldo = saldo - OLD.total WHERE id = OLD.cliente_id;;

CREATE TRIGGER clientes_bi BEFORE INSERT ON clientes FOR EACH ROW
BEGIN
  SET NEW.email = LCASE(TRIM(NEW.email)), NEW.nivel = IF(NEW.saldo > 500, 'oro', 'normal');
END;;

CREATE TRIGGER clientes_bu BEFORE UPDATE ON clientes FOR EACH ROW
BEGIN
  IF NEW.saldo < -1000 THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'saldo demasiado negativo';
  END IF;
  SET NEW.nivel = CASE WHEN NEW.saldo > 500 THEN 'oro' ELSE 'normal' END;
END;;
DELIMITER ;

INSERT INTO clientes (nombre, email, saldo, alta) VALUES
  ('Ana', ' ANA@E.ES ', 0, '2026-01-05 10:00:00'),
  ('alberto', NULL, 600, '2026-02-10 08:30:00'),
  ('Bea', 'Bea@E.es', 0, '2026-03-01 00:00:00');
INSERT INTO pedidos (cliente_id, total, estado, creado) VALUES
  (1, 40, NULL, '2026-03-01 09:00:00'),
  (1, 120, 'pagado', '2026-03-15 18:45:00'),
  (2, 75.5, NULL, '2026-03-20 12:00:00');
