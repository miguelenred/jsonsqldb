<#
.SYNOPSIS
    Dumps a Microsoft Access database (.mdb or .accdb) to an SQL file that
    jsonSQLDBadmin can import.

.DESCRIPTION
    Windows only. The easiest way to run it: right-click this file and choose
    "Run with PowerShell".

    It asks for the Access file and for the folder where the dump is saved,
    and writes <name>.access.sql there: tables, primary keys, indexes,
    relationships, data, and the saved select queries (as views). Then, in
    jsonSQLDBadmin, open the database and use "Import an SQL dump" with that
    file (format: Detect, or Microsoft Access).

    The database is read through OLEDB and opened read-only: nothing in it is
    changed. An .accdb needs the Microsoft Access Database Engine (ACE); an
    .mdb also works with the old Jet engine, which only exists in 32-bit
    PowerShell. If the engine is missing, the script says so and offers the
    download page.

    What does not come along: attachments and OLE objects (binary data),
    action, crosstab and parameter queries, and hidden form or report
    queries. The summary at the end lists them.

.PARAMETER Database
    The .mdb or .accdb file. Without it, a dialog asks for it.

.PARAMETER OutputFolder
    The folder for the dump. Without it, a dialog asks for it.

.NOTES
    Part of jsonSQLDB: https://github.com/miguelenred/jsonsqldb
    Apache License 2.0.
#>
[CmdletBinding()]
param(
    [string]$Database,
    [string]$OutputFolder
)

Set-StrictMode -Version 2.0
$ErrorActionPreference = 'Stop'

$EngineDownloadPage = 'https://www.microsoft.com/en-us/download/details.aspx?id=54920'
$Invariant = [System.Globalization.CultureInfo]::InvariantCulture

# ---------------------------------------------------------------------------
# SQL text: these functions do not touch OLEDB, so they can be tested anywhere
# ---------------------------------------------------------------------------

function ConvertTo-SqlName([string]$Name) {
    '[' + $Name.Replace(']', ']]') + ']'
}

function ConvertTo-SqlValue($Value) {
    if ($null -eq $Value -or $Value -is [System.DBNull]) { return 'NULL' }
    if ($Value -is [bool]) { if ($Value) { return 'True' } else { return 'False' } }
    if ($Value -is [datetime]) { return '#' + $Value.ToString('yyyy-MM-dd HH:mm:ss', $Invariant) + '#' }
    if ($Value -is [double] -or $Value -is [single]) {
        if ([double]::IsNaN($Value) -or [double]::IsInfinity($Value)) { return 'NULL' }
        return $Value.ToString('R', $Invariant)
    }
    if ($Value -is [decimal] -or $Value -is [int] -or $Value -is [long] -or $Value -is [int16] -or $Value -is [byte]) {
        return $Value.ToString($Invariant)
    }
    if ($Value -is [byte[]]) { return 'NULL' }
    "'" + ([string]$Value).Replace("'", "''") + "'"
}

# One column of a table, from what OLEDB says about it (GetSchemaTable)
function ConvertTo-ColumnSql($Column) {
    $type = switch ([int]$Column.ProviderType) {
        2   { 'SHORT' }
        3   { 'LONG' }
        4   { 'SINGLE' }
        5   { 'DOUBLE' }
        6   { 'CURRENCY' }
        7   { 'DATETIME' }
        11  { 'BIT' }
        17  { 'BYTE' }
        20  { 'BIGINT' }
        72  { 'GUID' }
        131 { 'DECIMAL(' + $Column.Precision + ',' + $Column.Scale + ')' }
        { $_ -in 128, 204, 205 } { 'LONGBINARY' }
        default {
            if ($Column.IsLong -or [int]$Column.Size -gt 255 -or [int]$Column.Size -le 0) { 'MEMO' }
            else { 'TEXT(' + [int]$Column.Size + ')' }
        }
    }
    if ($Column.AutoIncrement) { $type = 'COUNTER' }
    $sql = (ConvertTo-SqlName $Column.Name) + ' ' + $type
    if (-not $Column.AllowNull -and -not $Column.AutoIncrement) { $sql += ' NOT NULL' }
    $sql
}

