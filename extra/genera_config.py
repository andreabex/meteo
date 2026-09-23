#!/usr/bin/env python3
"""
genera_config.py

Si collega all'API Tuya (stessa autenticazione di tuyaJson.py), legge
TUTTI i sensori collegati alla stazione meteo con i relativi dp_id, e
genera automaticamente il blocco PHP $sensori da incollare in config.php.

Con l'opzione --scrivi genera direttamente un config.php completo,
unendo il blocco sensori scoperto automaticamente con il resto della
configurazione (percorsi Python, database, Telegram) che va comunque
compilato a mano.

Uso:
    python3 genera_config.py                  # stampa solo il blocco $sensori
    python3 genera_config.py --scrivi          # scrive config.php completo
    python3 genera_config.py --scrivi -o mio_config.php
"""

import argparse
import hashlib
import hmac
import json
import re
import sys
import time
import unicodedata
import uuid

import requests


# ============================================================
# CONFIGURAZIONE TUYA — compilare come in tuyaJson.py
# ============================================================

ACCESS_ID = ""
ACCESS_SECRET = ""
DEVICE_ID = ""

# Europa
HOST = "https://openapi.tuyaeu.com"


# ============================================================
# FIRMA HMAC-SHA256 (identica a tuyaJson.py)
# ============================================================

def hmac_sha256(text):
    return hmac.new(
        ACCESS_SECRET.encode(),
        text.encode(),
        hashlib.sha256
    ).hexdigest().upper()


def token_sign(path, t, nonce):
    body_hash = hashlib.sha256(b"").hexdigest()
    string_to_sign = "GET\n" + body_hash + "\n\n" + path
    sign_string = ACCESS_ID + t + nonce + string_to_sign
    return hmac_sha256(sign_string)


def api_sign(path, t, nonce, access_token):
    body_hash = hashlib.sha256(b"").hexdigest()
    string_to_sign = "GET\n" + body_hash + "\n\n" + path
    sign_string = ACCESS_ID + access_token + t + nonce + string_to_sign
    return hmac_sha256(sign_string)


def get_new_token():
    path = "/v1.0/token?grant_type=1"
    t = str(int(time.time() * 1000))
    nonce = uuid.uuid4().hex
    sign = token_sign(path, t, nonce)

    headers = {
        "client_id": ACCESS_ID,
        "t": t,
        "nonce": nonce,
        "sign": sign,
        "sign_method": "HMAC-SHA256"
    }

    response = requests.get(HOST + path, headers=headers, timeout=15)
    data = response.json()

    if not data.get("success"):
        raise RuntimeError(
            f"Errore richiesta token: {data.get('code')} - {data.get('msg')}"
        )

    return data["result"]


def get_shadow_properties(access_token):
    path = f"/v2.0/cloud/thing/{DEVICE_ID}/shadow/properties"
    t = str(int(time.time() * 1000))
    nonce = uuid.uuid4().hex
    sign = api_sign(path, t, nonce, access_token)

    headers = {
        "client_id": ACCESS_ID,
        "access_token": access_token,
        "t": t,
        "nonce": nonce,
        "sign": sign,
        "sign_method": "HMAC-SHA256"
    }

    return requests.get(HOST + path, headers=headers, timeout=15)


# ============================================================
# ESTRAZIONE SENSORI CON I RELATIVI dp_id
# Stessa logica di print_sensors() in tuyaJson.py, ma qui salviamo
# anche il dp_id di ogni codice invece di limitarci a stamparlo.
# ============================================================

