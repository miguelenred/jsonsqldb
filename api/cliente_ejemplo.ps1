Add-Type -AssemblyName System.Web

<#
    jsonSQLDB - Cliente PowerShell de la API.

    Diferencias con los clientes de tus otras APIs:

      0. El secreto es EL DE LA API KEY: el campo 'hmac_secret' de esa entrada de
         $API_KEYS en api/jsonsqldb_api_config.php. Cada clave tiene el suyo.

      1. La firma lleva la base y los parámetros:
             '+' + apiKey + '|' + base + '|' + timestamp + '|' + sql + params + '¿'
         Sin parámetros, params es cadena vacía. La base entró en la fórmula en
         la 2.0: antes se podía coger una petición firmada, cambiarle el campo
         'db' y reenviarla contra otra base.

      2. Hay que indicar SOBRE QUÉ BASE se ejecuta, en el campo 'db'. No hay
         USE ni prefijos tipo mibase.clientes. Solo puede ir vacío para
         SHOW DATABASES, CREATE DATABASE y DROP DATABASE.

      3. Los valores NO se concatenan a la SQL: se ponen '?' y los valores van
         aparte, en -Parametros. El servidor los inserta ya analizados, así que
         un valor no puede alterar la consulta por mucho SQL que contenga.

    Uso:
        Set-JsonSqlDbConexion -Url 'https://example.com/jsonsqldb/api/jsonsqldb_api.php' `
                             -ApiKey '...' -HmacSecret '...' -Base 'pruebas'

        API-SQL-JSON "SELECT * FROM clientes WHERE ciudad = ?" @('Torrevieja')
#>

# ----------------------------------------------------------------------
# Configuración de la conexión
# ----------------------------------------------------------------------

$Global:JsonSqlDb = @{
    Url         = 'https://example.com/jsonsqldb/api/jsonsqldb_api.php'
    # Clave de los ejemplos: permiso de escritura sobre la base 'pruebas'.
    # Es la misma que usa cliente_ejemplo.php. Para tu aplicación, crea una
    # clave propia en api/jsonsqldb_api_config.php.
    ApiKey      = 'CHANGE_ME_EXAMPLE_API_KEY'
    # El 'hmac_secret' de la API key de arriba
    HmacSecret  = 'CHANGE_ME_EXAMPLE_SECRET'
    Base        = 'pruebas'
    TimeoutSec  = 30
    # Certificado propio o autofirmado. Ruta al .crt/.pem para verificarlo,
    # o $true en Autofirmado para aceptarlo sin comprobar.
    Certificado = ''
    Autofirmado = $false
}

function Set-JsonSqlDbConexion {
    <#
        .SYNOPSIS
        Cambia los datos de conexión. Solo se tocan los parámetros que pases.
    #>
    param (
        [string]$Url,
        [string]$ApiKey,
        [string]$HmacSecret,
        [string]$Base,
        [int]$TimeoutSec,
        [string]$Certificado,
        [nullable[bool]]$Autofirmado
    )

    foreach ($clave in @('Url', 'ApiKey', 'HmacSecret', 'Base', 'Certificado')) {
        if ($PSBoundParameters.ContainsKey($clave)) { $Global:JsonSqlDb[$clave] = $PSBoundParameters[$clave] }
    }
    if ($PSBoundParameters.ContainsKey('TimeoutSec'))  { $Global:JsonSqlDb.TimeoutSec  = $TimeoutSec }
    if ($PSBoundParameters.ContainsKey('Autofirmado')) { $Global:JsonSqlDb.Autofirmado = [bool]$Autofirmado }
}

# ----------------------------------------------------------------------
# Utilidades
# ----------------------------------------------------------------------

function Get-HMACSHA256 {
    param (
        [Parameter(Mandatory = $true)][string]$InputString,
        [Parameter(Mandatory = $true)][string]$Key
    )

    $keyBytes     = [System.Text.Encoding]::UTF8.GetBytes($Key)
    $messageBytes = [System.Text.Encoding]::UTF8.GetBytes($InputString)

    $hmacsha256 = [System.Security.Cryptography.HMACSHA256]::new($keyBytes)
    $hashBytes  = $hmacsha256.ComputeHash($messageBytes)
    $hmacsha256.Dispose()

    return ([BitConverter]::ToString($hashBytes) -replace '-', '').ToLower()
}

function Is-ValidJson {
    param ([Parameter(Mandatory = $true)][string]$String)

    try {
        $null = $String | ConvertFrom-Json -ErrorAction Stop
        return $true
    }
    catch {
        return $false
    }
}

<#
    Convierte los parámetros al JSON que espera la API: una lista de valores
    simples. Es el texto EXACTO que se firma, así que se genera una sola vez y
    se usa tanto para la firma como para el envío.
#>
function ConvertTo-JsonSqlDbParametros {
    param ([object[]]$Parametros)

    if ($null -eq $Parametros -or $Parametros.Count -eq 0) { return '' }

    $piezas = foreach ($v in $Parametros) {
        if ($null -eq $v) {
            'null'
        }
        elseif ($v -is [bool]) {
            if ($v) { 'true' } else { 'false' }
        }
        elseif ($v -is [int] -or $v -is [long] -or $v -is [int16] -or $v -is [byte]) {
            $v.ToString([System.Globalization.CultureInfo]::InvariantCulture)
        }
        elseif ($v -is [double] -or $v -is [decimal] -or $v -is [single]) {
            # Punto como separador decimal, nunca coma
            $v.ToString([System.Globalization.CultureInfo]::InvariantCulture)
        }
        elseif ($v -is [datetime]) {
            '"' + $v.ToString('yyyy-MM-dd HH:mm:ss') + '"'
        }
        else {
            # ConvertTo-Json de un texto suelto ya escapa comillas y barras
            ($v.ToString() | ConvertTo-Json -Compress)
        }
    }

    return '[' + ($piezas -join ',') + ']'
}

# ----------------------------------------------------------------------
# Consulta
# ----------------------------------------------------------------------

function API-SQL-JSON {
    <#
        .SYNOPSIS
        Ejecuta una sentencia contra la API de jsonSQLDB.

        .PARAMETER Sql
        La sentencia. Pon ? donde vaya un valor.

        .PARAMETER Parametros
        Valores de los ?, en el mismo orden.

        .PARAMETER Base
        Base de datos. Si no la indicas, se usa la de la configuración.

        .EXAMPLE
        API-SQL-JSON "SELECT * FROM clientes WHERE ciudad = ? AND saldo > ?" @('Torrevieja', 100.5)

        .EXAMPLE
        API-SQL-JSON "SHOW DATABASES" -Base ''
    #>
    param (
        [Parameter(Mandatory = $true, Position = 0)][string]$Sql,
        [Parameter(Position = 1)][object[]]$Parametros = @(),
        [string]$Base
    )

    $cfg = $Global:JsonSqlDb
    if (-not $PSBoundParameters.ContainsKey('Base')) { $Base = $cfg.Base }

    $params    = ConvertTo-JsonSqlDbParametros -Parametros $Parametros
    $timestamp = [DateTimeOffset]::UtcNow.ToUnixTimeSeconds().ToString()

    # Firma de jsonSQLDB: '+' apiKey '|' base '|' timestamp '|' sql params '¿'
    #
    # El '¿' se monta desde su código (U+00BF) a propósito: si el fichero se
    # guardara en ANSI en lugar de UTF-8, un '¿' escrito literalmente daría
    # otros bytes y el token no cuadraría con el del servidor.
    $cierre = [char]0x00BF
    $token = Get-HMACSHA256 -Key $cfg.HmacSecret `
                            -InputString ('+' + $cfg.ApiKey + '|' + $Base + '|' + $timestamp + '|' + $Sql + $params + $cierre)

    $campos = [ordered]@{
        api_key   = $cfg.ApiKey
        db        = $Base
        sql       = $Sql
        params    = $params
        timestamp = $timestamp
        token     = $token
    }
    $formBody = (
        $campos.GetEnumerator() | ForEach-Object {
            "$($_.Key)=$([System.Web.HttpUtility]::UrlEncode([string]$_.Value))"
        }
    ) -join '&'

    $headers = @{ 'Content-Type' = 'application/x-www-form-urlencoded' }

    # --- Certificado del servidor ---
    $originalCallback = [System.Net.ServicePointManager]::ServerCertificateValidationCallback
    $originalProtocol = [Net.ServicePointManager]::SecurityProtocol

    try {
        [Net.ServicePointManager]::SecurityProtocol =
            [Net.SecurityProtocolType]::Tls12 -bor [Net.SecurityProtocolType]::Tls13

        if ($cfg.Certificado -and (Test-Path -LiteralPath $cfg.Certificado)) {
            # Se verifica, pero contra el certificado indicado
            $propio = [System.Security.Cryptography.X509Certificates.X509Certificate2]::new($cfg.Certificado)
            [System.Net.ServicePointManager]::ServerCertificateValidationCallback = {
                param($remitente, $certificado, $cadena, $errores)
                if ($errores -eq [System.Net.Security.SslPolicyErrors]::None) { return $true }
                return $certificado.GetCertHashString() -eq $propio.GetCertHashString()
            }.GetNewClosure()
        }
        elseif ($cfg.Autofirmado) {
            # Se acepta cualquier certificado. Vale en red interna de confianza.
            [System.Net.ServicePointManager]::ServerCertificateValidationCallback = { $true }
        }

        if ((Get-Command Invoke-WebRequest).Parameters.ContainsKey('UseBasicParsing')) {
            $PSDefaultParameterValues['Invoke-WebRequest:UseBasicParsing'] = $true
        }

        $response = Invoke-WebRequest -Uri $cfg.Url -Method Post -Body $formBody `
                                      -Headers $headers -TimeoutSec $cfg.TimeoutSec
    }
    catch {
        Write-Error "jsonSQLDB: no se pudo llamar a la API ($($cfg.Url)): $($_.Exception.Message)"
        return $null
    }
    finally {
        [System.Net.ServicePointManager]::ServerCertificateValidationCallback = $originalCallback
        [Net.ServicePointManager]::SecurityProtocol = $originalProtocol
    }

    $jsonString = $response.Content
    if ([string]::IsNullOrWhiteSpace($jsonString) -or -not (Is-ValidJson $jsonString)) {
        Write-Error "jsonSQLDB: respuesta no válida: $jsonString"
        return $null
    }

    $datos = $jsonString | ConvertFrom-Json

    # La API contesta con {"error": "..."} cuando algo falla
    if ($datos.PSObject.Properties.Name -contains 'error') {
        Write-Error "jsonSQLDB: $($datos.error)"
        return $null
    }

    # INSERT/UPDATE/DELETE/DDL devuelven {"success":true,"filas":n,"mensaje":"..."}
    if ($datos.PSObject.Properties.Name -contains 'success') {
        return $datos
    }

    return [System.Collections.ArrayList]@($datos)
}

# ----------------------------------------------------------------------
# Ejemplos
# ----------------------------------------------------------------------

<#
# --- Consultas ---

API-SQL-JSON "SELECT * FROM clientes LIMIT 10"

# Con parámetros: el valor va aparte, nunca dentro de la SQL
API-SQL-JSON "SELECT * FROM clientes WHERE nombre = ?" @("O'Donnell")

# Un valor con SQL dentro se busca literalmente: no altera la consulta
API-SQL-JSON "SELECT * FROM clientes WHERE nombre = ?" @("x'); DROP TABLE clientes; --")

API-SQL-JSON "SELECT cod, nombre, saldo FROM clientes WHERE saldo > ? AND alta >= ? ORDER BY saldo DESC LIMIT ?" `
             @(100.5, '2026-01-01', 20)

# IN de tamaño variable: un ? por elemento
$ciudades = @('Torrevieja', 'Alicante', 'Murcia')
$huecos   = ($ciudades | ForEach-Object { '?' }) -join ','
API-SQL-JSON "SELECT nombre FROM clientes WHERE ciudad IN ($huecos)" $ciudades

# --- Escrituras ---

API-SQL-JSON "INSERT INTO clientes (cod, nombre, saldo) VALUES (?, ?, ?)" @('A1', 'Ana', 10.55)
API-SQL-JSON "UPDATE clientes SET saldo = saldo + ? WHERE id = ?" @(25.40, 7)
API-SQL-JSON "DELETE FROM clientes WHERE id = ?" @(7)

# --- Estructura ---

API-SQL-JSON "SHOW TABLES"
API-SQL-JSON "SHOW SCHEMA clientes"
API-SQL-JSON "SHOW KEYS FROM pedidos"
API-SQL-JSON "SHOW TRIGGERS"

# --- Bases de datos: db va vacío ---

API-SQL-JSON "SHOW DATABASES" -Base ''
API-SQL-JSON "CREATE DATABASE nueva" -Base ''
API-SQL-JSON "DROP DATABASE nueva" -Base ''

# --- Cambiar de base sobre la marcha ---

API-SQL-JSON "SELECT COUNT(*) AS n FROM documentos" -Base 'otrabase'

# --- Volcar a CSV ---

API-SQL-JSON "SELECT * FROM clientes" |
    Export-Csv -Path 'clientes.csv' -NoTypeInformation -Encoding UTF8 -Delimiter ';'
#>

API-SQL-JSON "SHOW TABLES"
