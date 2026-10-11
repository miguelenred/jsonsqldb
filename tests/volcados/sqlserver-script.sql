-- jsonSQLDB: SQL Server database tienda, dumped with sqlserver-to-jsonsqldb.ps1
-- Import it in jsonSQLDBadmin: open the database, "Import an SQL dump" (format: Detect, or SQL Server)
SET NOCOUNT ON;
GO

CREATE TABLE [clientes] (
    [id] int IDENTITY(1, 1) NOT NULL,
    [nombre] nvarchar(50) NOT NULL,
    [email] varchar(120) NULL,
    [activo] bit NOT NULL DEFAULT ((1)),
    [alta] datetime NULL,
    [nacido] date NULL,
    [notas] nvarchar(max) NULL,
    [saldo] decimal(10, 2) NOT NULL DEFAULT ((0)),
    [foto] varbinary(max) NULL,
    CONSTRAINT [PK_clientes] PRIMARY KEY ([id]),
    CONSTRAINT [UQ_clientes_email] UNIQUE ([email])
);
GO
SET IDENTITY_INSERT [clientes] ON;
INSERT INTO [clientes] ([id], [nombre], [email], [activo], [alta], [nacido], [notas], [saldo], [foto]) VALUES (1, N'Ana', N'ana@e.es', 1, '2026-01-05 10:00:00', N'1990-03-01', N'Primera
línea con ''comillas''', 10.5, 0xCAFE);
INSERT INTO [clientes] ([id], [nombre], [email], [activo], [alta], [nacido], [notas], [saldo], [foto]) VALUES (2, N'Bea Ñúñez 😀', NULL, 0, NULL, NULL, NULL, 0, NULL);
SET IDENTITY_INSERT [clientes] OFF;
CREATE INDEX [IX_clientes_nombre] ON [clientes] ([nombre]);
GO

CREATE TABLE [ventas_pedidos] (
    [id] int IDENTITY(1, 1) NOT NULL,
    [cliente] int NOT NULL,
    [total] money NOT NULL,
    [creado] datetime2(3) NULL,
    [ratio] float NULL,
    CONSTRAINT [PK_pedidos] PRIMARY KEY ([id])
);
GO
SET IDENTITY_INSERT [ventas_pedidos] ON;
INSERT INTO [ventas_pedidos] ([id], [cliente], [total], [creado], [ratio]) VALUES (1, 1, 40, N'2026-03-01 09:00:00.123', 0.25);
INSERT INTO [ventas_pedidos] ([id], [cliente], [total], [creado], [ratio]) VALUES (2, 1, 120.5, N'2026-03-15 18:45:00.000', NULL);
INSERT INTO [ventas_pedidos] ([id], [cliente], [total], [creado], [ratio]) VALUES (3, 2, 7, NULL, 1.5);
SET IDENTITY_INSERT [ventas_pedidos] OFF;
GO

CREATE TABLE [ventas_registro] (
    [pedido] int NULL,
    [cuando] datetime NULL DEFAULT (getdate())
);
GO

ALTER TABLE [ventas_pedidos] ADD CONSTRAINT [FK_pedidos_clientes] FOREIGN KEY ([cliente]) REFERENCES [clientes] ([id]) ON DELETE CASCADE;
GO

CREATE VIEW [v_totales] AS
SELECT c.nombre, SUM(p.total) AS total, COUNT(*) AS pedidos
FROM [clientes] c JOIN [ventas_pedidos] p ON p.cliente = c.id
GROUP BY c.nombre
GO

CREATE TRIGGER [ventas_trg_pedidos] ON [ventas_pedidos] AFTER INSERT AS
BEGIN
    SET NOCOUNT ON;
    INSERT INTO [ventas_registro] (pedido) SELECT id FROM inserted;
END
GO
