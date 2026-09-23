<?php
// Il server ha fuso orario di default diverso (es. Londra/UTC); nel DB le date sono già in ora locale italiana

require_once __DIR__ . "/../extra/config.php";

date_default_timezone_set('Europe/Rome');

// config.php deve definire (vedi config_sensori_snippet.php):
//   $sensori               => array associativo con nome/color/temp_dp/hum_dp/bat_dp per sensore
//   $sensoreDefaultVisibile => chiave del sensore mostrato di default nel grafico

// ─── Config da cookie ─────────────────────────────────
define('COOKIE_NAME', 'weather_cfg');
define('COOKIE_TTL',  60 * 60 * 24 * 365);

function load_config(): array {
  $defaults = [
    'city'          => 'Imperia',
    'lat'           => 43.88,
    'lon'           => 8.03,
    'theme'         => 'system',
    'setup_done'    => false,
    'weze_geoid'    => 74033,
    'forecast_days' => 7,
  ];
  if (!isset($_COOKIE[COOKIE_NAME])) return $defaults;
  $cfg = json_decode($_COOKIE[COOKIE_NAME], true);
  if (!$cfg) return $defaults;
  return [
    'city'          => $cfg['city']          ?? $defaults['city'],
    'lat'           => (float)($cfg['lat']   ?? $defaults['lat']),
    'lon'           => (float)($cfg['lon']   ?? $defaults['lon']),
    'theme'         => in_array(($cfg['theme'] ?? 'system'), ['system', 'light', 'dark'], true) ? $cfg['theme'] : $defaults['theme'],
    'setup_done'    => (bool)($cfg['setup_done'] ?? false),
    'weze_geoid'    => (int)($_POST['weze_geoid'] ?? $cfg['weze_geoid'] ?? $defaults['weze_geoid']),
    'forecast_days' => (int)($_POST['forecast_days'] ?? $cfg['forecast_days'] ?? $defaults['forecast_days']),
  ];
}

function save_config(array $data): void {
  setcookie(COOKIE_NAME, json_encode($data), [
    'expires'  => time() + COOKIE_TTL,
    'path'     => '/',
    'samesite' => 'Lax',
  ]);
}

function delete_config(): void {
  setcookie(COOKIE_NAME, '', ['expires' => time() - 3600, 'path' => '/']);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $action = $_POST['action'] ?? '';
  if ($action === 'setup' || $action === 'save') {
    save_config([
      'city'          => trim($_POST['city'] ?? 'Imperia'),
      'lat'           => (float)str_replace(',', '.', $_POST['lat'] ?? '43.88'),
      'lon'           => (float)str_replace(',', '.', $_POST['lon'] ?? '8.03'),
      'theme'         => in_array(($_POST['theme'] ?? 'system'), ['system', 'light', 'dark'], true) ? $_POST['theme'] : 'system',
      'setup_done'    => true,
      'weze_geoid'    => (int)($_POST['weze_geoid'] ?? 73638),
      'forecast_days' => (int)($_POST['forecast_days'] ?? 7),
    ]);
    header('Location: ' . strtok($_SERVER['REQUEST_URI'], '?'));
    exit;
  }
  if ($action === 'reset') {
    delete_config();
    header('Location: ' . strtok($_SERVER['REQUEST_URI'], '?'));
    exit;
  }
}

$cfg           = load_config();
$setup_done    = $cfg['setup_done'];
$city          = htmlspecialchars($cfg['city']);
$lat           = $cfg['lat'];
$lon           = $cfg['lon'];
$theme         = $cfg['theme'];
$dark_mode     = ($theme === 'dark');
$weze_geoid    = $cfg['weze_geoid'];
$forecast_days = $cfg['forecast_days'];
$theme_class   = $theme;

$url_wetterzentrale = "https://www.wetterzentrale.de/de/show_diagrams.php?geoid=" . $weze_geoid . "&model=gfs&run=12&lid=OP";
$url_meteociel      = "https://www.meteociel.fr/observations-meteo/radar.php";
$url_omirl          = "https://omirl.regione.liguria.it/";
$url_blitzortung    = "https://map.blitzortung.org/";
$url_lightningmaps  = "https://www.lightningmaps.org/";
$url_windfinder     = "https://it.windfinder.com/";

try {
    $pdo = new PDO("mysql:host=$dbHost;dbname=$dbName;charset=utf8mb4", $dbUser, $dbPass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
    ]);
} catch (PDOException $e) {
    die("Errore database: " . htmlspecialchars($e->getMessage()));
}

