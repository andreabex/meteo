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
      'weze_geoid'    => (int)($_POST['weze_geoid'] ?? 74033),
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

$tipi_validi = ['temp' => 'Temperatura', 'hum' => 'Umidità', 'ah' => 'Umidità assoluta', 'dp' => 'Punto di rugiada'];
$tipoSel = $_GET['tipo'] ?? ['temp'];
if (!is_array($tipoSel)) { $tipoSel = [$tipoSel]; }
$tipoSel = array_values(array_intersect($tipoSel, array_keys($tipi_validi)));
if (count($tipoSel) === 0) { $tipoSel = ['temp']; }
// "Umidità assoluta" (g/m³) ha un'unità a sé: è esclusiva e ignora gli altri tipi arrivati insieme.
// Temperatura, umidità e punto di rugiada si possono invece vedere insieme.
if (in_array('ah', $tipoSel, true)) { $tipoSel = ['ah']; }
// Ordine canonico (temp, hum, ah, dp): il primo tipo è quello che riceve banda min-max e media mobile
$tipoSel = array_values(array_intersect(array_keys($tipi_validi), $tipoSel));

// Dettaglio dei dati: auto (in base all'estensione del periodo), grezzo, orario, giornaliero
$agg_validi = ['auto' => 'Automatico', 'raw' => 'Grezzo (15 min)', 'hourly' => 'Medie orarie', 'daily' => 'Medie giornaliere'];
$agg = $_GET['agg'] ?? 'auto';
if (!isset($agg_validi[$agg])) { $agg = 'auto'; }

// ─── Medie climatiche di riferimento: file stat.json ─────────────────────────────
// I dati NON stanno nel codice ma in web/stat.json (stessa cartella di questo file), così si possono
// aggiornare o sostituire senza toccare index.php. Il file si genera/aggiorna con extra/scraber.py
// (medie settimanali da dati giornalieri Open-Meteo, per qualsiasi località) oppure si scrive a mano:
//   { "etichetta": "...", "posizione": {"nome": "...", "lat": 0, "lon": 0}, "periodo": "1991-2020",
//     "settimanale": {"temp": [52 valori], "hum": [52 valori]},     ← usate se presenti
//     "mensile":     {"temp": [12 valori], "hum": [12 valori]} }    ← altrimenti interpolate in settimanali
// Se il file manca o non è valido la pagina funziona lo stesso, senza linea di riferimento né scarto.
//
// Perché settimanali e non mensili: usare il valore del mese per tutti i suoi giorni crea un gradino
// al cambio mese (es. ottobre 17,5 °C → novembre 13,4 °C): l'ultimo giorno di ottobre si risulta "sotto media"
// e il primo di novembre "sopra", anche se il tempo è lo stesso. Quando nel file ci sono solo le mensili
// vengono interpolate: ogni valore è collocato a metà del suo mese e tra un mese e il successivo la curva
// sale o scende in modo continuo (anche da dicembre a gennaio); poi si fa la media su 52 blocchi di 7 giorni
// (dal 1° gennaio; l'ultimo blocco ha 8 giorni). Sono valori RICAVATI, non misurati: l'errore tipico è di
// pochi decimi di grado. Le settimanali da scraber.py derivano invece dai dati giornalieri.

// Blocco settimanale (0..51) di una data: i giorni si contano su un anno non bisestile (29 febbraio = 28)
function bloccoSettimana(int $mese, int $giorno): int {
    $gm = [31, 28, 31, 30, 31, 30, 31, 31, 30, 31, 30, 31];
    if ($mese === 2 && $giorno > 28) { $giorno = 28; }
    $doy0 = array_sum(array_slice($gm, 0, $mese - 1)) + $giorno - 1;   // giorno dell'anno, base 0
    return min(51, intdiv($doy0, 7));
}

// Dalle 12 medie mensili (gennaio..dicembre) a 365 valori giornalieri, interpolando tra i centri dei mesi
function climaGiornalieroDaMensile(array $mensili): array {
    $mensili = array_values($mensili);
    $gm = [31, 28, 31, 30, 31, 30, 31, 31, 30, 31, 30, 31];
    $centri = [];
    $inizio = 0;
    foreach ($gm as $m => $n) { $centri[$m] = $inizio + $n / 2; $inizio += $n; }

    // Interpolazione lineare tra i valori collocati al centro di ogni mese (con raccordo dicembre → gennaio)
    $interpola = function (array $ancore) use ($centri): array {
        $giornalieri = [];
        for ($d = 0; $d < 365; $d++) {
            $x = $d + 0.5;                       // metà giornata
            $a = 11;                             // ultimo centro di mese non dopo x (prima di metà gennaio: dicembre)
            for ($m = 0; $m < 12; $m++) { if ($centri[$m] <= $x) { $a = $m; } }
            $b = ($a + 1) % 12;
            $xa = $centri[$a]; if ($xa > $x)   { $xa -= 365; }
            $xb = $centri[$b]; if ($xb < $xa)  { $xb += 365; }
            $t = ($x - $xa) / ($xb - $xa);
            $giornalieri[$d] = $ancore[$a] + $t * ($ancore[$b] - $ancore[$a]);
        }
        return $giornalieri;
    };

    // Un'interpolazione semplice "smussa" i picchi (in agosto la media del mese uscirebbe ~0,5 °C sotto quella
    // della fonte). Si correggono quindi i valori di ancoraggio finché la media di ogni mese della curva
    // coincide con la media mensile originale (converge in pochi passaggi).
    $ancore = $mensili;
    $giornalieri = $interpola($ancore);
    for ($passo = 0; $passo < 30; $passo++) {
        $i = 0;
        $errMax = 0.0;
        foreach ($gm as $m => $n) {
            $s = 0.0;
            for ($k = 0; $k < $n; $k++) { $s += $giornalieri[$i++]; }
            $err = $mensili[$m] - $s / $n;
            $ancore[$m] += $err;
            $errMax = max($errMax, abs($err));
        }
        if ($errMax < 0.005) { break; }
        $giornalieri = $interpola($ancore);
    }
    return $giornalieri;
}

