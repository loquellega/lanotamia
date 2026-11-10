<?php
/*
 * LaNotaMia · configuración de ejemplo
 * Copia este archivo como config.php y cambia el código de instalación.
 * Parte de la colección Alchemy of Meaning (Alquimia del Significado).
 * © 2026 Héctor Cruz Juárez · GPL-3.0-or-later
 */

// Carpeta donde viven las notas, la contraseña (cifrada) y las sesiones.
// Lo más seguro es ponerla FUERA de la carpeta pública del sitio, por ejemplo:
//   define('LNM_DATA', '/home/usuario/lanotamia-datos');
// Si la dejas aquí, el .htaccess que se crea solo bloquea el acceso en Apache.
// En nginx hay que bloquearla a mano (ver LEEME.txt).
define('LNM_DATA', __DIR__ . '/data');

// Días que dura una sesión abierta en un equipo antes de pedir la contraseña otra vez.
define('LNM_DIAS_SESION', 180);

// Días que una nota pasa en la papelera antes de borrarse para siempre.
define('LNM_DIAS_PAPELERA', 30);

// Archivos adjuntos: tamaño máximo de cada uno, días que se conservan y espacio total.
// Pasado ese plazo el archivo se borra solo; la nota (el texto) se queda.
define('LNM_MB_ARCHIVO', 20);
define('LNM_DIAS_ARCHIVOS', 30);
define('LNM_MB_TOTAL', 1000);

// Código de instalación: se pide UNA sola vez, al crear la contraseña la primera vez.
// Así nadie más puede apropiarse de LaNotaMia si llega antes que tú a la dirección.
// Escribe aquí uno propio antes de subir (por ejemplo tres grupos de 4 letras y números).
// Si lo dejas vacío (''), no se pide; no lo recomiendo.
define('LNM_CODIGO_INSTALACION', 'CAMBIA-ESTE-CODIGO');