if (!isset($sensori) || !is_array($sensori) || empty($sensori)) {
    die("Errore: nessun sensore configurato in config.php (\$sensori).");
}

// ─── Lettura live dai sensori Tuya (dinamica, da $sensori) ─────────────────
$live = [];
$output = shell_exec(escapeshellcmd($pythonPath) . ' ' . escapeshellarg($scriptPath) . ' 2>/dev/null');
$tuya = json_decode($output ?: '', true);

if (isset($tuya['dps']) && is_array($tuya['dps'])) {
    $dps = $tuya['dps'];
    foreach ($sensori as $chiave => $conf) {
        $tempDp = $conf['temp_dp'] ?? null;
        $humDp  = $conf['hum_dp'] ?? null;
        $batDp  = $conf['bat_dp'] ?? null;

        $live[$chiave] = [
            'temp'    => ($tempDp !== null && isset($dps[$tempDp])) ? ((float)$dps[$tempDp] / 10) : null,
            'hum'     => ($humDp !== null && isset($dps[$humDp])) ? (int)$dps[$humDp] : null,
            'battery' => ($batDp !== null) ? ($dps[$batDp] ?? null) : null,
        ];
    }
}

// ─── Parametri grafico (nomi/colonne/colori costruiti da $sensori) ────────
$sensori_validi = [];
foreach ($sensori as $chiave => $conf) {
    $sensori_validi[$chiave] = [
        'nome'  => $conf['nome'] ?? ucfirst($chiave),
        'temp'  => $chiave . '_temp',
        'hum'   => $chiave . '_hum',
        'color' => $conf['color'] ?? '#888888',
    ];
}
$sensore_default_visibile = (isset($sensoreDefaultVisibile) && isset($sensori[$sensoreDefaultVisibile]))
    ? $sensoreDefaultVisibile
    : array_key_first($sensori_validi);

$periodi_validi = ['24h' => '24 ore', '7d' => '7 giorni', '30d' => '30 giorni', 'all' => 'Tutto', 'custom' => 'Personalizzato'];
$periodo = $_GET['periodo'] ?? '24h';
if (!isset($periodi_validi[$periodo])) { $periodo = '24h'; }

$tipi_validi = ['temp' => 'Temperatura', 'hum' => 'Umidità', 'ah' => 'Umidità assoluta'];
$tipoSel = $_GET['tipo'] ?? ['temp'];
if (!is_array($tipoSel)) { $tipoSel = [$tipoSel]; }
$tipoSel = array_values(array_intersect($tipoSel, array_keys($tipi_validi)));
if (count($tipoSel) === 0) { $tipoSel = ['temp']; }
// "Umidità assoluta" è esclusiva: se selezionata, ignora eventuali temp/hum arrivati insieme
if (in_array('ah', $tipoSel, true)) { $tipoSel = ['ah']; }

$data_inizio_giorno = trim($_GET['data_inizio_giorno'] ?? '');
$data_inizio_ora    = trim($_GET['data_inizio_ora'] ?? '');
$data_fine_giorno   = trim($_GET['data_fine_giorno'] ?? '');
$data_fine_ora      = trim($_GET['data_fine_ora'] ?? '');

function valida_data(string $s): bool {
    $d = DateTime::createFromFormat('Y-m-d', $s);
    return $d !== false && $d->format('Y-m-d') === $s;
}

function valida_ora(string $s): bool {
    return (bool)preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $s);
}

switch ($periodo) {
    case '7d':
        $wherePeriodo = "dataora >= NOW() - INTERVAL 7 DAY";
        break;
    case '30d':
        $wherePeriodo = "dataora >= NOW() - INTERVAL 30 DAY";
        break;
    case 'all':
        $wherePeriodo = "1=1";
        break;
    case 'custom':
        if (valida_data($data_inizio_giorno) && valida_data($data_fine_giorno)) {
            // Ora vuota (--:--) = 00:00 per l'inizio, 23:59:59 (equivalente a "24:00") per la fine
            $oraIni = valida_ora($data_inizio_ora) ? $data_inizio_ora . ':00' : '00:00:00';
            $oraFin = valida_ora($data_fine_ora) ? $data_fine_ora . ':00' : '23:59:59';
            $dIni = $data_inizio_giorno . ' ' . $oraIni;
            $dFin = $data_fine_giorno . ' ' . $oraFin;
            $wherePeriodo = $pdo->quote($dIni) . " <= dataora AND dataora <= " . $pdo->quote($dFin);
        } else {
            // Range non valido/non ancora impostato: fallback alle 24 ore
            $periodo = 'custom';
            $wherePeriodo = "dataora >= NOW() - INTERVAL 24 HOUR";
        }
        break;
    default:
        $periodo = '24h';
        $wherePeriodo = "dataora >= NOW() - INTERVAL 24 HOUR";
        break;
}