function climaSettimanaleDaMensile(array $mensili): array {
    $somme = array_fill(0, 52, 0.0);
    $conta = array_fill(0, 52, 0);
    foreach (climaGiornalieroDaMensile($mensili) as $d => $v) {
        $b = min(51, intdiv($d, 7));
        $somme[$b] += $v;
        $conta[$b]++;
    }
    return array_map(fn($s, $n) => round($s / $n, 2), $somme, $conta);
}

// Lista di soli numeri con la lunghezza attesa (altrimenti null)
function serieNumerica($v, int $n): ?array {
    if (!is_array($v) || count($v) !== $n) { return null; }
    $v = array_values($v);
    foreach ($v as $x) { if (!is_int($x) && !is_float($x)) { return null; } }
    return array_map('floatval', $v);
}

$statFile = __DIR__ . '/stat.json';
$statClima = null;
if (is_readable($statFile)) {
    $tmpStat = json_decode((string)file_get_contents($statFile), true);
    if (is_array($tmpStat)) { $statClima = $tmpStat; }
}

$climaSett = ['etichetta' => 'Media climatica', 'temp' => [], 'hum' => []];
$climaOrigine = null;      // 'settimanale' (dati giornalieri) | 'mensile' (interpolata) | null (nessun dato)
$climaDescr = '';          // testo per le note: "Imperia 1991-2020, da dati giornalieri"
$climaLontano = false;     // true se la località del file è diversa da quella impostata nella pagina
$climaLuogo = '';
if ($statClima) {
    $climaOrigini = [];
    foreach (['temp', 'hum'] as $k) {
        $climaSettFile = serieNumerica($statClima['settimanale'][$k] ?? null, 52);
        if ($climaSettFile !== null) { $climaSett[$k] = $climaSettFile; $climaOrigini[$k] = 'settimanale'; continue; }
        $climaMensFile = serieNumerica($statClima['mensile'][$k] ?? null, 12);
        if ($climaMensFile !== null) { $climaSett[$k] = climaSettimanaleDaMensile($climaMensFile); $climaOrigini[$k] = 'mensile'; }
    }
    $climaOrigine = $climaOrigini['temp'] ?? ($climaOrigini['hum'] ?? null);
    if ($climaOrigine !== null) {
        $climaEtichetta = is_string($statClima['etichetta'] ?? null) ? $statClima['etichetta'] : 'Media climatica';
        $climaSett['etichetta'] = $climaEtichetta . ($climaOrigine === 'mensile' ? ' · sett. interpolata' : ' · settimanale');

        $climaPos = is_array($statClima['posizione'] ?? null) ? $statClima['posizione'] : [];
        $climaLuogo = is_string($climaPos['nome'] ?? null) ? $climaPos['nome'] : '';
        $climaPeriodo = is_string($statClima['periodo'] ?? null) ? $statClima['periodo'] : '';
        $climaDescr = trim($climaLuogo . ' ' . $climaPeriodo) . ($climaOrigine === 'mensile' ? ', interpolata dalle medie mensili' : ', da dati giornalieri');
        $climaDescr = ltrim($climaDescr, ', ');
        if (isset($climaPos['lat'], $climaPos['lon']) && is_numeric($climaPos['lat']) && is_numeric($climaPos['lon'])) {
            $climaLontano = abs((float)$climaPos['lat'] - $lat) > 0.25 || abs((float)$climaPos['lon'] - $lon) > 0.25;
        }
    }
}

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

// Punto di rugiada (°C) da temperatura (°C) e umidità relativa (%), formula di Magnus
// (stessi coefficienti di umiditaAssoluta, così le due grandezze sono coerenti)
function puntoRugiada(float $tempC, float $humRel): float {
    $b = 17.62;
    $c = 243.12;
    $humRel = max($humRel, 0.1); // evita log(0)
    $gamma = log($humRel / 100.0) + ($b * $tempC) / ($c + $tempC);
    return $c * $gamma / ($b - $gamma);
}

// ─── Livello di dettaglio (aggregazione) in base all'estensione del periodo ───
// <= 3 giorni: dati grezzi; <= 20 giorni: medie orarie; oltre: medie giornaliere con banda min-max
$stmtSpan = $pdo->query("SELECT MIN(dataora) AS mn, MAX(dataora) AS mx FROM rilevazioni WHERE $wherePeriodo");
$rowSpan = $stmtSpan->fetch();
$spanGiorni = ($rowSpan && $rowSpan['mn'] !== null)
    ? (strtotime($rowSpan['mx']) - strtotime($rowSpan['mn'])) / 86400
    : 0;

if ($agg === 'auto') {
    $modoAgg = $spanGiorni <= 3 ? 'raw' : ($spanGiorni <= 20 ? 'hourly' : 'daily');
} else {
    $modoAgg = $agg;
}

// Raggruppa i punti grezzi [{ts, v}] in "secchielli" orari/giornalieri.
// Giornaliero: restituisce anche minimo e massimo (per la banda); orario: solo la media.
function aggregaPunti(array $grezzi, string $modo): array {
    if ($modo === 'raw') {
        return array_map(fn($p) => ['t' => $p['ts'] * 1000, 'v' => round($p['v'], 2)], $grezzi);
    }
    $gruppi = [];
    foreach ($grezzi as $p) {
        $chiave = $modo === 'daily' ? date('Y-m-d', $p['ts']) : date('Y-m-d H', $p['ts']);
        if (!isset($gruppi[$chiave])) {
            $gruppi[$chiave] = ['somma' => 0.0, 'n' => 0, 'mn' => $p['v'], 'mx' => $p['v']];
        }
        $gruppi[$chiave]['somma'] += $p['v'];
        $gruppi[$chiave]['n']++;
        $gruppi[$chiave]['mn'] = min($gruppi[$chiave]['mn'], $p['v']);
        $gruppi[$chiave]['mx'] = max($gruppi[$chiave]['mx'], $p['v']);
    }
    $out = [];
    foreach ($gruppi as $chiave => $g) {
        // Il punto va al centro del secchiello (mezzogiorno / mezz'ora)
        $ts = $modo === 'daily' ? strtotime($chiave . ' 12:00:00') : strtotime($chiave . ':30:00');
        $punto = ['t' => $ts * 1000, 'v' => round($g['somma'] / $g['n'], 2)];
        if ($modo === 'daily') {
            $punto['mn'] = round($g['mn'], 2);
            $punto['mx'] = round($g['mx'], 2);
        }
        $out[] = $punto;
    }
    return $out;
}

