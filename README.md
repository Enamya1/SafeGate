# SafeGate Local Setup Guide

This project runs **two backend services** together:
- `xiaowu` (Laravel API) on `http://127.0.0.1:8000`
- `python_service` (FastAPI AI service) on `http://127.0.0.1:8001`

Use this guide to run everything on your own device.

## 1) Prerequisites

Install these first:
- **Git**
- **PHP 8.2+**
- **Composer 2+**
- **Python 3.11+** (3.11 or 3.12 recommended for better package compatibility)
- **MySQL 8+**
- (Optional but recommended) **PowerShell 5+** on Windows

## 2) Clone And Open Project

```powershell
git clone <your-repo-url>
cd SafeGate
```

## 3) Configure Laravel Environment

From project root:

```powershell
cd xiaowu
copy .env.example .env
composer install
php artisan key:generate
```

Open `xiaowu\.env` and update database settings to MySQL (important because the Python service reads the same DB):

```env
DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=XiaoWu
DB_USERNAME=root
DB_PASSWORD=your_password
```

Also make sure these service-link settings exist in `xiaowu\.env`:

```env
PYTHON_SERVICE_BASE_URL=http://127.0.0.1:8001
PYTHON_INTERNAL_TOKEN=change-this-internal-token
LARAVEL_BASE_URL=http://127.0.0.1:8000
```

Then run migrations:

```powershell
php artisan migrate
```

## 4) Configure Python Service Environment (Optional File, But Recommended)

The Python app auto-loads env values from:
- `python_service\.env` (if present)
- `xiaowu\.env`

Create `python_service\.env` only if you want to override or add Python-specific values:

```env
AI_PROVIDER=qwen
AI_MODEL=qwen-plus
AI_API_KEY=your_ai_api_key

VISUAL_SEARCH_DEVICE=cpu
VISUAL_SEARCH_CLIP_MODEL=ViT-B-32
VISUAL_SEARCH_CLIP_PRETRAINED=laion2b_s34b_b79k
```

Notes:
- If `AI_API_KEY` is empty, AI endpoints still run with fallback responses.
- Visual search dependencies (`torch`, `open-clip-torch`, `faiss-cpu`) may take time to install the first time.

## 5) Start Both Services (Recommended)

From project root:

```powershell
powershell -ExecutionPolicy Bypass -File .\run-dev.ps1
```

What this script does:
- Creates `python_service\.venv` if missing
- Installs Python requirements
- Starts Laravel on port `8000`
- Starts FastAPI on port `8001`

## 6) Manual Start (Alternative)

If you prefer separate terminals:

Terminal 1 (Laravel):
```powershell
cd xiaowu
php artisan serve --host 0.0.0.0 --port 8000
```

Terminal 2 (Python):
```powershell
cd python_service
python -m venv .venv
.\.venv\Scripts\python.exe -m pip install -r requirements.txt
.\.venv\Scripts\python.exe -m uvicorn app.main:app --host 0.0.0.0 --port 8001 --reload
```

## 7) Verify Everything Is Running

Check Laravel:
- `http://127.0.0.1:8000`
- `http://127.0.0.1:8000/api/health-check`

Check Python:
- `http://127.0.0.1:8001/health`

## 8) Useful Commands

Laravel tests:

```powershell
cd xiaowu
php artisan test
```

Stop services:
- If using `run-dev.ps1`, press `Ctrl + C` in that terminal.
- If started manually, stop each terminal process with `Ctrl + C`.

## 9) Common Issues

- **Database connection errors**
  - Confirm `DB_*` values in `xiaowu\.env`.
  - Confirm MySQL is running and database exists.
  - Run `php artisan migrate` again.

- **Python package install fails (Torch / Faiss)**
  - Upgrade pip: `python -m pip install --upgrade pip`
  - Prefer Python 3.11/3.12.
  - Recreate venv if needed: delete `python_service\.venv` and run `run-dev.ps1` again.

- **Laravel cannot reach Python service**
  - Confirm `PYTHON_SERVICE_BASE_URL=http://127.0.0.1:8001` in `xiaowu\.env`.
  - Confirm both services are running.

- **Internal token errors between services**
  - Ensure `PYTHON_INTERNAL_TOKEN` is exactly the same value where used.