// Umidità assoluta (g/m³) da temperatura (°C) e umidità relativa (%), formula di Bolton/Vaisala
function umiditaAssoluta(float $tempC, float $humRel): float {
    $es = 6.112 * exp((17.62 * $tempC) / (243.12 + $tempC)); // pressione di vapore saturo, hPa
    $e  = $es * ($humRel / 100.0);                            // pressione di vapore attuale, hPa
    return 216.7 * $e / ($tempC + 273.15);                    // g/m³
}

// $grafico[sensore][tipo] = [{t: timestamp_ms, v: valore}, ...]
$grafico = [];
foreach ($sensori_validi as $sensore => $info) {
    $grafico[$sensore] = [];
    foreach ($tipoSel as $tipo) {
        if ($tipo === 'ah') {
            $colT = $info['temp'];
            $colH = $info['hum'];
            $sql = "SELECT dataora, `$colT` AS t, `$colH` AS h FROM rilevazioni WHERE $wherePeriodo AND `$colT` IS NOT NULL AND `$colH` IS NOT NULL ORDER BY dataora ASC";
            $stmt = $pdo->query($sql);
            $punti = [];
            while ($row = $stmt->fetch()) {
                $t = (float)$row['t'];
                $h = (float)$row['h'];
                $punti[] = ['t' => strtotime($row['dataora']) * 1000, 'v' => round(umiditaAssoluta($t, $h), 2)];
            }
            $grafico[$sensore][$tipo] = $punti;
            continue;
        }
        $colonna = $info[$tipo];
        $sql = "SELECT dataora, `$colonna` AS valore FROM rilevazioni WHERE $wherePeriodo AND `$colonna` IS NOT NULL ORDER BY dataora ASC";
        $stmt = $pdo->query($sql);
        $punti = [];
        while ($row = $stmt->fetch()) {
            $punti[] = ['t' => strtotime($row['dataora']) * 1000, 'v' => (float)$row['valore']];
        }
        $grafico[$sensore][$tipo] = $punti;
    }
}

$tuyaHasData = false;
foreach ($grafico as $datiSensore) {
    foreach ($datiSensore as $punti) {
        if (count($punti) > 0) { $tuyaHasData = true; break 2; }
    }
}

$statistiche = [];
foreach ($sensori_validi as $chiave => $sensore) {
    $sql = "SELECT MIN(`{$sensore['temp']}`) AS temp_min, MAX(`{$sensore['temp']}`) AS temp_max, MIN(`{$sensore['hum']}`) AS hum_min, MAX(`{$sensore['hum']}`) AS hum_max FROM rilevazioni WHERE $wherePeriodo";
    $stmt = $pdo->query($sql);
    $statistiche[$chiave] = $stmt->fetch();
}

function formatTemp($value): string { return ($value === null || $value === '') ? '—' : number_format((float)$value, 1, ',', '') . ' °C'; }
function formatHum($value): string { return ($value === null || $value === '') ? '—' : (int)$value . ' %'; }
function batteryClass($battery): string { $b = strtolower(trim((string)$battery)); return in_array($b, ['high','middle','low']) ? "battery $b" : 'battery'; }
function batteryLabel($battery): string { return match (strtolower(trim((string)$battery))) { 'high' => 'Alta', 'middle' => 'Media', 'low' => 'Bassa', default => '—' }; }
?>
<!DOCTYPE html>
<html lang="it" class="<?= $theme_class ?>">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
<title>🌦️ Weather Dashboard<?= $setup_done ? ' — ' . $city : '' ?></title>
<link rel="stylesheet" href="style.css?v=<?= filemtime('style.css') ?>">
<style>
  .tuya-controls form { display: flex; flex-wrap: wrap; align-items: flex-end; gap: 16px 20px; row-gap: 12px; }
  .tuya-range-wrap { align-items: flex-end; gap: 20px; flex-wrap: wrap; }
  .tuya-range-group { display: flex; flex-direction: column; gap: 4px; }
  .tuya-range-label { font-size: 0.72rem; opacity: 0.75; white-space: nowrap; }
  .tuya-range-group input[type="date"] { min-width: 140px; }
  .tuya-range-group input[type="time"] { min-width: 90px; }
  .tuya-range-apply { padding: 6px 14px; align-self: flex-end; }
  .tuya-tipo-filter { display: flex; gap: 14px; align-items: center; }