// $grafico[sensore][tipo] = [{t: timestamp_ms, v: valore[, mn: minimo, mx: massimo]}, ...]
$grafico = [];
foreach ($sensori_validi as $sensore => $info) {
    $grafico[$sensore] = [];
    foreach ($tipoSel as $tipo) {
        $grezzi = [];
        if ($tipo === 'ah' || $tipo === 'dp') {
            // Grandezze derivate: calcolate riga per riga da temperatura e umidità, poi aggregate
            $colT = $info['temp'];
            $colH = $info['hum'];
            $sql = "SELECT dataora, `$colT` AS t, `$colH` AS h FROM rilevazioni WHERE $wherePeriodo AND `$colT` IS NOT NULL AND `$colH` IS NOT NULL ORDER BY dataora ASC";
            $stmt = $pdo->query($sql);
            while ($row = $stmt->fetch()) {
                $t = (float)$row['t'];
                $h = (float)$row['h'];
                $v = $tipo === 'ah' ? umiditaAssoluta($t, $h) : puntoRugiada($t, $h);
                $grezzi[] = ['ts' => strtotime($row['dataora']), 'v' => $v];
            }
        } else {
            $colonna = $info[$tipo];
            $sql = "SELECT dataora, `$colonna` AS valore FROM rilevazioni WHERE $wherePeriodo AND `$colonna` IS NOT NULL ORDER BY dataora ASC";
            $stmt = $pdo->query($sql);
            while ($row = $stmt->fetch()) {
                $grezzi[] = ['ts' => strtotime($row['dataora']), 'v' => (float)$row['valore']];
            }
        }
        $grafico[$sensore][$tipo] = aggregaPunti($grezzi, $modoAgg);
    }
}

// ─── Sensore esterno e di riferimento (per delta, statistiche e heatmap) ──────
// Di default "prato" (esterno) e "base" (riferimento); si possono cambiare in config.php
// definendo $sensoreEsterno e $sensoreRiferimento con le chiavi di $sensori.
$sensoreEsterno = (isset($sensoreEsterno) && isset($sensori_validi[$sensoreEsterno]))
    ? $sensoreEsterno : (isset($sensori_validi['prato']) ? 'prato' : null);
$sensoreRiferimento = (isset($sensoreRiferimento) && isset($sensori_validi[$sensoreRiferimento]))
    ? $sensoreRiferimento : (isset($sensori_validi['base']) ? 'base' : null);
// Soglia (°C) per i gradi-giorno di riscaldamento: somma di quanto la media giornaliera sta sotto la soglia
$sogliaGradiGiorno = (isset($sogliaGradiGiorno) && is_numeric($sogliaGradiGiorno)) ? (float)$sogliaGradiGiorno : 18.0;

function percentile(array $ordinati, float $p): ?float {
    $n = count($ordinati);
    if ($n === 0) { return null; }
    $pos = ($n - 1) * $p;
    $base = (int)floor($pos);
    $resto = $pos - $base;
    return isset($ordinati[$base + 1])
        ? $ordinati[$base] + $resto * ($ordinati[$base + 1] - $ordinati[$base])
        : $ordinati[$base];
}

function mediana(array $v): ?float {
    if (!$v) { return null; }
    sort($v);
    $n = count($v);
    return $n % 2 ? (float)$v[intdiv($n, 2)] : ($v[$n / 2 - 1] + $v[$n / 2]) / 2;
}

// Differenza di temperatura esterno − riferimento, aggregata come il grafico principale
$delta = [];
if ($sensoreEsterno && $sensoreRiferimento && $sensoreEsterno !== $sensoreRiferimento) {
    $cE = $sensori_validi[$sensoreEsterno]['temp'];
    $cR = $sensori_validi[$sensoreRiferimento]['temp'];
    $sql = "SELECT dataora, `$cE` - `$cR` AS d FROM rilevazioni WHERE $wherePeriodo AND `$cE` IS NOT NULL AND `$cR` IS NOT NULL ORDER BY dataora ASC";
    $stmt = $pdo->query($sql);
    $grezzi = [];
    while ($row = $stmt->fetch()) {
        $grezzi[] = ['ts' => strtotime($row['dataora']), 'v' => (float)$row['d']];
    }
    $delta = aggregaPunti($grezzi, $modoAgg === 'raw' ? 'raw' : $modoAgg);
}
$deltaHasData = count($delta) > 0;

// Scheda del grafico: "andamento" (principale) oppure "confronto" (esterno − riferimento).
// Il confronto è disponibile solo se ci sono dati; la scelta sopravvive al ricaricamento tramite ?vista=
$vista = (($_GET['vista'] ?? 'andamento') === 'confronto' && $deltaHasData) ? 'confronto' : 'andamento';