function ConvertTo-CreateTable([string]$Table, $Columns, [string[]]$PrimaryKey) {
    # A table without a primary key may come as $null: an empty list instead
    $PrimaryKey = @($PrimaryKey | Where-Object { $_ })
    $parts = @()
    foreach ($c in $Columns) {
        $def = ConvertTo-ColumnSql $c
        if ($PrimaryKey.Count -eq 1 -and $PrimaryKey[0] -eq $c.Name) { $def += ' PRIMARY KEY' }
        $parts += $def
    }
    if ($PrimaryKey.Count -gt 1) {
        $parts += 'PRIMARY KEY (' + (($PrimaryKey | ForEach-Object { ConvertTo-SqlName $_ }) -join ', ') + ')'
    }
    'CREATE TABLE ' + (ConvertTo-SqlName $Table) + " (`r`n  " + ($parts -join ",`r`n  ") + "`r`n);"
}

function ConvertTo-Insert([string]$Table, [string[]]$ColumnNames, [object[]]$Values) {
    'INSERT INTO ' + (ConvertTo-SqlName $Table) + ' (' + (($ColumnNames | ForEach-Object { ConvertTo-SqlName $_ }) -join ', ') +
        ') VALUES (' + (($Values | ForEach-Object { ConvertTo-SqlValue $_ }) -join ', ') + ');'
}

function ConvertTo-Index([string]$Table, [string]$Name, [bool]$Unique, [string[]]$Columns) {
    'CREATE ' + $(if ($Unique) { 'UNIQUE ' } else { '' }) + 'INDEX ' + (ConvertTo-SqlName $Name) + ' ON ' +
        (ConvertTo-SqlName $Table) + ' (' + (($Columns | ForEach-Object { ConvertTo-SqlName $_ }) -join ', ') + ');'
}

function ConvertTo-ForeignKey([string]$Table, [string]$Name, [string[]]$Columns, [string]$Target, [string[]]$TargetColumns,
                              [string]$OnDelete, [string]$OnUpdate) {
    $sql = 'ALTER TABLE ' + (ConvertTo-SqlName $Table) + ' ADD CONSTRAINT ' + (ConvertTo-SqlName $Name) +
        ' FOREIGN KEY (' + (($Columns | ForEach-Object { ConvertTo-SqlName $_ }) -join ', ') + ') REFERENCES ' +
        (ConvertTo-SqlName $Target) + ' (' + (($TargetColumns | ForEach-Object { ConvertTo-SqlName $_ }) -join ', ') + ')'
    if ($OnDelete -in 'CASCADE', 'SET NULL') { $sql += ' ON DELETE ' + $OnDelete }
    if ($OnUpdate -in 'CASCADE', 'SET NULL') { $sql += ' ON UPDATE ' + $OnUpdate }
    $sql + ';'
}

function ConvertTo-View([string]$Name, [string]$Definition) {
    'CREATE VIEW ' + (ConvertTo-SqlName $Name) + ' AS ' + $Definition.Trim().TrimEnd(';') + ';'
}

# ---------------------------------------------------------------------------
# Reading the database (Windows, OLEDB)
# ---------------------------------------------------------------------------

function Get-OleDbProvider([string]$Extension) {
    $registered = @()
    try {
        $registered = @((New-Object System.Data.OleDb.OleDbEnumerator).GetElements() | ForEach-Object { $_.SOURCES_NAME })
    } catch { }
    foreach ($p in 'Microsoft.ACE.OLEDB.16.0', 'Microsoft.ACE.OLEDB.12.0') {
        if ($registered -contains $p) { return $p }
    }
    if ($Extension -eq '.mdb' -and $registered -contains 'Microsoft.Jet.OLEDB.4.0') { return 'Microsoft.Jet.OLEDB.4.0' }
    $null
}

