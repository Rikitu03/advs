# Start ADVS

This guide covers first-time setup and the normal local startup workflow on
Windows 11 with XAMPP and PowerShell.

## Prerequisites

- XAMPP with PHP 8.2 and MySQL 8-compatible server
- Composer
- Node.js and npm
- Tesseract OCR and Poppler for PDF processing
- The repository Python environment at `python/env/`
- Trained model artifacts in `python/models/`

Check the main tools:

```powershell
php -v
composer --version
node.exe -v
npm.cmd -v
python\env\Scripts\python.exe --version
```

## First-Time Setup

Run these commands from the repository root:

```powershell
composer install
npm.cmd ci
Copy-Item .env.example .env
php artisan key:generate
```

Create a MySQL database named `advs`, then update `.env`:

```dotenv
APP_URL=http://localhost:8000

# WebAuthn requires a hostname. Do not open the app at 127.0.0.1.
PASSKEYS_RELYING_PARTY_ID=localhost
PASSKEYS_ALLOWED_ORIGINS=http://localhost:8000,http://localhost:8100

DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=advs
DB_USERNAME=root
DB_PASSWORD=

QUEUE_CONNECTION=database
ADVS_PYTHON_BIN=C:\xampp\htdocs\projects\advs\python\env\Scripts\python.exe

ML_API_URL=http://127.0.0.1:7860
ML_API_TOKEN=devtoken
ML_API_TIMEOUT=180
ML_API_CONNECT_TIMEOUT=10
```

Create the Python API environment file:

```powershell
Copy-Item python\.env.api.example python\.env.api
```

Set at least this value in `python/.env.api`:

```dotenv
API_TOKEN=devtoken
```

If Tesseract is not on `PATH`, also set:

```dotenv
TESSERACT_CMD=C:/Program Files/Tesseract-OCR/tesseract.exe
```

Build the database and frontend assets:

```powershell
php artisan migrate:fresh --seed --no-interaction
php artisan storage:link
npm.cmd run build
```

`migrate:fresh` deletes existing application data. For an existing database,
use `php artisan migrate --no-interaction` instead.

## Start the Application

ADVS needs two terminal sessions. `composer run dev` starts Laravel, the queue
listener, and Vite. FastAPI runs separately.

### Terminal 1: Laravel, Queue, and Vite

From the repository root:

```powershell
composer run dev
```

This starts:

- Laravel at `http://127.0.0.1:8000`
- Vite development assets
- Dedicated mail queue worker for `mail` with a 60-second job timeout
- Dedicated document queue worker for `document-processing` with a 360-second
  job timeout

Startup first checks the configured database connection. If MySQL is stopped
or the `DB_*` settings are invalid, the command fails before the other
processes start with an actionable error. The queue process is also restarted
automatically by the queue-only `scripts/queue-worker.ps1` supervisor after a
transient database disconnect. Each queue worker has its own restart loop, so
slow OCR/ML jobs cannot block authentication email delivery. Laravel normally
exits a database worker with status 0 when it detects a lost connection, so
the queue-only restart loops are required for a local multi-process development
command. Non-zero worker exits are still propagated so application errors
remain visible.

### Terminal 2: Python ML API

```powershell
Set-Location .\python
.\env\Scripts\python.exe -m uvicorn api.main:app --host 127.0.0.1 --port 7860
```

Model loading can take time. Wait until startup completes before submitting a
document.

Check the service from another terminal:

```powershell
curl.exe http://127.0.0.1:7860/health
curl.exe http://127.0.0.1:7860/ready
```

- `/health` confirms the process is alive and reports model load states.
- `/ready` returns 200 only when the required artifacts match
  `python/models/manifest.json`; otherwise it returns 503 with exact reasons.

## Open ADVS

Visit `http://localhost:8000`.

Seeded accounts use password `password`:

| Role | Email |
|---|---|
| Vendor | `vendor@advs.test` |
| Compliance officer | `officer@advs.test` |
| Compliance officer | `officer2@advs.test` |
| Administrator | `admin@advs.test` |
| Administrator | `admin2@advs.test` |

## Normal Daily Startup

When dependencies and the database already exist:

```powershell
# Terminal 1, repository root
composer run dev

# Terminal 2
Set-Location .\python
.\env\Scripts\python.exe -m uvicorn api.main:app --host 127.0.0.1 --port 7860
```

Do not run `migrate:fresh` during normal startup.

## Run Tests

Laravel:

```powershell
php artisan config:clear
php artisan test --compact
```

Python:

```powershell
python\env\Scripts\python.exe -m pytest python\tests -q
```

Frontend build:

```powershell
npm.cmd run build
```

Formatting after PHP changes:

```powershell
vendor\bin\pint --dirty --format agent
```

## Troubleshooting

### Laravel cannot connect to MySQL

Start MySQL in XAMPP and verify `.env` uses database `advs`. Clear cached
configuration after changing environment values:

```powershell
php artisan optimize:clear
```

### Documents stay queued

Confirm Terminal 1 is running and includes the dedicated document queue worker.
The required queue is `document-processing`; the mail worker does not process
document jobs.

### ML API is unreachable

Confirm FastAPI is listening on port 7860 and that `ML_API_TOKEN` matches
Python `API_TOKEN`.

```powershell
curl.exe http://127.0.0.1:7860/health
curl.exe http://127.0.0.1:7860/ready
```

### `/ready` returns 503

Read the `errors` array. Common causes are missing weights, a missing manifest,
or a checksum mismatch after replacing a model file. Update the manifest when
the deployed artifact set changes.

### OCR fails on Windows

Set `TESSERACT_CMD` in `python/.env.api` and ensure Poppler's `bin` directory is
available on `PATH` for PDF conversion.

### Frontend changes do not appear

Keep `composer run dev` running, or rebuild production assets:

```powershell
npm.cmd run build
```

### Port already in use

Check the process using the port:

```powershell
netstat -ano | Select-String ':8000|:7860|:5173'
```

Stop the old process or start the affected service on another port and update
the corresponding environment URL.
