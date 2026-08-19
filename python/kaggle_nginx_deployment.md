# Migration Spec (Final): FastAPI ML API → own GitHub repo → Kaggle (free GPU) + ngrok

## How this document is used

This file is an execution spec for an agentic coding tool. Tasks are ordered; do them in sequence. Sections marked **HUMAN** are manual and must NOT be attempted by the agent.

## Context

- The FastAPI app lives in `<LARAVEL_ROOT>/python` (e.g. `C:\xampp\htdocs\projects\advs\python`), a subdirectory of a Laravel project. It has **no git repo of its own yet**; the parent Laravel dir may or may not be a git repo.
- Entrypoint: `api.main:app` via uvicorn, port **7860**.
- Current layout inside `python/`: `api/`, `models/` (trained TF/HF models, LARGE), `backup_models/`, `data/`, `env/` (Windows venv), `tmp/`, `augraphy_cache/`, `scripts/`, `tests/`, `notebooks/`, `Dockerfile`, `requirements.txt`, `requirements-api.txt`, `.env.api` (secrets), `.env.api.example`.
- Target architecture:
  - **Code** → new private GitHub repo `advs-api` (repo root == current `python/` dir).
  - **Models** → private Kaggle Dataset `advs-models` (mounted read-only at `/kaggle/input/advs-models`).
  - **Secrets** → Kaggle Secrets; `.env.api` is materialized at runtime from them.
  - **Hosting** → Kaggle notebook (GPU T4, ~30 GB RAM) runs uvicorn + ngrok; public URL printed each session.
- Hugging Face Spaces is no longer free for Docker/Gradio — do not use it for compute.
- After extraction, repo root == `python/`, so all paths below (`api/`, `deploy/kaggle/`, …) are relative to the new repo root.

---

## HUMAN — one-time manual steps (agent must wait for these)

1. Create an empty **private** GitHub repo (no README): `advs-api`.
   (Optional: `gh repo create advs-api --private`.)
2. Create a free ngrok account → copy the authtoken.
3. Upload the `python/models/` folder as a **private Kaggle Dataset** named `advs-models`
   (web UI drag-drop or `kaggle datasets create`). Current Kaggle limit ≈ 20 GB per dataset — confirm total size first.
4. In Kaggle (notebook → Secrets), create one secret per key listed in `.env.api.example`, plus:
   `NGROK_AUTHTOKEN`, and `GITHUB_TOKEN` (personal access token, needed because the repo is private).
5. Note the GitHub username: `<GITHUB_USER>`.

---

## Task 1 — Git hygiene (BEFORE any commit)

Create/update `python/.gitignore` with exactly:

```text
env/
tmp/
augraphy_cache/
models/
backup_models/
*.log
.env.api
__pycache__/
*.pyc
```

No git commands yet. This file must exist before Task 2's first commit.

## Task 2 — Extract `python/` into its own GitHub repo

Do NOT physically move the folder; Laravel integration must keep working.

1. Init and push:

   ```bash
   cd <LARAVEL_ROOT>/python
   git init -b main
   git add .
   git commit -m "Initial commit: FastAPI backend (code only)"
   git remote add origin https://github.com/<GITHUB_USER>/advs-api.git
   git push -u origin main
   ```

2. If `<LARAVEL_ROOT>` is a git repo, stop tracking `python/` there:

   ```bash
   cd <LARAVEL_ROOT>
   git rm -r --cached python
   # add a line `python/` to the parent .gitignore
   git commit -m "Extract python API into its own repository"
   git push
   ```

   If the parent is not a git repo, skip this step.

3. Verify:
   - `git ls-files` in the new repo contains nothing under `models/`, `backup_models/`, `env/`, `tmp/`, and no `.env.api` or `*.log`.
   - No tracked file exceeds 50 MB.
   - Parent repo (if any) no longer lists python files in `git status`.

## Task 3 — Env-driven paths

Create `api/config.py`:

```python
import os
from pathlib import Path

BASE_DIR = Path(__file__).resolve().parent.parent
MODELS_DIR = Path(os.environ.get("MODELS_DIR", BASE_DIR / "models"))
DATA_DIR = Path(os.environ.get("DATA_DIR", BASE_DIR / "data"))
```

Replace every hardcoded `models/`, `backup_models/`, or absolute Windows path inside `api/` with these constants. Do NOT change model/inference logic. Local Windows run with no env vars must behave exactly as before.

## Task 4 — Health endpoint

If missing, add to `api/main.py`:

```python
@app.get("/health")
def health():
    return {"status": "ok"}
```

## Task 5 — Kaggle start script

Create `deploy/kaggle/kaggle_start.py`:

```python
"""Kaggle entrypoint: start FastAPI + ngrok, keep the notebook session alive."""
import argparse, os, subprocess, sys, time
from pathlib import Path

import requests

REPO_ROOT = Path(__file__).resolve().parents[2]
PORT = 7860

def wait_for_server(timeout_s=900):
    start = time.time()
    while time.time() - start < timeout_s:
        try:
            if requests.get(f"http://127.0.0.1:{PORT}/health", timeout=5).status_code == 200:
                return True
        except Exception:
            pass
        print(f"[boot] waiting for server (model load can take minutes)… {int(time.time()-start)}s", flush=True)
        time.sleep(5)
    return False

def main():
    ap = argparse.ArgumentParser()
    ap.add_argument("--smoke", action="store_true", help="local check: boot server, hit /health, exit (no ngrok)")
    args = ap.parse_args()

    ds = os.environ.get("KAGGLE_MODELS_DATASET", "advs-models")
    kpath = Path("/kaggle/input") / ds
    if kpath.is_dir():
        os.environ["MODELS_DIR"] = str(kpath)

    server = subprocess.Popen(
        [sys.executable, "-m", "uvicorn", "api.main:app", "--host", "0.0.0.0", "--port", str(PORT)],
        cwd=REPO_ROOT,
    )
    try:
        if not wait_for_server():
            raise SystemExit("server never became healthy")
        print("[boot] server healthy", flush=True)
        if args.smoke:
            return

        from pyngrok import ngrok
        url = ngrok.connect(PORT).public_url
        print("\n" + "=" * 20 + f" PUBLIC URL: {url} " + "=" * 20 + "\n", flush=True)

        while True:  # heartbeat keeps the notebook cell busy → prevents idle kill
            time.sleep(600)
            try:
                r = requests.get(f"http://127.0.0.1:{PORT}/health", timeout=10)
                print(f"[heartbeat] {time.strftime('%H:%M:%S')} status={r.status_code} url={url}", flush=True)
            except Exception as e:
                print(f"[heartbeat] error: {e}", flush=True)
    finally:
        server.terminate()

if __name__ == "__main__":
    main()
```