function Get-SchemaRows($Connection, $Guid) {
    try { @($Connection.GetOleDbSchemaTable($Guid, $null).Rows) } catch { @() }
}

# The dump, from a description of the database: tables (Name, Columns,
# PrimaryKey, Indexes and a Rows script block that returns each row as an
# array), relationships and views. Reading and writing are separate so the
# writing can be tested without Access.
function Write-AccessDump($Writer, [string]$Source, $Tables, $ForeignKeys, $Views, $Summary) {
    $Writer.WriteLine('-- jsonsqldb-dialecto: access')
    $Writer.WriteLine('-- ' + $Source + ', dumped by access-to-jsonsqldb.ps1')
    $Writer.WriteLine('-- Import it in jsonSQLDBadmin: open the database, "Import an SQL dump".')
    $Writer.WriteLine()
    foreach ($t in $Tables) {
        Write-Progress -Activity 'Dumping tables' -Status $t.Name
        $Writer.WriteLine((ConvertTo-CreateTable $t.Name $t.Columns $t.PrimaryKey))
        $names = @($t.Columns | ForEach-Object { $_.Name })
        $binary = $false
        # Row by row, through the pipeline: each row arrives as one array
        & $t.Rows | ForEach-Object {
            foreach ($v in $_) { if ($v -is [byte[]]) { $binary = $true } }
            $Writer.WriteLine((ConvertTo-Insert $t.Name $names $_))
            $Summary.Rows++
        }
        if ($binary) { $Summary.Skipped += 'binary data (OLE objects, attachments) in ' + $t.Name }
        foreach ($ix in $t.Indexes) {
            $Writer.WriteLine((ConvertTo-Index $t.Name $ix.Name $ix.Unique $ix.Columns))
            $Summary.Indexes++
        }
        $Writer.WriteLine()
        $Summary.Tables++
    }
    # Relationships, after all the tables and their data
    foreach ($fk in $ForeignKeys) {
        $Writer.WriteLine((ConvertTo-ForeignKey $fk.Table $fk.Name $fk.Columns $fk.Target $fk.TargetColumns $fk.OnDelete $fk.OnUpdate))
        $Summary.Relationships++
    }
    $Writer.WriteLine()
    foreach ($v in $Views) {
        $Writer.WriteLine((ConvertTo-View $v.Name $v.Definition))
        $Summary.Views++
    }
}

function New-Summary {
    [pscustomobject]@{ Tables = 0; Rows = 0; Indexes = 0; Relationships = 0; Views = 0; Skipped = @() }
}

