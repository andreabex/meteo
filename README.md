# Progetto Meteo
Web app per visualizzare sensore temperatura (base + 3 sonde) basata su Tuya
Visualizza altresì dati meteo tratti da Open-meteo
link a vari servizi meteo


## Requisiti
- MariaDB
- PHP 8.x
- Python 3.x + librerie 

## Installazione su un nuovo server
1. Clone repo: `git clone https://github.com/andreabex/meteo.git [percorso]/meteo`
2. Configurazione: `cp config.example.php config.php` e modifica i parametri (entrambi files in web/ s>
3. Database `cd extra` poi  `mysql -u root -p nome_db < database_schema.sql`
4. nginx: aggiungere quanto contenuto in extra/default al  `sudo nano /etc/nginx/sites-available/defau>
5. Copiare/aggiungere il contenuto di extra/root in crontab con  `crontab -e` (incollare: */15 * * * *>

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
5) Recuperare `ACCESS_ID` e `SECRET_I`D (è diponibile anche il `DEVICE_ID`) 
______________________________________________________________________________
### Questo è in parte spiegato con screenshot in https://pypi.org/project/tinytuya/

## Recupero local_key
inserire i valori ottenuti in `genera_config.py` ed eseguirlo:
`cd /[percorso]/meteo/extra`
`python3 genera_config.py`
verrà creato il file `config.php`
Modificare manualmente `config.php` con i propri dati
Copiare i dati necessari anche in `cd /[percorso]/meteo/service/tuyaJson.py`

## Modificare
Adattare nomi dei dispositivi in database_schema.sql

INFINE:

dal brower chiamare localhost:1000
