# File Conversion API

API REST sviluppata con Symfony 7 che funge da proxy tra client backend e Keycloak per l'autenticazione, e gestisce job asincroni di conversione file tramite RabbitMQ.

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

Il consumer deve essere in esecuzione per processare i job in background:

```bash
php bin/console messenger:consume async --time-limit=3600 -vv
```

In produzione si consiglia di gestirlo con `supervisord` per mantenerlo sempre attivo.

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

Risposta:
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
    "job_id": 1,
    "status": "pending",
    "input_format": "csv",
    "output_format": "json",
    "created_at": "2026-02-21T16:40:37+00:00"
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
    "job_id": 1,
    "status": "completed",
    "input_format": "csv",
    "output_format": "json",
    "output_file_path": "/var/www/.../var/outputs/job_xxx.json",
    "created_at": "2026-02-21T16:40:37+00:00"
}
```

Stati possibili: `pending` → `processing` → `completed`

---

### Download file convertito

```bash
GET /api/jobs/{id}/download
Authorization: Bearer {token}
```

Restituisce il file convertito come attachment. Disponibile solo quando lo status è `completed`.

```bash
# Esempio con salvataggio su disco
curl -s https://your-api/api/jobs/1/download \
  -H "Authorization: Bearer $TOKEN" \
  -o result.json
```

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
  | jq -r '.job_id')

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

## Note sulla demo

La validazione del file in upload è attualmente commentata per semplicità di test via curl. Il codice completo di validazione è presente nel controller `JobController` all'interno del blocco commentato `/* In un mondo normale */`.

## Testing

```bash
./vendor/bin/phpunit --testdox
```

I test coprono:
- `JwtListener`: validazione token, skip route pubbliche, cache JWKS