</style>
<link href="https://fonts.googleapis.com/css2?family=Space+Grotesk:wght@300;400;500;600;700&family=JetBrains+Mono:wght@400;500&display=swap" rel="stylesheet">
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<script src="https://cdn.jsdelivr.net/npm/chartjs-adapter-date-fns@3.0.0/dist/chartjs-adapter-date-fns.bundle.min.js"></script>
<script src="https://cdn.plot.ly/plotly-2.27.0.min.js"></script>
</head>
<body>

<?php if (!$setup_done): ?>
<div class="splash">
  <div class="splash-card">
    <h1>Weather Dashboard</h1>
    <form method="POST" class="setup-form">
      <input type="hidden" name="action" value="setup">
      <label>Località</label><input type="text" name="city" value="Imperia" class="inp" required>
      <label>Latitudine</label><input type="number" name="lat" value="43.88" step="0.01" class="inp" required>
      <label>Longitudine</label><input type="number" name="lon" value="8.03" step="0.01" class="inp" required>
      <label>Wetterzentrale GEOID</label><input type="number" name="weze_geoid" value="73638" class="inp" required>
      <label>Giorni Previsioni Open-Meteo</label><input type="number" name="forecast_days" value="7" min="1" max="16" class="inp" required>
      <button type="submit" class="btn-primary">🚀 Configura</button>
    </form>
  </div>
</div>
<?php else: ?>

<aside class="sidebar" id="sidebar">
  <div class="sidebar-header">
    <span>🌦️ Meteo</span>
    <button class="sidebar-close" onclick="toggleSidebar()">✕</button>
  </div>
  <nav class="weather-menu">
    <button class="weather-menu-item active" data-tab="tuya-dashboard"><span>🌡️</span> Tuya <b>LIVE</b></button>
    <button class="weather-menu-item" data-tab="tab1"><span>🌤️</span> Open-Meteo</button>
    <button class="weather-menu-item" data-tab="tab2"><span>🍝</span> Spaghetti Wetterzentrale</button>
    <button class="weather-menu-item" data-tab="tab3"><span>🌧️</span> Radar Meteociel</button>
    <button class="weather-menu-item" data-tab="tab4"><span>🔵</span> OMIRL Liguria</button>
    <button class="weather-menu-item" data-tab="tab5"><span>⚡</span> Blitzortung</button>
    <button class="weather-menu-item" data-tab="tab6"><span>🌩️</span> LightningMaps</button>
    <button class="weather-menu-item" data-tab="tab7"><span>💨</span> Windfinder</button>
  </nav>

  <div class="sidebar-settings">
    <button type="button" class="settings-toggle" id="settings_toggle"><span>⚙️</span> Settings <span class="settings-chevron">▾</span></button>
    <div class="settings-panel" id="settings_panel" hidden>
      <form method="POST">
        <input type="hidden" name="action" value="save">
        <label>Tema</label>
        <select name="theme" class="sel">
          <option value="system" <?= $theme === 'system' ? 'selected' : '' ?>>🖥️ Sistema</option>
          <option value="light" <?= $theme === 'light' ? 'selected' : '' ?>>☀️ Chiaro</option>
          <option value="dark" <?= $theme === 'dark' ? 'selected' : '' ?>>🌙 Scuro</option>
        </select>
        <label>Città</label><input type="text" name="city" value="<?= $city ?>" class="inp">
        <label>Latitudine</label><input type="number" name="lat" value="<?= $lat ?>" step="0.01" class="inp">
        <label>Longitudine</label><input type="number" name="lon" value="<?= $lon ?>" step="0.01" class="inp">
        <label>Wetterzentrale GEOID</label><input type="number" name="weze_geoid" value="<?= $weze_geoid ?>" class="inp">
        <label>Giorni Previsioni</label><input type="number" name="forecast_days" value="<?= $forecast_days ?>" min="1" max="16" class="inp">
        <button type="submit" class="btn-primary" style="margin-top:8px">💾 Salva Impostazioni</button>
      </form>
      <form method="POST" style="margin-top:8px">
        <input type="hidden" name="action" value="reset">
        <button type="submit" class="btn-secondary">🔄 Reset Configurazione</button>
      </form>
    </div>
  </div>
