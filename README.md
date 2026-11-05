# Progetto Meteo
Web app per visualizzare sensore temperatura (base + 3 sonde) basata su Tuya
Visualizza altresì dati meteo tratti da Open-meteo
link a vari servizi meteo

Il progetto prevede 
 - un crontab che rileva i dati dai sensori ogni 15 minuti e li memorizza in un database (cartella service)
 - una pagina web che appena aperta rileva i valori dei sensori e visualizza i dati memorizzati nel database


## Requisiti
- MariaDB
- PHP 8.x
- Python 3.x + librerie 

## Importazione 
clonare il progetto in una cartella /[percorso]/meteo NON ROOT

## Istallare python tinytuya in venv 
`cd /[perorso]/meteo/service`
`python3 -m venv tuya`
`source tuya/bin/activate`
`pip install tinytuya requests`
`deactivate`

_______________________________________________________________________________
## Recupero credenziali tuya
1) Registrarsi o accedere come sviluppatore al portale Tuya
2) Se il dipositivo era già stato  registrato passare al punto 5
3) Recuperare il DEVICE_ID dall'applicazione Tuya su smartphone (menu -> device info)
4) Registrare il dispositivo sul sito Tuya
5) Recuperare `ACCESS_ID` e `SECRET_ID` (è diponibile anche il `DEVICE_ID`) 
______________________________________________________________________________
### Questo è in parte spiegato con screenshot in https://pypi.org/project/tinytuya/

## Recupero local_key
Inserire i valori ottenuti in `genera_config.py` ed eseguirlo:
`cd /[percorso]/meteo/extra`
`python3 genera_config.py`
verrà creato il file `config.php`

## Modificare
Modificare manualmente `config.php` con i propri dati
Copiare i dati necessari anche in tuyaJson.py partendo da file di esempio 
`nano /[percorso]/meteo/service/tuyaJson.modify.py`
`mv /[percorso]/meteo/service/tuyaJson.modify.py  /[percorso]/meteo/service/tuyaJson.py`
`

Adattare nomi dei sensori nello schema del DB `cd /[percorso]/meteo/extra/database_schema.sql`

## Creare Database
`cd /[percorso]/meteo/extra`
`mysql -u root -p nome_db < database_schema.sql`

## Configurare Nginx
aggiungere quanto contenuto in extra/default al default: `sudo nano /etc/nginx/sites-available/default`

## Configurare crontab
eseguire `crontab -e` incollare `*/15 * * * * /usr/bin/php /var/www/meteo/service/salva_meteo.php >> /var/www/meteo/service/log.log 2>&1`

## Medie climatiche di riferimento (`web/stat.json`)
La linea tratteggiata "media climatica" nel grafico e lo "scarto dalla media climatica" usano i dati del file `web/stat.json`.
Il file fornito contiene le **medie mensili di Imperia** (1991-2020, stazione di Capo Mele): la dashboard le interpola in medie settimanali, così non c'è il "gradino" al cambio di mese.

**Se la tua località è diversa**, o per avere medie settimanali vere calcolate da dati giornalieri, genera il file con `extra/scraber.py`. Scarica da Open-Meteo gli anni 1991-2020 (gratuito, senza chiave; solo libreria standard, non serve il venv):

`python3 /[percorso]/meteo/extra/scraber.py --nome "Genova" --lat 44.41 --lon 8.93`

Per aggiornare il file senza ripetere la località basta `python3 extra/scraber.py`. Opzioni utili: `--da` e `--a` (anni), `--modello` (es. `era5_land`), `--smussa` (0 = nessuna smussatura), `--dry-run` (mostra il risultato senza scrivere).

Sono dati di rianalisi su griglia (circa 10-25 km), non misure di una stazione: la quota della cella usata dal modello è salvata nel file (`quota_modello_m`) e vicino alla costa o in montagna possono esserci scarti sistematici. Se la località impostata nella pagina non coincide con quella di `stat.json`, sotto il grafico compare un avviso con il comando da lanciare. Il file si può anche scrivere a mano (formato descritto nei commenti all'inizio di `web/index.php`).

INFINE:

dal brower chiamare localhost:1000
