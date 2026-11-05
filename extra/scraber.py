#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""
scraber.py - genera o aggiorna web/stat.json, il file con le medie climatiche di riferimento
usate dalla dashboard (linea tratteggiata nel grafico e "scarto dalla media climatica").

Scarica dall'archivio storico di Open-Meteo (gratuito, senza chiave) i valori GIORNALIERI di
temperatura media e umidità relativa media di un trentennio (di default 1991-2020, il periodo di
riferimento OMM) per la località indicata, e ne ricava:
  - 52 medie settimanali: blocchi di 7 giorni dal 1 gennaio (l'ultimo ne ha 8), anno non
    bisestile (il 29 febbraio viene scartato), con una leggera smussatura circolare;
  - 12 medie mensili.
La dashboard (web/index.php) usa le settimanali; le mensili restano nel file come riferimento.

Usa solo la libreria standard di Python 3: non serve il venv "tuya".

Esempi:
  python3 scraber.py                                  # aggiorna per la località già presente in stat.json
  python3 scraber.py --nome Genova --lat 44.41 --lon 8.93
  python3 scraber.py --dry-run                        # mostra il risultato senza scrivere il file
  python3 scraber.py --da 1981 --a 2010 --modello era5_land

Nota: sono dati di rianalisi su griglia (circa 10-25 km), non misure di una stazione. Vicino
alla costa o in zone di montagna la quota/posizione della cella può differire da quella reale
(la quota usata dal modello viene stampata e salvata nel file): aspettati scarti sistematici
di qualche decimo di grado, o più, rispetto a una stazione locale.
"""

import argparse
import json
import os
import re
import sys
import tempfile
import time
import urllib.error
import urllib.parse
import urllib.request
from datetime import date
from pathlib import Path

BASE_URL = "https://archive-api.open-meteo.com/v1/archive"
USER_AGENT = "meteo-scraber/1.0 (+https://github.com/andreabex/meteo)"
RITARDI_RIPROVA = (2, 5, 15)          # secondi di attesa tra i tentativi (429 / errori del server / rete)

BLOCCHI = 52
GIORNI_CUM = [0, 31, 59, 90, 120, 151, 181, 212, 243, 273, 304, 334]   # giorni cumulati, anno non bisestile
PERCORSO_PREDEFINITO = Path(__file__).resolve().parent.parent / "web" / "stat.json"

# Località di partenza se stat.json non esiste ancora e non si passano --lat/--lon
POSIZIONE_PREDEFINITA = ("Imperia", 43.88, 8.03)


class ErroreApi(Exception):
    def __init__(self, messaggio, codice=None):
        super().__init__(messaggio)
        self.codice = codice


def blocco_settimana(mese, giorno):
    """Blocco settimanale 0..51. Stesso calcolo di bloccoSettimana() in web/index.php."""
    if mese == 2 and giorno > 28:
        giorno = 28
    return min(BLOCCHI - 1, (GIORNI_CUM[mese - 1] + giorno - 1) // 7)


def messaggio_errore(corpo):
    """Estrae il motivo dalla risposta di errore di Open-Meteo ({"error": true, "reason": "..."})."""
    try:
        return str(json.loads(corpo).get("reason", corpo))[:300]
    except (ValueError, AttributeError):
        return corpo[:300]


def richiedi(url):
    """GET con qualche nuovo tentativo su 429, errori 5xx e problemi di rete."""
    tentativi = len(RITARDI_RIPROVA) + 1
    for n in range(tentativi):
        richiesta = urllib.request.Request(url, headers={"User-Agent": USER_AGENT, "Accept": "application/json"})
        try:
            with urllib.request.urlopen(richiesta, timeout=120) as risposta:
                return json.loads(risposta.read().decode("utf-8"))
        except urllib.error.HTTPError as e:
            motivo = messaggio_errore(e.read().decode("utf-8", "replace"))
            if e.code in (429, 500, 502, 503, 504) and n < tentativi - 1:
                attesa = RITARDI_RIPROVA[n]
                print(f"  risposta {e.code} ({motivo}); riprovo tra {attesa} s...", file=sys.stderr)
                time.sleep(attesa)
                continue
            if e.code == 429:
                motivo += " (limite di richieste di Open-Meteo raggiunto: riprova più tardi)"
            raise ErroreApi(f"HTTP {e.code}: {motivo}", e.code)
        except (urllib.error.URLError, TimeoutError, OSError) as e:
            if n < tentativi - 1:
                attesa = RITARDI_RIPROVA[n]
                print(f"  connessione non riuscita ({e}); riprovo tra {attesa} s...", file=sys.stderr)
                time.sleep(attesa)
                continue
            raise ErroreApi(f"connessione non riuscita: {e}")
        except ValueError as e:
            raise ErroreApi(f"risposta non valida (non è JSON): {e}")


def scarica(base_url, lat, lon, da, a, modello, con_umidita):
    variabili = ["temperature_2m_mean"] + (["relative_humidity_2m_mean"] if con_umidita else [])
    parametri = {
        "latitude": lat,
        "longitude": lon,
        "start_date": f"{da}-01-01",
        "end_date": f"{a}-12-31",
        "daily": ",".join(variabili),
        "timezone": "auto",              # giorni "locali"; senza, le medie giornaliere sarebbero in GMT
    }
    if modello:
        parametri["models"] = modello
    dati = richiedi(base_url + "?" + urllib.parse.urlencode(parametri))

    giornalieri = dati.get("daily") if isinstance(dati, dict) else None
    if not isinstance(giornalieri, dict) or "time" not in giornalieri or "temperature_2m_mean" not in giornalieri:
        raise ErroreApi("risposta inattesa: mancano 'daily', 'time' o 'temperature_2m_mean'")
    n = len(giornalieri["time"])
    for nome in variabili:
        if nome in giornalieri and len(giornalieri[nome]) != n:
            raise ErroreApi(f"risposta inattesa: '{nome}' ha una lunghezza diversa da 'time'")
    return dati


def aggrega(tempi, valori, anni):
    """Medie per blocco settimanale e per mese. Scarta i valori nulli e il 29 febbraio."""
    somme_b, conta_b = [0.0] * BLOCCHI, [0] * BLOCCHI
    somme_m, conta_m = [0.0] * 12, [0] * 12
    for t, v in zip(tempi, valori):
        if v is None:
            continue
        d = date.fromisoformat(t[:10])
        if d.month == 2 and d.day == 29:
            continue
        b = blocco_settimana(d.month, d.day)
        somme_b[b] += v
        conta_b[b] += 1
        somme_m[d.month - 1] += v
        conta_m[d.month - 1] += 1

    # Copertura minima: almeno metà dei valori attesi in ogni blocco/mese, altrimenti i dati non bastano
    for b in range(BLOCCHI):
        attesi = anni * (8 if b == BLOCCHI - 1 else 7)
        if conta_b[b] < attesi * 0.5:
            raise ErroreApi(f"dati insufficienti per la settimana {b + 1} ({conta_b[b]} valori su circa {attesi})")
    for m in range(12):
        if conta_m[m] == 0:
            raise ErroreApi(f"nessun dato per il mese {m + 1}")
    settimanali = [somme_b[b] / conta_b[b] for b in range(BLOCCHI)]
    mensili = [somme_m[m] / conta_m[m] for m in range(12)]
    return settimanali, mensili, sum(conta_b)


def smussa(valori, passate):
    """Media mobile circolare [1, 2, 1] / 4 (dicembre si raccorda con gennaio)."""
    v = list(valori)
    for _ in range(passate):
        n = len(v)
        v = [(v[i - 1] + 2 * v[i] + v[(i + 1) % n]) / 4 for i in range(n)]
    return v


def formatta_json(oggetto):
    """JSON leggibile: le liste di soli numeri restano su una riga."""
    testo = json.dumps(oggetto, indent=2, ensure_ascii=False)
    testo = re.sub(r"\[\s+([-\d.,\s]+?)\s+\]", lambda m: "[" + " ".join(m.group(1).split()) + "]", testo)
    return testo + "\n"


def scrivi_atomico(percorso, testo):
    percorso.parent.mkdir(parents=True, exist_ok=True)
    fd, tmp = tempfile.mkstemp(dir=str(percorso.parent), prefix=".stat-", suffix=".tmp")
    try:
        with os.fdopen(fd, "w", encoding="utf-8") as f:
            f.write(testo)
        os.chmod(tmp, 0o644)
        os.replace(tmp, str(percorso))
    except BaseException:
        if os.path.exists(tmp):
            os.unlink(tmp)
        raise


def posizione_da_file(percorso):
    """Nome, lat e lon già presenti in stat.json (per aggiornare senza ripeterli)."""
    try:
        with open(percorso, encoding="utf-8") as f:
            p = json.load(f).get("posizione") or {}
        return p.get("nome"), float(p["lat"]), float(p["lon"])
    except (OSError, ValueError, KeyError, TypeError, AttributeError):
        return None, None, None


def main():
    ap = argparse.ArgumentParser(
        description="Genera/aggiorna web/stat.json con le medie climatiche settimanali e mensili (da Open-Meteo).")
    ap.add_argument("--nome", help="nome della località (etichetta nel grafico)")
    ap.add_argument("--lat", type=float, help="latitudine in gradi decimali")
    ap.add_argument("--lon", type=float, help="longitudine in gradi decimali")
    ap.add_argument("--da", type=int, default=1991, help="primo anno (default 1991)")
    ap.add_argument("--a", type=int, default=2020, help="ultimo anno (default 2020)")
    ap.add_argument("--modello", help="modello Open-Meteo (es. era5, era5_land, cerra); default: quello predefinito dell'API")
    ap.add_argument("--smussa", type=int, default=1,
                    help="passate di smussatura circolare sulle medie settimanali (default 1, 0 = nessuna)")
    ap.add_argument("--out", type=Path, default=PERCORSO_PREDEFINITO, help=f"file di uscita (default {PERCORSO_PREDEFINITO})")
    ap.add_argument("--base-url", default=BASE_URL, help="indirizzo dell'API (per prove o per un'istanza Open-Meteo propria)")
    ap.add_argument("--dry-run", action="store_true", help="mostra il risultato senza scrivere il file")
    args = ap.parse_args()

    # Località: argomenti, altrimenti quella già nel file, altrimenti quella predefinita (Imperia)
    nome_f, lat_f, lon_f = posizione_da_file(args.out)
    nome = args.nome or nome_f or POSIZIONE_PREDEFINITA[0]
    if (args.lat is None) != (args.lon is None):
        ap.error("--lat e --lon vanno indicate insieme")
    if args.lat is not None:
        lat, lon = args.lat, args.lon
        if args.nome is None and nome_f and (abs(lat - lat_f) > 0.01 or abs(lon - lon_f) > 0.01):
            ap.error("hai cambiato le coordinate: indica anche --nome della nuova località")
    elif lat_f is not None:
        lat, lon = lat_f, lon_f
    else:
        lat, lon = POSIZIONE_PREDEFINITA[1], POSIZIONE_PREDEFINITA[2]
    if not (-90 <= lat <= 90 and -180 <= lon <= 180):
        ap.error("coordinate fuori intervallo")

    ultimo_anno_completo = date.today().year - 1
    if not (1940 <= args.da <= args.a <= ultimo_anno_completo):
        ap.error(f"intervallo di anni non valido: servono 1940 <= da <= a <= {ultimo_anno_completo}")
    anni = args.a - args.da + 1
    if anni < 5:
        ap.error("servono almeno 5 anni (meglio 30) per medie climatiche sensate")
    if anni < 20:
        print(f"Attenzione: solo {anni} anni; le medie settimanali saranno più rumorose di un trentennio.", file=sys.stderr)
    if args.smussa < 0:
        ap.error("--smussa non può essere negativo")

    print(f"Scarico {args.da}-{args.a} per {nome} ({lat:.2f}, {lon:.2f}) da Open-Meteo...")
    try:
        try:
            dati = scarica(args.base_url, lat, lon, args.da, args.a, args.modello, con_umidita=True)
        except ErroreApi as e:
            # Se l'API rifiuta la variabile dell'umidità si prosegue con la sola temperatura
            if e.codice == 400 and "humidity" in str(e).lower():
                print(f"Attenzione: umidità non disponibile ({e}); scrivo solo la temperatura.", file=sys.stderr)
                dati = scarica(args.base_url, lat, lon, args.da, args.a, args.modello, con_umidita=False)
            else:
                raise

        tempi = dati["daily"]["time"]
        sett_t, mens_t, usati = aggrega(tempi, dati["daily"]["temperature_2m_mean"], anni)
        sett_t = smussa(sett_t, args.smussa)

        settimanale = {"temp": [round(v, 2) for v in sett_t]}
        mensile = {"temp": [round(v, 2) for v in mens_t]}
        if "relative_humidity_2m_mean" in dati["daily"]:
            try:
                sett_h, mens_h, _ = aggrega(tempi, dati["daily"]["relative_humidity_2m_mean"], anni)
                sett_h = [min(100.0, max(0.0, v)) for v in smussa(sett_h, args.smussa)]
                settimanale["hum"] = [round(v, 1) for v in sett_h]
                mensile["hum"] = [round(v, 1) for v in mens_h]
            except ErroreApi as e:
                print(f"Attenzione: umidità scartata ({e}); scrivo solo la temperatura.", file=sys.stderr)
    except ErroreApi as e:
        print(f"Errore: {e}", file=sys.stderr)
        return 1

    risultato = {
        "versione": 1,
        "etichetta": f"Media clim. {nome} {args.da}-{args.a}",
        "posizione": {"nome": nome, "lat": lat, "lon": lon},
        "periodo": f"{args.da}-{args.a}",
        "fonte": "Open-Meteo Historical Weather API, medie giornaliere" +
                 (f", modello {args.modello}" if args.modello else ""),
        "quota_modello_m": dati.get("elevation"),
        "generato": date.today().isoformat(),
        "note": "settimanale = 52 blocchi di 7 giorni dal 1 gennaio (l'ultimo di 8), anno non bisestile senza 29 febbraio, "
                f"smussatura circolare x{args.smussa}; generato da extra/scraber.py",
        "settimanale": settimanale,
        "mensile": mensile,
    }

    oggi = date.today()
    b_oggi = blocco_settimana(oggi.month, oggi.day)
    t = settimanale["temp"]
    print(f"Valori giornalieri usati: {usati} ({anni} anni, 29 febbraio escluso)")
    if dati.get("elevation") is not None:
        print(f"Quota della cella del modello: {dati['elevation']} m")
    print(f"Temperatura settimanale: min {min(t):.1f} °C (sett. {t.index(min(t)) + 1}), "
          f"max {max(t):.1f} °C (sett. {t.index(max(t)) + 1})")
    print(f"Oggi (sett. {b_oggi + 1}): norma {t[b_oggi]:.1f} °C"
          + (f", umidità {settimanale['hum'][b_oggi]:.0f} %" if "hum" in settimanale else ""))

    if args.dry_run:
        print("\n--dry-run: nessun file scritto. Contenuto:\n")
        print(formatta_json(risultato))
        return 0
    try:
        scrivi_atomico(args.out, formatta_json(risultato))
    except OSError as e:
        print(f"Errore: impossibile scrivere {args.out}: {e}", file=sys.stderr)
        return 1
    print(f"Scritto: {args.out}")
    return 0


if __name__ == "__main__":
    sys.exit(main())