function Export-AccessDatabase([string]$Path, [string]$Folder, [string]$Provider) {
    $connection = New-Object System.Data.OleDb.OleDbConnection("Provider=$Provider;Data Source=$Path;Mode=Read;Persist Security Info=False;")
    $connection.Open()
    $output = Join-Path $Folder ([System.IO.Path]::GetFileNameWithoutExtension($Path) + '.access.sql')
    $writer = New-Object System.IO.StreamWriter($output, $false, (New-Object System.Text.UTF8Encoding($false)))
    $summary = New-Summary
    try {
        $schema = [System.Data.OleDb.OleDbSchemaGuid]
        $names = @(Get-SchemaRows $connection $schema::Tables | Where-Object { $_.TABLE_TYPE -eq 'TABLE' } | ForEach-Object { [string]$_.TABLE_NAME })
        $primaryKeys = Get-SchemaRows $connection $schema::Primary_Keys
        $indexes = Get-SchemaRows $connection $schema::Indexes

        $tables = foreach ($name in $names) {
            # The columns, from a query that returns no rows
            $command = $connection.CreateCommand()
            $command.CommandText = 'SELECT * FROM ' + (ConvertTo-SqlName $name) + ' WHERE 1 = 0'
            $reader = $command.ExecuteReader()
            $columns = @($reader.GetSchemaTable().Rows | ForEach-Object {
                [pscustomobject]@{
                    Name = [string]$_.ColumnName; ProviderType = [int]$_.ProviderType; Size = [int]$_.ColumnSize
                    Precision = $_.NumericPrecision; Scale = $_.NumericScale; AllowNull = [bool]$_.AllowDBNull
                    AutoIncrement = [bool]$_.IsAutoIncrement; IsLong = [bool]$_.IsLong
                }
            })
            $reader.Close()
            $ixs = foreach ($g in ($indexes | Where-Object { $_.TABLE_NAME -eq $name -and -not $_.PRIMARY_KEY } | Group-Object INDEX_NAME)) {
                [pscustomobject]@{ Name = $g.Name; Unique = [bool]$g.Group[0].UNIQUE
                                   Columns = @($g.Group | Sort-Object ORDINAL_POSITION | ForEach-Object { [string]$_.COLUMN_NAME }) }
            }
            $rows = {
                $c = $connection.CreateCommand()
                $c.CommandText = 'SELECT * FROM ' + (ConvertTo-SqlName $name)
                $r = $c.ExecuteReader()
                try {
                    while ($r.Read()) {
                        $values = New-Object object[] $r.FieldCount
                        [void]$r.GetValues($values)
                        , $values
                    }
                } finally { $r.Close() }
            }.GetNewClosure()
            [pscustomobject]@{
                Name = $name; Columns = $columns; Indexes = @($ixs); Rows = $rows
                PrimaryKey = @($primaryKeys | Where-Object { $_.TABLE_NAME -eq $name } | Sort-Object ORDINAL | ForEach-Object { [string]$_.COLUMN_NAME })
            }
        }
        $foreignKeys = foreach ($g in (Get-SchemaRows $connection $schema::Foreign_Keys | Group-Object FK_NAME)) {
            $rows = @($g.Group | Sort-Object ORDINAL)
            if ($names -notcontains [string]$rows[0].FK_TABLE_NAME -or $names -notcontains [string]$rows[0].PK_TABLE_NAME) { continue }
            [pscustomobject]@{
                Table = [string]$rows[0].FK_TABLE_NAME; Name = $g.Name; Columns = @($rows | ForEach-Object { [string]$_.FK_COLUMN_NAME })
                Target = [string]$rows[0].PK_TABLE_NAME; TargetColumns = @($rows | ForEach-Object { [string]$_.PK_COLUMN_NAME })
                OnDelete = [string]$rows[0].DELETE_RULE; OnUpdate = [string]$rows[0].UPDATE_RULE
            }
        }
        # Saved select queries, as views. Hidden ones (~sq_...) belong to forms and reports
        $views = foreach ($v in (Get-SchemaRows $connection $schema::Views)) {
            if (-not ([string]$v.TABLE_NAME).StartsWith('~')) {
                [pscustomobject]@{ Name = [string]$v.TABLE_NAME; Definition = [string]$v.VIEW_DEFINITION }
            }
        }
        foreach ($p in (Get-SchemaRows $connection $schema::Procedures)) {
            if (-not ([string]$p.PROCEDURE_NAME).StartsWith('~')) {
                $summary.Skipped += "query '" + $p.PROCEDURE_NAME + "' (action, crosstab or with parameters)"
            }
        }
        Write-AccessDump $writer ([System.IO.Path]::GetFileName($Path)) @($tables) @($foreignKeys) @($views) $summary
    } finally {
        $writer.Close()
        $connection.Close()
    }
    [pscustomobject]@{ Output = $output; Summary = $summary }
}

# ---------------------------------------------------------------------------
# The windows
# ---------------------------------------------------------------------------

function Show-Message([string]$Text, [string]$Icon = 'Information', [string]$Buttons = 'OK') {
    [System.Windows.Forms.MessageBox]::Show($Text, 'Access to jsonSQLDB', $Buttons, $Icon)
}

