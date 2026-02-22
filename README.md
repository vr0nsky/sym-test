# File Conversion API

API REST sviluppata con Symfony 7 che funge da proxy tra client backend e Keycloak per l'autenticazione, e gestisce job asincroni di conversione file tramite RabbitMQ.

> **Stato attuale (demo):** Nell'implementazione corrente il parametro `file` di `POST /api/jobs` è completamente ignorato — nessun file viene caricato sulla macchina. Il formato di input è hardcodato a `csv`, il path del file è fittizio e il worker produce un output dummy hardcodato (nessuna conversione reale viene eseguita). La validazione completa di upload, formato e MIME type è presente nel controller `JobController` nel blocco commentato `/* In un mondo normale */`, pronta per essere attivata.

## Architettura

```
CLIENT BACKEND
    │
    ├── POST /api/auth {client_id, client_secret}
    │   └── Symfony → Keycloak (client_credentials grant) → token JWT
    │
    ├── POST /api/jobs {output_format} + file
    │   └── Symfony → crea Job su DB → dispatcha messaggio su RabbitMQ
    │
    ├── GET /api/jobs/{id}
    │   └── Symfony → polling stato job
    │
    └── GET /api/jobs/{id}/download
        └── Symfony → stream file convertito
```

**Stack:**
- **Symfony 7** — framework PHP
- **Keycloak** — autenticazione (client_credentials grant)
- **RabbitMQ** — message broker per job asincroni
- **PostgreSQL** — persistenza job
- **Symfony Messenger** — gestione code e worker

## Requisiti

- PHP 8.3+
- Composer
- PostgreSQL 16+
- RabbitMQ
- Istanza Keycloak con un realm e client configurato

## Installazione

```bash
git clone <repository-url>
cd <project-dir>
composer install
```

Copia e configura il file `.env`:

```bash
cp .env .env.local
```

Configura le variabili:

```env
DATABASE_URL="postgresql://DB_USER:DB_PASSWORD@127.0.0.1:5432/DB_NAME?serverVersion=16&charset=utf8"
MESSENGER_TRANSPORT_DSN=amqp://RABBITMQ_USER:RABBITMQ_PASSWORD@RABBITMQ_HOST:5672/%2f/messages
KEYCLOAK_URL=https://your-keycloak-host
KEYCLOAK_REALM=your-realm
```

Esegui le migration:

```bash
php bin/console doctrine:migrations:migrate
```

## Avvio del consumer

Il worker è gestito tramite systemd e si avvia automaticamente con il sistema:

```bash
# Stato
systemctl status orango-worker

# Avvio / stop / riavvio
systemctl start orango-worker
systemctl stop orango-worker
systemctl restart orango-worker

# Log in tempo reale
tail -f /var/log/orango-worker.log
```

## Formato delle risposte

Tutte le risposte JSON seguono una struttura uniforme.

**Successo:**
```json
{
    "data": { ... }
}
```

**Errore:**
```json
{
    "error": {
        "code": "ERROR_CODE",
        "message": "Descrizione dell'errore"
    }
}
```

## API Reference

### Autenticazione

```bash
POST /api/auth
Content-Type: application/json

{
    "client_id": "your-client-id",
    "client_secret": "your-client-secret"
}
```

Risposta (passthrough Keycloak):
```json
{
    "access_token": "eyJ...",
    "expires_in": 1800,
    "token_type": "Bearer"
}
```

---

### Crea un job di conversione

```bash
POST /api/jobs
Authorization: Bearer {token}
Content-Type: multipart/form-data

file: <file>              # CSV, JSON, XLSX, ODS
output_format: json       # json | xml
```

Risposta `201`:
```json
{
    "data": {
        "job_id": 1,
        "status": "pending",
        "input_format": "csv",
        "output_format": "json",
        "created_at": "2026-02-21T16:40:37+00:00"
    }
}
```

---

### Stato del job

