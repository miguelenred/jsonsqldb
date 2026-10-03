/**
 * jsonSQLDBadmin — comportamiento de la interfaz, sin más librería que Bootstrap.
 *
 *  - Barra lateral como cajón en pantallas pequeñas, filtro de tablas y tema
 *    claro u oscuro recordado en este navegador.
 *  - Formularios con data-confirm que preguntan antes de enviarse.
 *  - Ctrl/Cmd+Enter envía el formulario del editor SQL.
 *  - Asistente de instalación: marca la opción de conexión elegida.
 *  - Importar un fichero mayor que el límite de subida de PHP, por trozos.
 *  - Copias programadas pendientes, pedidas aparte cuando no hay cron.
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

// Un desplegable que envía su formulario al cambiar (sin onchange en el HTML:
// la CSP no admite código en atributos)
document.querySelectorAll('[data-enviar-al-cambiar]').forEach(function (el) {
    el.addEventListener('change', function () { el.form.submit(); });
});

// La guía de volcados se abre en la pestaña que pida el botón (data-pestana)
document.querySelectorAll('#guiaVolcados').forEach(function (modal) {
    modal.addEventListener('show.bs.modal', function (ev) {
        var pestana = ev.relatedTarget && ev.relatedTarget.getAttribute('data-pestana');
        var boton = document.getElementById('pestana-' + (pestana || 'sqlite'));
        if (boton && window.bootstrap) { bootstrap.Tab.getOrCreateInstance(boton).show(); }
    });
});

// Importar un fichero mayor que el límite de subida de PHP: se envía por
// trozos (data-trozo bytes) a la acción subir_trozo, que los junta en el
// servidor, y después se envía el formulario sin el fichero, con lo que
// identifica la subida. El identificador sale del nombre, el tamaño y la
// fecha del fichero: si la subida se corta, al volver a elegir el mismo
// fichero sigue desde lo que ya había llegado.
document.querySelectorAll('form[data-trozo]').forEach(function (form) {
    form.addEventListener('submit', function (e) {
        var input = form.querySelector('input[type=file]');
        var f = input && input.files && input.files[0];
        var trozo = parseInt(form.getAttribute('data-trozo'), 10);
        if (e.defaultPrevented || !f || !(trozo > 0) || f.size <= trozo || !window.fetch) { return; }
        e.preventDefault();

        var boton = form.querySelector('button:not([type=button])');
        var barra = document.createElement('div');
        barra.className = 'progress mt-2 w-100';
        barra.innerHTML = '<div class="progress-bar" role="progressbar" style="width:0%"></div>';
        form.appendChild(barra);
        var texto = barra.firstChild;
        if (boton) { boton.disabled = true; }

        function peticion(desde, blob) {
            var datos = new FormData();
            datos.append('csrf', form.querySelector('input[name=csrf]').value);
            datos.append('accion', 'subir_trozo');
            datos.append('id', id);
            datos.append('desde', String(desde));
            if (blob) { datos.append('trozo', blob, 'trozo'); }
            return fetch(form.getAttribute('action') || window.location.href, { method: 'POST', body: datos, credentials: 'same-origin' })
                .then(function (r) { return r.json(); })
                .then(function (j) { if (j.error) { throw new Error(j.error); } return j.recibido; });
        }
        function avance(n) {
            var p = Math.floor(n * 100 / f.size);
            texto.style.width = p + '%';
            texto.textContent = form.getAttribute('data-texto-subiendo').replace('{p}', p);
        }
        function fallo(err) {
            barra.remove();
            if (boton) { boton.disabled = false; }
            window.alert(form.getAttribute('data-texto-error') + (err && err.message ? '\n\n' + err.message : ''));
        }
        var intentos = 0;
        function seguir(recibido) {
            avance(recibido);
            if (recibido >= f.size) {
                [['subida', id], ['subida_tamano', String(f.size)], ['subida_nombre', f.name]].forEach(function (c) {
                    var h = document.createElement('input');
                    h.type = 'hidden'; h.name = c[0]; h.value = c[1];
                    form.appendChild(h);
                });
                input.disabled = true;                 // el fichero ya está en el servidor
                form.submit();
                return;
            }
            peticion(recibido, f.slice(recibido, Math.min(f.size, recibido + trozo)))
                .then(function (n) { intentos = 0; seguir(n); })
                .catch(function (err) {
                    // Un corte de la red: unos reintentos, preguntando antes qué llegó
                    if (++intentos > 5) { fallo(err); return; }
                    setTimeout(function () { peticion(-1, null).then(seguir).catch(fallo); }, 1000 * intentos);
                });
        }
        var id;
        var clave = f.name + '|' + f.size + '|' + f.lastModified;
        var hash = window.crypto && window.crypto.subtle
            ? window.crypto.subtle.digest('SHA-256', new TextEncoder().encode(clave)).then(function (b) {
                return Array.prototype.map.call(new Uint8Array(b), function (x) { return ('0' + x.toString(16)).slice(-2); }).join('').slice(0, 32);
            })
            : Promise.resolve(Array.prototype.map.call(window.crypto.getRandomValues(new Uint8Array(16)),
                function (x) { return ('0' + x.toString(16)).slice(-2); }).join(''));
        hash.then(function (h) { id = h; return peticion(-1, null); }).then(seguir).catch(fallo);
    });
});

// Copias programadas sin cron: si la página avisa de que hay alguna pendiente
// (data-copias, con el token del formulario), se pide aparte y no se espera la
// respuesta; el servidor la termina aunque se cierre la página
(function () {
    var token = document.body.getAttribute('data-copias');
    if (!token || !window.fetch || !window.FormData) { return; }
    var datos = new FormData();
    datos.append('csrf', token);
    datos.append('accion', 'ejecutar_copias');
    fetch(window.location.pathname, { method: 'POST', body: datos, credentials: 'same-origin', keepalive: true })
        .catch(function () { /* la próxima visita lo vuelve a intentar */ });
})();
