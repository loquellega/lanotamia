<?php
/*
 * LaNotaMia 0.5.1 · servidor de notas
 * Parte de la colección Alchemy of Meaning (Alquimia del Significado).
 * © 2026 Héctor Cruz Juárez · https://hectorcruz.com.mx
 * Software libre bajo licencia GPL-3.0-or-later.
 *
 * Un solo archivo PHP, sin base de datos: cada nota es un .json en LNM_DATA/notas.
 * Cada escritura recibe un número de revisión creciente (srv); los equipos piden
 * «lo que cambió desde la revisión N» y así se mantienen al día.
 */
declare(strict_types=1);
if (!is_file(__DIR__ . '/config.php')) {
    header('Content-Type: application/json; charset=utf-8'); http_response_code(500);
    echo json_encode(['error' => 'Falta config.php: copia config.example.php como config.php y pon tu código de instalación.'], JSON_UNESCAPED_UNICODE);
    exit;
}
require __DIR__ . '/config.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: no-referrer');

const LNM_COOKIE = 'lnm_sesion';
$D = rtrim(LNM_DATA, '/');

// ---------- utilidades ----------
function fin(int $codigo, array $datos): void {
    http_response_code($codigo);
    echo json_encode($datos, JSON_UNESCAPED_UNICODE);
    exit;
}
function leer(string $f, $def = null) {
    if (!is_file($f)) return $def;
    $t = @file_get_contents($f);
    if ($t === false || $t === '') return $def;
    $j = json_decode($t, true);
    return $j === null ? $def : $j;
}
function escribir(string $f, $datos): void {
    $tmp = $f . '.' . bin2hex(random_bytes(4)) . '.tmp';
    if (@file_put_contents($tmp, json_encode($datos, JSON_UNESCAPED_UNICODE), LOCK_EX) === false) {
        fin(500, ['error' => 'No se pudo escribir en la carpeta de datos. Revisa permisos.']);
    }
    @chmod($tmp, 0600);
    rename($tmp, $f);
}
function ahora(): int { return (int) floor(microtime(true) * 1000); }
function ip(): string { return $_SERVER['REMOTE_ADDR'] ?? '0'; }
function https(): bool {
    return (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
}

// ---------- carpeta de datos ----------
if ((!is_dir($D . '/notas') && !@mkdir($D . '/notas', 0700, true)) || (!is_dir($D . '/archivos') && !@mkdir($D . '/archivos', 0700, true))) {
    fin(500, ['error' => 'No se pudo crear la carpeta de datos (' . basename($D) . '). Revisa permisos.']);
}
if (!is_file($D . '/.htaccess')) {
    @file_put_contents($D . '/.htaccess',
        "<IfModule mod_authz_core.c>\nRequire all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\nDeny from all\n</IfModule>\n");
}
if (!is_file($D . '/index.html')) @file_put_contents($D . '/index.html', '');

// ---------- revisión global (bloqueo exclusivo mientras se escribe) ----------
$candado = fopen($D . '/rev.lock', 'c+');
function bloquear() { global $candado; flock($candado, LOCK_EX); }
function soltar() { global $candado; flock($candado, LOCK_UN); }
function revActual(): int { global $D; return (int) (leer($D . '/rev.json', ['rev' => 0])['rev'] ?? 0); }
function revSiguiente(): int {
    global $D;
    $r = revActual() + 1;
    escribir($D . '/rev.json', ['rev' => $r]);
    return $r;
}

// ---------- sesiones ----------
function sesiones(): array { global $D; return leer($D . '/sesiones.json', []); }
function guardarSesiones(array $s): void { global $D; escribir($D . '/sesiones.json', $s); }
function rutaCookie(): string {
    $p = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/')), '/');
    return $p === '' ? '/' : $p . '/';
}
function abrirSesion(): void {
    $token = bin2hex(random_bytes(32));
    $exp = time() + LNM_DIAS_SESION * 86400;
    $s = array_filter(sesiones(), fn($v) => ($v['exp'] ?? 0) > time());
    $s[hash('sha256', $token)] = ['exp' => $exp, 'creada' => time(), 'visto' => time(), 'ua' => substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 300)];
    guardarSesiones($s);
    setcookie(LNM_COOKIE, $token, [
        'expires' => $exp, 'path' => rutaCookie(), 'secure' => https(),
        'httponly' => true, 'samesite' => 'Strict',
    ]);
}
function claveSesion(): string {
    $t = $_COOKIE[LNM_COOKIE] ?? '';
    return (is_string($t) && strlen($t) === 64) ? hash('sha256', $t) : '';
}
function sesionValida(): bool {
    $k = claveSesion();
    if ($k === '') return false;
    $todas = sesiones();
    $s = $todas[$k] ?? null;
    if (!$s || ($s['exp'] ?? 0) <= time()) return false;
    // «último uso», para la lista de sesiones abiertas (se anota cada 10 min como máximo)
    if (($s['visto'] ?? 0) < time() - 600) {
        $todas[$k]['visto'] = time();
        $todas[$k]['ua'] = substr($_SERVER['HTTP_USER_AGENT'] ?? ($s['ua'] ?? ''), 0, 300);
        guardarSesiones($todas);
    }
    return true;
}
function cerrarSesion(): void {
    $t = $_COOKIE[LNM_COOKIE] ?? '';
    if (is_string($t) && $t !== '') {
        $s = sesiones(); unset($s[hash('sha256', $t)]); guardarSesiones($s);
    }
    setcookie(LNM_COOKIE, '', ['expires' => 1, 'path' => rutaCookie(), 'secure' => https(), 'httponly' => true, 'samesite' => 'Strict']);
}