</aside>
<div class="sidebar-overlay" id="sidebar-overlay" onclick="toggleSidebar()"></div>

<div class="main-wrap">
  <header class="dash-header">
    <button class="hamburger" onclick="toggleSidebar()">☰</button>
    <div>
      <h1>🌦️ Weather Dashboard — <?= $city ?></h1>
      <p>📍 <?= number_format($lat,2) ?>°N, <?= number_format($lon,2) ?>°E | Aggiornato: <?= date('d/m/Y H:i') ?></p>
    </div>
  </header>
  <hr class="divider">

  <!-- TAB TUYA -->
  <section class="tab-section active" id="tuya-dashboard">
    <h2>🌡️ Meteo Tuya</h2>
    <div class="tuya-chart-card">
      <div class="tuya-controls">
        <form method="get" id="tuya-filter-form">
          <label>Periodo:
            <select name="periodo" id="periodo-select" onchange="onPeriodoChange(this)">
              <?php foreach($periodi_validi as $k=>$v): ?><option value="<?=$k?>" <?=$periodo===$k?'selected':''?>><?=$v?></option><?php endforeach; ?>
            </select>
          </label>

          <span id="custom-range-wrap" class="tuya-range-wrap" style="<?= $periodo === 'custom' ? 'display:inline-flex;' : 'display:none' ?>">
            <span class="tuya-range-group">
              <span class="tuya-range-label">Dal giorno</span>
              <input type="date" name="data_inizio_giorno" value="<?= htmlspecialchars($data_inizio_giorno) ?>">
              <span class="tuya-range-label">ore (opz.)</span>
              <input type="time" name="data_inizio_ora" value="<?= htmlspecialchars($data_inizio_ora) ?>" placeholder="00:00">
            </span>
            <span class="tuya-range-group">
              <span class="tuya-range-label">Al giorno</span>
              <input type="date" name="data_fine_giorno" value="<?= htmlspecialchars($data_fine_giorno) ?>">
              <span class="tuya-range-label">ore (opz.)</span>
              <input type="time" name="data_fine_ora" value="<?= htmlspecialchars($data_fine_ora) ?>" placeholder="24:00">
            </span>
            <button type="submit" class="btn-primary tuya-range-apply">Applica</button>
          </span>

          <span class="tuya-tipo-filter">
            <label><input type="checkbox" name="tipo[]" value="temp" <?= in_array('temp',$tipoSel,true)?'checked':'' ?> onchange="onTipoChange(this)"> Temperatura</label>
            <label><input type="checkbox" name="tipo[]" value="hum" <?= in_array('hum',$tipoSel,true)?'checked':'' ?> onchange="onTipoChange(this)"> Umidità</label>
            <label><input type="checkbox" name="tipo[]" value="ah" <?= in_array('ah',$tipoSel,true)?'checked':'' ?> onchange="onTipoChange(this)"> Umidità assoluta</label>
          </span>
        </form>
      </div>

      <div style="position: relative; height:360px;">
        <?php if (!$tuyaHasData): ?>
          <p>Nessun dato disponibile.</p>
        <?php else: ?>
          <canvas id="tuyaChart"></canvas>
        <?php endif; ?>
      </div>
    </div>

    <div class="tuya-sensor-grid" style="margin-top: 20px;">
      <?php foreach ($sensori_validi as $k => $s): ?>
      <?php $dL = $live[$k] ?? ['temp'=>null,'hum'=>null,'battery'=>null]; $st=$statistiche[$k]??[]; ?>
      <article class="tuya-card">
        <div class="tuya-card-head"><h3><?=$s['nome']?></h3><span class="tuya-dot" style="background:<?=$s['color']?>"></span></div>
        <div class="tuya-current"><?=formatTemp($dL['temp'])?></div>
        <div class="tuya-minmax"><span>min <strong><?=formatTemp($st['temp_min']??null)?></strong></span><span>max <strong><?=formatTemp($st['temp_max']??null)?></strong></span></div>
        <div class="tuya-info-row"><span>💧 Umidità</span><strong><?=formatHum($dL['hum'])?></strong></div>
        <div class="tuya-info-row"><span>🔋 Batteria</span><strong class="<?=batteryClass($dL['battery'])?>"><?=batteryLabel($dL['battery'])?></strong></div>
      </article>
      <?php endforeach; ?>
    </div>
  </section>

  <!-- TAB OPEN-METEO -->
  <section class="tab-section" id="tab1">
    <h2>🌤️ Open-Meteo Ensemble & Condizioni Attuali</h2>
    <div class="current-weather-wrapper">
      <div class="current-weather-grid">
        <div class="weather-card-mini"><span class="label">🌡️ Temp. Attuale</span><span class="val" id="curr-temp">—</span></div>
        <div class="weather-card-mini"><span class="label">💧 Umidità</span><span class="val" id="curr-hum">—</span></div>
        <div class="weather-card-mini"><span class="label">⏲️ Pressione</span><span class="val" id="curr-press">—</span></div>
        <div class="weather-card-mini"><span class="label">💨 Vento</span><span class="val" id="curr-wind">—</span></div>
        <div class="weather-card-mini"><span class="label">🌧️ Pioggia Oggi</span><span class="val" id="curr-precip">—</span></div>
      </div>
    </div>

    <div class="charts-wrapper">
      <div id="chart-temp" class="chart-box"></div>
      <div id="chart-t850" class="chart-box"></div>
      <div id="chart-t500" class="chart-box"></div>
      <div id="chart-wind" class="chart-box"></div>
      <div id="chart-precip" class="chart-box"></div>
    </div>
  </section>

  <!-- TAB WETTERZENTRALE -->
  <section class="tab-section" id="tab2">
    <h2>🍝 Wetterzentrale Spaghetti</h2>
    <div class="controls-row">
      <label><input type="radio" name="weze_mode" value="hidden"> Nascosto</label>
      <label><input type="radio" name="weze_mode" value="iframe" checked> Iframe integrato</label>
      <label><input type="radio" name="weze_mode" value="link"> Nuova finestra</label>
    </div>
    <div id="weze-output"><iframe src="<?=$url_wetterzentrale?>"></iframe></div>
  </section>

  <!-- TAB METEOCIEL -->
  <section class="tab-section" id="tab3">
    <h2>🌧️ Radar Meteociel</h2>
    <div class="controls-row">
      <label><input type="radio" name="radar_mode" value="hidden"> Nascosto</label>
      <label><input type="radio" name="radar_mode" value="iframe" checked> Iframe integrato</label>
      <label><input type="radio" name="radar_mode" value="link"> Nuova finestra</label>
    </div>
    <div id="radar-output"><iframe src="<?=$url_meteociel?>"></iframe></div>
  </section>

  <!-- TAB OMIRL -->
  <section class="tab-section" id="tab4">
    <h2>🔵 OMIRL Liguria</h2>
    <div class="controls-row">
      <label><input type="radio" name="omirl_mode" value="hidden"> Nascosto</label>
      <label><input type="radio" name="omirl_mode" value="iframe" checked> Iframe integrato</label>
      <label><input type="radio" name="omirl_mode" value="link"> Nuova finestra</label>
    </div>
    <div id="omirl-output"><iframe src="<?=$url_omirl?>"></iframe></div>
  </section>

  <!-- TAB BLITZORTUNG -->
  <section class="tab-section" id="tab5">
    <h2>⚡ Blitzortung</h2>
    <div class="controls-row">
      <label><input type="radio" name="blitz_mode" value="hidden"> Nascosto</label>
      <label><input type="radio" name="blitz_mode" value="iframe" checked> Iframe integrato</label>
      <label><input type="radio" name="blitz_mode" value="link"> Nuova finestra</label>
    </div>
    <div id="blitz-output"><iframe src="<?=$url_blitzortung?>"></iframe></div>
  </section>

  <!-- TAB LIGHTNINGMAPS -->
  <section class="tab-section" id="tab6">
    <h2>🌩️ LightningMaps</h2>
    <div class="controls-row">
      <label><input type="radio" name="lmap_mode" value="hidden"> Nascosto</label>
      <label><input type="radio" name="lmap_mode" value="iframe" checked> Iframe integrato</label>
      <label><input type="radio" name="lmap_mode" value="link"> Nuova finestra</label>
    </div>
    <div id="lmap-output"><iframe src="<?=$url_lightningmaps?>"></iframe></div>
  </section>

  <!-- TAB WINDFINDER -->
  <section class="tab-section" id="tab7">
    <h2>💨 Windfinder</h2>
    <div class="controls-row">
      <label><input type="radio" name="wind_mode" value="hidden"> Nascosto</label>
      <label><input type="radio" name="wind_mode" value="iframe" checked> Iframe integrato</label>
      <label><input type="radio" name="wind_mode" value="link"> Nuova finestra</label>
    </div>
    <div id="wind-output"><iframe src="<?=$url_windfinder?>"></iframe></div>
  </section>
