-- Script de SQL Server al estilo de «Generar scripts» de Management Studio,
-- para tests/f17_rutinas_importadas.php: las mismas tablas, vistas y reglas
-- que tests/volcados/rutinas_mysql.sql, escritas como se escriben en SQL
-- Server: funciones de T-SQL en las vistas y triggers que trabajan con todas
-- las filas a la vez (inserted, deleted, IF EXISTS, RAISERROR, THROW,
-- UPDATE … FROM … JOIN inserted, IF UPDATE(col)). Aquí no hay SQL Server: se
-- comprueba que, importado, hace lo mismo que la versión de MySQL, que a su
-- vez se comprueba contra un MySQL de verdad.
USE [tienda]
GO
/****** Object:  Table [dbo].[clientes]    Script Date: 01/10/2026 12:00:00 ******/
SET ANSI_NULLS ON
GO
SET QUOTED_IDENTIFIER ON
GO
CREATE TABLE [dbo].[clientes](
	[id] [int] IDENTITY(1,1) NOT NULL,
	[nombre] [nvarchar](60) NOT NULL,
	[email] [nvarchar](100) NULL,
	[saldo] [decimal](10, 2) NOT NULL,
	[alta] [datetime] NULL,
	[nivel] [nvarchar](10) NULL,
 CONSTRAINT [PK_clientes] PRIMARY KEY CLUSTERED 
(
	[id] ASC
)WITH (PAD_INDEX = OFF, STATISTICS_NORECOMPUTE = OFF, IGNORE_DUP_KEY = OFF, ALLOW_ROW_LOCKS = ON, ALLOW_PAGE_LOCKS = ON) ON [PRIMARY]
) ON [PRIMARY]
GO
/****** Object:  Table [dbo].[pedidos]    Script Date: 01/10/2026 12:00:00 ******/
CREATE TABLE [dbo].[pedidos](
	[id] [int] IDENTITY(1,1) NOT NULL,
	[cliente_id] [int] NOT NULL,
	[total] [decimal](10, 2) NOT NULL,
	[estado] [nvarchar](20) NULL,
	[creado] [datetime] NULL,
 CONSTRAINT [PK_pedidos] PRIMARY KEY CLUSTERED 
(
	[id] ASC
)WITH (PAD_INDEX = OFF, STATISTICS_NORECOMPUTE = OFF, IGNORE_DUP_KEY = OFF, ALLOW_ROW_LOCKS = ON, ALLOW_PAGE_LOCKS = ON) ON [PRIMARY]
) ON [PRIMARY]
GO
/****** Object:  Table [dbo].[registro]    Script Date: 01/10/2026 12:00:00 ******/
CREATE TABLE [dbo].[registro](
	[id] [int] IDENTITY(1,1) NOT NULL,
	[msg] [nvarchar](200) NULL,
 CONSTRAINT [PK_registro] PRIMARY KEY CLUSTERED 
(
	[id] ASC
)WITH (PAD_INDEX = OFF, STATISTICS_NORECOMPUTE = OFF, IGNORE_DUP_KEY = OFF, ALLOW_ROW_LOCKS = ON, ALLOW_PAGE_LOCKS = ON) ON [PRIMARY]
) ON [PRIMARY]
GO
/****** Object:  View [dbo].[v_resumen]    Script Date: 01/10/2026 12:00:00 ******/
CREATE VIEW [dbo].[v_resumen] AS
SELECT c.nombre, COUNT(p.id) AS n, ISNULL(SUM(p.total), 0) AS total, ROUND(AVG(p.total), 2) AS media
FROM dbo.clientes c LEFT JOIN dbo.pedidos p WITH (NOLOCK) ON p.cliente_id = c.id
GROUP BY c.id, c.nombre
GO
/****** Object:  View [dbo].[v_textos]    Script Date: 01/10/2026 12:00:00 ******/
CREATE VIEW [dbo].[v_textos] AS
SELECT id, UPPER(nombre) AS may, LEN(nombre) AS largo, LEFT(nombre, 2) AS ini, RIGHT(nombre, 2) AS fin,
       CONCAT_WS(N' - ', nombre, email) AS ficha, IIF(email IS NULL, N'sin email', email) AS correo,
       CHARINDEX(N'a', nombre) AS pos, SUBSTRING(nombre, 2, 3) AS trozo,
       nombre + N'#' + CAST(id AS NVARCHAR(10)) AS clave