// ---------- freno a intentos fallidos ----------
function esperaPendiente(): int {
    global $D;
    $i = leer($D . '/intentos.json', [])[ip()] ?? null;
    return $i ? max(0, ($i['hasta'] ?? 0) - time()) : 0;
}
function registrarFallo(): void {
    global $D;
    $todo = leer($D . '/intentos.json', []);
    $i = $todo[ip()] ?? ['n' => 0, 'hasta' => 0];
    $i['n']++;
    if ($i['n'] >= 5) $i['hasta'] = time() + min(3600, 30 * (2 ** ($i['n'] - 5)));
    $todo[ip()] = $i;
    escribir($D . '/intentos.json', $todo);
}
function limpiarFallos(): void {
    global $D;
    $todo = leer($D . '/intentos.json', []);
    unset($todo[ip()]);
    escribir($D . '/intentos.json', $todo);
}

// ---------- notas ----------
function idValido($id): bool { return is_string($id) && preg_match('/^[a-z0-9-]{8,40}$/', $id) === 1; }
function rutaNota(string $id): string { global $D; return $D . '/notas/' . $id . '.json'; }
function nuevoId(): string { return 'n' . base_convert((string) ahora(), 10, 36) . '-' . bin2hex(random_bytes(4)); }
function limpiarTexto($t): string {
    $t = is_string($t) ? $t : '';
    if (strlen($t) > 2000000) fin(413, ['error' => 'La nota es demasiado grande (máx. 2 MB).']);
    return $t;
}
function limpiarCarpeta($c): string {
    $c = is_string($c) ? trim($c) : '';
    return mb_substr($c, 0, 80);
}