</div>

<?php if ($tuyaHasData): ?>
<script>
(() => {
    // grafico[sensore][tipo] = [{t: timestamp_ms, v: valore}, ...]
    const grafico = <?= json_encode($grafico, JSON_UNESCAPED_UNICODE) ?>;
    const nomiSensori = <?= json_encode(array_map(fn($s) => $s['nome'], $sensori_validi), JSON_UNESCAPED_UNICODE) ?>;
    const coloriSensori = <?= json_encode(array_map(fn($s) => $s['color'], $sensori_validi), JSON_UNESCAPED_UNICODE) ?>;
    const tipiSelezionati = <?= json_encode($tipoSel) ?>;
    const sensoreDefaultVisibile = <?= json_encode($sensore_default_visibile) ?>;

    const etichettaUnita = { temp: '°C', hum: '%', ah: 'g/m³' };
    const stileTratteggio = { temp: [], hum: [6, 3], ah: [2, 2] };
    const titoloAsseY = { temp: 'Temperatura (°C)', hum: 'Umidità (%)', ah: 'Umidità assoluta (g/m³)' };

    const datasets = [];
    let tMin = null, tMax = null;

    for (const sensore of Object.keys(grafico)) {
        for (const tipo of tipiSelezionati) {
            const punti = (grafico[sensore] && grafico[sensore][tipo]) || [];
            if (!punti.length) continue;

            punti.forEach(p => {
                if (tMin === null || p.t < tMin) tMin = p.t;
                if (tMax === null || p.t > tMax) tMax = p.t;
            });

            const suffisso = tipiSelezionati.length > 1 ? ` (${etichettaUnita[tipo]})` : '';
            datasets.push({
                label: (nomiSensori[sensore] || sensore) + suffisso,
                data: punti.map(p => ({ x: p.t, y: p.v })),
                borderColor: coloriSensori[sensore] || '#333',
                backgroundColor: coloriSensori[sensore] || '#333',
                borderDash: stileTratteggio[tipo] || [],
                borderWidth: 2,
                pointRadius: 0,
                pointHoverRadius: 5,
                fill: false,
                spanGaps: true,
                yAxisID: tipo === 'hum' ? 'y1' : 'y',
                unita: etichettaUnita[tipo] || '',
                hidden: sensore !== sensoreDefaultVisibile
            });
        }
    }

    // Formattazione compatta dell'asse X in base all'estensione temporale effettiva dei dati
    function formatEtichettaData(ts) {
        const d = new Date(ts);
        const pad = n => String(n).padStart(2, '0');
        const span = (tMax !== null && tMin !== null) ? (tMax - tMin) : 0;

        if (span <= 24 * 60 * 60 * 1000) {
            return `${pad(d.getHours())}:${pad(d.getMinutes())}`;
        } else if (span <= 7 * 24 * 60 * 60 * 1000) {
            return `${pad(d.getDate())} ${pad(d.getHours())}:${pad(d.getMinutes())}`;
        } else if (span <= 30 * 24 * 60 * 60 * 1000) {
            return `${pad(d.getDate())}-${pad(d.getMonth() + 1)} ${pad(d.getHours())}`;
        } else {
            return `${pad(d.getDate())}-${pad(d.getMonth() + 1)}-${String(d.getFullYear()).slice(-2)}`;
        }
    }

    const canvas = document.getElementById('tuyaChart');
    if (canvas && datasets.length) {
        const scales = {
            x: {
                type: 'time',
                grid: { color: '#30363d' },
                ticks: {
                    color: '#c9d1d9',
                    autoSkip: true,
                    maxTicksLimit: 8,
                    maxRotation: 0,
                    callback: function (value) { return formatEtichettaData(value); }
                }
            },
            y: {
                position: 'left',
                grid: { color: '#30363d' },
                ticks: { color: '#c9d1d9' },
                title: { display: tipiSelezionati.length > 1, text: 'Temperatura (°C)', color: '#c9d1d9' }
            }
        };
        if (tipiSelezionati.includes('hum')) {
            scales.y1 = {
                position: 'right',
                grid: { drawOnChartArea: false },
                ticks: { color: '#c9d1d9' },
                title: { display: tipiSelezionati.length > 1, text: 'Umidità (%)', color: '#c9d1d9' }
            };
        }

        new Chart(canvas.getContext('2d'), {
            type: 'line',
            data: { datasets },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                parsing: false,
                interaction: { mode: 'nearest', intersect: false, axis: 'x' },
                plugins: {
                    legend: {
                        labels: { color: '#c9d1d9' }
                        // Il click sulla legenda mostra/nasconde i singoli sensori (default Chart.js)
                    },
                    tooltip: {
                        enabled: true,
                        callbacks: {
                            title: function (items) {
                                return items.length ? formatEtichettaData(items[0].parsed.x) : '';
                            },
                            label: function (context) {
                                let label = context.dataset.label || '';
                                if (label) { label += ': '; }
                                if (context.parsed.y !== null) {
                                    const isHum = context.dataset.yAxisID === 'y1';
                                    label += context.parsed.y + (isHum ? ' %' : ' gr/m²');
                                }
                                return label;
                            }
                        }
                    }
                },
                scales: scales
            }
        });
    }
})();
</script>
<?php endif; ?>