function Invoke-Main {
    Add-Type -AssemblyName System.Windows.Forms
    Add-Type -AssemblyName System.Data

    # The file dialogs need a single-threaded apartment
    if ([System.Threading.Thread]::CurrentThread.ApartmentState -ne 'STA') {
        $exe = (Get-Process -Id $PID).Path
        $arguments = @('-NoProfile', '-STA', '-ExecutionPolicy', 'Bypass', '-File', $PSCommandPath)
        if ($Database) { $arguments += @('-Database', $Database) }
        if ($OutputFolder) { $arguments += @('-OutputFolder', $OutputFolder) }
        & $exe @arguments
        return
    }

    $path = $Database
    if (-not $path) {
        $dialog = New-Object System.Windows.Forms.OpenFileDialog
        $dialog.Title = 'Choose the Access database to dump'
        $dialog.Filter = 'Access databases (*.mdb;*.accdb)|*.mdb;*.accdb|All files (*.*)|*.*'
        if ($dialog.ShowDialog() -ne 'OK') { return }
        $path = $dialog.FileName
    }
    if (-not (Test-Path -LiteralPath $path)) {
        Show-Message "The file does not exist:`r`n$path" 'Error' | Out-Null
        return
    }

    $extension = [System.IO.Path]::GetExtension($path).ToLowerInvariant()
    $provider = Get-OleDbProvider $extension
    if (-not $provider) {
        $bits = if ([Environment]::Is64BitProcess) { '64-bit' } else { '32-bit' }
        $text = "This computer cannot read $extension files: the Microsoft Access Database Engine is not installed " +
                "for $bits programs.`r`n`r`n" +
                "Install the Microsoft Access Database Engine 2016 Redistributable, the $bits version " +
                "(this PowerShell is $bits).`r`n`r`n" +
                "If 32-bit Office is installed, the 64-bit engine will not install next to it: run this script with the " +
                "32-bit PowerShell instead (C:\Windows\SysWOW64\WindowsPowerShell\v1.0\powershell.exe), with the 32-bit engine.`r`n`r`n" +
                "Open the download page now?`r`n$EngineDownloadPage"
        if ((Show-Message $text 'Warning' 'YesNo') -eq 'Yes') { Start-Process $EngineDownloadPage }
        return
    }

    $folder = $OutputFolder
    if (-not $folder) {
        $dialog = New-Object System.Windows.Forms.FolderBrowserDialog
        $dialog.Description = 'Choose the folder where the SQL dump will be saved'
        $dialog.SelectedPath = [System.IO.Path]::GetDirectoryName($path)
        if ($dialog.ShowDialog() -ne 'OK') { return }
        $folder = $dialog.SelectedPath
    }

    try {
        $result = Export-AccessDatabase $path $folder $provider
    } catch {
        Show-Message ("The dump could not be made:`r`n`r`n" + $_.Exception.Message) 'Error' | Out-Null
        return
    }
    $s = $result.Summary
    $text = "Done: $($s.Tables) tables, $($s.Rows) rows, $($s.Indexes) indexes, $($s.Relationships) relationships " +
            "and $($s.Views) queries as views.`r`n`r`nSaved as:`r`n$($result.Output)`r`n`r`n" +
            'Import it in jsonSQLDBadmin: open the database and use "Import an SQL dump".'
    if ($s.Skipped.Count -gt 0) {
        $text += "`r`n`r`nNot included:`r`n- " + (($s.Skipped | Select-Object -First 15) -join "`r`n- ")
    }
    $text += "`r`n`r`nOpen the folder?"
    if ((Show-Message $text 'Information' 'YesNo') -eq 'Yes') { Start-Process explorer.exe "/select,`"$($result.Output)`"" }
}

# Dot-sourced (. .\access-to-jsonsqldb.ps1) it only defines the functions: that is how it is tested
if ($MyInvocation.InvocationName -ne '.') { Invoke-Main }
