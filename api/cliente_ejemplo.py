"""
jsonSQLDB - Cliente de ejemplo en Python.

Copia este fichero en la aplicación que vaya a consumir la API. Solo usa la
biblioteca estándar: sin pip, sin requests, sin dependencias.

    from cliente_ejemplo import JsonSqlDbCliente

    cli = JsonSqlDbCliente(
        "https://miservidor/jsonsqldb/api/jsonsqldb_api.php",
        "MI_API_KEY", "EL_HMAC_SECRET_DE_ESA_KEY", "mibase")

    filas = cli.consultar("SELECT * FROM clientes WHERE ciudad = ?", ["Madrid"])

EL SECRETO ES EL DE LA CLAVE: el campo 'hmac_secret' de esa misma entrada de
$API_KEYS en api/jsonsqldb_api_config.php. Cada cuenta tiene el suyo, distinto
del de las demás.

Los valores NO se concatenan a la SQL: se ponen ? y los valores van aparte. El
servidor los inserta ya analizados en el árbol de la consulta, así que un valor
no puede alterarla por mucho SQL que contenga.

Requiere Python 3.7 o superior.
"""

from __future__ import annotations

import hashlib
import hmac
import json
import ssl
import time
import urllib.error
import urllib.parse
import urllib.request
from typing import Any, Dict, List, Optional, Sequence, Union

__all__ = ["JsonSqlDbCliente", "JsonSqlDbError"]

Fila = Dict[str, Any]
Resultado = Union[List[Fila], Dict[str, Any]]


class JsonSqlDbError(RuntimeError):
    """Error devuelto por la API, o fallo al llamarla."""


class JsonSqlDbCliente:
    def __init__(
        self,
        url: str,
        api_key: str,
        hmac_secret: str,
        base: str,
        timeout: int = 60,
    ) -> None:
        self.url = url
        self.api_key = api_key
        self.hmac_secret = hmac_secret   # el 'hmac_secret' de esta API key
        self.base = base
        self.timeout = timeout
        self._ca: Optional[str] = None
        self._autofirmado = False

    # ------------------------------------------------------------------
    # Certificado del servidor
    # ------------------------------------------------------------------

    def certificado(self, ruta_crt: str) -> "JsonSqlDbCliente":
        """
        Certificado del servidor cuando es propio o de una CA interna.
        Se sigue verificando, pero contra este fichero .crt / .pem.
        """
        self._ca = ruta_crt
        return self

    def aceptar_autofirmado(self, si: bool = True) -> "JsonSqlDbCliente":
        """
        Aceptar el certificado sin comprobarlo. Vale en una red interna de
        confianza, pero deja de protegerte frente a un intermediario: es peor
        que certificado().
        """
        self._autofirmado = si
        return self

    # ------------------------------------------------------------------
    # Consulta
    # ------------------------------------------------------------------

    def consultar(self, sql: str, parametros: Sequence[Any] = ()) -> Resultado:
        """
        Ejecuta una sentencia.

        Devuelve la lista de filas en SELECT y SHOW, o un diccionario
        {'success': True, 'filas': n, 'mensaje': '...'} en las escrituras.

        Valores admitidos en los parámetros: None, bool, int, float y str.
        """
        params = ""
        if parametros:
            # separators sin espacios: el servidor firma el texto EXACTO que
            # recibe, así que lo que se firma y lo que se envía deben coincidir
            params = json.dumps(
                list(parametros), ensure_ascii=False, separators=(",", ":")
            )

        timestamp = str(int(time.time()))
        mensaje = f"+{self.api_key}|{timestamp}|{sql}{params}\u00bf"
        token = hmac.new(
            self.hmac_secret.encode("utf-8"),
            mensaje.encode("utf-8"),
            hashlib.sha256,
        ).hexdigest()

        cuerpo = urllib.parse.urlencode(
            {
                "api_key": self.api_key,
                "db": self.base,
                "sql": sql,
                "params": params,
                "timestamp": timestamp,
                "token": token,
            }
        ).encode("utf-8")

        datos = self._enviar(cuerpo)

        if isinstance(datos, dict) and "error" in datos:
            raise JsonSqlDbError(str(datos["error"]))
        return datos

    def valor(self, sql: str, parametros: Sequence[Any] = ()) -> Any:
        """Primer valor de la primera fila, o None si no hay filas."""
        filas = self.consultar(sql, parametros)
        if isinstance(filas, list) and filas:
            return next(iter(filas[0].values()), None)
        return None

    def bases(self) -> List[str]:
        """Lista de bases de datos. Necesita una API key con acceso a todas."""
        anterior, self.base = self.base, ""
        try:
            filas = self.consultar("SHOW DATABASES")
        finally:
            self.base = anterior
        return [f["base"] for f in filas] if isinstance(filas, list) else []

    # ------------------------------------------------------------------
    # Interno
    # ------------------------------------------------------------------

    def _enviar(self, cuerpo: bytes) -> Resultado:
        peticion = urllib.request.Request(
            self.url,
            data=cuerpo,
            headers={"Content-Type": "application/x-www-form-urlencoded"},
            method="POST",
        )

        contexto: Optional[ssl.SSLContext] = None
        if self.url.lower().startswith("https://"):
            if self._ca:
                contexto = ssl.create_default_context(cafile=self._ca)
            elif self._autofirmado:
                contexto = ssl.create_default_context()
                contexto.check_hostname = False
                contexto.verify_mode = ssl.CERT_NONE

        try:
            with urllib.request.urlopen(
                peticion, timeout=self.timeout, context=contexto
            ) as respuesta:
                texto = respuesta.read().decode("utf-8")
        except urllib.error.HTTPError as e:
            # La API responde con JSON incluso en los códigos de error
            texto = e.read().decode("utf-8", errors="replace")
        except (urllib.error.URLError, OSError) as e:
            raise JsonSqlDbError(f"No se pudo llamar a la API ({self.url}): {e}") from e

        try:
            return json.loads(texto)
        except json.JSONDecodeError as e:
            raise JsonSqlDbError(
                f"Respuesta no válida de la API: {texto[:300]}"
            ) from e


