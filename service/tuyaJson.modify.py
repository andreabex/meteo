import json
import tinytuya

# ============================================================
# CONFIGURAZIONE
# ============================================================

DEVICE_ID = ""
LOCAL_KEY = ""
ADDRESS = "192.168.x.x"
VERSION = 3.5

if __name__ == "__main__":
  d = tinytuya.Device(
      dev_id=DEVICE_ID, address=ADDRESS, local_key=LOCAL_KEY, version=VERSION
  )

  data = d.status()

  # Stampa il JSON grezzo su stdout
  print(json.dumps(data, ensure_ascii=False))