Agent may refine (logging to file, cleaner shutdown) but must keep: MODELS_DIR override, long boot tolerance, ngrok banner, infinite heartbeat, `--smoke` mode.

## Task 6 — Light requirements

Create `deploy/kaggle/requirements-kaggle.txt`. The Kaggle base image ALREADY ships tensorflow, torch, transformers, opencv-python, numpy, pandas, scikit-learn, Pillow. Include ONLY what's missing, e.g.:

```text
fastapi
uvicorn[standard]
pyngrok
python-dotenv
requests
# + app-specific light deps diffed from requirements-api.txt
# DO NOT include tensorflow / torch / transformers / opencv here
```

Agent: diff `requirements-api.txt` against the preinstalled list; include remaining light deps; comment out heavy ones with a note.

## Task 7 — Bootstrap notebook

Create `deploy/kaggle/bootstrap.ipynb` (valid nbformat 4 JSON, python3 kernel) with these cells:

1. **Markdown** — preflight checklist: Accelerator = GPU (T4); Internet = ON; attach dataset `advs-models`; attach all secrets.
2. **Code** — clone (private-aware):

   ```python
   import os
   token = os.environ.get("GITHUB_TOKEN", "")
   repo = "<GITHUB_USER>/advs-api"
   url = f"https://{token}@github.com/{repo}" if token else f"https://github.com/{repo}"
   !git clone --depth 1 {url} advs
   %cd advs
   ```

3. **Code** — deps:

   ```python
   !pip install -q -r deploy/kaggle/requirements-kaggle.txt
   ```

4. **Code** — secrets → runtime files/env:

   ```python
   import os
   from pathlib import Path
   from kaggle_secrets import UserSecretsClient
   sec = UserSecretsClient()

   os.environ["NGROK_AUTHTOKEN"] = sec.get_secret("NGROK_AUTHTOKEN")

   keys = [l.split("=")[0].strip() for l in Path(".env.api.example").read_text().splitlines()
           if "=" in l and not l.startswith("#")]
   Path(".env.api").write_text("\n".join(f"{k}={sec.get_secret(k)}" for k in keys) + "\n")
   print("wrote .env.api with:", keys)
   ```

5. **Code** — run (blocking; this cell IS the keep-alive):

   ```python
   !python deploy/kaggle/kaggle_start.py
   ```

## Task 8 — Docs

Create `deploy/kaggle/README.md` covering:

1. The HUMAN one-time steps above (repo, dataset, secrets, ngrok).
2. Session workflow: new notebook → GPU + Internet ON → attach dataset + secrets → paste/run bootstrap cells → copy printed ngrok URL.
3. Restart procedure: Kaggle kills sessions after ~9–12 h → Run All again; free-ngrok URL changes every session, re-share it.
4. Troubleshooting: dataset not mounted (`ls /kaggle/input`), ngrok auth error, clone auth error, OOM (never run 2 workers; restart kernel), long model load (watch `[boot]` prints).

## Task 9 — Commit and push

Commit all changes to `advs-api` (`main`) with clear messages and push.

---

## Constraints (DO NOT)

- Do not change inference/model logic.
- Do not commit `models/`, `backup_models/`, `.env.api`, `env/`, `tmp/`, logs.
- Do not delete `Dockerfile` / `requirements.txt` (used elsewhere).
- Do not add heavy ML packages to `requirements-kaggle.txt`.
- Do not physically move `python/` out of the Laravel root.
- Windows local run with zero env vars must behave exactly as before.

## Acceptance criteria

- [ ] `python/` is its own repo pushed to `https://github.com/<GITHUB_USER>/advs-api`; parent repo (if any) no longer tracks it.
- [ ] First commit contains code only (no models, venv, secrets, logs); no tracked file >50 MB.
- [ ] No hardcoded `models/` literals in `api/` except `api/config.py`.
- [ ] `/health` endpoint exists and responds.
- [ ] `deploy/kaggle/` contains: `kaggle_start.py`, `requirements-kaggle.txt`, `bootstrap.ipynb` (valid JSON), `README.md`.
- [ ] `python deploy/kaggle/kaggle_start.py --smoke` passes locally (server boots, `/health` 200, clean exit, no ngrok).
- [ ] `uvicorn api.main:app` still boots locally with defaults.
- [ ] README covers dataset upload, secrets, GPU/Internet settings, daily restart.

## Open inputs (resolve before starting)

- `<GITHUB_USER>` — from the human.
- Whether `<LARAVEL_ROOT>` is a git repo — agent must detect and branch in Task 2.
- Exact secret key names — agent reads `.env.api.example` itself.
- Total size of `models/` — human confirms it fits the Kaggle dataset limit.