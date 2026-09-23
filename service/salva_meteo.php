<?php

//require_once "./../extra/config.php";
require_once __DIR__ . "/../extra/config.php";
// config.php deve definire:
//   $sensori              => array associativo con la configurazione dei sensori
//   $confrontoSensore      => chiave del sensore da confrontare (es. 'prato')
//   $confrontoRiferimento  => chiave del sensore di riferimento (es. 'base')
// (vedi config_sensori_snippet.php)

class Telegram
{
    public function send($text, $chatId = null, $replyMarkup = "", $url = null)
    {
        $chatId = $chatId ?? \Config::CHAT_ID;
        $url = $url ?? "https://api.telegram.org/bot" . BOT_TOKEN . "/SendMessage";
        $text = urlencode($text);
        if ($replyMarkup != "") {
            $replyMarkup = json_encode($replyMarkup);
        }
        return (file_get_contents("$url?text=$text&chat_id=$chatId&reply_markup=$replyMarkup"));
    }
}


// ============================================================
// ESEGUI PYTHON
// ============================================================

$output = shell_exec(
    escapeshellarg($pythonPath) . ' ' .
    escapeshellarg($scriptPath) . ' 2>&1'
);

if ($output === null || $output === '') {
    die("Errore: nessuna risposta dallo script Python.");
}


// ============================================================
// DECODIFICA JSON
// ============================================================

$data = json_decode($output, true);

if (!is_array($data)) {
    die(
        "Errore JSON:<br><pre>" .
        htmlspecialchars($output) .
        "</pre>"
    );
}

if (!isset($data['dps']) || !is_array($data['dps'])) {
    die(
        "Risposta Python non valida:<br><pre>" .
        htmlspecialchars($output) .
        "</pre>"
    );
}

$dps = $data['dps'];


// ============================================================
// FUNZIONE PER LEGGERE UN DP
// ============================================================

function dp($dps, $id, $default = null)
{
    if ($id === null) {
        return $default;
    }

    return array_key_exists($id, $dps)
    ? $dps[$id]
    : $default;
}


// ============================================================
// CONVERSIONE TEMPERATURE
// Tuya restituisce 274 = 27.4 °C
// ============================================================

function temperatura($value)
{
    if ($value === null) {
        return null;
    }

    return $value / 10;
}


// ============================================================
// ESTRAZIONE DATI (dinamica, in base a $sensori da config.php)
// ============================================================

if (!isset($sensori) || !is_array($sensori) || empty($sensori)) {
    die("Errore: nessun sensore configurato in config.php (\$sensori).");
}

$letture = [];

foreach ($sensori as $nome => $conf) {
    $tempDp = $conf['temp_dp'] ?? null;
    $humDp  = $conf['hum_dp'] ?? null;

    $letture[$nome] = [
        'temp' => temperatura(dp($dps, $tempDp)),
        'hum'  => dp($dps, $humDp),
    ];
}


// ============================================================
// CONNESSIONE MARIA DB
// ============================================================

try {

    $pdo = new PDO(
        "mysql:host=$dbHost;dbname=$dbName;charset=utf8mb4",
        $dbUser,
        $dbPass,
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
        ]
    );

} catch (PDOException $e) {

    die("Errore connessione database: " . $e->getMessage());

}


// ============================================================
// LETTURA REGISTRAZIONE PRECEDENTE
// (serve per confrontare $confrontoSensore/$confrontoRiferimento
// con la rilevazione precedente)
// ============================================================

$prevRow = null;
$confrontoAttivo = isset($confrontoSensore, $confrontoRiferimento)
    && isset($sensori[$confrontoSensore])
    && isset($sensori[$confrontoRiferimento]);

if ($confrontoAttivo) {

    $colSensore = $confrontoSensore . '_temp';
    $colRiferimento = $confrontoRiferimento . '_temp';

    try {

        $stmtPrev = $pdo->query(
            "SELECT `$colRiferimento`, `$colSensore` FROM rilevazioni ORDER BY id DESC LIMIT 1"
        );
        $prevRow = $stmtPrev->fetch();

    } catch (PDOException $e) {

        $prevRow = null;

    }
}


// ============================================================
// INSERT (colonne costruite dinamicamente dai sensori)
// ============================================================

$colonne = [];
$placeholder = [];
$params = [];

foreach ($letture as $nome => $valori) {
    $colTemp = $nome . '_temp';
    $colHum  = $nome . '_hum';

    $colonne[] = "`$colTemp`";
    $colonne[] = "`$colHum`";

    $placeholder[] = ":$colTemp";
    $placeholder[] = ":$colHum";

    $params[":$colTemp"] = $valori['temp'];
    $params[":$colHum"]  = $valori['hum'];
}

$sql = "INSERT INTO rilevazioni (" . implode(', ', $colonne) . ") "
     . "VALUES (" . implode(', ', $placeholder) . ")";

try {

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);

} catch (PDOException $e) {

    die("Errore INSERT: " . $e->getMessage());

}


// ============================================================
// CONTROLLO CONFRONTO SENSORI (notifica Telegram)
// Invia un messaggio solo quando la relazione tra i due sensori
// CAMBIA rispetto alla registrazione precedente (fronte di transizione)
// ============================================================

if ($confrontoAttivo) {

    $tempSensore = $letture[$confrontoSensore]['temp'];
    $tempRiferimento = $letture[$confrontoRiferimento]['temp'];

    $colSensore = $confrontoSensore . '_temp';
    $colRiferimento = $confrontoRiferimento . '_temp';

    if (
        $tempSensore !== null &&
        $tempRiferimento !== null &&
        $prevRow &&
        $prevRow[$colSensore] !== null &&
        $prevRow[$colRiferimento] !== null
    ) {

        $diffPrecedente = $prevRow[$colSensore] - $prevRow[$colRiferimento];
        $diffAttuale = $tempSensore - $tempRiferimento;

        $telegram = new Telegram();

        // Il sensore è diventato più caldo del riferimento (prima non lo era)
        if ($diffPrecedente <= 0 && $diffAttuale > 0) {
            $telegram->send("Conviene chiudere le finestre");
        }

        // Il sensore è diventato più freddo del riferimento (prima non lo era)
        if ($diffPrecedente >= 0 && $diffAttuale < 0) {
            $telegram->send("Conviene aprire le finestre");
        }
        // else {
        //     $telegram->send("Temperatura $confrontoSensore: $tempSensore, $confrontoRiferimento: $tempRiferimento");
        // }
    }
}


// ============================================================
// RISULTATO
// ============================================================

echo "Dati salvati correttamente.<br><br>";

foreach ($letture as $nome => $valori) {
    echo ucfirst($nome) . ": " .
    ($valori['temp'] !== null ? $valori['temp'] . " °C" : "--") .
    " / " .
    ($valori['hum'] !== null ? $valori['hum'] . " %" : "--") .
    "<br>";
}

?>
