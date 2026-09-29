/**
 * jsonSQLDBadmin — comportamiento de la interfaz, sin más librería que Bootstrap.
 *
 *  - Barra lateral como cajón en pantallas pequeñas, filtro de tablas y tema
 *    claro u oscuro recordado en este navegador.
 *  - Formularios con data-confirm que preguntan antes de enviarse.
 *  - Ctrl/Cmd+Enter envía el formulario del editor SQL.
 *  - Asistente de instalación: marca la opción de conexión elegida.
 *  - Formularios de columnas, que se explican a continuación.
 *
 * https://miguelenred.es/jsonsqldb
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

(function () {
    'use strict';
    // Los formularios con data-confirm preguntan antes de enviarse (el texto es
    // un atributo escapado, nunca código)
    document.addEventListener('submit', function (e) {
        var msg = e.target.getAttribute('data-confirm');
        if (msg && !window.confirm(msg)) { e.preventDefault(); }
    });
    // Y los botones con data-confirm-click, antes de hacer lo suyo
    document.addEventListener('click', function (e) {
        var b = e.target.closest ? e.target.closest('[data-confirm-click]') : null;
        if (b && !window.confirm(b.getAttribute('data-confirm-click'))) { e.preventDefault(); e.stopPropagation(); }
    }, true);
    // Barra lateral como cajón en pantallas pequeñas
    var side = document.getElementById('sidebar'), back = document.getElementById('sidebarBackdrop'),
        tog = document.getElementById('sidebarToggle');
    function cajon(abierto) {
        if (side) { side.classList.toggle('open', abierto); }
        if (back) { back.classList.toggle('open', abierto); }
    }
    if (tog) { tog.addEventListener('click', function () { cajon(!side.classList.contains('open')); }); }
    if (back) { back.addEventListener('click', function () { cajon(false); }); }
    // Filtro de tablas de la barra lateral
    var filtro = document.getElementById('tableFilter');
    if (filtro) {
        filtro.addEventListener('input', function () {
            var q = filtro.value.trim().toLowerCase();
            document.querySelectorAll('#sideTables .side-table').forEach(function (a) {
                a.style.display = !q || a.getAttribute('data-name').indexOf(q) !== -1 ? '' : 'none';
            });
        });
    }
    // Tema claro u oscuro, recordado en este navegador
    var tema = document.getElementById('themeToggle');
    if (tema) {
        tema.addEventListener('click', function () {
            var t = document.documentElement.getAttribute('data-bs-theme') === 'dark' ? 'light' : 'dark';
            document.documentElement.setAttribute('data-bs-theme', t);
            try { localStorage.setItem('jsa-theme', t); } catch (e) { /* modo privado */ }
        });
    }
    // Ctrl/Cmd+Enter envía el formulario del textarea que tiene el foco
    document.addEventListener('keydown', function (e) {
        if ((e.ctrlKey || e.metaKey) && e.key === 'Enter' && e.target.tagName === 'TEXTAREA' && e.target.form) {
            e.preventDefault();
            if (e.target.form.requestSubmit) { e.target.form.requestSubmit(); } else { e.target.form.submit(); }
        }
    });
    // Asistente: la opción de conexión elegida se marca, y su bloque se despliega
    var opciones = document.querySelectorAll('.setup-option');
    function marcar() {
        opciones.forEach(function (el) { el.classList.toggle('sel', el.querySelector('input[type=radio]').checked); });
    }
    opciones.forEach(function (el) {
        var radio = el.querySelector('input[type=radio]');
        radio.addEventListener('change', marcar);
        // Toda la tarjeta elige la opción, salvo sus propios campos
        el.addEventListener('click', function (e) {
            if (!radio.checked && !e.target.closest('.extra')) { radio.checked = true; marcar(); }
        });
    });
    marcar();
})();