// ---------- archivos adjuntos ----------
// Cada archivo vive en NM_DATA/archivos/<id>.bin con su ficha <id>.json.
// Se sube por partes de 1 MB para no chocar con los límites de PHP del hosting.
const LNM_PARTE = 1048576;
function rutaArchivo(string $id, string $ext = 'json'): string { global $D; return $D . '/archivos/' . $id . '.' . $ext; }
function maxArchivo(): int { return (defined('LNM_MB_ARCHIVO') ? LNM_MB_ARCHIVO : 20) * 1048576; }
function diasArchivos(): int { return defined('LNM_DIAS_ARCHIVOS') ? LNM_DIAS_ARCHIVOS : 30; }
function limpiarNombre($n): string {
    $n = is_string($n) ? $n : '';
    $n = preg_replace('/[\x00-\x1f\x7f\/\\\\]+/u', '', $n);
    $n = trim($n);
    return $n === '' ? 'archivo' : mb_substr($n, 0, 160);
}
function fichaPublica(array $f): array {
    return array_intersect_key($f, array_flip(['id', 'nota', 'nombre', 'tam', 'tipo', 'subido', 'expira', 'srv', 'borrado', 'ref']));
}
function espacioUsado(): int {
    $t = 0;
    foreach (glob(rutaArchivo('*', 'json')) ?: [] as $f) {
        $a = leer($f);
        if ($a && empty($a['borrado'])) $t += (int) ($a['tam'] ?? 0);
    }
    return $t;
}
function borrarArchivo(string $id): array {
    @unlink(rutaArchivo($id, 'bin'));
    $ficha = ['id' => $id, 'borrado' => true, 'srv' => revSiguiente()];
    escribir(rutaArchivo($id), $ficha);
    return $ficha;
}

// Papelera: lo que lleva más de LNM_DIAS_PAPELERA días se vuelve lápida (solo id y srv),
// para que los demás equipos sepan que deben olvidarla.
function vaciarPapeleraVieja(): void {
    global $D;
    $limite = ahora() - LNM_DIAS_PAPELERA * 86400000;
    $marca = $D . '/ultima-limpieza.json';
    if ((leer($marca, ['t' => 0])['t'] ?? 0) > time() - 3600) return;
    foreach (glob($D . '/notas/*.json') ?: [] as $f) {
        $n = leer($f);
        if ($n && !empty($n['borrada']) && empty($n['purgada']) && ($n['borradaEn'] ?? ahora()) < $limite) {
            escribir($f, ['id' => $n['id'], 'purgada' => true, 'srv' => revSiguiente()]);
        }
    }
    // archivos vencidos y subidas que quedaron a medias
    foreach (glob(rutaArchivo('*', 'json')) ?: [] as $f) {
        $a = leer($f);
        if (!$a || !empty($a['borrado'])) continue;
        if (empty($a['completo']) && ($a['inicio'] ?? 0) < ahora() - 86400000) {
            @unlink(rutaArchivo($a['id'], 'bin')); @unlink($f);
        } elseif (!empty($a['completo']) && ($a['expira'] ?? PHP_INT_MAX) < ahora()) {
            borrarArchivo($a['id']);
        }
    }
    escribir($marca, ['t' => time()]);
}

// ---------- entrada ----------
$accion = $_GET['a'] ?? '';
$metodo = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$cuerpo = [];
if ($metodo === 'POST') {
    // Un encabezado propio obliga a los navegadores ajenos a pedir permiso (CORS) antes de enviar:
    // así otra página no puede escribir en tus notas aprovechando tu sesión.
    if (($_SERVER['HTTP_X_LANOTAMIA'] ?? '') !== '1') fin(403, ['error' => 'Petición no válida.']);
    if (($_GET['a'] ?? '') !== 'subir-parte') {
        $raw = file_get_contents('php://input', false, null, 0, 8000000);
        $cuerpo = json_decode($raw ?: '{}', true) ?: [];
    }
}
$hash = leer($D . '/clave.json', [])['hash'] ?? '';

// --- acciones sin sesión ---
if ($accion === 'estado') {
    $codigo = defined('LNM_CODIGO_INSTALACION') ? (string) LNM_CODIGO_INSTALACION : '';
    fin(200, ['configurada' => $hash !== '', 'sesion' => $hash !== '' && sesionValida(),
              'pideCodigo' => $hash === '' && $codigo !== '', 'version' => '0.5.1']);
}

