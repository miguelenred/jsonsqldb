-- Script con la forma de «Generar scripts» de SQL Server Management Studio
-- (esquema y datos), escrito a mano para tests/f14_volcados.php: corchetes,
-- [dbo]., GO, N'…', CAST(N'…' AS DateTime), IDENTITY_INSERT, restricciones
-- con WITH (…) ON [PRIMARY], valores por defecto y claves foráneas añadidos
-- con ALTER TABLE después de crear las tablas, e índices NONCLUSTERED.
USE [Tienda]
GO
/****** Object:  Table [dbo].[Clientes]    Script Date: 30/09/2026 10:00:00 ******/
SET ANSI_NULLS ON
GO
SET QUOTED_IDENTIFIER ON
GO
CREATE TABLE [dbo].[Clientes](
	[Id] [int] IDENTITY(1,1) NOT NULL,
	[Email] [nvarchar](120) NOT NULL,
	[Nombre] [nvarchar](max) NULL,
	[Saldo] [decimal](18, 2) NOT NULL,
	[Activo] [bit] NOT NULL,
	[Alta] [datetime2](7) NULL,
	[Guid] [uniqueidentifier] NULL,
 CONSTRAINT [PK_Clientes] PRIMARY KEY CLUSTERED 
(
	[Id] ASC
)WITH (PAD_INDEX = OFF, STATISTICS_NORECOMPUTE = OFF, IGNORE_DUP_KEY = OFF, ALLOW_ROW_LOCKS = ON, ALLOW_PAGE_LOCKS = ON, OPTIMIZE_FOR_SEQUENTIAL_KEY = OFF) ON [PRIMARY],
 CONSTRAINT [UQ_Clientes_Email] UNIQUE NONCLUSTERED 
(
	[Email] ASC
)WITH (PAD_INDEX = OFF, STATISTICS_NORECOMPUTE = OFF, IGNORE_DUP_KEY = OFF, ALLOW_ROW_LOCKS = ON, ALLOW_PAGE_LOCKS = ON) ON [PRIMARY]
) ON [PRIMARY] TEXTIMAGE_ON [PRIMARY]
GO
/****** Object:  Table [dbo].[Pedidos]    Script Date: 30/09/2026 10:00:00 ******/
SET ANSI_NULLS ON
GO
SET QUOTED_IDENTIFIER ON
GO
CREATE TABLE [dbo].[Pedidos](
	[Id] [bigint] IDENTITY(1,1) NOT NULL,
	[ClienteId] [int] NOT NULL,
	[Total] [money] NULL,
	[Fecha] [datetime] NOT NULL,
	[Notas] [nvarchar](200) NULL,
 CONSTRAINT [PK_Pedidos] PRIMARY KEY CLUSTERED 
(
	[Id] ASC
)WITH (PAD_INDEX = OFF, STATISTICS_NORECOMPUTE = OFF, IGNORE_DUP_KEY = OFF, ALLOW_ROW_LOCKS = ON, ALLOW_PAGE_LOCKS = ON) ON [PRIMARY]
) ON [PRIMARY]
GO
SET IDENTITY_INSERT [dbo].[Clientes] ON 
GO
INSERT [dbo].[Clientes] ([Id], [Email], [Nombre], [Saldo], [Activo], [Alta], [Guid]) VALUES (1, N'ana@e.es', N'Ana O''Brien', CAST(10.50 AS Decimal(18, 2)), 1, CAST(N'2026-01-02T03:04:05.1230000' AS DateTime2), N'6f9619ff-8b86-d011-b42d-00c04fc964ff')
GO
INSERT [dbo].[Clientes] ([Id], [Email], [Nombre], [Saldo], [Activo], [Alta], [Guid]) VALUES (2, N'bea@e.es', N'Bea [corchetes]; y punto y coma', CAST(-3.00 AS Decimal(18, 2)), 0, NULL, NULL)
GO
INSERT [dbo].[Clientes] ([Id], [Email], [Nombre], [Saldo], [Activo], [Alta], [Guid]) VALUES (3, N'cris@e.es', N'Cris 😀 ñandú', CAST(0.00 AS Decimal(18, 2)), 1, CAST(N'2026-12-31T00:00:00.0000000' AS DateTime2), NULL)
GO
SET IDENTITY_INSERT [dbo].[Clientes] OFF
GO
SET IDENTITY_INSERT [dbo].[Pedidos] ON 
GO
INSERT [dbo].[Pedidos] ([Id], [ClienteId], [Total], [Fecha], [Notas]) VALUES (1, 1, 120.5000, CAST(N'2026-05-01T10:00:00.000' AS DateTime), N'primero')
GO
INSERT [dbo].[Pedidos] ([Id], [ClienteId], [Total], [Fecha], [Notas]) VALUES (2, 2, NULL, CAST(N'2026-05-02T11:00:00.000' AS DateTime), NULL)
GO
INSERT [dbo].[Pedidos] ([Id], [ClienteId], [Total], [Fecha], [Notas]) VALUES (3, 1, 0.1000, CAST(N'2026-05-03T12:00:00.000' AS DateTime), N'con
salto de línea')
GO
SET IDENTITY_INSERT [dbo].[Pedidos] OFF
GO
SET ANSI_PADDING ON
GO
/****** Object:  Index [IX_Pedidos_Fecha]    Script Date: 30/09/2026 10:00:00 ******/
CREATE NONCLUSTERED INDEX [IX_Pedidos_Fecha] ON [dbo].[Pedidos]
(
	[Fecha] ASC
)WITH (PAD_INDEX = OFF, STATISTICS_NORECOMPUTE = OFF, SORT_IN_TEMPDB = OFF, DROP_EXISTING = OFF, ONLINE = OFF, ALLOW_ROW_LOCKS = ON, ALLOW_PAGE_LOCKS = ON) ON [PRIMARY]
GO
ALTER TABLE [dbo].[Clientes] ADD  CONSTRAINT [DF_Clientes_Saldo]  DEFAULT ((0)) FOR [Saldo]
GO
ALTER TABLE [dbo].[Clientes] ADD  CONSTRAINT [DF_Clientes_Activo]  DEFAULT ((1)) FOR [Activo]
GO
ALTER TABLE [dbo].[Pedidos] ADD  CONSTRAINT [DF_Pedidos_Fecha]  DEFAULT (getdate()) FOR [Fecha]
GO
ALTER TABLE [dbo].[Pedidos]  WITH CHECK ADD  CONSTRAINT [FK_Pedidos_Clientes] FOREIGN KEY([ClienteId])
REFERENCES [dbo].[Clientes] ([Id])
ON DELETE CASCADE
GO
ALTER TABLE [dbo].[Pedidos] CHECK CONSTRAINT [FK_Pedidos_Clientes]
GO
ALTER TABLE [dbo].[Clientes]  WITH CHECK ADD  CONSTRAINT [CK_Clientes_Saldo] CHECK  (([Saldo]>(-100000)))
GO
ALTER TABLE [dbo].[Clientes] CHECK CONSTRAINT [CK_Clientes_Saldo]
GO
/****** Object:  View [dbo].[Grandes]    Script Date: 30/09/2026 10:00:00 ******/
CREATE VIEW [dbo].[Grandes] AS
SELECT Id, Total FROM dbo.Pedidos WHERE Total > 100
GO
