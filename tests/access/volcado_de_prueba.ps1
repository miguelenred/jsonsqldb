# Para tests/f18_access.php: escribe con las funciones de
# jsonsqldbadmin/herramientas/access-to-jsonsqldb.ps1 el volcado de una base de
# Access descrita aquí (sin Access ni OLEDB, que solo hay en Windows), para
# comprobar que lo que escribe el script es lo que importa el panel.
# Uso: pwsh -NoProfile -File volcado_de_prueba.ps1 <fichero de salida>
param([string]$Salida)
. (Join-Path $PSScriptRoot '../../jsonsqldbadmin/herramientas/access-to-jsonsqldb.ps1')

function Col($nombre, $tipo, $tam = 0, [bool]$nulos = $true, [bool]$auto = $false, [bool]$largo = $false, $precision = 0, $escala = 0) {
    [pscustomobject]@{ Name = $nombre; ProviderType = $tipo; Size = $tam; Precision = $precision; Scale = $escala
                       AllowNull = $nulos; AutoIncrement = $auto; IsLong = $largo }
}
$d = { param($t) [datetime]::ParseExact($t, 'yyyy-MM-dd HH:mm:ss', [Globalization.CultureInfo]::InvariantCulture) }

$tablas = @(
    [pscustomobject]@{
        Name = 'Clientes'; PrimaryKey = @('Id')
        Columns = @((Col 'Id' 3 4 $false $true), (Col 'Nombre' 130 50 $false), (Col 'Email' 130 100), (Col 'Activo' 11 2 $false),
                    (Col 'Alta' 7 8), (Col 'Notas' 130 0 $true $false $true))
        Indexes = @([pscustomobject]@{ Name = 'ixEmail'; Unique = $true; Columns = @('Email') })
        Rows = { , @(1, 'Ana', 'ana@e.es', $true, (& $d '2026-01-05 10:00:00'), "Primera`r`nlínea con 'comillas' y ""dobles""")
                 , @(2, 'alberto', [DBNull]::Value, $false, (& $d '2026-02-10 08:30:00'), [DBNull]::Value)
                 , @(3, 'Bea Ñúñez', 'bea@e.es', $true, [DBNull]::Value, 'emoji 😀') }
    },
    [pscustomobject]@{
        Name = 'Productos'; PrimaryKey = @('Id')
        Columns = @((Col 'Id' 3 4 $false $true), (Col 'Codigo' 130 10 $false), (Col 'Nombre' 130 40 $false), (Col 'Precio' 6 8 $false),
                    (Col 'Peso' 4 4), (Col 'Stock' 2 2), (Col 'Ratio' 131 19 $true $false $false 18 4))
        Indexes = @()
        Rows = { , @(1, 'A12X', 'Lápiz', [decimal]1.25, [single]0.01, [int16]100, [decimal]0.3333)
                 , @(2, 'B7', 'Goma', [decimal]0.5, [single]0.02, [int16]0, [DBNull]::Value)
                 , @(3, 'A99', 'Cuaderno A4', [decimal]3.75, [single]0.25, [int16]-3, [decimal]1.5) }
    },
    [pscustomobject]@{
        Name = 'Pedidos'; PrimaryKey = @('Id')
        Columns = @((Col 'Id' 3 4 $false $true), (Col 'ClienteId' 3 4 $false), (Col 'ProductoId' 3 4 $false), (Col 'Total' 6 8 $false),
                    (Col 'Estado' 130 20), (Col 'Creado' 7 8))
        Indexes = @([pscustomobject]@{ Name = 'ixEstado'; Unique = $false; Columns = @('Estado') })
        Rows = { , @(1, 1, 1, [decimal]40, 'nuevo', (& $d '2026-03-01 09:00:00'))
                 , @(2, 1, 3, [decimal]120.5, 'pagado', (& $d '2026-03-15 18:45:00'))
                 , @(3, 2, 2, [decimal]75.5, [DBNull]::Value, [DBNull]::Value) }
    },
    [pscustomobject]@{
        Name = 'Order Details'; PrimaryKey = @('PedidoId', 'Linea')
        Columns = @((Col 'PedidoId' 3 4 $false), (Col 'Linea' 2 2 $false), (Col 'Cantidad' 3 4 $false))
        Indexes = @()
        Rows = { , @(1, 1, 2); , @(1, 2, 1); , @(2, 1, 5) }
    }
)
$relaciones = @(
    [pscustomobject]@{ Table = 'Pedidos'; Name = 'ClientesPedidos'; Columns = @('ClienteId'); Target = 'Clientes'; TargetColumns = @('Id'); OnDelete = 'CASCADE'; OnUpdate = 'NO ACTION' },
    [pscustomobject]@{ Table = 'Pedidos'; Name = 'ProductosPedidos'; Columns = @('ProductoId'); Target = 'Productos'; TargetColumns = @('Id'); OnDelete = 'NO ACTION'; OnUpdate = 'CASCADE' },
    [pscustomobject]@{ Table = 'Order Details'; Name = 'PedidosOrder Details'; Columns = @('PedidoId'); Target = 'Pedidos'; TargetColumns = @('Id'); OnDelete = 'CASCADE'; OnUpdate = 'NO ACTION' }
)
# Consultas como las guarda Access: comillas dobles para el texto, #fechas#,
# comodines * y #, Tabla!Campo, JOIN entre paréntesis, DISTINCTROW, TOP…
$vistas = @(
    [pscustomobject]@{ Name = 'qryResumen'; Definition = "SELECT Clientes.Nombre, Count(Pedidos.Id) AS N, Sum(Pedidos.Total) AS SumaTotal`r`nFROM Clientes LEFT JOIN Pedidos ON Clientes.Id = Pedidos.ClienteId`r`nGROUP BY Clientes.Id, Clientes.Nombre;" },
    [pscustomobject]@{ Name = 'qryTextos'; Definition = 'SELECT Clientes.Id, UCase([Nombre]) AS May, Len([Nombre]) AS Largo, Mid([Nombre],2,3) AS Trozo, Left([Nombre],2) AS Ini, [Nombre] & " <" & Nz([Email],"-") & ">" AS Contacto, IIf(IsNull([Email]),"sin email",LCase([Email])) AS Correo, InStr(1,[Nombre],"a") AS Pos FROM Clientes;' },
    [pscustomobject]@{ Name = 'qryFechas'; Definition = 'SELECT Pedidos.Id, Format([Creado],"yyyy-mm") AS Mes, DateAdd("d",1,[Creado]) AS Manana, Year([Creado]) AS Anio, DateDiff("d",#1/1/2026#,[Creado]) AS Dias FROM Pedidos WHERE (((Pedidos.Creado) Is Not Null));' },
    [pscustomobject]@{ Name = 'qryFiltro'; Definition = 'SELECT TOP 2 Clientes.Nombre FROM Clientes WHERE (((Clientes.Nombre) Like "a*")) OR (((Clientes.Email) Is Null)) ORDER BY Clientes.Nombre;' },
    [pscustomobject]@{ Name = 'qryEstados'; Definition = 'SELECT DISTINCTROW Pedidos.Estado FROM Pedidos;' },
    [pscustomobject]@{ Name = 'qryLineas'; Definition = 'SELECT Clientes.Nombre, Pedidos.Total, Productos.Nombre AS Producto, [Order Details].Cantidad FROM ((Clientes INNER JOIN Pedidos ON Clientes.Id = Pedidos.ClienteId) INNER JOIN Productos ON Pedidos.ProductoId = Productos.Id) INNER JOIN [Order Details] ON Pedidos.Id = [Order Details].PedidoId WHERE (((Pedidos.Total)>50));' },
    [pscustomobject]@{ Name = 'qryCodigos'; Definition = 'SELECT Productos.Codigo, CCur([Precio]*1.21) AS ConIva, Int([Peso]*100) AS Gramos FROM Productos WHERE Productos.Codigo Like "A##*";' },
    [pscustomobject]@{ Name = 'qryActivos'; Definition = 'SELECT Clientes!Nombre AS N FROM Clientes WHERE Clientes!Activo = True;' }
)
$escritor = New-Object System.IO.StreamWriter($Salida, $false, (New-Object System.Text.UTF8Encoding($false)))
try {
    Write-AccessDump $escritor 'prueba.accdb' $tablas $relaciones $vistas (New-Summary)
} finally {
    $escritor.Close()
}
