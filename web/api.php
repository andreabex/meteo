<?php
// Open-Meteo proxy for Weather Dashboard
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

$type = $_GET['type'] ?? '';
$lat = filter_input(INPUT_GET, 'lat', FILTER_VALIDATE_FLOAT);
$lon = filter_input(INPUT_GET, 'lon', FILTER_VALIDATE_FLOAT);
$days = (int)($_GET['forecast_days'] ?? 7);
$days = max(1, min(7, $days));

if ($lat === false || $lat === null || $lon === false || $lon === null) {
    http_response_code(400);
    echo json_encode(['error' => true, 'reason' => 'Coordinate non valide']);
    exit;
}

function om_fetch(string $base, array $params): void {
    $url = $base . '?' . http_build_query($params, '', '&', PHP_QUERY_RFC3986);
    $ctx = stream_context_create(['http' => [
        'timeout' => 20,
        'ignore_errors' => true,
        'header' => "User-Agent: WeatherDashboard/1.0\r\nAccept: application/json\r\n"
    ]]);
    $body = @file_get_contents($url, false, $ctx);
    if ($body === false) {
        http_response_code(502);
        echo json_encode(['error' => true, 'reason' => 'Impossibile raggiungere Open-Meteo']);
        exit;
    }
    $status = 200;
    if (!empty($http_response_header[0]) && preg_match('/\s(\d{3})\s/', $http_response_header[0], $m)) {
        $status = (int)$m[1];
    }
    http_response_code($status >= 400 ? $status : 200);
    echo $body;
    exit;
}

switch ($type) {
    case 'current':
        om_fetch('https://api.open-meteo.com/v1/forecast', [
            'latitude' => $lat,
            'longitude' => $lon,
            'current' => 'temperature_2m,relative_humidity_2m,wind_speed_10m,wind_direction_10m,precipitation,weather_code',
            'timezone' => 'auto'
        ]);
        break;

    case 'ensemble':
        om_fetch('https://ensemble-api.open-meteo.com/v1/ensemble', [
            'latitude' => $lat,
            'longitude' => $lon,
            'models' => 'icon_seamless',
            'hourly' => 'temperature_2m,precipitation,wind_speed_10m',
            'forecast_days' => $days,
            'timezone' => 'auto'
        ]);
        break;

    case 'pressure':
        om_fetch('https://api.open-meteo.com/v1/forecast', [
            'latitude' => $lat,
            'longitude' => $lon,
            'hourly' => 'temperature_500hPa,temperature_800hPa,geopotential_height_500hPa,geopotential_height_800hPa',
            'forecast_days' => $days,
            'timezone' => 'auto'
        ]);
        break;

    default:
        http_response_code(400);
        echo json_encode(['error' => true, 'reason' => 'Tipo API non valido']);
}
