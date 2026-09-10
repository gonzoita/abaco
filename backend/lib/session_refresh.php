<?php
// backend/lib/session_refresh.php
//
// Decide si a una sesión válida le toca renovarse.
//
// POR QUÉ EXISTE: el token dura 30 días fijos desde el login. Un usuario que
// entraba a diario igual quedaba fuera al llegar al día 30, y como el frontend
// no distinguía un 401 de una caída del servidor, la app se quedaba "cargando"
// diciendo que no podía acceder al servidor. Con la renovación deslizante,
// quien usa la app con regularidad nunca se cae; quien la abandona meses sí
// termina cerrando sesión, que es lo correcto.

/** Vida de una sesión, en segundos (30 días). */
define('SESSION_LIFETIME', 60 * 60 * 24 * 30);

/**
 * ¿Hay que emitir un token nuevo?
 * Sí cuando al token le queda menos de la mitad de su vida.
 * Un token ya vencido NUNCA se renueva: eso sería revivir una sesión muerta.
 *
 * @param int|null $exp      Marca de expiración del token (null si no la trae).
 * @param int      $ahora    Momento actual (timestamp).
 * @param int      $vida     Duración total de una sesión, en segundos.
 */
function should_refresh_session($exp, $ahora, $vida = SESSION_LIFETIME) {
    if ($exp === null || !is_numeric($exp)) return false;

    $restante = (int) $exp - (int) $ahora;

    if ($restante <= 0) return false;          // vencido: se rechaza, no se renueva
    return $restante < ($vida / 2);
}