FROM dbo.clientes
GO
/****** Object:  View [dbo].[v_fechas]    Script Date: 01/10/2026 12:00:00 ******/
CREATE VIEW [dbo].[v_fechas] AS
SELECT id, FORMAT(creado, 'yyyy-MM') AS mes, DATEADD(day, 1, creado) AS manana, DATEADD(hour, -2, creado) AS antes,
       YEAR(creado) AS anio, MONTH(creado) AS m, DATEDIFF(day, '2026-01-01', creado) AS dias
FROM dbo.pedidos
WHERE creado IS NOT NULL
GO
/****** Object:  View [dbo].[v_calc]    Script Date: 01/10/2026 12:00:00 ******/
CREATE VIEW [dbo].[v_calc] AS
SELECT id, IIF(total > 100, total, 100) AS tope, IIF(total < 100, total, 100) AS suelo,
       FLOOR(total / 7) AS f, CEILING(total / 7) AS c, id % 3 AS resto, ROUND(total / 3, 2, 1) AS tercio,
       CASE WHEN total > 100 THEN N'alto' ELSE N'bajo' END AS tramo
FROM dbo.pedidos
GO
/****** Object:  View [dbo].[v_top]    Script Date: 01/10/2026 12:00:00 ******/
CREATE VIEW [dbo].[v_top] AS
SELECT TOP (2) nombre, saldo FROM dbo.clientes ORDER BY saldo DESC
GO
/****** Object:  View [dbo].[v_grupos]    Script Date: 01/10/2026 12:00:00 ******/
CREATE VIEW [dbo].[v_grupos] AS
SELECT cliente_id, STRING_AGG(estado, N'|') WITHIN GROUP (ORDER BY estado) AS estados
FROM (SELECT DISTINCT cliente_id, estado FROM dbo.pedidos) AS d
GROUP BY cliente_id
GO
/****** Object:  View [dbo].[v_de_vista]    Script Date: 01/10/2026 12:00:00 ******/
CREATE VIEW [dbo].[v_de_vista] AS
SELECT nombre, total FROM dbo.v_resumen WHERE n > 0
GO
SET IDENTITY_INSERT [dbo].[clientes] ON 
GO
INSERT [dbo].[clientes] ([id], [nombre], [email], [saldo], [alta], [nivel]) VALUES (1, N'Ana', N'ana@e.es', CAST(160.00 AS Decimal(10, 2)), CAST(N'2026-01-05T10:00:00.000' AS DateTime), N'normal')
GO
INSERT [dbo].[clientes] ([id], [nombre], [email], [saldo], [alta], [nivel]) VALUES (2, N'alberto', NULL, CAST(675.50 AS Decimal(10, 2)), CAST(N'2026-02-10T08:30:00.000' AS DateTime), N'oro')
GO
INSERT [dbo].[clientes] ([id], [nombre], [email], [saldo], [alta], [nivel]) VALUES (3, N'Bea', N'bea@e.es', CAST(0.00 AS Decimal(10, 2)), CAST(N'2026-03-01T00:00:00.000' AS DateTime), N'normal')
GO
SET IDENTITY_INSERT [dbo].[clientes] OFF
GO
SET IDENTITY_INSERT [dbo].[pedidos] ON 
GO
INSERT [dbo].[pedidos] ([id], [cliente_id], [total], [estado], [creado]) VALUES (1, 1, CAST(40.00 AS Decimal(10, 2)), N'nuevo', CAST(N'2026-03-01T09:00:00.000' AS DateTime))
GO
INSERT [dbo].[pedidos] ([id], [cliente_id], [total], [estado], [creado]) VALUES (2, 1, CAST(120.00 AS Decimal(10, 2)), N'pagado', CAST(N'2026-03-15T18:45:00.000' AS DateTime))
GO
INSERT [dbo].[pedidos] ([id], [cliente_id], [total], [estado], [creado]) VALUES (3, 2, CAST(75.50 AS Decimal(10, 2)), N'nuevo', CAST(N'2026-03-20T12:00:00.000' AS DateTime))
GO
SET IDENTITY_INSERT [dbo].[pedidos] OFF
GO
SET IDENTITY_INSERT [dbo].[registro] ON 
GO
INSERT [dbo].[registro] ([id], [msg]) VALUES (1, N'alta 1 de 1')
GO
INSERT [dbo].[registro] ([id], [msg]) VALUES (2, N'alta 2 de 1')
GO
INSERT [dbo].[registro] ([id], [msg]) VALUES (3, N'alta 3 de 2')
GO
SET IDENTITY_INSERT [dbo].[registro] OFF
GO
ALTER TABLE [dbo].[clientes] ADD  DEFAULT ((0)) FOR [saldo]
GO
ALTER TABLE [dbo].[pedidos]  WITH CHECK ADD  CONSTRAINT [fk_cli] FOREIGN KEY([cliente_id])
REFERENCES [dbo].[clientes] ([id])
GO
ALTER TABLE [dbo].[pedidos] CHECK CONSTRAINT [fk_cli]
GO
/****** Object:  Trigger [dbo].[pedidos_alta]    Script Date: 01/10/2026 12:00:00 ******/
SET ANSI_NULLS ON
GO
SET QUOTED_IDENTIFIER ON
GO
CREATE TRIGGER [dbo].[pedidos_alta] ON [dbo].[pedidos]
AFTER INSERT
AS
BEGIN
	SET NOCOUNT ON;
	IF EXISTS (SELECT * FROM inserted WHERE total < 0)
	BEGIN
		RAISERROR (N'total negativo', 16, 1);
		ROLLBACK TRANSACTION;
		RETURN;
	END
	UPDATE p SET estado = CASE WHEN i.total > 1000 THEN N'revisar' ELSE ISNULL(i.estado, N'nuevo') END,
	             creado = ISNULL(i.creado, '2026-01-01 00:00:00')
	FROM dbo.pedidos p INNER JOIN inserted i ON p.id = i.id;
	UPDATE c SET saldo = c.saldo + i.total FROM dbo.clientes c INNER JOIN inserted i ON c.id = i.cliente_id;
	INSERT INTO dbo.registro (msg)
	SELECT N'alta ' + CAST(i.id AS NVARCHAR(10)) + N' de ' + CAST(i.cliente_id AS NVARCHAR(10)) FROM inserted i;
