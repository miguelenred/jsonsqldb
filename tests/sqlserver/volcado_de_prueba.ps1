# Para tests/f22_sqlserver.php: escribe con las funciones de
# jsonsqldbadmin/herramientas/sqlserver-to-jsonsqldb.ps1 el volcado de una base
# de SQL Server descrita aquí (sin SQL Server ni OLE DB, que solo hay en
# Windows), para comprobar que lo que escribe el script es lo que importa el panel.
# Uso: pwsh -NoProfile -File volcado_de_prueba.ps1 <fichero de salida>
param([string]$Salida)
. (Join-Path $PSScriptRoot '../../jsonsqldbadmin/herramientas/sqlserver-to-jsonsqldb.ps1')

function Col($nombre, $tipo, $largo = 0, [bool]$nulos = $true, [bool]$identidad = $false, $precision = 0, $escala = 0, $defecto = $null) {
    [pscustomobject]@{ Name = $nombre; Type = $tipo; MaxLength = $largo; Precision = $precision; Scale = $escala; Nullable = $nulos
                       Identity = $identidad; Seed = 1; Increment = 1; Default = $defecto }
}
$d = { param($t) [datetime]::ParseExact($t, 'yyyy-MM-dd HH:mm:ss', [Globalization.CultureInfo]::InvariantCulture) }

# El esquema ventas: sus tablas se llaman ventas_<tabla>, también en la vista y el trigger
$nombres = @{ 'dbo.clientes' = 'clientes'; 'ventas.pedidos' = 'ventas_pedidos'; 'ventas.registro' = 'ventas_registro' }
$tablas = @(
    [pscustomobject]@{
        Name = 'clientes'; PrimaryKeyName = 'PK_clientes'; PrimaryKey = @('id')
        Columns = @((Col 'id' 'int' 4 $false $true), (Col 'nombre' 'nvarchar' 100 $false), (Col 'email' 'varchar' 120),
                    (Col 'activo' 'bit' 1 $false $false 0 0 '((1))'), (Col 'alta' 'datetime' 8), (Col 'nacido' 'date' 3),
                    (Col 'notas' 'nvarchar' -1), (Col 'saldo' 'decimal' 9 $false $false 10 2 '((0))'), (Col 'foto' 'varbinary' -1))
        Uniques = @([pscustomobject]@{ Name = 'UQ_clientes_email'; Columns = @('email') })
        Indexes = @([pscustomobject]@{ Name = 'IX_clientes_nombre'; Unique = $false; Columns = @('nombre') })
        Rows = { , @(1, 'Ana', 'ana@e.es', $true, (& $d '2026-01-05 10:00:00'), '1990-03-01', "Primera`r`nlínea con 'comillas'", [decimal]10.5, [byte[]](0xCA, 0xFE))
                 , @(2, 'Bea Ñúñez 😀', [DBNull]::Value, $false, [DBNull]::Value, [DBNull]::Value, [DBNull]::Value, [decimal]0, [DBNull]::Value) }
    },
    [pscustomobject]@{
        Name = 'ventas_pedidos'; PrimaryKeyName = 'PK_pedidos'; PrimaryKey = @('id')
        Columns = @((Col 'id' 'int' 4 $false $true), (Col 'cliente' 'int' 4 $false), (Col 'total' 'money' 8 $false),
                    (Col 'creado' 'datetime2' 8 $true $false 27 3), (Col 'ratio' 'float' 8))
        Uniques = @(); Indexes = @()
        Rows = { , @(1, 1, [decimal]40, '2026-03-01 09:00:00.123', [double]0.25)
                 , @(2, 1, [decimal]120.5, '2026-03-15 18:45:00.000', [DBNull]::Value)
                 , @(3, 2, [decimal]7, [DBNull]::Value, [double]1.5) }
    },
    [pscustomobject]@{
        Name = 'ventas_registro'; PrimaryKeyName = ''; PrimaryKey = @()
        Columns = @((Col 'pedido' 'int' 4), (Col 'cuando' 'datetime' 8 $true $false 0 0 '(getdate())'))
        Uniques = @(); Indexes = @(); Rows = { }
    }
)
$fks = @([pscustomobject]@{ Name = 'FK_pedidos_clientes'; Table = 'ventas_pedidos'; Target = 'clientes'; Columns = @('cliente')
                            TargetColumns = @('id'); OnDelete = 'CASCADE'; OnUpdate = 'NO_ACTION' })
$vistas = @([pscustomobject]@{ Definition = Rename-CreatedObject (Rename-SchemaReferences (@"
CREATE VIEW [dbo].[v_totales] AS
SELECT c.nombre, SUM(p.total) AS total, COUNT(*) AS pedidos
FROM dbo.clientes c JOIN [ventas].[pedidos] p ON p.cliente = c.id
GROUP BY c.nombre
"@) $nombres) 'v_totales' })
$triggers = @([pscustomobject]@{ Definition = Rename-CreatedObject (Rename-SchemaReferences (@"
CREATE TRIGGER [ventas].[trg_pedidos] ON ventas.pedidos AFTER INSERT AS
BEGIN
    SET NOCOUNT ON;
    INSERT INTO ventas.registro (pedido) SELECT id FROM inserted;
END
"@) $nombres) 'ventas_trg_pedidos' })

$resumen = New-Summary
$w = New-Object System.IO.StreamWriter($Salida, $false, (New-Object System.Text.UTF8Encoding($false)))
try { Write-SqlServerDump $w 'tienda' $tablas $fks $vistas $triggers $resumen } finally { $w.Close() }
# Y lo que no es SQL: los lotes de un script y las tablas que crea
$lotes = Split-SqlBatches ([IO.File]::ReadAllText($Salida))
"lotes=$($lotes.Count) tablas=$((Get-CreatedTables ([IO.File]::ReadAllText($Salida))) -join ',') filas=$($resumen.Rows)"
