<?php
declare(strict_types=1);
require_once __DIR__ . '/../config.php';

final class ApiException extends RuntimeException
{
    public function __construct(public readonly int $estado, string $mensaje, public readonly array $errores = [])
    {
        parent::__construct($mensaje);
    }
}

function e(?string $texto): string
{
    return htmlspecialchars($texto ?? '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function valorTexto(array $datos, string $clave, string $predeterminado = ''): string
{
    return isset($datos[$clave]) && is_string($datos[$clave]) ? $datos[$clave] : $predeterminado;
}

function cookieNombre(): string
{
    $nombre = (string) config('SESSION_COOKIE', 'agenda_session');
    return preg_match('/^[A-Za-z0-9_]{1,64}$/D', $nombre) ? $nombre : 'agenda_session';
}

function tokenSesion(): string
{
    $token = valorTexto($_COOKIE, cookieNombre());
    return preg_match('/^[a-f0-9]{64}$/D', $token) ? $token : '';
}

function guardarCookie(?string $token): void
{
    // HTTPS llega desde Nginx como HTTPS=on; COOKIE_SECURE permite forzarlo.
    $https = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
    $segura = filter_var(config('COOKIE_SECURE', false), FILTER_VALIDATE_BOOLEAN) || $https;
    setcookie(cookieNombre(), $token ?? '', [
        'expires' => $token === null ? time() - 3600 : time() + duracionSesion(),
        'path' => '/',
        'secure' => $segura,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    if ($token === null) {
        unset($_COOKIE[cookieNombre()]);
    } else {
        $_COOKIE[cookieNombre()] = $token;
    }
}

function api(string $accion, string $metodo = 'GET', array $datos = [], array $consulta = []): array
{
    $base = (string) config('API_URL', '');
    $partes = parse_url($base);
    if (!$partes || !in_array($partes['scheme'] ?? '', ['http', 'https'], true)
        || empty($partes['host']) || isset($partes['query']) || isset($partes['fragment'])
        || isset($partes['user']) || isset($partes['pass'])) {
        throw new RuntimeException('Configure la URL del backend.');
    }
    $url = $base . '?' . http_build_query(['action' => $accion] + $consulta);
    $cabeceras = ['Accept: application/json', 'X-Api-Key: ' . apiKey()];
    if (tokenSesion() !== '') {
        $cabeceras[] = 'X-Session-Token: ' . tokenSesion();
    }
    $curl = curl_init($url);
    $opciones = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 3,
        CURLOPT_TIMEOUT => 8,
        CURLOPT_CUSTOMREQUEST => $metodo,
        CURLOPT_FOLLOWLOCATION => false,
    ];
    if ($metodo !== 'GET') {
        $cabeceras[] = 'Content-Type: application/json';
        $opciones[CURLOPT_POSTFIELDS] = json_encode($datos, JSON_THROW_ON_ERROR);
    }
    $opciones[CURLOPT_HTTPHEADER] = $cabeceras;
    curl_setopt_array($curl, $opciones);
    $respuesta = curl_exec($curl);
    $estado = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
    if ($respuesta === false || $estado === 0) {
        throw new ApiException(503, 'El backend no está disponible. Intenta de nuevo en unos momentos.');
    }
    try {
        $contenido = json_decode($respuesta, true, 64, JSON_THROW_ON_ERROR);
    } catch (JsonException $error) {
        throw new ApiException(503, 'El backend no está disponible. Intenta de nuevo en unos momentos.');
    }
    if (!is_array($contenido) || !isset($contenido['ok']) || !is_bool($contenido['ok'])) {
        throw new ApiException(503, 'El backend no está disponible. Intenta de nuevo en unos momentos.');
    }
    if ($estado < 200 || $estado >= 300 || !$contenido['ok']) {
        $mensaje = is_string($contenido['error'] ?? null) ? $contenido['error'] : 'No se pudo completar la operación.';
        $errores = is_array($contenido['errors'] ?? null) ? array_filter($contenido['errors'], 'is_string') : [];
        throw new ApiException($estado >= 400 && $estado <= 599 ? $estado : 503, $mensaje, $errores);
    }
    return is_array($contenido['data'] ?? null) ? $contenido['data'] : [];
}

function sesionActual(): array
{
    static $sesion = null;
    if ($sesion === null) {
        $sesion = api('session');
        if (isset($sesion['token'])) {
            guardarCookie($sesion['token']);
        } elseif (tokenSesion() !== '') {
            guardarCookie(tokenSesion());
        }
    }
    return $sesion;
}

function exigirLogin(): array
{
    $sesion = sesionActual();
    if (empty($sesion['user'])) {
        header('Location: login.php', true, 302);
        exit;
    }
    return $sesion;
}

function csrfFormulario(): string
{
    return (string) (sesionActual()['csrf'] ?? '');
}

function redirigir(string $destino): never
{
    header('Location: ' . $destino, true, 303);
    exit;
}

function mostrarError(Throwable $error): never
{
    $estado = $error instanceof ApiException ? $error->estado : 503;
    http_response_code($estado);
    $mensaje = $error instanceof ApiException ? $error->getMessage()
        : 'El servicio no está disponible. Revisa la configuración del servidor.';
    error_log('Cloud-1 frontend: ' . get_class($error));
    require __DIR__ . '/header.php';
    echo '<div class="alerta error">' . e($mensaje) . '</div>';
    echo '<a class="btn" href="index.php">Volver a la agenda</a>';
    require __DIR__ . '/footer.php';
    exit;
}

header('X-App-Instance: ' . nombreInstancia());
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('Referrer-Policy: same-origin');
header('Cache-Control: no-store');
set_exception_handler('mostrarError');