if ($accion === 'crear' && $metodo === 'POST') {
    if ($hash !== '') fin(409, ['error' => 'La contraseña ya existe.']);
    $codigo = defined('LNM_CODIGO_INSTALACION') ? (string) LNM_CODIGO_INSTALACION : '';
    if ($codigo !== '') {
        $espera = esperaPendiente();
        if ($espera > 0) fin(429, ['error' => "Demasiados intentos. Espera {$espera} s.", 'espera' => $espera]);
        if (!hash_equals(strtolower($codigo), strtolower(trim((string) ($cuerpo['codigo'] ?? ''))))) {
            registrarFallo(); usleep(400000);
            fin(403, ['error' => 'El código de instalación no coincide. Está en config.php.']);
        }
        limpiarFallos();
    }
    $clave = (string) ($cuerpo['clave'] ?? '');
    if (mb_strlen($clave) < 8) fin(400, ['error' => 'Usa al menos 8 caracteres.']);
    bloquear();
    if ((leer($D . '/clave.json', [])['hash'] ?? '') !== '') { soltar(); fin(409, ['error' => 'La contraseña ya existe.']); }
    escribir($D . '/clave.json', ['hash' => password_hash($clave, PASSWORD_DEFAULT), 'creada' => time()]);
    // nota de bienvenida
    $id = nuevoId();
    escribir(rutaNota($id), [
        'id' => $id, 'carpeta' => '', 'fijada' => true, 'borrada' => false,
        'texto' => "Bienvenido a LaNotaMia\n\nEl título se toma solo de las primeras palabras.\n\nTodo se guarda solo y se sincroniza con tus otros equipos. Abre esta misma dirección en el teléfono o en otra computadora, entra con tu contraseña y verás las mismas notas.\n\nAtajos (computadora)\nAlt + N  nota nueva\nCtrl/Cmd + K  buscar\nCtrl/Cmd + Shift + C  copiar la nota completa\nEsc  volver a la lista\n\nEnlaces, correos y teléfonos se pueden tocar cuando no estás escribiendo, por ejemplo https://hectorcruz.com.mx\n\nCon el clip adjuntas archivos de hasta 20 MB para pasarlos entre equipos. Se borran solos al mes; la nota se queda.",
        'creada' => ahora(), 'modificada' => ahora(), 'srv' => revSiguiente(),
    ]);
    soltar();
    abrirSesion();
    fin(200, ['ok' => true]);
}

if ($accion === 'entrar' && $metodo === 'POST') {
    if ($hash === '') fin(409, ['error' => 'Todavía no hay contraseña.']);
    $espera = esperaPendiente();
    if ($espera > 0) fin(429, ['error' => "Demasiados intentos. Espera {$espera} s.", 'espera' => $espera]);
    if (!password_verify((string) ($cuerpo['clave'] ?? ''), $hash)) {
        registrarFallo();
        usleep(400000);
        fin(401, ['error' => 'Contraseña incorrecta.']);
    }
    limpiarFallos();
    if (password_needs_rehash($hash, PASSWORD_DEFAULT)) {
        escribir($D . '/clave.json', ['hash' => password_hash((string) $cuerpo['clave'], PASSWORD_DEFAULT), 'creada' => time()]);
    }
    abrirSesion();
    fin(200, ['ok' => true]);
}

// --- de aquí en adelante, solo con sesión ---
if (!sesionValida()) fin(401, ['error' => 'Sesión cerrada.', 'sesion' => false]);