// Statistiche di periodo e heatmap giorno × ora per il sensore esterno
$statEsterno = null;
$heat = [];
$heatMin = $heatMax = null;
if ($sensoreEsterno) {
    $cT = $sensori_validi[$sensoreEsterno]['temp'];
    $cH = $sensori_validi[$sensoreEsterno]['hum'];

    $sql = "SELECT DATE(dataora) AS g, MIN(`$cT`) AS mn, MAX(`$cT`) AS mx, AVG(`$cT`) AS av, COUNT(*) AS n FROM rilevazioni WHERE $wherePeriodo AND `$cT` IS NOT NULL GROUP BY DATE(dataora) ORDER BY g ASC";
    $giorni = $pdo->query($sql)->fetchAll();

    if (count($giorni) > 0) {
        $sql = "SELECT `$cT` AS v FROM rilevazioni WHERE $wherePeriodo AND `$cT` IS NOT NULL ORDER BY `$cT` ASC";
        $valori = array_map('floatval', $pdo->query($sql)->fetchAll(PDO::FETCH_COLUMN));

        $sql = "SELECT AVG(`$cH`) FROM rilevazioni WHERE $wherePeriodo AND `$cH` IS NOT NULL";
        $umMedia = $pdo->query($sql)->fetchColumn();

        $nGiorni = count($giorni);
        $minimeG = array_map(fn($r) => (float)$r['mn'], $giorni);
        $massimeG = array_map(fn($r) => (float)$r['mx'], $giorni);
        $escursioni = array_map(fn($r) => (float)$r['mx'] - (float)$r['mn'], $giorni);

        $gradiGiorno = 0.0;
        $giorniGelo = 0;
        $scartoSomma = 0.0;
        $scartoN = 0;
        foreach ($giorni as $r) {
            $gradiGiorno += max(0.0, $sogliaGradiGiorno - (float)$r['av']);
            if ((float)$r['mn'] <= 0.0) { $giorniGelo++; }
            // Scarto del giorno dalla media climatica della sua settimana (non del suo mese: niente gradino a fine mese)
            $tsG = strtotime($r['g']);
            $norma = $climaSett['temp'][bloccoSettimana((int)date('n', $tsG), (int)date('j', $tsG))] ?? null;
            if ($norma !== null) {
                $scartoSomma += (float)$r['av'] - (float)$norma;
                $scartoN++;
            }
        }

        // Giorni con rilevazioni mancanti: meno dell'80% della mediana dei giorni interni
        // (primo e ultimo giorno sono parziali per costruzione, quindi non contano)
        $incompleti = null;
        if ($nGiorni >= 4) {
            $interni = array_slice($giorni, 1, -1);
            $med = mediana(array_map(fn($r) => (int)$r['n'], $interni));
            $incompleti = 0;
            foreach ($interni as $r) {
                if ($med !== null && (int)$r['n'] < 0.8 * $med) { $incompleti++; }
            }
            $incompletiSu = count($interni);
        }

        $idxMin = array_keys($minimeG, min($minimeG))[0];
        $idxMax = array_keys($massimeG, max($massimeG))[0];

        $statEsterno = [
            'giorni'      => $nGiorni,
            'media'       => array_sum($valori) / count($valori),
            'min'         => min($minimeG),
            'min_giorno'  => $giorni[$idxMin]['g'],
            'max'         => max($massimeG),
            'max_giorno'  => $giorni[$idxMax]['g'],
            'media_min'   => array_sum($minimeG) / $nGiorni,
            'media_max'   => array_sum($massimeG) / $nGiorni,
            'escursione'  => array_sum($escursioni) / $nGiorni,
            'p10'         => percentile($valori, 0.10),
            'p90'         => percentile($valori, 0.90),
            'gradi_giorno' => $gradiGiorno,
            'giorni_gelo' => $giorniGelo,
            'um_media'    => ($umMedia !== false && $umMedia !== null) ? (float)$umMedia : null,
            'scarto_clima' => $scartoN > 0 ? $scartoSomma / $scartoN : null,
            'incompleti'  => $incompleti,
            'incompleti_su' => $incompletiSu ?? null,
        ];
    }

    // Heatmap: media oraria per giorno (ultimi 60 giorni del periodo), solo se il periodo supera ~1 giorno
    if ($spanGiorni >= 1.5) {
        $sql = "SELECT DATE(dataora) AS g, HOUR(dataora) AS h, AVG(`$cT`) AS v FROM rilevazioni WHERE $wherePeriodo AND `$cT` IS NOT NULL GROUP BY DATE(dataora), HOUR(dataora) ORDER BY g ASC, h ASC";
        $stmt = $pdo->query($sql);
        while ($row = $stmt->fetch()) {
            $heat[$row['g']][(int)$row['h']] = round((float)$row['v'], 1);
        }
        $heat = array_slice($heat, -60, null, true);
        foreach ($heat as $ore) {
            foreach ($ore as $v) {
                if ($heatMin === null || $v < $heatMin) { $heatMin = $v; }
                if ($heatMax === null || $v > $heatMax) { $heatMax = $v; }
            }
        }
    }
}

// Colore della heatmap: dal blu (freddo) al rosso (caldo) passando per ciano, verde e giallo
function coloreHeat(float $v, float $min, float $max): string {
    $t = $max > $min ? ($v - $min) / ($max - $min) : 0.5;
    $t = max(0.0, min(1.0, $t));
    $hue = (1.0 - $t) * 240; // 240 = blu, 0 = rosso
    return sprintf('hsl(%d, 75%%, 48%%)', (int)round($hue));
}

