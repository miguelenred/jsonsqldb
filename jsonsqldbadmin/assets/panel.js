/**
 * jsonSQLDBadmin — ajustes de los formularios de columnas.
 *
 * Según el tipo elegido se habilita solo lo que tiene sentido:
 *   TEXT     longitud máxima de caracteres
 *   DECIMAL  número de decimales
 *   INTEGER  AUTOINCREMENT, y solo si además es clave primaria
 *
 * Los campos deshabilitados no se envían, así que el motor aplica su valor
 * por defecto en lugar de recibir un dato que no le corresponde.
 */
(function () {
    'use strict';

    function ajustar(contenedor) {
        if (!contenedor) { return; }
        var tipo = contenedor.querySelector('.tipo-col');
        if (!tipo) { return; }

        var longitud = contenedor.querySelector('.long-col');
        var escala   = contenedor.querySelector('.esc-col');
        var auto     = contenedor.querySelector('.auto-col');
        var pk       = contenedor.querySelector('.pk-col');
        var valor    = tipo.value;

        if (longitud) {
            longitud.disabled = valor !== 'TEXT';
            if (longitud.disabled) { longitud.value = ''; }
        }
        if (escala) {
            escala.disabled = valor !== 'DECIMAL';
            if (escala.disabled) { escala.value = ''; }
        }
        if (auto) {
            var vale = valor === 'INTEGER' && pk !== null && pk.checked;
            auto.disabled = !vale;
            if (!vale) { auto.checked = false; }
        }
    }

    /** Repasa todos los bloques de la página. Se llama también al añadir filas. */
    window.ajustarTipos = function () {
        document.querySelectorAll('.tipo-col').forEach(function (t) {
            ajustar(t.closest('tr, form'));
        });
    };

    document.addEventListener('change', function (e) {
        if (e.target.matches('.tipo-col, .pk-col')) {
            ajustar(e.target.closest('tr, form'));
        }
    });

    document.addEventListener('DOMContentLoaded', window.ajustarTipos);
})();