END
GO
ALTER TABLE [dbo].[pedidos] ENABLE TRIGGER [pedidos_alta]
GO
/****** Object:  Trigger [dbo].[pedidos_cambios]    Script Date: 01/10/2026 12:00:00 ******/
CREATE TRIGGER [dbo].[pedidos_cambios] ON [dbo].[pedidos]
AFTER UPDATE
AS
BEGIN
	SET NOCOUNT ON;
	IF UPDATE(total)
		UPDATE c SET saldo = c.saldo - d.total + i.total
		FROM dbo.clientes c
		INNER JOIN inserted i ON c.id = i.cliente_id
		INNER JOIN deleted d ON d.id = i.id
		WHERE i.total <> d.total;
END
GO
ALTER TABLE [dbo].[pedidos] ENABLE TRIGGER [pedidos_cambios]
GO
/****** Object:  Trigger [dbo].[pedidos_baja]    Script Date: 01/10/2026 12:00:00 ******/
CREATE TRIGGER [dbo].[pedidos_baja] ON [dbo].[pedidos]
FOR DELETE
AS
BEGIN
	SET NOCOUNT ON;
	IF EXISTS (SELECT 1 FROM deleted WHERE estado = N'cerrado')
	BEGIN
		THROW 50001, N'no se borra un pedido cerrado', 1;
	END
	UPDATE c SET saldo = c.saldo - d.total FROM dbo.clientes c INNER JOIN deleted d ON c.id = d.cliente_id;
END
GO
ALTER TABLE [dbo].[pedidos] ENABLE TRIGGER [pedidos_baja]
GO
/****** Object:  Trigger [dbo].[clientes_normalizar]    Script Date: 01/10/2026 12:00:00 ******/
CREATE TRIGGER [dbo].[clientes_normalizar] ON [dbo].[clientes]
AFTER INSERT, UPDATE
AS
BEGIN
	SET NOCOUNT ON;
	DECLARE @saldo DECIMAL(10, 2);
	SELECT @saldo = saldo FROM inserted;
	IF EXISTS (SELECT * FROM deleted) AND @saldo < -1000
	BEGIN
		RAISERROR(N'saldo demasiado negativo', 16, 1);
		ROLLBACK;
		RETURN;
	END
	UPDATE c SET email = LOWER(LTRIM(RTRIM(i.email))),
	             nivel = CASE WHEN i.saldo > 500 THEN N'oro' ELSE N'normal' END
	FROM dbo.clientes c INNER JOIN inserted i ON c.id = i.id;
END
GO
ALTER TABLE [dbo].[clientes] ENABLE TRIGGER [clientes_normalizar]
GO
