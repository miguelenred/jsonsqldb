-- Base de origen para tests/f17_rutinas_importadas.php: tablas, vistas y
-- triggers escritos como se escriben en PostgreSQL, con funciones plpgsql.
-- Se crea en el servidor, se vuelca con pg_dump, se importa en jsonSQLDB y se
-- comprueba que las vistas y los triggers hacen lo mismo.

CREATE TABLE clientes (id SERIAL PRIMARY KEY, nombre VARCHAR(60) NOT NULL, email VARCHAR(100),
  saldo NUMERIC(10,2) NOT NULL DEFAULT 0, alta TIMESTAMP, nivel VARCHAR(10));
CREATE TABLE pedidos (id SERIAL PRIMARY KEY, cliente_id INTEGER NOT NULL REFERENCES clientes (id),
  total NUMERIC(10,2) NOT NULL, estado VARCHAR(20), creado TIMESTAMP);
CREATE INDEX ix_estado ON pedidos (estado);
CREATE TABLE registro (id SERIAL PRIMARY KEY, msg VARCHAR(200));

CREATE VIEW v_resumen AS
  SELECT c.nombre, COUNT(p.id) AS n, COALESCE(SUM(p.total), 0) AS total, ROUND(AVG(p.total), 2) AS media
  FROM clientes c LEFT JOIN pedidos p ON p.cliente_id = c.id GROUP BY c.id, c.nombre;

CREATE VIEW v_textos AS
  SELECT id, upper(nombre) AS may, char_length(nombre) AS largo, substring(nombre, 2, 3) AS trozo,
         left(nombre, 2) AS ini, right(nombre, 2) AS fin, nombre || ' <' || COALESCE(email, '-') || '>' AS contacto,
         concat(nombre, '/', email) AS c2, concat_ws(' - ', nombre, email) AS ficha, strpos(nombre, 'a') AS pos,
         CASE WHEN email IS NULL THEN 'sin email' ELSE lower(email) END AS correo, id::text || '#' AS clave
  FROM clientes;

CREATE VIEW v_fechas AS
  SELECT id, to_char(creado, 'YYYY-MM') AS mes, creado + interval '1 day' AS manana, creado - interval '2 hours' AS antes,
         EXTRACT(YEAR FROM creado)::int AS anio, date_part('month', creado)::int AS m,
         date_trunc('month', creado) AS inicio, creado::date AS dia
  FROM pedidos WHERE creado IS NOT NULL;

CREATE VIEW v_calc AS
  SELECT id, GREATEST(total, 100) AS tope, LEAST(total, 100) AS suelo, floor(total / 7) AS f, ceil(total / 7) AS c,
         mod(id, 3) AS resto, trunc(total / 3, 2) AS tercio,
         CASE WHEN total > 100 THEN 'alto' ELSE 'bajo' END AS tramo, (total > 50)::int AS grande
  FROM pedidos;

CREATE VIEW v_filtro AS
  SELECT nombre FROM clientes WHERE nombre LIKE 'A%' OR nombre ILIKE 'b%' ORDER BY nombre LIMIT 10;

CREATE VIEW v_grupos AS
  SELECT cliente_id, string_agg(DISTINCT estado, '|' ORDER BY estado) AS estados FROM pedidos GROUP BY cliente_id;

CREATE VIEW v_in AS
  SELECT id, estado FROM pedidos WHERE estado IN ('nuevo', 'pagado') AND id <> ALL (ARRAY[99, 100]);

CREATE VIEW v_de_vista AS SELECT nombre, total FROM v_resumen WHERE n > 0;

CREATE FUNCTION pedidos_bi_fn() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
  IF NEW.total < 0 THEN
    RAISE EXCEPTION 'total negativo';
  ELSIF NEW.total > 1000 THEN
    NEW.estado := 'revisar';
  END IF;
  IF NEW.estado IS NULL THEN NEW.estado := 'nuevo'; END IF;
  NEW.creado := COALESCE(NEW.creado, '2026-01-01 00:00:00');
  RETURN NEW;
END;
$$;
CREATE TRIGGER pedidos_bi BEFORE INSERT ON pedidos FOR EACH ROW EXECUTE FUNCTION pedidos_bi_fn();

CREATE FUNCTION pedidos_cambios_fn() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
  IF TG_OP = 'INSERT' THEN
    UPDATE clientes SET saldo = saldo + NEW.total WHERE id = NEW.cliente_id;
    INSERT INTO registro (msg) VALUES ('alta ' || NEW.id || ' de ' || NEW.cliente_id);
    RETURN NEW;
  ELSIF TG_OP = 'UPDATE' THEN
    IF OLD.total <> NEW.total THEN
      UPDATE clientes SET saldo = saldo - OLD.total + NEW.total WHERE id = NEW.cliente_id;
    END IF;
    RETURN NEW;
  ELSE
    UPDATE clientes SET saldo = saldo - OLD.total WHERE id = OLD.cliente_id;
    RETURN OLD;
  END IF;
END;
$$;
CREATE TRIGGER pedidos_cambios AFTER INSERT OR UPDATE OR DELETE ON pedidos FOR EACH ROW EXECUTE FUNCTION pedidos_cambios_fn();

CREATE FUNCTION pedidos_bd_fn() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
  IF OLD.estado = 'cerrado' THEN
    RAISE EXCEPTION 'no se borra el pedido % porque está cerrado', OLD.id;
  END IF;
  RETURN OLD;
END;
$$;
CREATE TRIGGER pedidos_bd BEFORE DELETE ON pedidos FOR EACH ROW EXECUTE FUNCTION pedidos_bd_fn();

CREATE FUNCTION registrar_estado() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
  INSERT INTO registro (msg) VALUES ('estado ' || NEW.id || ': ' || COALESCE(OLD.estado, '-') || ' -> ' || COALESCE(NEW.estado, '-'));
  RETURN NULL;
END;
$$;
CREATE TRIGGER pedidos_estado AFTER UPDATE ON pedidos FOR EACH ROW
  WHEN (OLD.estado IS DISTINCT FROM NEW.estado) EXECUTE FUNCTION registrar_estado();

CREATE FUNCTION clientes_normalizar() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
  NEW.email := lower(trim(NEW.email));
  NEW.nivel := CASE WHEN NEW.saldo > 500 THEN 'oro' ELSE 'normal' END;
  IF TG_OP = 'UPDATE' AND NEW.saldo < -1000 THEN
    RAISE EXCEPTION 'saldo demasiado negativo';
  END IF;
  RETURN NEW;
END;
$$;
CREATE TRIGGER clientes_normalizar BEFORE INSERT OR UPDATE ON clientes FOR EACH ROW EXECUTE FUNCTION clientes_normalizar();

INSERT INTO clientes (nombre, email, saldo, alta) VALUES
  ('Ana', ' ANA@E.ES ', 0, '2026-01-05 10:00:00'),
  ('alberto', NULL, 600, '2026-02-10 08:30:00'),
  ('Bea', 'Bea@E.es', 0, '2026-03-01 00:00:00');
INSERT INTO pedidos (cliente_id, total, estado, creado) VALUES
  (1, 40, NULL, '2026-03-01 09:00:00'),
  (1, 120, 'pagado', '2026-03-15 18:45:00'),
  (2, 75.5, NULL, '2026-03-20 12:00:00');
