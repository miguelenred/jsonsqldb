<?php
declare(strict_types=1);

/**
 * Iconos SVG del panel, dibujados en línea (trazo 1.8, 24x24), sin fuentes
 * ni librerías. Heredan el color del texto.
 *
 *   icono('table')        el <svg> listo para insertar
 *   icono('trash', 'x')   con una clase más
 *
 * Acepta también los nombres de Bootstrap Icons que usaba el panel antes de
 * la 2.7 (hdd-stack, clipboard-check...), para no tener que tocar cada vista.
 *
 * https://miguelenred.es/jsonsqldb
 */
function icono(string $nombre, string $clase = ''): string
{
    static $trazos = [
        'home'       => '<path d="M3 11l9-7 9 7"/><path d="M5 10v10h14V10"/><path d="M10 20v-6h4v6"/>',
        'terminal'   => '<rect x="3" y="4" width="18" height="16" rx="2"/><path d="M7 9l3 3-3 3"/><path d="M13 15h4"/>',
        'upload'     => '<path d="M12 16V4"/><path d="M7 9l5-5 5 5"/><path d="M4 16v3a1 1 0 001 1h14a1 1 0 001-1v-3"/>',
        'download'   => '<path d="M12 4v12"/><path d="M7 11l5 5 5-5"/><path d="M4 16v3a1 1 0 001 1h14a1 1 0 001-1v-3"/>',
        'settings'   => '<circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.7 1.7 0 00.3 1.8l.1.1a2 2 0 11-2.8 2.8l-.1-.1a1.7 1.7 0 00-1.8-.3 1.7 1.7 0 00-1 1.5V21a2 2 0 11-4 0v-.1a1.7 1.7 0 00-1.1-1.6 1.7 1.7 0 00-1.8.3l-.1.1a2 2 0 11-2.8-2.8l.1-.1a1.7 1.7 0 00.3-1.8 1.7 1.7 0 00-1.5-1H3a2 2 0 110-4h.1a1.7 1.7 0 001.6-1.1 1.7 1.7 0 00-.3-1.8l-.1-.1a2 2 0 112.8-2.8l.1.1a1.7 1.7 0 001.8.3H9a1.7 1.7 0 001-1.5V3a2 2 0 114 0v.1a1.7 1.7 0 001 1.5 1.7 1.7 0 001.8-.3l.1-.1a2 2 0 112.8 2.8l-.1.1a1.7 1.7 0 00-.3 1.8V9a1.7 1.7 0 001.5 1H21a2 2 0 110 4h-.1a1.7 1.7 0 00-1.5 1z"/>',
        'table'      => '<rect x="3" y="4" width="18" height="16" rx="2"/><path d="M3 10h18"/><path d="M3 15h18"/><path d="M9 10v10"/>',
        'view'       => '<path d="M2 12s3.5-7 10-7 10 7 10 7-3.5 7-10 7S2 12 2 12z"/><circle cx="12" cy="12" r="3"/>',
        'search'     => '<circle cx="11" cy="11" r="7"/><path d="M20 20l-3.5-3.5"/>',
        'plus'       => '<path d="M12 5v14"/><path d="M5 12h14"/>',
        'edit'       => '<path d="M4 20h4L19 9a2.8 2.8 0 00-4-4L4 16v4z"/><path d="M13.5 6.5l4 4"/>',
        'trash'      => '<path d="M4 7h16"/><path d="M10 11v6"/><path d="M14 11v6"/><path d="M6 7l1 13h10l1-13"/><path d="M9 7V4h6v3"/>',
        'key'        => '<circle cx="8" cy="15" r="4"/><path d="M11 12l9-9"/><path d="M16 7l3 3"/>',
        'link'       => '<path d="M10 14a4 4 0 005.7 0l3-3a4 4 0 00-5.7-5.7l-1 1"/><path d="M14 10a4 4 0 00-5.7 0l-3 3a4 4 0 005.7 5.7l1-1"/>',
        'bolt'       => '<path d="M13 3L4 14h7l-1 7 9-11h-7l1-7z"/>',
        'shield'     => '<path d="M12 3l8 3v6c0 5-3.5 8-8 9-4.5-1-8-4-8-9V6l8-3z"/><path d="M9 12l2 2 4-4"/>',
        'database'   => '<ellipse cx="12" cy="5" rx="8" ry="3"/><path d="M4 5v14c0 1.7 3.6 3 8 3s8-1.3 8-3V5"/><path d="M4 12c0 1.7 3.6 3 8 3s8-1.3 8-3"/>',
        'logout'     => '<path d="M15 4h3a2 2 0 012 2v12a2 2 0 01-2 2h-3"/><path d="M10 17l-5-5 5-5"/><path d="M5 12h11"/>',
        'play'       => '<path d="M7 4l13 8-13 8V4z"/>',
        'columns'    => '<rect x="3" y="4" width="18" height="16" rx="2"/><path d="M9 4v16"/><path d="M15 4v16"/>',
        'menu'       => '<path d="M4 6h16"/><path d="M4 12h16"/><path d="M4 18h16"/>',
        'x'          => '<path d="M6 6l12 12"/><path d="M18 6L6 18"/>',
        'sun'        => '<circle cx="12" cy="12" r="4"/><path d="M12 2v2M12 20v2M4.9 4.9l1.4 1.4M17.7 17.7l1.4 1.4M2 12h2M20 12h2M4.9 19.1l1.4-1.4M17.7 6.3l1.4-1.4"/>',
        'moon'       => '<path d="M20 14.5A8 8 0 019.5 4 8 8 0 1020 14.5z"/>',
        'wrench'     => '<path d="M14.7 6.3a4 4 0 00-5.4 5.4L3 18l3 3 6.3-6.3a4 4 0 005.4-5.4l-2.6 2.6-2.4-.6-.6-2.4 2.6-2.6z"/>',
        'check'      => '<path d="M5 12l5 5 9-10"/>',
        'history'    => '<path d="M3 12a9 9 0 103-6.7"/><path d="M3 4v4h4"/><path d="M12 8v4l3 2"/>',
        'layers'     => '<path d="M12 3l9 5-9 5-9-5 9-5z"/><path d="M3 13l9 5 9-5"/>',
        'info'       => '<circle cx="12" cy="12" r="9"/><path d="M12 11v5"/><path d="M12 8h.01"/>',
        // Iconos específicos del panel, en el mismo trazo
        'users'      => '<path d="M16 20v-1a4 4 0 00-4-4H6a4 4 0 00-4 4v1"/><circle cx="9" cy="8" r="4"/><path d="M22 20v-1a4 4 0 00-3-3.9"/><path d="M16 4.1a4 4 0 010 7.8"/>',
        'user'       => '<path d="M20 21v-1a6 6 0 00-6-6h-4a6 6 0 00-6 6v1"/><circle cx="12" cy="7" r="4"/>',
        'user-plus'  => '<path d="M15 21v-1a5 5 0 00-5-5H6a5 5 0 00-5 5v1"/><circle cx="8" cy="7" r="4"/><path d="M19 8v6"/><path d="M22 11h-6"/>',
        'server'     => '<rect x="3" y="4" width="18" height="7" rx="2"/><rect x="3" y="13" width="18" height="7" rx="2"/><path d="M7 7.5h.01"/><path d="M7 16.5h.01"/>',
        'alert'      => '<path d="M10.3 3.9L1.8 18a2 2 0 001.7 3h17a2 2 0 001.7-3L13.7 3.9a2 2 0 00-3.4 0z"/><path d="M12 9v4"/><path d="M12 17h.01"/>',
        'stop'       => '<path d="M7.9 2h8.2L22 7.9v8.2L16.1 22H7.9L2 16.1V7.9z"/><path d="M15 9l-6 6"/><path d="M9 9l6 6"/>',
        'list'       => '<path d="M9 6h11"/><path d="M9 12h11"/><path d="M9 18h11"/><path d="M4 6h.01"/><path d="M4 12h.01"/><path d="M4 18h.01"/>',
        'file'       => '<path d="M14 3H6a2 2 0 00-2 2v14a2 2 0 002 2h12a2 2 0 002-2V9z"/><path d="M14 3v6h6"/>',
        'archive'    => '<rect x="3" y="4" width="18" height="4" rx="1"/><path d="M5 8v11a1 1 0 001 1h12a1 1 0 001-1V8"/><path d="M10 12h4"/>',
        'eraser'     => '<path d="M20 20H9"/><path d="M4.6 15.4l9.8-9.8a2 2 0 012.8 0l2.2 2.2a2 2 0 010 2.8L12 18H7.2z"/><path d="M9 11l5 5"/>',
        'refresh'    => '<path d="M21 12a9 9 0 11-3-6.7"/><path d="M21 4v5h-5"/>',
        'caret-up'   => '<path d="M7 14l5-5 5 5"/>',
        'caret-down' => '<path d="M7 10l5 5 5-5"/>',
        'plug'       => '<path d="M9 3v5"/><path d="M15 3v5"/><path d="M6 8h12v3a6 6 0 01-12 0V8z"/><path d="M12 17v4"/>',
        'folder'     => '<path d="M3 7a2 2 0 012-2h4l2 2h8a2 2 0 012 2v8a2 2 0 01-2 2H5a2 2 0 01-2-2V7z"/>',
    ];
    static $alias = [
        'hdd-stack' => 'server', 'plus-circle' => 'plus', 'plus-lg' => 'plus', 'check2' => 'check',
        'check2-circle' => 'check', 'eye' => 'view', 'x-octagon' => 'stop', 'x-lg' => 'x',
        'shield-check' => 'shield', 'people' => 'users', 'pencil' => 'edit', 'exclamation-triangle' => 'alert',
        'clipboard-check' => 'history', 'play-fill' => 'play', 'person-plus' => 'user-plus',
        'person-circle' => 'user', 'list-ul' => 'list', 'lightning' => 'bolt',
        'layout-three-columns' => 'columns', 'diagram-3' => 'columns', 'info-circle' => 'info',
        'gear' => 'settings', 'filetype-sql' => 'file', 'filetype-csv' => 'file', 'file-zip' => 'archive',
        'box-arrow-right' => 'logout', 'arrow-clockwise' => 'refresh',
    ];
    $trazo = $trazos[$alias[$nombre] ?? $nombre] ?? $trazos['info'];
    return '<svg class="ic' . ($clase !== '' ? ' ' . $clase : '') . '" viewBox="0 0 24 24" fill="none" '
         . 'stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" '
         . 'aria-hidden="true">' . $trazo . '</svg>';
}