```bash
GET /api/jobs/{id}
Authorization: Bearer {token}
```

Risposta:
```json
{
    "data": {
        "job_id": 1,
        "status": "completed",
        "input_format": "csv",
        "output_format": "json",
        "output_file_path": "/var/www/.../var/outputs/job_xxx.json",
        "created_at": "2026-02-21T16:40:37+00:00"
    }
}
```

Stati possibili: `pending` → `processing` → `completed` | `failed`

---

### Download file convertito

```bash
GET /api/jobs/{id}/download
Authorization: Bearer {token}
```

Restituisce il file convertito come attachment. Disponibile solo quando lo status è `completed`.

```bash
curl -s https://your-api/api/jobs/1/download \
  -H "Authorization: Bearer $TOKEN" \
  -o result.json
```

---

## Codici di errore

| Code | HTTP | Descrizione |
|---|---|---|
| `MISSING_TOKEN` | 401 | Header Authorization assente |
| `INVALID_TOKEN` | 401 | Token JWT non valido o scaduto |
| `INVALID_OUTPUT_FORMAT` | 400 | Formato di output non supportato |
| `JOB_NOT_FOUND` | 404 | Job non trovato |
| `JOB_NOT_COMPLETED` | 409 | Job non ancora completato |
| `OUTPUT_FILE_NOT_FOUND` | 404 | File di output mancante |

---

## Esempio flusso completo con curl

```bash
# 1. Login
TOKEN=$(curl -s -X POST https://your-api/api/auth \
  -H "Content-Type: application/json" \
  -d '{"client_id": "YOUR_CLIENT_ID", "client_secret": "YOUR_CLIENT_SECRET"}' \
  | jq -r '.access_token')

# 2. Crea job
JOB_ID=$(curl -s -X POST https://your-api/api/jobs \
  -H "Authorization: Bearer $TOKEN" \
  -F "file=@myfile.csv" \
  -F "output_format=json" \
  | jq -r '.data.job_id')

# 3. Polling stato
curl -s https://your-api/api/jobs/$JOB_ID \
  -H "Authorization: Bearer $TOKEN" | jq .

# 4. Download risultato
curl -s https://your-api/api/jobs/$JOB_ID/download \
  -H "Authorization: Bearer $TOKEN" \
  -o result.json
```

## Sicurezza

- I token JWT vengono validati localmente tramite le chiavi pubbliche JWKS di Keycloak (cachiate per 1 ora)
- La scadenza del token è sempre verificata localmente senza chiamare Keycloak ad ogni richiesta
- Il MIME type dei file in upload viene verificato sui magic bytes, non solo sull'estensione
- L'API è progettata per comunicazione backend-to-backend: il `client_secret` non dovrebbe essere esposto a client pubblici (browser, app mobile)

## Testing

Il DB di test viene creato automaticamente con il suffisso `_test`. Prima di eseguire i test per la prima volta:

```bash
php bin/console doctrine:database:create --env=test
php bin/console doctrine:migrations:migrate --env=test
```

Crea `.env.test.local` con le credenziali del DB (non committato):

```env
DATABASE_URL="postgresql://USER:PASSWORD@127.0.0.1:5432/DB_NAME?serverVersion=16&charset=utf8"
```

Esegui i test:

```bash
php bin/phpunit --testdox
```

I test coprono:
- `JobController`: creazione job, stato, download (tutti i casi di errore)
- `AuthController`: validazione input, risposta Keycloak, fallimento Keycloak
- `RateLimiterListener`: route accettate, limiti superati, isolamento pubblico/privato
- `JwtListener`: validazione token, skip route pubbliche, cache JWKS
- `HomeController`: risposta dell'endpoint root
- `ProcessJobMessageHandler`: job non trovato, transizione di stato, output JSON e XML

In test env il `JwtListener` viene sostituito da uno stub (`JwtListenerStub`) che bypassa la validazione Keycloak, e il transport Messenger usa la modalità in-memory.
