-- jsonsqldb-dialecto: access
-- prueba.accdb, dumped by access-to-jsonsqldb.ps1
-- Import it in jsonSQLDBadmin: open the database, "Import an SQL dump".
-- Access SQL (ANSI-89). To load it into Access: run this script and choose "Load an SQL file into Access",
-- or paste each statement (not the -- lines) into Create > Query Design > SQL View and Run.
-- Saved queries go as CREATE VIEW: only .mdb of Access 2000-2003 and .accdb, and only in ANSI-92 syntax (ADO/OLEDB,
-- or the database's "SQL Server Compatible Syntax (ANSI 92)" option, since Access 2002); not in Access 97 or earlier.
-- In any version: paste what follows AS into a new query and save it with the view's name, or load the file
-- with this script, which creates them as saved queries without CREATE VIEW.
-- A "-- [relationship] ON DELETE CASCADE" (or ON UPDATE) line is what Access SQL cannot write: set it by hand in
-- Database Tools > Relationships > Enforce Referential Integrity > Cascade. jsonSQLDBadmin applies it on import.

CREATE TABLE [Clientes] ([Id] COUNTER CONSTRAINT [PK_Clientes] PRIMARY KEY, [Nombre] TEXT(50) NOT NULL, [Email] TEXT(100), [Activo] BIT NOT NULL, [Alta] DATETIME, [Notas] MEMO);

INSERT INTO [Clientes] ([Id], [Nombre], [Email], [Activo], [Alta], [Notas]) VALUES (1, 'Ana', 'ana@e.es', True, #2026-01-05 10:00:00#, 'Primera' & Chr(13) & Chr(10) & 'línea con ''comillas'' y "dobles"');
INSERT INTO [Clientes] ([Id], [Nombre], [Email], [Activo], [Alta], [Notas]) VALUES (2, 'alberto', NULL, False, #2026-02-10 08:30:00#, NULL);
INSERT INTO [Clientes] ([Id], [Nombre], [Email], [Activo], [Alta], [Notas]) VALUES (3, 'Bea Ñúñez', 'bea@e.es', True, NULL, 'emoji 😀');
CREATE UNIQUE INDEX [ixEmail] ON [Clientes] ([Email]);

CREATE TABLE [Productos] ([Id] COUNTER CONSTRAINT [PK_Productos] PRIMARY KEY, [Codigo] TEXT(10) NOT NULL, [Nombre] TEXT(40) NOT NULL, [Precio] CURRENCY NOT NULL, [Peso] SINGLE, [Stock] SHORT, [Ratio] CURRENCY);

INSERT INTO [Productos] ([Id], [Codigo], [Nombre], [Precio], [Peso], [Stock], [Ratio]) VALUES (1, 'A12X', 'Lápiz', 1.25, 0.01, 100, 0.3333);
INSERT INTO [Productos] ([Id], [Codigo], [Nombre], [Precio], [Peso], [Stock], [Ratio]) VALUES (2, 'B7', 'Goma', 0.5, 0.02, 0, NULL);
INSERT INTO [Productos] ([Id], [Codigo], [Nombre], [Precio], [Peso], [Stock], [Ratio]) VALUES (3, 'A99', 'Cuaderno A4', 3.75, 0.25, -3, 1.5);

CREATE TABLE [Pedidos] ([Id] COUNTER CONSTRAINT [PK_Pedidos] PRIMARY KEY, [ClienteId] LONG NOT NULL, [ProductoId] LONG NOT NULL, [Total] CURRENCY NOT NULL, [Estado] TEXT(20), [Creado] DATETIME);

INSERT INTO [Pedidos] ([Id], [ClienteId], [ProductoId], [Total], [Estado], [Creado]) VALUES (1, 1, 1, 40, 'nuevo', #2026-03-01 09:00:00#);
INSERT INTO [Pedidos] ([Id], [ClienteId], [ProductoId], [Total], [Estado], [Creado]) VALUES (2, 1, 3, 120.5, 'pagado', #2026-03-15 18:45:00#);
INSERT INTO [Pedidos] ([Id], [ClienteId], [ProductoId], [Total], [Estado], [Creado]) VALUES (3, 2, 2, 75.5, NULL, NULL);
CREATE INDEX [ixEstado] ON [Pedidos] ([Estado]);

CREATE TABLE [Order Details] ([PedidoId] LONG NOT NULL, [Linea] SHORT NOT NULL, [Cantidad] LONG NOT NULL, CONSTRAINT [PK_Order Details] PRIMARY KEY ([PedidoId], [Linea]));

INSERT INTO [Order Details] ([PedidoId], [Linea], [Cantidad]) VALUES (1, 1, 2);
INSERT INTO [Order Details] ([PedidoId], [Linea], [Cantidad]) VALUES (1, 2, 1);
INSERT INTO [Order Details] ([PedidoId], [Linea], [Cantidad]) VALUES (2, 1, 5);

-- [ClientesPedidos] ON DELETE CASCADE
ALTER TABLE [Pedidos] ADD CONSTRAINT [ClientesPedidos] FOREIGN KEY ([ClienteId]) REFERENCES [Clientes] ([Id]);

-- [ProductosPedidos] ON UPDATE CASCADE
ALTER TABLE [Pedidos] ADD CONSTRAINT [ProductosPedidos] FOREIGN KEY ([ProductoId]) REFERENCES [Productos] ([Id]);

-- [PedidosOrder Details] ON DELETE CASCADE
ALTER TABLE [Order Details] ADD CONSTRAINT [PedidosOrder Details] FOREIGN KEY ([PedidoId]) REFERENCES [Pedidos] ([Id]);

CREATE VIEW [qryResumen] AS SELECT Clientes.Nombre, Count(Pedidos.Id) AS N, Sum(Pedidos.Total) AS SumaTotal FROM Clientes LEFT JOIN Pedidos ON Clientes.Id = Pedidos.ClienteId GROUP BY Clientes.Id, Clientes.Nombre;

CREATE VIEW [qryTextos] AS SELECT Clientes.Id, UCase([Nombre]) AS May, Len([Nombre]) AS Largo, Mid([Nombre],2,3) AS Trozo, Left([Nombre],2) AS Ini, [Nombre] & " <" & Nz([Email],"-") & ">" AS Contacto, IIf(IsNull([Email]),"sin email",LCase([Email])) AS Correo, InStr(1,[Nombre],"a") AS Pos FROM Clientes;

CREATE VIEW [qryFechas] AS SELECT Pedidos.Id, Format([Creado],"yyyy-mm") AS Mes, DateAdd("d",1,[Creado]) AS Manana, Year([Creado]) AS Anio, DateDiff("d",#1/1/2026#,[Creado]) AS Dias FROM Pedidos WHERE (((Pedidos.Creado) Is Not Null));

CREATE VIEW [qryFiltro] AS SELECT TOP 2 Clientes.Nombre FROM Clientes WHERE (((Clientes.Nombre) Like "a*")) OR (((Clientes.Email) Is Null)) ORDER BY Clientes.Nombre;

CREATE VIEW [qryEstados] AS SELECT DISTINCTROW Pedidos.Estado FROM Pedidos;

CREATE VIEW [qryLineas] AS SELECT Clientes.Nombre, Pedidos.Total, Productos.Nombre AS Producto, [Order Details].Cantidad FROM ((Clientes INNER JOIN Pedidos ON Clientes.Id = Pedidos.ClienteId) INNER JOIN Productos ON Pedidos.ProductoId = Productos.Id) INNER JOIN [Order Details] ON Pedidos.Id = [Order Details].PedidoId WHERE (((Pedidos.Total)>50));

CREATE VIEW [qryCodigos] AS SELECT Productos.Codigo, CCur([Precio]*1.21) AS ConIva, Int([Peso]*100) AS Gramos FROM Productos WHERE Productos.Codigo Like "A##*";

CREATE VIEW [qryActivos] AS SELECT Clientes!Nombre AS N FROM Clientes WHERE Clientes!Activo = True;