switch ($accion) {

case 'salir':
    cerrarSesion();
    fin(200, ['ok' => true]);

case 'clave':
    if ($metodo !== 'POST') break;
    if (!password_verify((string) ($cuerpo['actual'] ?? ''), $hash)) fin(401, ['error' => 'La contraseña actual no coincide.', 'sesion' => true]);
    $nueva = (string) ($cuerpo['nueva'] ?? '');
    if (mb_strlen($nueva) < 8) fin(400, ['error' => 'Usa al menos 8 caracteres.']);
    escribir($D . '/clave.json', ['hash' => password_hash($nueva, PASSWORD_DEFAULT), 'creada' => time()]);
    $yo = claveSesion();            // cierra los demás equipos, este sigue dentro
    guardarSesiones(array_filter(sesiones(), fn($v, $k) => $k === $yo, ARRAY_FILTER_USE_BOTH));
    fin(200, ['ok' => true]);

case 'sesiones':
    // los equipos con sesión abierta; el id es solo un fragmento del hash, nunca el token
    $yo = claveSesion();
    $lista = [];
    foreach (sesiones() as $k => $v) {
        if (($v['exp'] ?? 0) <= time()) continue;
        $lista[] = ['id' => substr($k, 0, 16), 'actual' => $k === $yo, 'ua' => (string) ($v['ua'] ?? ''),
                    'creada' => (int) ($v['creada'] ?? 0), 'visto' => (int) ($v['visto'] ?? $v['creada'] ?? 0)];
    }
    usort($lista, fn($a, $b) => ($b['actual'] <=> $a['actual']) ?: ($b['visto'] <=> $a['visto']));
    fin(200, ['sesiones' => $lista]);

case 'cerrar-sesion':
    if ($metodo !== 'POST') break;
    $id = (string) ($cuerpo['id'] ?? '');
    if (!preg_match('/^[0-9a-f]{16}$/', $id)) fin(400, ['error' => 'Sesión no válida.']);
    if (strpos(claveSesion(), $id) === 0) fin(400, ['error' => 'Para este equipo usa «cerrar sesión en este equipo».']);
    $todas = sesiones();
    foreach (array_keys($todas) as $k) if (strpos($k, $id) === 0) unset($todas[$k]);
    guardarSesiones($todas);
    fin(200, ['ok' => true]);

case 'cerrar-todo':
    // cierra todas las sesiones menos la de este equipo
    if ($metodo !== 'POST') break;
    $yo = claveSesion();
    guardarSesiones(array_filter(sesiones(), fn($v, $k) => $k === $yo, ARRAY_FILTER_USE_BOTH));
    fin(200, ['ok' => true]);

case 'cambios':
    // Todo lo que cambió después de la revisión «desde».
    bloquear();
    vaciarPapeleraVieja();
    $desde = (int) ($_GET['desde'] ?? 0);
    $rev = revActual();
    // lo más común: nada cambió desde la última vez; se responde sin abrir ningún archivo
    if ($desde > 0 && $desde >= $rev) { soltar(); fin(200, ['rev' => $rev, 'notas' => [], 'archivos' => [], 'carpetas' => null]); }
    $notas = [];
    foreach (glob($D . '/notas/*.json') ?: [] as $f) {
        // filemtime es una criba rápida; srv es el dato que manda
        $n = leer($f);
        if ($n && ($n['srv'] ?? 0) > $desde) {
            if ($desde === 0 && !empty($n['purgada'])) continue;
            $notas[] = $n;
        }
    }
    $archivos = [];
    foreach (glob(rutaArchivo('*', 'json')) ?: [] as $f) {
        $a = leer($f);
        if (!$a || ($a['srv'] ?? 0) <= $desde || (empty($a['completo']) && empty($a['borrado']))) continue;
        if ($desde === 0 && !empty($a['borrado'])) continue;
        $archivos[] = fichaPublica($a);
    }
    $c = leer($D . '/carpetas.json', ['lista' => [], 'srv' => 0]);
    soltar();
    fin(200, [
        'rev' => $rev, 'notas' => $notas, 'archivos' => $archivos,
        'carpetas' => ($c['srv'] ?? 0) > $desde ? ($c['lista'] ?? []) : null,
    ]);

case 'guardar':
    // Recibe un lote de notas modificadas. Cada una trae «base»: la revisión del servidor
    // que ese equipo vio por última vez. Si en el servidor ya hay otra versión distinta,
    // no se pisa: lo que llega se guarda como copia marcada «conflicto».
    if ($metodo !== 'POST') break;
    $res = [];
    bloquear();
    foreach (array_slice((array) ($cuerpo['notas'] ?? []), 0, 500) as $n) {
        $id = $n['id'] ?? '';
        if (!idValido($id)) { $res[] = ['id' => $id, 'error' => 'id']; continue; }
        $f = rutaNota($id);
        $prev = leer($f);
        $nota = [
            'id' => $id,
            'texto' => limpiarTexto($n['texto'] ?? ''),
            'carpeta' => limpiarCarpeta($n['carpeta'] ?? ''),
            'fijada' => !empty($n['fijada']),
            'borrada' => !empty($n['borrada']),
            'creada' => (int) ($prev['creada'] ?? ($n['creada'] ?? ahora())),
            'modificada' => (int) ($n['modificada'] ?? ahora()),
        ];
        if ($nota['borrada']) $nota['borradaEn'] = (int) ($prev['borradaEn'] ?? ahora());

        $base = (int) ($n['base'] ?? 0);
        $hayOtra = $prev && empty($prev['purgada']) && ($prev['srv'] ?? 0) !== $base;
        // si este equipo solo movió, fijó o borró (sin tocar el texto), se respeta el texto del servidor
        if ($prev && empty($prev['purgada']) && (!empty($n['soloMeta']) || ($nota['borrada'] && $hayOtra))) {
            $nota['texto'] = $prev['texto'] ?? '';
            $nota['modificada'] = max($nota['modificada'], (int) ($prev['modificada'] ?? 0));
        }
        $textoDistinto = $prev && (($prev['texto'] ?? '') !== $nota['texto']);

        if ($prev && !empty($prev['purgada'])) {
            // la nota se borró para siempre en otro equipo: lo que llega vive como nota nueva
            $nota['id'] = nuevoId();
            $nota['srv'] = revSiguiente();
            unset($nota['borradaEn']); $nota['borrada'] = false;
            escribir(rutaNota($nota['id']), $nota);
            $res[] = ['id' => $id, 'reemplazo' => $nota['id'], 'nota' => $nota];
        } elseif ($hayOtra && $textoDistinto && !$nota['borrada']) {
            $copia = $nota;
            $copia['id'] = nuevoId();
            $copia['conflicto'] = true;
            $copia['fijada'] = false;
            $copia['srv'] = revSiguiente();
            escribir(rutaNota($copia['id']), $copia);
            $res[] = ['id' => $id, 'conflicto' => $copia['id'], 'nota' => $prev, 'copia' => $copia];
        } else {
            if (!empty($n['conflicto'])) $nota['conflicto'] = true;
            $nota['srv'] = revSiguiente();
            escribir($f, $nota);
            $res[] = ['id' => $id, 'nota' => $nota];
        }
    }
    $rev = revActual();
    soltar();
    fin(200, ['resultados' => $res, 'rev' => $rev]);

case 'purgar':
    // borrar para siempre (desde la papelera)
    if ($metodo !== 'POST') break;
    bloquear();
    $hechos = []; $archivosBorrados = [];
    foreach ((array) ($cuerpo['ids'] ?? []) as $id) {
        if (!idValido($id) || !is_file(rutaNota($id))) continue;
        $n = ['id' => $id, 'purgada' => true, 'srv' => revSiguiente()];
        escribir(rutaNota($id), $n);
        $hechos[] = $n;
        foreach (glob(rutaArchivo('*', 'json')) ?: [] as $f) {
            $a = leer($f);
            if ($a && empty($a['borrado']) && ($a['nota'] ?? '') === $id) $archivosBorrados[] = borrarArchivo($a['id']);
        }
    }
    soltar();
    fin(200, ['notas' => $hechos, 'archivos' => $archivosBorrados]);

case 'carpetas':
    if ($metodo !== 'POST') break;
    $lista = [];
    foreach ((array) ($cuerpo['lista'] ?? []) as $c) {
        $c = limpiarCarpeta($c);
        if ($c !== '' && !in_array($c, $lista, true)) $lista[] = $c;
    }
    bloquear();
    escribir($D . '/carpetas.json', ['lista' => array_slice($lista, 0, 200), 'srv' => revSiguiente()]);
    soltar();
    fin(200, ['ok' => true, 'lista' => $lista]);

case 'subir-inicio':
    if ($metodo !== 'POST') break;
    $nota = $cuerpo['nota'] ?? '';
    $tam = (int) ($cuerpo['tam'] ?? -1);
    if (!idValido($nota)) fin(400, ['error' => 'Nota no válida.']);
    if ($tam < 0) fin(400, ['error' => 'Tamaño no válido.']);
    $ref = !empty($cuerpo['ref']);     // imagen de referencia: pequeña y sin fecha de caducidad
    $tipoRef = (string) ($cuerpo['tipo'] ?? '');
    if ($ref && $tam > 1536 * 1024) fin(413, ['error' => 'La imagen de referencia pasa de 1.5 MB.']);
    if ($ref && !preg_match('#^image/(jpeg|webp|png)$#', $tipoRef)) fin(400, ['error' => 'Formato de imagen no válido.']);
    if ($tam > maxArchivo()) fin(413, ['error' => 'El archivo pasa de ' . (maxArchivo() / 1048576) . ' MB.']);
    $total = (defined('LNM_MB_TOTAL') ? LNM_MB_TOTAL : 1000) * 1048576;
    if (espacioUsado() + $tam > $total) fin(507, ['error' => 'Ya no hay espacio para archivos. Borra alguno o espera a que venzan.']);
    $id = nuevoId();
    escribir(rutaArchivo($id), [
        'id' => $id, 'nota' => $nota, 'nombre' => limpiarNombre($cuerpo['nombre'] ?? ''),
        'tam' => $tam, 'tipo' => mb_substr((string) ($cuerpo['tipo'] ?? ''), 0, 100),
        'recibido' => 0, 'completo' => false, 'inicio' => ahora(), 'ref' => $ref,
    ]);
    @file_put_contents(rutaArchivo($id, 'bin'), '');
    @chmod(rutaArchivo($id, 'bin'), 0600);
    fin(200, ['id' => $id, 'parte' => LNM_PARTE]);

case 'subir-parte':
    if ($metodo !== 'POST') break;
    $id = $_GET['id'] ?? '';
    $desde = (int) ($_GET['desde'] ?? -1);
    if (!idValido($id) || !is_file(rutaArchivo($id))) fin(404, ['error' => 'Subida no encontrada.']);
    $a = leer(rutaArchivo($id));
    if (!empty($a['completo']) || !empty($a['borrado'])) fin(409, ['error' => 'Esta subida ya terminó.']);
    $datos = file_get_contents('php://input', false, null, 0, LNM_PARTE + 1);
    if ($datos === false || strlen($datos) > LNM_PARTE) fin(413, ['error' => 'Parte demasiado grande.']);
    $recibido = (int) $a['recibido'];
    if ($desde + strlen($datos) <= $recibido) fin(200, ['recibido' => $recibido]);   // reintento de una parte ya guardada
    if ($desde !== $recibido) fin(409, ['error' => 'Parte fuera de orden.', 'recibido' => $recibido]);
    if ($recibido + strlen($datos) > (int) $a['tam']) fin(400, ['error' => 'El archivo llegó más grande de lo anunciado.']);
    if (@file_put_contents(rutaArchivo($id, 'bin'), $datos, FILE_APPEND | LOCK_EX) === false) fin(500, ['error' => 'No se pudo guardar el archivo.']);
    $a['recibido'] = $recibido + strlen($datos);
    escribir(rutaArchivo($id), $a);
    fin(200, ['recibido' => $a['recibido']]);

case 'subir-fin':
    if ($metodo !== 'POST') break;
    $id = $cuerpo['id'] ?? '';
    if (!idValido($id) || !is_file(rutaArchivo($id))) fin(404, ['error' => 'Subida no encontrada.']);
    bloquear();
    $a = leer(rutaArchivo($id));
    clearstatcache();
    if ((int) $a['recibido'] !== (int) $a['tam'] || filesize(rutaArchivo($id, 'bin')) !== (int) $a['tam']) {
        soltar(); fin(400, ['error' => 'El archivo llegó incompleto. Vuelve a subirlo.']);
    }
    $a['completo'] = true;
    $a['subido'] = ahora();
    $a['expira'] = !empty($a['ref']) ? null : ahora() + diasArchivos() * 86400000;
    $a['srv'] = revSiguiente();
    escribir(rutaArchivo($id), $a);
    soltar();
    fin(200, ['archivo' => fichaPublica($a)]);

case 'subir-cancelar':
case 'borrar-archivo':
    if ($metodo !== 'POST') break;
    $id = $cuerpo['id'] ?? '';
    if (!idValido($id) || !is_file(rutaArchivo($id))) fin(200, ['ok' => true]);
    bloquear();
    $a = leer(rutaArchivo($id));
    if ($a && empty($a['completo']) && empty($a['borrado'])) { @unlink(rutaArchivo($id, 'bin')); @unlink(rutaArchivo($id)); $ficha = null; }
    else $ficha = borrarArchivo($id);
    soltar();
    fin(200, ['ok' => true, 'archivo' => $ficha]);

case 'archivo':
    // descarga (o vista en línea con &ver=1, solo para imágenes, PDF y texto)
    $id = $_GET['id'] ?? '';
    $a = idValido($id) ? leer(rutaArchivo($id)) : null;
    if (!$a || empty($a['completo']) || !empty($a['borrado']) || !is_file(rutaArchivo($id, 'bin'))) {
        header('Content-Type: text/plain; charset=utf-8');
        http_response_code(404); echo 'Este archivo ya no existe (los adjuntos se borran al mes).'; exit;
    }
    $tipo = (string) ($a['tipo'] ?? '');
    $seguro = preg_match('#^(image/(png|jpeg|gif|webp|heic|heif|avif)|application/pdf|text/plain|audio/[a-z0-9.+-]+|video/(mp4|quicktime|webm))$#', $tipo) === 1;
    $enLinea = !empty($_GET['ver']) && $seguro;
    $nombre = (string) $a['nombre'];
    $ascii = preg_replace('/[^A-Za-z0-9._ -]+/', '_', $nombre);
    header_remove('Content-Type');
    header('Content-Type: ' . ($enLinea ? $tipo : 'application/octet-stream'));
    header('Content-Length: ' . filesize(rutaArchivo($id, 'bin')));
    header('Content-Disposition: ' . ($enLinea ? 'inline' : 'attachment') . '; filename="' . $ascii . '"; filename*=UTF-8\'\'' . rawurlencode($nombre));
    header("Content-Security-Policy: default-src 'none'; img-src 'self'; media-src 'self'; style-src 'unsafe-inline'; sandbox");
    header('Cache-Control: private, max-age=' . (!empty($a['ref']) ? '31536000, immutable' : '86400'));
    while (ob_get_level()) ob_end_clean();
    readfile(rutaArchivo($id, 'bin'));
    exit;

case 'exportar':
    $todo = [];
    foreach (glob($D . '/notas/*.json') ?: [] as $f) {
        $n = leer($f);
        if ($n && empty($n['purgada'])) $todo[] = $n;
    }
    header('Content-Disposition: attachment; filename="lanotamia-' . date('Y-m-d') . '.json"');
    $fichas = [];
    foreach (glob(rutaArchivo('*', 'json')) ?: [] as $f) {
        $x = leer($f);
        if ($x && !empty($x['completo']) && empty($x['borrado'])) $fichas[] = fichaPublica($x);
    }
    // los archivos en sí no van en el .json: se bajan uno por uno desde su nota
    fin(200, ['app' => 'LaNotaMia', 'version' => '0.5.1', 'fecha' => date('c'),
              'carpetas' => leer($D . '/carpetas.json', ['lista' => []])['lista'] ?? [], 'notas' => $todo, 'archivos' => $fichas]);
}

fin(404, ['error' => 'Acción desconocida.']);