def estrai_sensori(shadow_data):
    properties = shadow_data["result"]["properties"]

    # sensori['base' o numero] = {'nome':..., 'temp_dp':..., 'hum_dp':..., 'bat_dp':...}
    sensori = {}

    def ensure(chiave, nome_default):
        if chiave not in sensori:
            sensori[chiave] = {
                "nome": nome_default,
                "temp_dp": None,
                "hum_dp": None,
                "bat_dp": None,
            }
        return sensori[chiave]

    for item in properties:
        code = item.get("code", "")
        custom_name = item.get("custom_name", "") or ""
        dp_id = item.get("dp_id")
        if dp_id is None:
            continue
        dp_id = str(dp_id)

        # --- stazione base ---
        if code == "temp_current":
            ensure("base", "Stazione base")["temp_dp"] = dp_id
        elif code == "humidity_value":
            ensure("base", "Stazione base")["hum_dp"] = dp_id

        # --- temperatura sensori satellite ---
        elif code.startswith("temp_current_sub"):
            numero = code.replace("temp_current_sub", "")
            nome = custom_name
            if nome.endswith("Temperature"):
                nome = nome[:-11].strip()
            if not nome:
                nome = f"Sensore {numero}"
            s = ensure(numero, nome)
            s["temp_dp"] = dp_id
            if custom_name:
                s["nome"] = nome

        # --- umidità sensori satellite ---
        elif code.startswith("humidity_value_sub"):
            numero = code.replace("humidity_value_sub", "")
            s = ensure(numero, f"Sensore {numero}")
            s["hum_dp"] = dp_id
            if custom_name:
                nome = custom_name
                if nome.endswith("Humidity"):
                    nome = nome[:-8].strip()
                s["nome"] = nome

        # --- batteria sensori satellite ---
        elif code.startswith("battery_percentage"):
            numero = code.replace("battery_percentage", "")
            if numero == "":
                # battery_percentage senza suffisso appartiene alla base
                continue
            s = ensure(numero, f"Sensore {numero}")
            s["bat_dp"] = dp_id
            if custom_name:
                nome = custom_name
                if nome.endswith("Battery"):
                    nome = nome[:-7].strip()
                s["nome"] = nome

    return sensori


# ============================================================
# CHIAVE PHP LEGGIBILE DAL NOME DEL SENSORE
# "Cameretta" -> "cameretta", "Camera Letto" -> "camera_letto"
# ============================================================

def slugify(nome, fallback):
    if not nome:
        nome = fallback
    nfkd = unicodedata.normalize("NFKD", nome)
    ascii_str = nfkd.encode("ascii", "ignore").decode("ascii")
    ascii_str = ascii_str.lower().strip()
    ascii_str = re.sub(r"[^a-z0-9]+", "_", ascii_str).strip("_")
    return ascii_str or fallback


# ============================================================
# GENERAZIONE BLOCCO PHP $sensori
# ============================================================

PALETTE = [
    "#36a2eb", "#ff6384", "#4bc0c0", "#ff9f40",
    "#9966ff", "#c9cc3f", "#e91e8c", "#2ecc71",
]


def genera_blocco_php(sensori):
    # "base" prima, poi gli altri in ordine numerico dove possibile
    def ordina(chiave):
        return (0, "") if chiave == "base" else (1, chiave.zfill(4))

    chiavi_ordinate = sorted(sensori.keys(), key=ordina)

    righe = []
    righe.append("<?php")
    righe.append("// ============================================================")
    righe.append("// CONFIGURAZIONE SENSORI METEO")
    righe.append("// Generato automaticamente da genera_config.py il "
                  + time.strftime("%Y-%m-%d %H:%M"))
    righe.append("// Controlla i nomi/chiavi/colori e adattali se necessario:")
    righe.append("// la chiave di ogni sensore (es. 'base', 'cameretta', ...) deve")
    righe.append("// corrispondere alle colonne \"{chiave}_temp\"/\"{chiave}_hum\"")
    righe.append("// nella tabella `rilevazioni`.")
    righe.append("// ============================================================")
    righe.append("")
    righe.append("$sensori = [")

    chiavi_php = []
    for chiave_orig in chiavi_ordinate:
        s = sensori[chiave_orig]
        fallback = f"sensore_{chiave_orig}"
        chiave_php = "base" if chiave_orig == "base" else slugify(s["nome"], fallback)
        # evita collisioni di chiave (es. due sensori con lo stesso nome)
        base_chiave_php = chiave_php
        i = 2
        while chiave_php in chiavi_php:
            chiave_php = f"{base_chiave_php}_{i}"
            i += 1
        chiavi_php.append(chiave_php)

        colore = PALETTE[(len(chiavi_php) - 1) % len(PALETTE)]

        righe.append(f"    '{chiave_php}' => [")
        righe.append(f"        'nome'    => '{s['nome']}',")
        righe.append(f"        'color'   => '{colore}',")
        righe.append(f"        'temp_dp' => {json.dumps(s['temp_dp'])},")
        righe.append(f"        'hum_dp'  => {json.dumps(s['hum_dp'])},")
        righe.append(f"        'bat_dp'  => {json.dumps(s['bat_dp'])},")
        righe.append("    ],")

    righe.append("];")
    righe.append("")

    default_visibile = next((k for k in chiavi_php if k != "base"), chiavi_php[0])
    riferimento = "base" if "base" in chiavi_php else chiavi_php[0]

    righe.append("// Sensore mostrato di default (non nascosto) nel grafico della dashboard")
    righe.append(f"$sensoreDefaultVisibile = '{default_visibile}';")
    righe.append("")
    righe.append("// ============================================================")
    righe.append("// CONFRONTO PER NOTIFICA TELEGRAM (apri/chiudi finestre)")
    righe.append("// Verifica/adatta questi due valori in base a cosa vuoi confrontare.")
    righe.append("// ============================================================")
    righe.append(f"$confrontoSensore = '{default_visibile}';")
    righe.append(f"$confrontoRiferimento = '{riferimento}';")

    return "\n".join(righe) + "\n"


