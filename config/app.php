<?php

date_default_timezone_set('America/Lima');

define('APP_ROOT', dirname(__DIR__));

function detectar_url_base(): string
{
    $script = str_replace('\\', '/', $_SERVER['SCRIPT_NAME'] ?? '');

    foreach (['/pages/', '/api/', '/exports/'] as $segmento) {
        $posicion = strpos($script, $segmento);

        if ($posicion !== false) {
            return rtrim(substr($script, 0, $posicion), '/');
        }
    }

    $directorio = str_replace('\\', '/', dirname($script));

    return $directorio === '/' || $directorio === '.' ? '' : rtrim($directorio, '/');
}

if (!defined('APP_URL')) {
    $urlConfigurada = trim((string) (getenv('APP_URL') ?: ''));
    define('APP_URL', $urlConfigurada !== '' ? rtrim($urlConfigurada, '/') : detectar_url_base());
}

function app_url(string $ruta = ''): string
{
    $ruta = ltrim($ruta, '/');

    if ($ruta === '') {
        return APP_URL !== '' ? APP_URL : '/';
    }

    return (APP_URL !== '' ? APP_URL : '') . '/' . $ruta;
}

function clave_api_arduino(): string
{
    return (string) (getenv('ARDUINO_API_KEY') ?: 'Bio#As$stenci4@100%');
}
