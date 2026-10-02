<#
.SYNOPSIS
    Dumps a Microsoft Access database (.mdb or .accdb) to an SQL file that
    jsonSQLDBadmin can import, and loads such a file into an Access database.

.DESCRIPTION
    Windows only. The easiest way to run it: right-click this file and choose
    "Run with PowerShell". It asks what to do:

    Dump an Access database to SQL: it asks for the Access file and for the
    folder where the dump is saved, and writes <name>.access.sql there:
    tables, primary keys, indexes, relationships, data, and the saved select
    queries. Then, in jsonSQLDBadmin, open the database and use "Import an SQL
    dump" with that file (format: Detect, or Microsoft Access).

    Load an SQL file into Access: it asks for an .access.sql file (one made by
    this script, or by jsonSQLDBadmin's "SQL: Microsoft Access" export) and for
    the Access database to load it into, new or existing, and runs its
    statements one by one, as if each were pasted into a query's SQL view.

    The file is Access SQL in its usual syntax (ANSI-89), so each statement can
    also be pasted by hand into Create > Query Design > SQL View. Access runs
    one statement at a time and has no DECIMAL, DEFAULT, ON DELETE / ON UPDATE
    CASCADE or comments in that syntax: what cannot be written goes in a "--"
    line above its table or relationship. Saved queries go as CREATE VIEW, which
    Access only accepts in .mdb databases of Access 2000 to 2003 (Jet 4.0) and
    in .accdb ones, and only in ANSI-92 syntax (through ADO/OLEDB, or with the
    database's "SQL Server Compatible Syntax (ANSI 92)" option, which exists
    since Access 2002); in the usual ANSI-89 syntax it is a syntax error, and in
    Access 97 or earlier (Jet 3) it does not exist. By hand, in any version:
    paste what follows AS into a new query and save it with the view's name.
    Loading the file with this script creates them as saved queries without
    CREATE VIEW, so any version works.

    The database is read through OLEDB (opened read-only: nothing in it is
    changed) and loaded through DAO. An .mdb needs nothing installed: Windows
    has the Jet engine, but only for 32-bit programs, so when this PowerShell is
    64-bit the script opens itself again in the 32-bit one. An .accdb needs the
    Microsoft Access Database Engine (ACE) with the same bitness as PowerShell;
    if it is missing, the script says so and offers the download page.

    What does not come along: attachments and OLE objects (binary data),
    action, crosstab and parameter queries, and hidden form or report
    queries. The summary at the end lists them.

.PARAMETER Database
    The .mdb or .accdb file. Without it, a dialog asks for it.

.PARAMETER OutputFolder
    The folder for the dump. Without it, a dialog asks for it.

.PARAMETER Load
    An .access.sql file to load into the database given with -Database
    (created if it does not exist), instead of dumping it.

.NOTES
    Part of jsonSQLDB: https://github.com/miguelenred/jsonsqldb
    Apache License 2.0.
#>
[CmdletBinding()]
param(
    [string]$Database,
    [string]$OutputFolder,
    [string]$Load
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
    $text = ([string]$Value).Replace("'", "''")
    if ($text -notmatch "[`r`n]") { return "'" + $text + "'" }
    # Access SQL cannot write a line break inside a text: 'a' & Chr(13) & Chr(10) & 'b'.
    # The statement stays on one line, and no editor changes the data
    $parts = foreach ($piece in [regex]::Split($text, "(`r|`n)")) {
        if ($piece -eq "`r") { 'Chr(13)' } elseif ($piece -eq "`n") { 'Chr(10)' } elseif ($piece -ne '') { "'" + $piece + "'" }
    }
    @($parts) -join ' & '
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
        20  { 'DOUBLE' }                # Large Number: no BIGINT in ANSI-89; DOUBLE is exact up to 2^53
        72  { 'GUID' }
        131 { if ([int]$Column.Scale -le 4) { 'CURRENCY' } else { 'DOUBLE' } }   # no DECIMAL in ANSI-89
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
    # Keys with CONSTRAINT and a name, as ANSI-89 wants them
    $pkName = ConvertTo-SqlName ('PK_' + $Table)
    $notes = @()
    foreach ($c in $Columns) {
        $def = ConvertTo-ColumnSql $c
        if ($PrimaryKey.Count -eq 1 -and $PrimaryKey[0] -eq $c.Name) { $def += ' CONSTRAINT ' + $pkName + ' PRIMARY KEY' }
        if ([int]$c.ProviderType -eq 131 -and [int]$c.Scale -gt 4) {
            $notes += '-- [' + $Table + '].[' + $c.Name + '] was DECIMAL(' + $c.Precision + ',' + $c.Scale + '): DOUBLE here (no DECIMAL in Access SQL)'
        }
        $parts += $def
    }
    if ($PrimaryKey.Count -gt 1) {
        $parts += 'CONSTRAINT ' + $pkName + ' PRIMARY KEY (' + (($PrimaryKey | ForEach-Object { ConvertTo-SqlName $_ }) -join ', ') + ')'
    }
    $sql = 'CREATE TABLE ' + (ConvertTo-SqlName $Table) + ' (' + ($parts -join ', ') + ');'
    if ($notes.Count -gt 0) { ($notes -join "`r`n") + "`r`n" + $sql } else { $sql }
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
    # ANSI-89 has no ON DELETE / ON UPDATE: said in a note, to tick in Relationships
    $notes = @()
    if ($OnDelete -in 'CASCADE', 'SET NULL') { $notes += '-- ' + (ConvertTo-SqlName $Name) + ' ON DELETE ' + $OnDelete }
    if ($OnUpdate -in 'CASCADE', 'SET NULL') { $notes += '-- ' + (ConvertTo-SqlName $Name) + ' ON UPDATE ' + $OnUpdate }
    if ($notes.Count -gt 0) { ($notes -join "`r`n") + "`r`n" + $sql + ';' } else { $sql + ';' }
}

# A saved query, as CREATE VIEW, on one line. The SELECT is kept as Access
# stored it (ANSI-89: double-quoted text, * and ? in Like)
function ConvertTo-View([string]$Name, [string]$Definition) {
    'CREATE VIEW ' + (ConvertTo-SqlName $Name) + ' AS ' + ($Definition.Trim().TrimEnd(';') -replace "\s*`r?`n\s*", ' ') + ';'
}

# The statements of an .access.sql file. A CREATE VIEW comes as the name of the
# saved query and its SELECT; any other statement, without a name. The "--"
# lines are skipped. A statement ends with ; outside quotes and brackets
function Split-SqlStatements([string]$Text) {
    $result = New-Object System.Collections.Generic.List[object]
    $current = New-Object System.Text.StringBuilder
    $quote = [char]0
    foreach ($line in ($Text -split "`r?`n")) {
        if ($current.Length -eq 0 -and $quote -eq [char]0 -and $line -match '^\s*(--.*)?$') { continue }
        for ($i = 0; $i -lt $line.Length; $i++) {
            $c = $line[$i]
            if ($quote -ne [char]0) {
                [void]$current.Append($c)
                if ($c -eq $quote) {
                    if ($quote -ne ']' -and $i + 1 -lt $line.Length -and $line[$i + 1] -eq $quote) { [void]$current.Append($c); $i++ }
                    else { $quote = [char]0 }
                }
                continue
            }
            if ($c -eq "'" -or $c -eq '"') { $quote = $c }
            elseif ($c -eq '[') { $quote = ']' }
            elseif ($c -eq ';') {
                $sql = $current.ToString().Trim()
                if ($sql -ne '') { $result.Add((ConvertTo-Statement $sql)) }
                [void]$current.Clear()
                continue
            }
            [void]$current.Append($c)
        }
        if ($current.Length -gt 0) { [void]$current.Append("`r`n") }
    }
    $sql = $current.ToString().Trim()
    if ($sql -ne '') { $result.Add((ConvertTo-Statement $sql)) }
    , $result.ToArray()
}

# CREATE VIEW [name] AS SELECT ...: the name and the SELECT, to create it as a
# saved query (DAO keeps the SELECT in Access's usual syntax, which is how it
# was written). Any other statement as it is
function ConvertTo-Statement([string]$Sql) {
    if ($Sql -match '(?s)^CREATE\s+VIEW\s+\[((?:[^\]]|\]\])+)\]\s+AS\s+(.+)$') {
        return [pscustomobject]@{ Name = $Matches[1].Replace(']]', ']'); Sql = $Matches[2].Trim() }
    }
    [pscustomobject]@{ Name = $null; Sql = $Sql }
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
    $Writer.WriteLine('-- Access SQL (ANSI-89). To load it into Access: run this script and choose "Load an SQL file into Access",')
    $Writer.WriteLine('-- or paste each statement (not the -- lines) into Create > Query Design > SQL View and Run.')
    $Writer.WriteLine('-- Saved queries go as CREATE VIEW: only .mdb of Access 2000-2003 and .accdb, and only in ANSI-92 syntax (ADO/OLEDB,')
    $Writer.WriteLine('-- or the database''s "SQL Server Compatible Syntax (ANSI 92)" option, since Access 2002); not in Access 97 or earlier.')
    $Writer.WriteLine('-- In any version: paste what follows AS into a new query and save it with the view''s name, or load the file')
    $Writer.WriteLine('-- with this script, which creates them as saved queries without CREATE VIEW.')
    $Writer.WriteLine('-- A "-- [relationship] ON DELETE CASCADE" (or ON UPDATE) line is what Access SQL cannot write: set it by hand in')
    $Writer.WriteLine('-- Database Tools > Relationships > Enforce Referential Integrity > Cascade. jsonSQLDBadmin applies it on import.')
    $Writer.WriteLine()
    foreach ($t in $Tables) {
        Write-Progress -Activity 'Dumping tables' -Status $t.Name
        $Writer.WriteLine((ConvertTo-CreateTable $t.Name $t.Columns $t.PrimaryKey))
        $Writer.WriteLine()
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
        $Writer.WriteLine()
        $Summary.Relationships++
    }
    foreach ($v in $Views) {
        $Writer.WriteLine((ConvertTo-View $v.Name $v.Definition))
        $Writer.WriteLine()
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
# Loading an SQL file into Access (Windows, DAO: the same syntax as the SQL view)
# ---------------------------------------------------------------------------

function Get-DaoEngine([string]$Extension) {
    foreach ($id in 'DAO.DBEngine.120', 'DAO.DBEngine.36') {
        if ($id -eq 'DAO.DBEngine.36' -and $Extension -ne '.mdb') { continue }
        try { return New-Object -ComObject $id } catch { }
    }
    $null
}

# Runs the statements of the file one by one in the database (created if it
# does not exist), as if each were pasted into a query's SQL view. Saved
# queries ("-- Query: name" + SELECT) are created with their name. It stops at
# the first statement that fails and says which one
function Import-SqlIntoAccess([string]$SqlPath, [string]$DbPath, $Engine) {
    $statements = Split-SqlStatements ([System.IO.File]::ReadAllText($SqlPath, [System.Text.Encoding]::UTF8))
    if (Test-Path -LiteralPath $DbPath) {
        $db = $Engine.OpenDatabase($DbPath)
    } else {
        # dbVersion40 (.mdb) or dbVersion120 (.accdb), with the general sort order
        $version = if ([System.IO.Path]::GetExtension($DbPath).ToLowerInvariant() -eq '.mdb') { 64 } else { 128 }
        $db = $Engine.CreateDatabase($DbPath, ';LANGID=0x0409;CP=1252;COUNTRY=0', $version)
    }
    $done = 0
    $queries = 0
    try {
        foreach ($st in $statements) {
            Write-Progress -Activity 'Loading into Access' -Status ("Statement " + ($done + 1) + " of " + $statements.Count) `
                -PercentComplete (100 * $done / [math]::Max(1, $statements.Count))
            try {
                if ($st.Name) {
                    [void]$db.CreateQueryDef($st.Name, $st.Sql)
                    $queries++
                } else {
                    $db.Execute($st.Sql, 128)       # dbFailOnError: an error stops it
                }
            } catch {
                $text = $st.Sql
                if ($text.Length -gt 300) { $text = $text.Substring(0, 300) + '...' }
                throw ("Statement " + ($done + 1) + " of " + $statements.Count + " failed: " + $_.Exception.Message +
                       "`r`n`r`n" + $text + "`r`n`r`nThe " + $done + " statements before it are already in the database.")
            }
            $done++
        }
    } finally {
        $db.Close()
        [void][System.Runtime.InteropServices.Marshal]::ReleaseComObject($db)
    }
    [pscustomobject]@{ Statements = $done; Queries = $queries }
}

# ---------------------------------------------------------------------------
# The windows
# ---------------------------------------------------------------------------

# Windows has the Jet engine (.mdb) only for 32-bit programs. From a 64-bit
# PowerShell without ACE, the script opens itself again in the 32-bit one,
# with what has been chosen so far. Returns $true if it did
function Restart-In32Bit([hashtable]$Arguments) {
    $ps32 = Join-Path $env:WINDIR 'SysWOW64\WindowsPowerShell\v1.0\powershell.exe'
    if (-not [Environment]::Is64BitProcess -or -not (Test-Path -LiteralPath $ps32)) { return $false }
    $list = @('-NoProfile', '-STA', '-ExecutionPolicy', 'Bypass', '-File', $PSCommandPath)
    foreach ($k in $Arguments.Keys) { if ($Arguments[$k]) { $list += @('-' + $k, $Arguments[$k]) } }
    & $ps32 @list
    $true
}

function Show-Message([string]$Text, [string]$Icon = 'Information', [string]$Buttons = 'OK') {
    [System.Windows.Forms.MessageBox]::Show($Text, 'Access to jsonSQLDB', $Buttons, $Icon)
}

function Show-EngineMissing([string]$Extension) {
    $bits = if ([Environment]::Is64BitProcess) { '64-bit' } else { '32-bit' }
    if ($Extension -eq '.mdb') {
        # 32-bit already, and still no Jet: unusual (it comes with Windows)
        $text = "This computer cannot open .mdb files: neither the Jet engine that comes with Windows nor the " +
                "Microsoft Access Database Engine is available.`r`n`r`nInstall the Microsoft Access Database Engine 2016 " +
                "Redistributable, the $bits version.`r`n`r`nOpen the download page now?`r`n$EngineDownloadPage"
        if ((Show-Message $text 'Warning' 'YesNo') -eq 'Yes') { Start-Process $EngineDownloadPage }
        return
    }
    $text = "This computer cannot open $Extension files: the Microsoft Access Database Engine is not installed " +
            "for $bits programs.`r`n`r`n" +
            "Install the Microsoft Access Database Engine 2016 Redistributable, the $bits version " +
            "(this PowerShell is $bits).`r`n`r`n" +
            "If 32-bit Office is installed, the 64-bit engine will not install next to it: run this script with the " +
            "32-bit PowerShell instead (C:\Windows\SysWOW64\WindowsPowerShell\v1.0\powershell.exe), with the 32-bit engine.`r`n`r`n" +
            "Open the download page now?`r`n$EngineDownloadPage"
    if ((Show-Message $text 'Warning' 'YesNo') -eq 'Yes') { Start-Process $EngineDownloadPage }
}

# What to do: dump (Yes), load (No) or nothing (Cancel)
function Show-Choice {
    $form = New-Object System.Windows.Forms.Form
    $form.Text = 'Access to jsonSQLDB'
    $form.ClientSize = New-Object System.Drawing.Size(420, 170)
    $form.StartPosition = 'CenterScreen'
    $form.FormBorderStyle = 'FixedDialog'
    $form.MaximizeBox = $false
    $form.MinimizeBox = $false
    $label = New-Object System.Windows.Forms.Label
    $label.Text = 'What do you want to do?'
    $label.Location = New-Object System.Drawing.Point(20, 18)
    $label.AutoSize = $true
    $form.Controls.Add($label)
    $i = 0
    foreach ($b in @(@('Dump an Access database to SQL', 'Yes'), @('Load an SQL file into Access', 'No'), @('Cancel', 'Cancel'))) {
        $button = New-Object System.Windows.Forms.Button
        $button.Text = $b[0]
        $button.DialogResult = $b[1]
        $button.Size = New-Object System.Drawing.Size(380, 30)
        $button.Location = New-Object System.Drawing.Point(20, (48 + 38 * $i))
        $form.Controls.Add($button)
        $i++
    }
    $form.CancelButton = $form.Controls[$form.Controls.Count - 1]
    $form.ShowDialog()
}

function Invoke-Dump {
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
    if (-not $provider -and $extension -eq '.mdb' -and (Restart-In32Bit @{ Database = $path; OutputFolder = $OutputFolder })) { return }
    if (-not $provider) { Show-EngineMissing $extension; return }

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
            "and $($s.Views) saved queries.`r`n`r`nSaved as:`r`n$($result.Output)`r`n`r`n" +
            'Import it in jsonSQLDBadmin: open the database and use "Import an SQL dump".'
    if ($s.Skipped.Count -gt 0) {
        $text += "`r`n`r`nNot included:`r`n- " + (($s.Skipped | Select-Object -First 15) -join "`r`n- ")
    }
    $text += "`r`n`r`nOpen the folder?"
    if ((Show-Message $text 'Information' 'YesNo') -eq 'Yes') { Start-Process explorer.exe "/select,`"$($result.Output)`"" }
}

function Invoke-Load {
    $sqlPath = $Load
    if (-not $sqlPath) {
        $dialog = New-Object System.Windows.Forms.OpenFileDialog
        $dialog.Title = 'Choose the SQL file to load into Access'
        $dialog.Filter = 'Access SQL (*.access.sql;*.sql)|*.access.sql;*.sql|All files (*.*)|*.*'
        if ($dialog.ShowDialog() -ne 'OK') { return }
        $sqlPath = $dialog.FileName
    }
    $dbPath = $Database
    if (-not $dbPath) {
        $dialog = New-Object System.Windows.Forms.SaveFileDialog
        $dialog.Title = 'Choose the Access database to load it into: a new name creates it'
        $dialog.Filter = 'Access 2007 or later (*.accdb)|*.accdb|Access 2000-2003 (*.mdb)|*.mdb'
        $dialog.OverwritePrompt = $false
        $dialog.InitialDirectory = [System.IO.Path]::GetDirectoryName($sqlPath)
        $dialog.FileName = [System.IO.Path]::GetFileNameWithoutExtension([System.IO.Path]::GetFileNameWithoutExtension($sqlPath)) + '.accdb'
        if ($dialog.ShowDialog() -ne 'OK') { return }
        $dbPath = $dialog.FileName
        if ((Test-Path -LiteralPath $dbPath) -and
            (Show-Message ("Load it into the existing database?`r`n$dbPath`r`n`r`nA table that already exists there makes it stop.") 'Question' 'YesNo') -ne 'Yes') {
            return
        }
    }
    $extension = [System.IO.Path]::GetExtension($dbPath).ToLowerInvariant()
    $engine = Get-DaoEngine $extension
    if (-not $engine -and $extension -eq '.mdb' -and (Restart-In32Bit @{ Load = $sqlPath; Database = $dbPath })) { return }
    if (-not $engine) { Show-EngineMissing $extension; return }
    try {
        $r = Import-SqlIntoAccess $sqlPath $dbPath $engine
    } catch {
        Show-Message ("The load stopped:`r`n`r`n" + $_.Exception.Message) 'Error' | Out-Null
        return
    }
    $text = "Done: $($r.Statements) statements run, $($r.Queries) of them saved queries, in`r`n$dbPath`r`n`r`n" +
            'Defaults and cascading relationships are not in Access SQL: the "--" lines of the file say which ones to set by hand.'
    Show-Message $text 'Information' | Out-Null
}

function Invoke-Main {
    Add-Type -AssemblyName System.Windows.Forms
    Add-Type -AssemblyName System.Drawing
    Add-Type -AssemblyName System.Data

    # The file dialogs need a single-threaded apartment
    if ([System.Threading.Thread]::CurrentThread.ApartmentState -ne 'STA') {
        $exe = (Get-Process -Id $PID).Path
        $arguments = @('-NoProfile', '-STA', '-ExecutionPolicy', 'Bypass', '-File', $PSCommandPath)
        if ($Database) { $arguments += @('-Database', $Database) }
        if ($OutputFolder) { $arguments += @('-OutputFolder', $OutputFolder) }
        if ($Load) { $arguments += @('-Load', $Load) }
        & $exe @arguments
        return
    }
    if ($Load) { Invoke-Load; return }
    if ($Database -or $OutputFolder) { Invoke-Dump; return }
    switch (Show-Choice) {
        'Yes' { Invoke-Dump }
        'No'  { Invoke-Load }
    }
}

# Dot-sourced (. .\access-to-jsonsqldb.ps1) it only defines the functions: that is how it is tested
if ($MyInvocation.InvocationName -ne '.') { Invoke-Main }