# ============================================================
# UNIONE CON IL RESTO DEL CONFIG (percorsi/db/telegram)
# I placeholder vanno comunque compilati a mano.
# ============================================================

RESTO_CONFIG_TEMPLATE = """<?php
// ============================================================
// CONFIGURAZIONE PYTHON
// ============================================================

$pythonPath = '/[percorso]/meteo/service/tuya/bin/python';
$scriptPath = '/[percorso]/meteo/service/tuyaJson.py';


// ============================================================
// CONFIGURAZIONE DATABASE
// ============================================================

$dbHost = $host = 'localhost';
$dbName = $db ='meteo';
$dbUser = $user ='user';
$dbPass = $pass = 'password';


// ============================================================
// CONFIGURAZIONE TELEGRAM
// ============================================================

define('BOT_TOKEN', 'xxxxxxx');

class Config
{
    const CHAT_ID = 'xxxxxxx';
}
"""


def genera_config_completo(blocco_sensori_php):
    # Rimuove l'header "<?php" duplicato dal blocco sensori
    corpo_sensori = blocco_sensori_php.split("\n", 1)[1]
    return RESTO_CONFIG_TEMPLATE.rstrip() + "\n\n\n" + corpo_sensori


# ============================================================
# PROGRAMMA PRINCIPALE
# ============================================================

def main():
    parser = argparse.ArgumentParser(
        description="Genera automaticamente il blocco/il file config.php dei sensori Tuya."
    )
    parser.add_argument(
        "--scrivi", action="store_true",
        help="Scrive un config.php completo (sensori + template db/telegram/python)."
    )
    parser.add_argument(
        "-o", "--output", default="config.php",
        help="Nome del file da scrivere quando si usa --scrivi (default: config.php)."
    )
    args = parser.parse_args()

    if not ACCESS_ID or not ACCESS_SECRET or not DEVICE_ID:
        print("Errore: compila ACCESS_ID, ACCESS_SECRET e DEVICE_ID in cima allo script "
              "(stessi valori usati in tuyaJson.py).", file=sys.stderr)
        raise SystemExit(1)

    print("Richiedo access token...", file=sys.stderr)
    token = get_new_token()
    access_token = token["access_token"]
    print("Token OK", file=sys.stderr)

    print("Leggo le proprietà della stazione...", file=sys.stderr)
    response = get_shadow_properties(access_token)

    if response.status_code != 200:
        print(response.text, file=sys.stderr)
        raise SystemExit(1)

    shadow_data = response.json()
    if not shadow_data.get("success"):
        print(shadow_data, file=sys.stderr)
        raise SystemExit(1)

    sensori = estrai_sensori(shadow_data)

    if not sensori:
        print("Nessun sensore trovato nella risposta Tuya.", file=sys.stderr)
        raise SystemExit(1)

    print(f"Trovati {len(sensori)} sensori.", file=sys.stderr)

    blocco_php = genera_blocco_php(sensori)

    if args.scrivi:
        contenuto = genera_config_completo(blocco_php)
        with open(args.output, "w", encoding="utf-8") as f:
            f.write(contenuto)
        print(f"\nScritto {args.output} — ricorda di compilare i placeholder "
              f"(percorsi Python, database, Telegram, e di controllare nomi/chiavi dei sensori).",
              file=sys.stderr)
    else:
        print()
        print(blocco_php)
        print("// Incolla questo blocco in fondo al tuo config.php esistente.", file=sys.stderr)


if __name__ == "__main__":
    main()