function fmtGiorno(string $ymd): string { return date('d/m', strtotime($ymd)); }
function fmtNum($v, int $dec = 1): string { return $v === null ? '—' : number_format((float)$v, $dec, ',', ''); }

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
  .tuya-tabs { display: flex; flex-wrap: wrap; gap: 4px; margin-bottom: 12px; border-bottom: 1px solid rgba(128,128,128,0.3); }
  .tuya-tab { background: none; border: none; border-bottom: 2px solid transparent; margin-bottom: -1px; padding: 8px 14px; cursor: pointer; color: inherit; font: inherit; opacity: 0.65; }
  .tuya-tab:hover { opacity: 0.9; }
  .tuya-tab.active { opacity: 1; font-weight: 600; border-bottom-color: #4da6ff; }
  .tuya-chart-card h3 { margin: 0 0 6px; }
  .tuya-chart-card h3 small { font-weight: 400; opacity: 0.7; }
  .tuya-stat-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(190px, 1fr)); gap: 10px; margin-top: 10px; }
  .tuya-stat { border: 1px solid rgba(128,128,128,0.3); border-radius: 8px; padding: 8px 10px; display: flex; flex-direction: column; gap: 2px; }
  .tuya-stat span { font-size: 0.74rem; opacity: 0.75; }
  .tuya-stat strong { font-size: 1.05rem; }
  .tuya-stat em { font-size: 0.7rem; opacity: 0.65; font-style: normal; }
  .tuya-heat-scroll { overflow-x: auto; margin-top: 10px; }
  .tuya-heat { display: grid; grid-template-columns: 48px repeat(24, minmax(14px, 1fr)); gap: 1px; min-width: 460px; font-size: 0.64rem; }
  .tuya-heat .h-ora, .tuya-heat .h-giorno { opacity: 0.7; text-align: center; line-height: 14px; }
  .tuya-heat .h-giorno { text-align: right; padding-right: 6px; }
  .tuya-heat .h-cella { height: 14px; border-radius: 2px; }
  .tuya-heat .h-cella.vuota { background: rgba(128,128,128,0.15); }
  .tuya-heat-legend { display: flex; align-items: center; gap: 8px; margin-top: 8px; font-size: 0.72rem; }
  .tuya-heat-legend .barra { flex: 0 0 160px; height: 10px; border-radius: 5px; background: linear-gradient(to right, hsl(240,75%,48%), hsl(180,75%,48%), hsl(120,75%,48%), hsl(60,75%,48%), hsl(0,75%,48%)); }
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

    <!-- Riquadri in tempo reale -->
    <div class="tuya-sensor-grid" style="margin-bottom: 20px;">
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

    <!-- Grafico: andamento / confronto esterno-interno (schede alternative) -->
    <div class="tuya-chart-card">
      <?php if ($deltaHasData): ?>
      <div class="tuya-tabs" role="tablist">
        <button type="button" class="tuya-tab <?= $vista === 'andamento' ? 'active' : '' ?>" data-vista="andamento" role="tab" onclick="cambiaVista('andamento')">📈 Andamento</button>
        <button type="button" class="tuya-tab <?= $vista === 'confronto' ? 'active' : '' ?>" data-vista="confronto" role="tab" onclick="cambiaVista('confronto')">↕️ Confronto <?= htmlspecialchars($sensori_validi[$sensoreEsterno]['nome']) ?> − <?= htmlspecialchars($sensori_validi[$sensoreRiferimento]['nome']) ?></button>
      </div>
      <?php endif; ?>

      <div class="tuya-controls">
        <form method="get" id="tuya-filter-form">
          <input type="hidden" name="vista" id="vista-input" value="<?= $vista ?>">
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

          <span class="tuya-tipo-filter" id="tipo-filter" style="<?= $vista === 'confronto' ? 'display:none;' : '' ?>">
            <label><input type="checkbox" name="tipo[]" value="temp" <?= in_array('temp',$tipoSel,true)?'checked':'' ?> onchange="onTipoChange(this)"> Temperatura</label>
            <label><input type="checkbox" name="tipo[]" value="hum" <?= in_array('hum',$tipoSel,true)?'checked':'' ?> onchange="onTipoChange(this)"> Umidità</label>
            <label><input type="checkbox" name="tipo[]" value="ah" <?= in_array('ah',$tipoSel,true)?'checked':'' ?> onchange="onTipoChange(this)"> Umidità assoluta</label>
            <label><input type="checkbox" name="tipo[]" value="dp" <?= in_array('dp',$tipoSel,true)?'checked':'' ?> onchange="onTipoChange(this)"> Punto di rugiada</label>
          </span>

          <label>Dettaglio:
            <select name="agg" onchange="this.form.submit()">
              <?php foreach($agg_validi as $k=>$v): ?><option value="<?=$k?>" <?=$agg===$k?'selected':''?>><?=$v?></option><?php endforeach; ?>
            </select>
          </label>
        </form>
      </div>

      <div id="vista-andamento" style="<?= $vista === 'andamento' ? '' : 'display:none;' ?>">
        <div style="position: relative; height:360px;">
          <?php if (!$tuyaHasData): ?>
            <p>Nessun dato disponibile.</p>
          <?php else: ?>
            <canvas id="tuyaChart"></canvas>
          <?php endif; ?>
        </div>
        <?php if ($tuyaHasData && $modoAgg !== 'raw'): ?>
          <p class="tuya-range-label" style="margin-top:8px;">
            <?= $modoAgg === 'daily' ? 'Medie giornaliere con banda minimo–massimo' : 'Medie orarie' ?>
            (periodo di <?= number_format($spanGiorni, 1, ',', '') ?> giorni).
            <?php if ($climaOrigine !== null): ?>
              Il riferimento tratteggiato è la media climatica settimanale<?= $climaDescr !== '' ? ' (' . htmlspecialchars($climaDescr) . ')' : '' ?>.
            <?php endif; ?>
          </p>
        <?php endif; ?>
        <?php if ($tuyaHasData && $modoAgg !== 'raw' && $climaLontano): ?>
          <p class="tuya-range-label" style="margin-top:4px; color:#ffa94d;">
            ⚠️ Le medie climatiche di riferimento sono per <?= htmlspecialchars($climaLuogo !== '' ? $climaLuogo : 'un\'altra località') ?>,
            diversa dalla località impostata (<?= $city ?>). Rigenerale con:
            <code>python3 extra/scraber.py --nome "<?= $city ?>" --lat <?= number_format($lat, 2, '.', '') ?> --lon <?= number_format($lon, 2, '.', '') ?></code>
          </p>
        <?php endif; ?>
      </div>

      <?php if ($deltaHasData): ?>
      <div id="vista-confronto" style="<?= $vista === 'confronto' ? '' : 'display:none;' ?>">
        <p class="tuya-range-label">Differenza di temperatura <?= htmlspecialchars($sensori_validi[$sensoreEsterno]['nome']) ?> − <?= htmlspecialchars($sensori_validi[$sensoreRiferimento]['nome']) ?>:
          sopra lo zero l'esterno è più caldo, sotto è più freddo (utile per decidere quando aprire o chiudere le finestre).
          Periodo e dettaglio sono quelli scelti sopra<?= $modoAgg === 'daily' ? '; con le medie giornaliere il tooltip riporta anche minimo e massimo del giorno' : '' ?>.</p>
        <div style="position: relative; height:360px;">
          <canvas id="deltaChart"></canvas>
        </div>
      </div>
      <?php endif; ?>
    </div>

    <?php if ($statEsterno): ?>
    <?php
      $S = $statEsterno;
      $nomeEst = htmlspecialchars($sensori_validi[$sensoreEsterno]['nome']);
      $voci = [
        ['Temperatura media', fmtNum($S['media']) . ' °C', null],
        ['Minima del periodo', fmtNum($S['min']) . ' °C', fmtGiorno($S['min_giorno'])],
        ['Massima del periodo', fmtNum($S['max']) . ' °C', fmtGiorno($S['max_giorno'])],
        ['Media delle minime giornaliere', fmtNum($S['media_min']) . ' °C', null],
        ['Media delle massime giornaliere', fmtNum($S['media_max']) . ' °C', null],
        ['Escursione giornaliera media', fmtNum($S['escursione']) . ' °C', 'massima − minima del giorno'],
        ['Percentili 10° / 90°', fmtNum($S['p10']) . ' / ' . fmtNum($S['p90']) . ' °C', 'meno sensibili ai picchi isolati'],
        ['Gradi-giorno (soglia ' . fmtNum($sogliaGradiGiorno, 0) . ' °C)', fmtNum($S['gradi_giorno'], 0), 'somma di quanto la media sta sotto soglia'],
        ['Giorni di gelo (min ≤ 0 °C)', $S['giorni_gelo'] . ' su ' . $S['giorni'], null],
      ];
      if ($S['um_media'] !== null) { $voci[] = ['Umidità media', fmtNum($S['um_media'], 0) . ' %', null]; }
      if ($S['scarto_clima'] !== null) {
          $sc = $S['scarto_clima'];
          $voci[] = ['Scarto dalla media climatica settimanale', ($sc > 0 ? '+' : '') . fmtNum($sc) . ' °C', 'rispetto a ' . ($climaDescr !== '' ? $climaDescr : 'media climatica')];
      }
      if ($S['incompleti'] !== null) {
          $voci[] = ['Giorni con rilevazioni mancanti', $S['incompleti'] . ' su ' . $S['incompleti_su'], 'meno dell\'80% del normale'];
      }
    ?>
    <div class="tuya-chart-card" style="margin-top: 20px;">
      <h3>📊 Statistiche del periodo — <?= $nomeEst ?> <small>(<?= (int)$S['giorni'] ?> giorni con dati)</small></h3>
      <div class="tuya-stat-grid">
        <?php foreach ($voci as [$etichetta, $valore, $nota]): ?>
          <div class="tuya-stat">
            <span><?= htmlspecialchars($etichetta) ?></span>
            <strong><?= htmlspecialchars($valore) ?></strong>
            <?php if ($nota): ?><em><?= htmlspecialchars($nota) ?></em><?php endif; ?>
          </div>
        <?php endforeach; ?>
      </div>
    </div>
    <?php endif; ?>

    <?php if ($heat && $heatMin !== null): ?>
    <div class="tuya-chart-card" style="margin-top: 20px;">
      <details open>
        <summary><strong>🗓️ Heatmap giorno × ora — <?= htmlspecialchars($sensori_validi[$sensoreEsterno]['nome']) ?></strong>
          <small>(temperatura media oraria<?= count($heat) >= 60 ? ', ultimi 60 giorni del periodo' : '' ?>)</small></summary>
        <div class="tuya-heat-scroll">
          <div class="tuya-heat">
            <div></div>
            <?php for ($o = 0; $o < 24; $o++): ?><div class="h-ora"><?= $o % 3 === 0 ? $o : '' ?></div><?php endfor; ?>
            <?php foreach ($heat as $giorno => $ore): ?>
              <div class="h-giorno"><?= fmtGiorno($giorno) ?></div>
              <?php for ($o = 0; $o < 24; $o++): ?>
                <?php if (isset($ore[$o])): ?>
                  <div class="h-cella" style="background:<?= coloreHeat($ore[$o], (float)$heatMin, (float)$heatMax) ?>" title="<?= fmtGiorno($giorno) ?> ore <?= $o ?>: <?= fmtNum($ore[$o]) ?> °C"></div>
                <?php else: ?>
                  <div class="h-cella vuota" title="<?= fmtGiorno($giorno) ?> ore <?= $o ?>: nessun dato"></div>
                <?php endif; ?>
              <?php endfor; ?>
            <?php endforeach; ?>
          </div>
        </div>
        <div class="tuya-heat-legend">
          <span><?= fmtNum($heatMin) ?> °C</span><div class="barra"></div><span><?= fmtNum($heatMax) ?> °C</span>
        </div>
      </details>
    </div>
    <?php endif; ?>
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

    const modoAgg = <?= json_encode($modoAgg) ?>;
    const climaSett = <?= json_encode($climaSett, JSON_UNESCAPED_UNICODE) ?>;

    // Blocco settimanale (0..51) di un timestamp: stesso calcolo di bloccoSettimana() in PHP
    // (anno non bisestile, 29 febbraio = 28; blocchi di 7 giorni dal 1° gennaio, l'ultimo ne ha 8)
    const GIORNI_CUM = [0, 31, 59, 90, 120, 151, 181, 212, 243, 273, 304, 334];
    function bloccoSettimana(ts) {
        const d = new Date(ts);
        const mese = d.getMonth();
        const giorno = (mese === 1 && d.getDate() > 28) ? 28 : d.getDate();
        return Math.min(51, Math.floor((GIORNI_CUM[mese] + giorno - 1) / 7));
    }

    const etichettaUnita = { temp: '°C', hum: '%', ah: 'g/m³', dp: '°C' };
    const etichettaTipo = { temp: 'temp.', hum: 'umidità', ah: 'umid. ass.', dp: 'rugiada' };
    const stileTratteggio = { temp: [], hum: [6, 3], ah: [2, 2], dp: [2, 2] };
    const titoloAsseY = { temp: 'Temperatura (°C)', hum: 'Umidità (%)', ah: 'Umidità assoluta (g/m³)', dp: 'Punto di rugiada (°C)' };

    const GIORNO_MS = 24 * 60 * 60 * 1000;

    // Colore con trasparenza per la banda min-max (accetta #rrggbb / #rgb, altrimenti grigio)
    function coloreConAlpha(colore, alpha) {
        let h = String(colore || '').replace('#', '');
        if (h.length === 3) h = h.split('').map(c => c + c).join('');
        if (!/^[0-9a-fA-F]{6}$/.test(h)) return `rgba(136,136,136,${alpha})`;
        const n = parseInt(h, 16);
        return `rgba(${(n >> 16) & 255},${(n >> 8) & 255},${n & 255},${alpha})`;
    }

    // Media mobile centrata su N punti (solo punti disponibili, ai bordi la finestra si restringe)
    function mediaMobile(punti, n) {
        const meta = Math.floor(n / 2);
        return punti.map((p, i) => {
            const da = Math.max(0, i - meta);
            const a = Math.min(punti.length - 1, i + meta);
            let somma = 0;
            for (let k = da; k <= a; k++) somma += punti[k].v;
            return { x: p.t, y: Math.round((somma / (a - da + 1)) * 100) / 100 };
        });
    }

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

            const nome = nomiSensori[sensore] || sensore;
            const suffisso = tipiSelezionati.length > 1 ? ` (${etichettaTipo[tipo]})` : '';
            const colore = coloriSensori[sensore] || '#333';
            const yAxisID = tipo === 'hum' ? 'y1' : 'y';
            const nascosto = sensore !== sensoreDefaultVisibile;
            const gruppo = `${sensore}|${tipo}`; // i dataset dello stesso gruppo si accendono/spengono insieme
            // Con più tipi insieme banda e media mobile solo sul primo (temperatura), per non affollare il grafico
            const dettaglioCompleto = tipiSelezionati.length === 1 || tipo === tipiSelezionati[0];
            const haBanda = modoAgg === 'daily' && dettaglioCompleto && punti.every(p => p.mn !== undefined);

            // Banda minimo–massimo (periodi lunghi): due serie, la seconda riempie fino alla prima
            if (haBanda) {
                datasets.push({
                    label: `${nome}${suffisso} min`,
                    data: punti.map(p => ({ x: p.t, y: p.mn })),
                    borderColor: coloreConAlpha(colore, 0.35), borderWidth: 1,
                    backgroundColor: 'transparent', pointRadius: 0, pointHoverRadius: 3,
                    fill: false, spanGaps: true, yAxisID, unita: etichettaUnita[tipo] || '',
                    hidden: nascosto, gruppo, ruolo: 'banda'
                });
                datasets.push({
                    label: `${nome}${suffisso} max`,
                    data: punti.map(p => ({ x: p.t, y: p.mx })),
                    borderColor: coloreConAlpha(colore, 0.35), borderWidth: 1,
                    backgroundColor: coloreConAlpha(colore, 0.16), pointRadius: 0, pointHoverRadius: 3,
                    fill: '-1', spanGaps: true, yAxisID, unita: etichettaUnita[tipo] || '',
                    hidden: nascosto, gruppo, ruolo: 'banda'
                });
            }

            // Serie principale (grezzi, medie orarie o medie giornaliere)
            datasets.push({
                label: nome + suffisso,
                data: punti.map(p => ({ x: p.t, y: p.v })),
                borderColor: colore,
                backgroundColor: colore,
                borderDash: stileTratteggio[tipo] || [],
                borderWidth: 2,
                pointRadius: 0,
                pointHoverRadius: 5,
                fill: false,
                spanGaps: true,
                yAxisID,
                unita: etichettaUnita[tipo] || '',
                hidden: nascosto,
                gruppo, ruolo: 'principale',
                dettaglio: haBanda ? punti : null // per mostrare min/max nel tooltip
            });

            // Media mobile su 7 giorni, solo con dati giornalieri e almeno due settimane
            if (modoAgg === 'daily' && dettaglioCompleto && punti.length >= 14) {
                datasets.push({
                    label: `${nome}${suffisso} media mobile 7 gg`,
                    data: mediaMobile(punti, 7),
                    borderColor: colore, backgroundColor: colore,
                    borderWidth: 3.5, borderDash: [], pointRadius: 0, pointHoverRadius: 4,
                    fill: false, spanGaps: true, tension: 0.3, yAxisID,
                    unita: etichettaUnita[tipo] || '',
                    hidden: nascosto, gruppo, ruolo: 'media-mobile'
                });
            }
        }
    }

    // Riferimento climatico settimanale (Imperia): un punto al giorno col valore della media della sua settimana.
    // Si mostra solo con dati aggregati (periodi oltre 3 giorni) e per temperatura/umidità relativa.
    if (modoAgg !== 'raw' && tMin !== null) {
        for (const tipo of ['temp', 'hum']) {
            if (!tipiSelezionati.includes(tipo) || !climaSett[tipo] || !climaSett[tipo].length) continue;
            const serie = [];
            for (let t = tMin; t <= tMax + GIORNO_MS; t += GIORNO_MS) {
                const valore = climaSett[tipo][bloccoSettimana(t)];
                if (valore !== undefined) serie.push({ x: t, y: valore });
            }
            datasets.push({
                label: (climaSett.etichetta || 'Media climatica settimanale') + (tipiSelezionati.length > 1 ? ` (${etichettaTipo[tipo]})` : ''),
                data: serie,
                borderColor: '#9aa4b2', backgroundColor: '#9aa4b2',
                borderWidth: 1.5, borderDash: [5, 4], pointRadius: 0, pointHoverRadius: 3,
                fill: false, spanGaps: true, stepped: 'middle',
                yAxisID: tipo === 'hum' ? 'y1' : 'y',
                unita: etichettaUnita[tipo] || '',
                hidden: false, gruppo: `clima|${tipo}`, ruolo: 'clima'
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
                title: { display: tipiSelezionati.length > 1, text: tipiSelezionati.includes('dp') ? 'Temperatura / rugiada (°C)' : 'Temperatura (°C)', color: '#c9d1d9' }
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

        window.tuyaChartInstance = new Chart(canvas.getContext('2d'), {
            type: 'line',
            data: { datasets },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                parsing: false,
                interaction: { mode: 'nearest', intersect: false, axis: 'x' },
                plugins: {
                    legend: {
                        labels: {
                            color: '#c9d1d9',
                            // In legenda solo le serie principali e il riferimento climatico:
                            // banda min-max e media mobile seguono la serie principale
                            filter: function (item, data) {
                                const ds = data.datasets[item.datasetIndex];
                                return ds.ruolo === 'principale' || ds.ruolo === 'clima';
                            }
                        },
                        // Il click sulla legenda mostra/nasconde il sensore insieme alla sua banda e media mobile
                        onClick: function (e, item, legend) {
                            const chart = legend.chart;
                            const gruppo = chart.data.datasets[item.datasetIndex].gruppo;
                            const visibile = chart.isDatasetVisible(item.datasetIndex);
                            chart.data.datasets.forEach((ds, i) => {
                                if (ds.gruppo === gruppo) chart.setDatasetVisibility(i, !visibile);
                            });
                            chart.update();
                        }
                    },
                    tooltip: {
                        enabled: true,
                        // Le due serie della banda (min e max) non vanno elencate nel tooltip: minimo e massimo
                        // compaiono già una sola volta nella riga della serie principale (afterLabel).
                        // Senza questo filtro comparivano due volte (serie "min"/"max" + riga della principale).
                        filter: function (item) { return item.dataset.ruolo !== 'banda'; },
                        callbacks: {
                            title: function (items) {
                                return items.length ? formatEtichettaData(items[0].parsed.x) : '';
                            },
                            label: function (context) {
                                let label = context.dataset.label || '';
                                if (label) { label += ': '; }
                                if (context.parsed.y !== null) {
                                    label += context.parsed.y + ' ' + (context.dataset.unita || '');
                                }
                                return label;
                            },
                            // Con le medie giornaliere mostra anche minimo e massimo del giorno
                            afterLabel: function (context) {
                                const det = context.dataset.dettaglio;
                                const p = det && det[context.dataIndex];
                                if (!p || p.mn === undefined) return '';
                                const u = context.dataset.unita || '';
                                return `min ${p.mn} ${u} · max ${p.mx} ${u}`;
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

<?php if ($deltaHasData): ?>
<script>
(() => {
    // delta[i] = {t: timestamp_ms, v: differenza media, [mn, mx]}
    const delta = <?= json_encode($delta) ?>;
    const giornaliero = <?= json_encode($modoAgg === 'daily') ?>;
    const canvas = document.getElementById('deltaChart');
    if (!canvas || !delta.length) return;

    const formatData = ts => new Date(ts).toLocaleString('it-IT', giornaliero
        ? { day: '2-digit', month: '2-digit', year: '2-digit' }
        : { day: '2-digit', month: '2-digit', hour: '2-digit', minute: '2-digit' });
    const segno = v => (v > 0 ? '+' : '') + String(v).replace('.', ',');

    window.deltaChartInstance = new Chart(canvas.getContext('2d'), {
        type: 'line',
        data: {
            datasets: [{
                label: 'Esterno − riferimento',
                data: delta.map(p => ({ x: p.t, y: p.v })),
                borderColor: '#ffa94d',
                borderWidth: 2,
                pointRadius: 0,
                pointHoverRadius: 4,
                spanGaps: true,
                tension: 0.2,
                // Rosso sopra lo zero (esterno più caldo), azzurro sotto (esterno più freddo)
                fill: { target: 'origin', above: 'rgba(255,107,107,0.25)', below: 'rgba(77,166,255,0.25)' }
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            parsing: false,
            interaction: { mode: 'nearest', intersect: false, axis: 'x' },
            plugins: {
                legend: { display: false },
                tooltip: {
                    callbacks: {
                        title: items => items.length ? formatData(items[0].parsed.x) : '',
                        label: ctx => ctx.parsed.y === null ? '' : `${segno(ctx.parsed.y)} °C`,
                        afterLabel: ctx => {
                            const p = delta[ctx.dataIndex];
                            return p && p.mn !== undefined ? `min ${segno(p.mn)} °C · max ${segno(p.mx)} °C` : '';
                        }
                    }
                }
            },
            scales: {
                x: { type: 'time', grid: { color: '#30363d' }, ticks: { color: '#c9d1d9', autoSkip: true, maxTicksLimit: 8, maxRotation: 0 } },
                y: {
                    grid: { color: ctx => ctx.tick && ctx.tick.value === 0 ? '#8b949e' : '#30363d' },
                    ticks: { color: '#c9d1d9' },
                    title: { display: true, text: 'Differenza (°C)', color: '#c9d1d9' }
                }
            }
        }
    });
})();
</script>
<?php endif; ?>

<script>
// Passa dalla scheda "Andamento" a "Confronto" senza ricaricare la pagina.
// Il valore va anche nel campo nascosto del form, così resta scelto quando si cambia periodo/dettaglio.
function cambiaVista(vista) {
  const input = document.getElementById('vista-input');
  if (input) input.value = vista;

  document.querySelectorAll('.tuya-tab').forEach(t => t.classList.toggle('active', t.dataset.vista === vista));

  const andamento = document.getElementById('vista-andamento');
  const confronto = document.getElementById('vista-confronto');
  if (andamento) andamento.style.display = vista === 'andamento' ? '' : 'none';
  if (confronto) confronto.style.display = vista === 'confronto' ? '' : 'none';

  // La scelta dei tipi (temp/umidità/rugiada) vale solo per il grafico dell'andamento
  const tipi = document.getElementById('tipo-filter');
  if (tipi) tipi.style.display = vista === 'andamento' ? '' : 'none';

  // Un grafico nascosto ha dimensione 0: va ridimensionato quando torna visibile
  const chart = vista === 'andamento' ? window.tuyaChartInstance : window.deltaChartInstance;
  if (chart) chart.resize();
}

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
  const esclusivi = ['ah']; // l'umidità assoluta (g/m³) non si combina con gli altri tipi; temp, umidità e rugiada sì
  if (esclusivi.includes(checkbox.value) && checkbox.checked) {
    // Deseleziona tutti gli altri tipi
    tutte.forEach(cb => { if (cb !== checkbox) cb.checked = false; });
  } else if (!esclusivi.includes(checkbox.value) && checkbox.checked) {
    // Selezionando temp o hum si disattivano i tipi esclusivi
    tutte.forEach(cb => { if (esclusivi.includes(cb.value)) cb.checked = false; });
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