<script>
function onPeriodoChange(sel) {
  const wrap = document.getElementById('custom-range-wrap');
  if (sel.value === 'custom') {
    wrap.style.display = 'inline-flex';
  } else {
    wrap.style.display = 'none';
    sel.form.submit();
  }
}

function onTipoChange(checkbox) {
  const form = checkbox.form;
  const tutte = form.querySelectorAll('input[name="tipo[]"]');
  if (checkbox.value === 'ah' && checkbox.checked) {
    // "Umidità assoluta" è esclusiva: deseleziona temperatura/umidità
    tutte.forEach(cb => { if (cb.value !== 'ah') cb.checked = false; });
  } else if (checkbox.value !== 'ah' && checkbox.checked) {
    // Selezionando temp o hum si disattiva l'umidità assoluta
    tutte.forEach(cb => { if (cb.value === 'ah') cb.checked = false; });
  }
  form.submit();
}

const externalLinks = {
  weze_mode: { url: <?= json_encode($url_wetterzentrale) ?>, name: "Spaghetti Wetterzentrale", targetId: "weze-output" },
  radar_mode: { url: <?= json_encode($url_meteociel) ?>, name: "Radar Meteociel", targetId: "radar-output" },
  omirl_mode: { url: <?= json_encode($url_omirl) ?>, name: "OMIRL Liguria", targetId: "omirl-output" },
  blitz_mode: { url: <?= json_encode($url_blitzortung) ?>, name: "Blitzortung", targetId: "blitz-output" },
  lmap_mode: { url: <?= json_encode($url_lightningmaps) ?>, name: "LightningMaps", targetId: "lmap-output" },
  wind_mode: { url: <?= json_encode($url_windfinder) ?>, name: "Windfinder", targetId: "wind-output" }
};

document.addEventListener('change', (e) => {
  const group = e.target.name;
  if (externalLinks[group]) {
    const config = externalLinks[group];
    const container = document.getElementById(config.targetId);
    if (!container) return;

    if (e.target.value === 'hidden') {
      container.innerHTML = '';
    } else if (e.target.value === 'iframe') {
      container.innerHTML = `<iframe src="${config.url}"></iframe>`;
    } else if (e.target.value === 'link') {
      container.innerHTML = `<p style="padding: 1rem;"><a href="${config.url}" target="_blank" class="btn-primary" style="display:inline-block; text-decoration:none; width:auto;">🔗 Apri ${config.name} in una nuova scheda</a></p>`;
    }
  }
});

const WEATHER_CONFIG = {
  lat: <?= number_format($lat, 6, '.', '') ?>,
  lon: <?= number_format($lon, 6, '.', '') ?>,
  city: <?= json_encode($city) ?>,
  forecast_days: <?= json_encode($forecast_days) ?>,
  theme: <?= json_encode($theme) ?>
};
</script>
<script src="app.js?v=<?= filemtime('app.js') ?>"></script>
<?php endif; ?>
</body>
</html>
