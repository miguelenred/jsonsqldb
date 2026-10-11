<#
.SYNOPSIS
    Dumps a SQL Server database to an SQL file that jsonSQLDBadmin can import,
    and loads such a file into a SQL Server database.

.DESCRIPTION
    Windows only, with nothing to install: it connects through the "SQLOLEDB"
    OLE DB provider that comes with Windows, which works with any SQL Server
    version. The easiest way to run it: right-click this file and choose
    "Run with PowerShell". It asks for the server (and a SQL login, or
    Windows authentication) and what to do:

    Dump a database to SQL: it lists the databases of the server to choose one
    by number, asks for the folder where the dump is saved, and writes
    <database>.sqlserver.sql there: tables, primary and unique keys, indexes,
    foreign keys, data, views and triggers, in SQL Server's own syntax (as
    Management Studio's "Generate Scripts" writes it). Tables of every schema
    go: those of dbo with their name, the others as schema_table, also in the
    views and triggers that use them. Then, in jsonSQLDBadmin, open the
    database and use "Import an SQL dump" with that file (format: Detect, or
    SQL Server). Stored procedures and functions have no equivalent in
    jsonSQLDB: the summary at the end lists them as not included.

    Load an SQL file into SQL Server: it asks for an .sqlserver.sql file (one
    made by jsonSQLDBadmin's "SQL: SQL Server" export, or by this script) and
    lists the databases of the server, with one more option to create a new
    one. If any table of the file already exists there, it stops before
    changing anything and says which. Everything runs in one transaction: if a
    statement fails, nothing stays.

    The database is read with plain SELECT statements: nothing in it changes.
    The newer date types (date, datetime2, time, datetimeoffset) are read as
    text in ISO format, which is how SQLOLEDB can pass them.

.PARAMETER Server
    The server, as in Management Studio: localhost, .\SQLEXPRESS, host,1433...
    Without it, it is asked for.

.PARAMETER Database
    The database to dump (or to load into, with -Load). Without it, a numbered
    list is shown.

.PARAMETER User
    A SQL Server login. Without it, Windows authentication is used.

.PARAMETER OutputFolder
    The folder for the dump. Without it, a dialog asks for it.

.PARAMETER Load
    An .sqlserver.sql file to load, instead of dumping.

.NOTES
    Part of jsonSQLDB: https://github.com/miguelenred/jsonsqldb
    Apache License 2.0.
#>
[CmdletBinding()]
param(
    [string]$Server,
    [string]$Database,
    [string]$User,
    [string]$OutputFolder,
    [string]$Load
)

Set-StrictMode -Version 2.0
$ErrorActionPreference = 'Stop'

$Invariant = [System.Globalization.CultureInfo]::InvariantCulture

# ---------------------------------------------------------------------------
# SQL text: these functions do not touch OLE DB, so they can be tested anywhere
# ---------------------------------------------------------------------------

function ConvertTo-SqlName([string]$Name) {
    '[' + $Name.Replace(']', ']]') + ']'
}

function ConvertTo-SqlValue($Value) {
    if ($null -eq $Value -or $Value -is [System.DBNull]) { return 'NULL' }
    if ($Value -is [bool]) { if ($Value) { return '1' } else { return '0' } }
    if ($Value -is [datetime]) {
        # The milliseconds only when there are some, as jsonSQLDB writes its dates
        $format = if ($Value.Millisecond -eq 0) { 'yyyy-MM-dd HH:mm:ss' } else { 'yyyy-MM-dd HH:mm:ss.fff' }
        return "'" + $Value.ToString($format, $Invariant) + "'"
    }
    if ($Value -is [double] -or $Value -is [single]) {
        if ([double]::IsNaN($Value) -or [double]::IsInfinity($Value)) { return 'NULL' }
        return $Value.ToString('R', $Invariant)
    }
    if ($Value -is [decimal] -or $Value -is [int] -or $Value -is [long] -or $Value -is [int16] -or $Value -is [byte]) {
        return $Value.ToString($Invariant)
    }
    if ($Value -is [byte[]]) { return '0x' + (($Value | ForEach-Object { $_.ToString('X2') }) -join '') }
    "N'" + ([string]$Value).Replace("'", "''") + "'"
}

# One column, from sys.columns: Name, Type (its sys.types name), MaxLength
# (bytes, -1 for max), Precision, Scale, Nullable, Identity, Seed, Increment,
# Default (as SQL Server keeps it, e.g. ((0)) or (getdate()))
function ConvertTo-ColumnSql($Column) {
    $type = [string]$Column.Type
    $size = [int]$Column.MaxLength
    switch ($type) {
        { $_ -in 'varchar', 'char', 'varbinary', 'binary' } { $type += '(' + $(if ($size -lt 0) { 'max' } else { $size }) + ')' }
        { $_ -in 'nvarchar', 'nchar' } { $type += '(' + $(if ($size -lt 0) { 'max' } else { $size / 2 }) + ')' }
        { $_ -in 'decimal', 'numeric' } { $type += '(' + $Column.Precision + ', ' + $Column.Scale + ')' }
        { $_ -in 'datetime2', 'time', 'datetimeoffset' } { $type += '(' + $Column.Scale + ')' }
    }
    $sql = (ConvertTo-SqlName $Column.Name) + ' ' + $type
    if ($Column.Identity) { $sql += ' IDENTITY(' + $Column.Seed + ', ' + $Column.Increment + ')' }
    $sql += $(if ($Column.Nullable) { ' NULL' } else { ' NOT NULL' })
    if ($Column.Default) { $sql += ' DEFAULT ' + $Column.Default }
    $sql
}

# A table: its columns, primary key (name and columns) and unique keys
# (each with Name and Columns)
function ConvertTo-CreateTable([string]$Table, $Columns, [string]$PrimaryKeyName, [string[]]$PrimaryKey, $Uniques) {
    $PrimaryKey = @($PrimaryKey | Where-Object { $_ })
    $parts = @($Columns | ForEach-Object { '    ' + (ConvertTo-ColumnSql $_) })
    if ($PrimaryKey.Count -gt 0) {
        $parts += '    CONSTRAINT ' + (ConvertTo-SqlName $PrimaryKeyName) + ' PRIMARY KEY (' + (($PrimaryKey | ForEach-Object { ConvertTo-SqlName $_ }) -join ', ') + ')'
    }
    foreach ($u in @($Uniques)) {
        $parts += '    CONSTRAINT ' + (ConvertTo-SqlName $u.Name) + ' UNIQUE (' + (($u.Columns | ForEach-Object { ConvertTo-SqlName $_ }) -join ', ') + ')'
    }
    'CREATE TABLE ' + (ConvertTo-SqlName $Table) + " (`r`n" + ($parts -join ",`r`n") + "`r`n);"
}

function ConvertTo-Insert([string]$Table, [string[]]$ColumnNames, [object[]]$Values) {
    'INSERT INTO ' + (ConvertTo-SqlName $Table) + ' (' + (($ColumnNames | ForEach-Object { ConvertTo-SqlName $_ }) -join ', ') +
        ') VALUES (' + (($Values | ForEach-Object { ConvertTo-SqlValue $_ }) -join ', ') + ');'
}

function ConvertTo-Index([string]$Table, [string]$Name, [bool]$Unique, [string[]]$Columns) {
    'CREATE ' + $(if ($Unique) { 'UNIQUE ' } else { '' }) + 'INDEX ' + (ConvertTo-SqlName $Name) + ' ON ' +
        (ConvertTo-SqlName $Table) + ' (' + (($Columns | ForEach-Object { ConvertTo-SqlName $_ }) -join ', ') + ');'
}

# OnDelete and OnUpdate as sys.foreign_keys says them: NO_ACTION, CASCADE, SET_NULL, SET_DEFAULT
function ConvertTo-ForeignKey([string]$Table, [string]$Name, [string[]]$Columns, [string]$Target, [string[]]$TargetColumns,
                              [string]$OnDelete, [string]$OnUpdate) {
    $sql = 'ALTER TABLE ' + (ConvertTo-SqlName $Table) + ' ADD CONSTRAINT ' + (ConvertTo-SqlName $Name) +
        ' FOREIGN KEY (' + (($Columns | ForEach-Object { ConvertTo-SqlName $_ }) -join ', ') + ') REFERENCES ' +
        (ConvertTo-SqlName $Target) + ' (' + (($TargetColumns | ForEach-Object { ConvertTo-SqlName $_ }) -join ', ') + ')'
    if ($OnDelete -and $OnDelete -ne 'NO_ACTION') { $sql += ' ON DELETE ' + $OnDelete.Replace('_', ' ') }
    if ($OnUpdate -and $OnUpdate -ne 'NO_ACTION') { $sql += ' ON UPDATE ' + $OnUpdate.Replace('_', ' ') }
    $sql + ';'
}

# The name a table takes here: its own in dbo, schema_table in any other schema
function Get-TableName([string]$Schema, [string]$Table) {
    if ($Schema -eq 'dbo') { $Table } else { $Schema + '_' + $Table }
}

# The SQL of a view or a trigger with schema.table written as the new names
# ($Names: "schema.table" in lower case => new name)
function Rename-SchemaReferences([string]$Sql, [hashtable]$Names) {
    foreach ($key in $Names.Keys) {
        $schema, $table = $key -split '\.', 2
        $s = [regex]::Escape($schema)
        $t = [regex]::Escape($table)
        $pattern = '(?i)(?:\[' + $s + '\]|(?<![\w\]])' + $s + ')\s*\.\s*(?:\[' + $t + '\]|' + $t + '(?![\w]))'
        $Sql = [regex]::Replace($Sql, $pattern, [System.Text.RegularExpressions.MatchEvaluator] { param($m) ConvertTo-SqlName $Names[$key] })
    }
    $Sql
}

# The CREATE [OR ALTER] VIEW or TRIGGER of $Sql with the name it takes here,
# without its schema
function Rename-CreatedObject([string]$Sql, [string]$NewName) {
    [regex]::Replace($Sql, '(?i)(CREATE\s+(?:OR\s+ALTER\s+)?(?:VIEW|TRIGGER)\s+)(?:(?:\[[^\]]+\]|\w+)\s*\.\s*)?(?:\[[^\]]+\]|\w+)',
        { param($m) $m.Groups[1].Value + (ConvertTo-SqlName $NewName) }, 1)
}

# The batches of a script: GO on a line of its own separates them
function Split-SqlBatches([string]$Text) {
    @([regex]::Split($Text, '(?im)^[ \t]*GO[ \t]*(?:\d+[ \t]*)?\r?$') | ForEach-Object { $_.Trim() } | Where-Object { $_ -ne '' })
}

# The tables a script creates: [name], "name" or name, with or without schema
function Get-CreatedTables([string]$Text) {
    $found = [regex]::Matches($Text, '(?im)^\s*CREATE\s+TABLE\s+(?:(?:\[[^\]]+\]|"[^"]+"|\w+)\s*\.\s*)?(\[[^\]]+\]|"[^"]+"|\w+)')
    @($found | ForEach-Object { $_.Groups[1].Value.Trim('[', ']', '"') } | Select-Object -Unique)
}

function New-Summary {
    [pscustomobject]@{ Tables = 0; Rows = 0; Indexes = 0; ForeignKeys = 0; Views = 0; Triggers = 0; Skipped = New-Object System.Collections.ArrayList }
}

# The whole dump. Each table has Name, Columns, PrimaryKeyName, PrimaryKey,
# Uniques, Indexes (Name, Unique, Columns) and Rows: a script block that gives
# its rows, each an array of values in the order of Columns
function Write-SqlServerDump($Writer, [string]$Source, $Tables, $ForeignKeys, $Views, $Triggers, $Summary) {
    $Writer.NewLine = "`r`n"
    $Writer.WriteLine('-- jsonSQLDB: SQL Server database ' + $Source + ', dumped with sqlserver-to-jsonsqldb.ps1')
    $Writer.WriteLine('-- Import it in jsonSQLDBadmin: open the database, "Import an SQL dump" (format: Detect, or SQL Server)')
    $Writer.WriteLine('SET NOCOUNT ON;')
    $Writer.WriteLine('GO')
    foreach ($t in $Tables) {
        $Writer.WriteLine('')
        $Writer.WriteLine((ConvertTo-CreateTable $t.Name $t.Columns $t.PrimaryKeyName $t.PrimaryKey $t.Uniques))
        $Writer.WriteLine('GO')
        # A rowversion (timestamp) column is filled by SQL Server: it cannot be inserted
        $names = @($t.Columns | Where-Object { $_.Type -ne 'timestamp' } | ForEach-Object { $_.Name })
        $identity = @($t.Columns | Where-Object { $_.Identity }).Count -gt 0
        $rows = 0
        foreach ($row in (& $t.Rows)) {
            if ($rows -eq 0 -and $identity) { $Writer.WriteLine('SET IDENTITY_INSERT ' + (ConvertTo-SqlName $t.Name) + ' ON;') }
            $Writer.WriteLine((ConvertTo-Insert $t.Name $names $row))
            $rows++
        }
        if ($rows -gt 0 -and $identity) { $Writer.WriteLine('SET IDENTITY_INSERT ' + (ConvertTo-SqlName $t.Name) + ' OFF;') }
        foreach ($i in @($t.Indexes)) {
            $Writer.WriteLine((ConvertTo-Index $t.Name $i.Name $i.Unique $i.Columns))
        }
        if ($rows -gt 0 -or @($t.Indexes).Count -gt 0) { $Writer.WriteLine('GO') }
        $Summary.Rows += $rows
        $Summary.Indexes += @($t.Indexes).Count
        $Summary.Tables++
    }
    foreach ($k in @($ForeignKeys)) {
        if ($Summary.ForeignKeys -eq 0) { $Writer.WriteLine('') }
        $Writer.WriteLine((ConvertTo-ForeignKey $k.Table $k.Name $k.Columns $k.Target $k.TargetColumns $k.OnDelete $k.OnUpdate))
        $Summary.ForeignKeys++
    }
    if (@($ForeignKeys).Count -gt 0) { $Writer.WriteLine('GO') }
    # Views and triggers as SQL Server keeps them, each in a batch of its own
    foreach ($v in @($Views)) {
        $Writer.WriteLine('')
        $Writer.WriteLine($v.Definition.Trim())
        $Writer.WriteLine('GO')
        $Summary.Views++
    }
    foreach ($tr in @($Triggers)) {
        $Writer.WriteLine('')
        $Writer.WriteLine($tr.Definition.Trim())
        $Writer.WriteLine('GO')
        $Summary.Triggers++
    }
}

# ---------------------------------------------------------------------------
# SQL Server, through OLE DB
# ---------------------------------------------------------------------------

function New-SqlConnection([string]$Server, [string]$Database, [pscredential]$Credential) {
    $text = 'Provider=SQLOLEDB;Data Source=' + $Server + ';Initial Catalog=' + $Database + ';'
    if ($Credential) {
        $text += 'User ID=' + $Credential.UserName + ';Password=' + $Credential.GetNetworkCredential().Password + ';'
    } else {
        $text += 'Integrated Security=SSPI;'
    }
    $c = New-Object System.Data.OleDb.OleDbConnection $text
    $c.Open()
    $c
}

function Invoke-Query($Connection, [string]$Sql, $Transaction = $null) {
    $command = $Connection.CreateCommand()
    $command.CommandText = $Sql
    $command.CommandTimeout = 0
    if ($Transaction) { $command.Transaction = $Transaction }
    $table = New-Object System.Data.DataTable
    (New-Object System.Data.OleDb.OleDbDataAdapter $command).Fill($table) | Out-Null
    , $table
}

function Get-Databases($Connection) {
    @((Invoke-Query $Connection 'SELECT name FROM sys.databases WHERE database_id > 4 AND state = 0 ORDER BY name').Rows | ForEach-Object { [string]$_.name })
}

# What to select of each column: the newer date types as ISO text (SQLOLEDB
# cannot pass them as dates), and the CLR and XML types as text
function Get-ColumnSelect($Column) {
    $n = ConvertTo-SqlName $Column.Name
    switch ([string]$Column.Type) {
        { $_ -in 'date' }                                { return "CONVERT(varchar(10), $n, 23)" }
        { $_ -in 'datetime2', 'datetimeoffset', 'time' } { return "REPLACE(CONVERT(varchar(40), $n, 126), 'T', ' ')" }
        { $_ -in 'xml', 'sql_variant' }                  { return "CAST($n AS nvarchar(max))" }
        { $_ -in 'geography', 'geometry', 'hierarchyid' } { return "$n.ToString()" }
    }
    $n
}

function Read-SqlServerCatalog($Connection, $Summary) {
    $tables = @(Invoke-Query $Connection @'
SELECT t.object_id, s.name AS esquema, t.name FROM sys.tables t JOIN sys.schemas s ON s.schema_id = t.schema_id
WHERE t.is_ms_shipped = 0 ORDER BY s.name, t.name
'@).Rows
    $names = @{}
    foreach ($t in $tables) { $names[([string]$t.esquema + '.' + [string]$t.name).ToLowerInvariant()] = Get-TableName $t.esquema $t.name }

    $columns = (Invoke-Query $Connection @'
SELECT c.object_id, c.column_id, c.name, CASE WHEN ty.is_user_defined = 1 AND ty.is_assembly_type = 0 THEN TYPE_NAME(ty.system_type_id) ELSE ty.name END AS tipo,
       c.max_length, c.precision, c.scale, c.is_nullable, c.is_identity,
       c.is_computed, CAST(ic.seed_value AS bigint) AS seed, CAST(ic.increment_value AS bigint) AS increment, dc.definition AS defecto
FROM sys.columns c JOIN sys.types ty ON ty.user_type_id = c.user_type_id
LEFT JOIN sys.identity_columns ic ON ic.object_id = c.object_id AND ic.column_id = c.column_id
LEFT JOIN sys.default_constraints dc ON dc.object_id = c.default_object_id
JOIN sys.tables t ON t.object_id = c.object_id WHERE t.is_ms_shipped = 0
ORDER BY c.object_id, c.column_id
'@).Rows
    $keys = (Invoke-Query $Connection @'
SELECT i.object_id, i.name, i.is_primary_key, i.is_unique_constraint, i.is_unique, i.has_filter, c.name AS columna
FROM sys.indexes i JOIN sys.index_columns ic ON ic.object_id = i.object_id AND ic.index_id = i.index_id AND ic.is_included_column = 0
JOIN sys.columns c ON c.object_id = ic.object_id AND c.column_id = ic.column_id
JOIN sys.tables t ON t.object_id = i.object_id WHERE t.is_ms_shipped = 0 AND i.type IN (1, 2)
ORDER BY i.object_id, i.index_id, ic.key_ordinal
'@).Rows

    $result = foreach ($t in $tables) {
        $id = $t.object_id
        $name = $names[([string]$t.esquema + '.' + [string]$t.name).ToLowerInvariant()]
        $cols = @($columns | Where-Object { $_.object_id -eq $id } | ForEach-Object {
            if ($_.is_computed) { [void]$Summary.Skipped.Add("$name.$($_.name): computed column, dumped with its values as a plain column") }
            if ([string]$_.tipo -eq 'timestamp') { [void]$Summary.Skipped.Add("$name.$($_.name): rowversion column, dumped without its values (SQL Server fills it)") }
            [pscustomobject]@{ Name = [string]$_.name; Type = [string]$_.tipo; MaxLength = [int]$_.max_length; Precision = [int]$_.precision
                               Scale = [int]$_.scale; Nullable = [bool]$_.is_nullable; Identity = [bool]$_.is_identity
                               Seed = $_.seed; Increment = $_.increment
                               Default = $(if ($_.defecto -is [System.DBNull] -or $_.is_computed) { $null } else { [string]$_.defecto }) }
        })
        $own = @($keys | Where-Object { $_.object_id -eq $id })
        $pk = @($own | Where-Object { $_.is_primary_key })
        $groups = @($own | Where-Object { -not $_.is_primary_key } | Group-Object name)
        $uniques = @(); $indexes = @()
        foreach ($g in $groups) {
            $first = $g.Group[0]
            $entry = [pscustomobject]@{ Name = [string]$g.Name; Unique = [bool]$first.is_unique; Columns = @($g.Group | ForEach-Object { [string]$_.columna }) }
            if ($first.has_filter) { [void]$Summary.Skipped.Add("$name.$($g.Name): filtered index, dumped without its WHERE") }
            if ($first.is_unique_constraint) { $uniques += $entry } else { $indexes += $entry }
        }
        $select = 'SELECT ' + (($cols | Where-Object { $_.Type -ne 'timestamp' } | ForEach-Object { Get-ColumnSelect $_ }) -join ', ') + ' FROM ' + (ConvertTo-SqlName $t.esquema) + '.' + (ConvertTo-SqlName $t.name)
        [pscustomobject]@{
            Name = $name; Columns = $cols; Uniques = $uniques; Indexes = $indexes
            PrimaryKeyName = $(if ($pk.Count -gt 0) { [string]$pk[0].name } else { '' })
            PrimaryKey = @($pk | ForEach-Object { [string]$_.columna })
            # The rows, read one by one when the dump gets to them
            Rows = { $command = $Connection.CreateCommand(); $command.CommandText = $select; $command.CommandTimeout = 0
                     $reader = $command.ExecuteReader()
                     try {
                         while ($reader.Read()) {
                             $values = New-Object object[] $reader.FieldCount
                             [void]$reader.GetValues($values)
                             , $values
                         }
                     } finally { $reader.Close() } }.GetNewClosure()
        }
    }

    $fkRows = (Invoke-Query $Connection @'
SELECT fk.object_id, fk.name, sp.name AS esquema, tp.name AS tabla, sr.name AS esquema_destino, tr.name AS destino,
       cp.name AS columna, cr.name AS columna_destino, fk.delete_referential_action_desc AS borrar, fk.update_referential_action_desc AS cambiar
FROM sys.foreign_keys fk
JOIN sys.foreign_key_columns fc ON fc.constraint_object_id = fk.object_id
JOIN sys.tables tp ON tp.object_id = fk.parent_object_id JOIN sys.schemas sp ON sp.schema_id = tp.schema_id
JOIN sys.tables tr ON tr.object_id = fk.referenced_object_id JOIN sys.schemas sr ON sr.schema_id = tr.schema_id
JOIN sys.columns cp ON cp.object_id = fc.parent_object_id AND cp.column_id = fc.parent_column_id
JOIN sys.columns cr ON cr.object_id = fc.referenced_object_id AND cr.column_id = fc.referenced_column_id
ORDER BY fk.object_id, fc.constraint_column_id
'@).Rows
    $foreignKeys = foreach ($g in @($fkRows | Group-Object object_id)) {
        $f = $g.Group[0]
        [pscustomobject]@{ Name = [string]$f.name; Table = Get-TableName $f.esquema $f.tabla; Target = Get-TableName $f.esquema_destino $f.destino
                           Columns = @($g.Group | ForEach-Object { [string]$_.columna }); TargetColumns = @($g.Group | ForEach-Object { [string]$_.columna_destino })
                           OnDelete = [string]$f.borrar; OnUpdate = [string]$f.cambiar }
    }

    # Views (dependencies first, as SQL Server created them) and table triggers
    # Each with its name as here (schema_name outside dbo) and the tables it
    # uses renamed the same way
    $routines = foreach ($r in @((Invoke-Query $Connection @'
SELECT 'view' AS clase, s.name AS esquema, v.name, OBJECT_DEFINITION(v.object_id) AS definicion, v.create_date, v.object_id
FROM sys.views v JOIN sys.schemas s ON s.schema_id = v.schema_id WHERE v.is_ms_shipped = 0
UNION ALL
SELECT 'trigger', s.name, tr.name, OBJECT_DEFINITION(tr.object_id), tr.create_date, tr.object_id
FROM sys.triggers tr JOIN sys.objects o ON o.object_id = tr.parent_id JOIN sys.schemas s ON s.schema_id = o.schema_id
WHERE tr.parent_class = 1 AND tr.is_ms_shipped = 0
ORDER BY create_date, object_id
'@).Rows)) {
        if ($r.definicion -is [System.DBNull]) { [void]$Summary.Skipped.Add("$($r.clase) $($r.esquema).$($r.name): encrypted"); continue }
        $sql = Rename-CreatedObject (Rename-SchemaReferences ([string]$r.definicion) $names) (Get-TableName $r.esquema $r.name)
        [pscustomobject]@{ Kind = [string]$r.clase; Definition = $sql }
    }
    $views = @($routines | Where-Object { $_.Kind -eq 'view' })
    $triggers = @($routines | Where-Object { $_.Kind -eq 'trigger' })
    foreach ($r in @((Invoke-Query $Connection @'
SELECT s.name AS esquema, o.name, o.type_desc FROM sys.objects o JOIN sys.schemas s ON s.schema_id = o.schema_id
WHERE o.type IN ('P', 'FN', 'IF', 'TF', 'PC', 'FS', 'FT') AND o.is_ms_shipped = 0 ORDER BY o.type_desc, s.name, o.name
'@).Rows)) {
        [void]$Summary.Skipped.Add(([string]$r.type_desc).ToLowerInvariant().Replace('_', ' ') + " $($r.esquema).$($r.name): not included (jsonSQLDB has no stored procedures or functions)")
    }
    [pscustomobject]@{ Tables = @($result); ForeignKeys = @($foreignKeys); Views = @($views); Triggers = @($triggers) }
}

function Export-SqlServerDatabase($Connection, [string]$DatabaseName, [string]$Folder) {
    $summary = New-Summary
    $catalog = Read-SqlServerCatalog $Connection $summary
    $output = Join-Path $Folder ($DatabaseName + '.sqlserver.sql')
    $writer = New-Object System.IO.StreamWriter($output, $false, (New-Object System.Text.UTF8Encoding($true)))
    try {
        Write-SqlServerDump $writer $DatabaseName $catalog.Tables $catalog.ForeignKeys $catalog.Views $catalog.Triggers $summary
    } finally {
        $writer.Close()
    }
    [pscustomobject]@{ Output = $output; Summary = $summary }
}

# Loads a script into the database of $Connection, in one transaction. It
# stops before changing anything if a table of the script already exists
function Import-SqlIntoSqlServer([string]$SqlPath, $Connection) {
    $text = [System.IO.File]::ReadAllText($SqlPath)
    $existing = @((Invoke-Query $Connection 'SELECT name FROM sys.tables').Rows | ForEach-Object { ([string]$_.name).ToLowerInvariant() })
    $clash = @(Get-CreatedTables $text | Where-Object { $existing -contains $_.ToLowerInvariant() })
    if ($clash.Count -gt 0) {
        throw ('These tables already exist in the database, and nothing has been changed: ' + ($clash -join ', '))
    }
    $batches = Split-SqlBatches $text
    $transaction = $Connection.BeginTransaction()
    $n = 0
    try {
        foreach ($b in $batches) {
            $n++
            $command = $Connection.CreateCommand()
            $command.CommandText = $b
            $command.CommandTimeout = 0
            $command.Transaction = $transaction
            [void]$command.ExecuteNonQuery()
        }
        $transaction.Commit()
    } catch {
        $message = $_.Exception.Message
        try { $transaction.Rollback() } catch { $null = $_ }     # with XACT_ABORT ON the server has already rolled it back
        throw "Batch $n of $($batches.Count) failed: $message`r`nNothing has been changed: everything ran in one transaction."
    }
    $batches.Count
}

# ---------------------------------------------------------------------------
# Talking to the user
# ---------------------------------------------------------------------------

function Read-Choice([string]$Title, [string[]]$Options) {
    Write-Host ''
    Write-Host $Title
    for ($i = 0; $i -lt $Options.Count; $i++) { Write-Host ('  {0,3}. {1}' -f ($i + 1), $Options[$i]) }
    while ($true) {
        $answer = Read-Host 'Number (Enter to cancel)'
        if ($answer -eq '') { return -1 }
        $k = 0
        if ([int]::TryParse($answer, [ref]$k) -and $k -ge 1 -and $k -le $Options.Count) { return $k - 1 }
        Write-Host 'That is not one of the numbers.'
    }
}

function Invoke-Main {
    # SQLOLEDB is reached through System.Data.OleDb, which only Windows
    # PowerShell (5.1, in every Windows) has: from PowerShell 7, run again there
    if ($PSVersionTable.PSEdition -eq 'Core') {
        $ps = Join-Path $env:WINDIR 'System32\WindowsPowerShell\v1.0\powershell.exe'
        $arguments = @('-NoProfile', '-STA', '-ExecutionPolicy', 'Bypass', '-File', $PSCommandPath)
        foreach ($p in 'Server', 'Database', 'User', 'OutputFolder', 'Load') {
            $v = Get-Variable -Name $p -ValueOnly
            if ($v) { $arguments += @('-' + $p, $v) }
        }
        & $ps @arguments
        return
    }
    Add-Type -AssemblyName System.Windows.Forms
    Add-Type -AssemblyName System.Data

    $server = $Server
    if (-not $server) { $server = Read-Host 'SQL Server (localhost, .\SQLEXPRESS, host,1433...)' }
    if (-not $server) { return }
    $credential = $null
    $user = $User
    if (-not $user) { $user = Read-Host 'SQL Server login (Enter for Windows authentication)' }
    if ($user) { $credential = Get-Credential -UserName $user -Message "Password of $user on $server" }

    $master = New-SqlConnection $server 'master' $credential
    try {
        $databases = Get-Databases $master
        $load = $Load
        if (-not $load -and -not $Database -and -not $OutputFolder) {
            $what = Read-Choice 'What do you want to do?' @('Dump a database to SQL, for jsonSQLDB', 'Load an .sqlserver.sql file into SQL Server')
            if ($what -lt 0) { return }
            if ($what -eq 1) {
                $dialog = New-Object System.Windows.Forms.OpenFileDialog
                $dialog.Title = 'Choose the SQL file to load into SQL Server'
                $dialog.Filter = 'SQL Server SQL (*.sqlserver.sql;*.sql)|*.sqlserver.sql;*.sql|All files (*.*)|*.*'
                if ($dialog.ShowDialog() -ne 'OK') { return }
                $load = $dialog.FileName
            }
        }
        $name = $Database
        if ($load) {
            if (-not $name) {
                $k = Read-Choice 'Database to load it into:' (@($databases) + '(create a new database)')
                if ($k -lt 0) { return }
                if ($k -eq $databases.Count) {
                    $name = Read-Host 'Name of the new database'
                    if (-not $name) { return }
                    $command = $master.CreateCommand()
                    $command.CommandText = 'CREATE DATABASE ' + (ConvertTo-SqlName $name)
                    [void]$command.ExecuteNonQuery()
                } else { $name = $databases[$k] }
            }
            $target = New-SqlConnection $server $name $credential
            try {
                $n = Import-SqlIntoSqlServer $load $target
                Write-Host ''
                Write-Host "Done: $n batches run in $name."
            } finally { $target.Close() }
            return
        }
        if (-not $name) {
            $k = Read-Choice 'Database to dump:' $databases
            if ($k -lt 0) { return }
            $name = $databases[$k]
        }
        $folder = $OutputFolder
        if (-not $folder) {
            $dialog = New-Object System.Windows.Forms.FolderBrowserDialog
            $dialog.Description = 'Choose the folder where the SQL dump will be saved'
            if ($dialog.ShowDialog() -ne 'OK') { return }
            $folder = $dialog.SelectedPath
        }
        $source = New-SqlConnection $server $name $credential
        try {
            $result = Export-SqlServerDatabase $source $name $folder
        } finally { $source.Close() }
        $s = $result.Summary
        Write-Host ''
        Write-Host "Done: $($s.Tables) tables, $($s.Rows) rows, $($s.Indexes) indexes, $($s.ForeignKeys) foreign keys, $($s.Views) views and $($s.Triggers) triggers."
        Write-Host "Saved as: $($result.Output)"
        Write-Host 'Import it in jsonSQLDBadmin: open the database and use "Import an SQL dump".'
        if ($s.Skipped.Count -gt 0) {
            Write-Host ''
            Write-Host 'Not included, or not as it was:'
            $s.Skipped | ForEach-Object { Write-Host "  - $_" }
        }
    } catch {
        Write-Host ''
        Write-Host ('It stopped: ' + $_.Exception.Message) -ForegroundColor Red
    } finally {
        $master.Close()
        Write-Host ''
        Read-Host 'Press Enter to close' | Out-Null
    }
}

# Dot-sourced (. .\sqlserver-to-jsonsqldb.ps1) it only defines the functions: that is how it is tested
if ($MyInvocation.InvocationName -ne '.') { Invoke-Main }