# ----------------------------------------------------------------------
# Ejemplos
# ----------------------------------------------------------------------

if __name__ == "__main__":
    cli = JsonSqlDbCliente(
        "https://example.com/jsonsqldb/api/jsonsqldb_api.php",
        "CHANGE_ME_EXAMPLE_API_KEY",
        "CHANGE_ME_EXAMPLE_SECRET",
        "pruebas",
    )

    # Certificado propio o autofirmado, si hace falta:
    # cli.certificado("C:/xampp/apache/conf/ssl.crt/server.crt")
    # cli.aceptar_autofirmado()

    # --- Consultas ---
    print(cli.consultar("SHOW TABLES"))
    print(cli.consultar("SELECT * FROM clientes LIMIT 10"))

    # Con parámetros: el valor viaja aparte, nunca dentro de la SQL
    print(cli.consultar("SELECT * FROM clientes WHERE nombre = ?", ["O'Donnell"]))

    # Un valor con SQL dentro se busca literalmente: no altera la consulta
    print(
        cli.consultar(
            "SELECT * FROM clientes WHERE nombre = ?",
            ["x'); DROP TABLE clientes; --"],
        )
    )

    # IN de tamaño variable: un ? por elemento
    ciudades = ["Torrevieja", "Alicante", "Murcia"]
    huecos = ",".join("?" for _ in ciudades)
    print(cli.consultar(f"SELECT nombre FROM clientes WHERE ciudad IN ({huecos})", ciudades))

    # --- Escrituras ---
    # cli.consultar("INSERT INTO clientes (cod, nombre, saldo) VALUES (?, ?, ?)",
    #               ["A1", "Ana", 10.55])
    # cli.consultar("UPDATE clientes SET saldo = saldo + ? WHERE id = ?", [25.40, 7])
    # cli.consultar("DELETE FROM clientes WHERE id = ?", [7])

    # --- Bases de datos: db vacío ---
    # print(cli.bases())

    # --- Volcar a CSV ---
    # import csv
    # filas = cli.consultar("SELECT * FROM clientes")
    # if filas:
    #     with open("clientes.csv", "w", newline="", encoding="utf-8-sig") as f:
    #         w = csv.DictWriter(f, fieldnames=list(filas[0].keys()), delimiter=";")
    #         w.writeheader()
    #         w.writerows(filas)
