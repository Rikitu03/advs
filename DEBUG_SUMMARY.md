# ADVS Test-Driven Debugging: Final Summary & Action Plan

## Quick Answer to Your Question

**Q:** "Is the ProcessDocumentJob failing, or is the queue worker not up and the job is just stuck?"

**A:** **Neither exactly.** The queue worker IS running, and the job IS being processed. **The job is actively failing** because the ML API (`/v1/validate`) is returning HTTP 500 errors. Your document is not "stuck floating"—it's been attempted 3 times (with exponential backoff) and failed each time.

---

## What Actually Happened

### Timeline of Document Processing

1. **Document Created** 
   - Submission ID: 6
   - Document ID: 7  
   - File: `BIR Permit.pdf`
   - Status: `queued`

2. **Job Dispatched to Queue**
   - ProcessDocumentJob successfully placed in `document-processing` queue
   - Job ID: 4
   - Queue table records the job

3. **Job Execution Attempted (3 times)**
   - **Attempt 1**: ProcessDocumentJob dequeued → ProcessDocumentAction::execute() → MlPipelineService::validate() → **HTTP 500**
   - **Attempt 2**: Same error (queued for retry with 30-second delay)
   - **Attempt 3**: Same error (queued for retry with 60-second delay)
   - After 3rd failure, job marked as exhausted

4. **Current State**
   - Document status: `verifying` (paused, waiting for manual intervention)
   - Vendor can see: "Submission pending" (no error shown to vendor yet)
   - Officer can see: Risk score = null (processing failed)
   - No way to auto-recover; needs manual re-queue or ML API fix

---

## Diagnosed Issues

### ✅ Working Correctly
| Component | Status | Evidence |
|-----------|--------|----------|
| Laravel database | ✅ | MySQL running, all tables created, seeders successful |
| Queue system | ✅ | Jobs table populated, retry logic working, 3 attempts recorded |
| Job dispatch | ✅ | ProcessDocumentJob successfully queued |
| File storage | ✅ | PDF correctly stored in `storage/app/uploads/` |
| Vendor data | ✅ | Account created, signature reference enrolled, vendor profile complete |
| FastAPI service | ✅ | Running, `/health` endpoint responds with `status: ok` |

### ❌ Not Working
| Component | Status | Issue |
|-----------|--------|-------|
| **ML API /v1/validate** | ❌ | Returns HTTP 500 for all requests |
| Error logging | ❌ | No error details captured (generic "Internal Server Error") |
| Document validation | ❌ | Cannot complete, submission stuck in `processing` |

---

## The ML API HTTP 500 Problem

### What We Know
- The FastAPI service IS running on `http://127.0.0.1:7860`
- Health check works: `GET /health` → `status: ok, version: 1.0.0`
- BUT: `POST /v1/validate` → **HTTP 500 (no error details)**

### Possible Causes
1. **Model Loading Issue**
   - One or more ML models failed to load at startup
   - Models directory has files, but file format might be wrong
   - TensorFlow/Keras version mismatch with saved models

2. **Request Parsing Error**  
   - Multipart form parsing fails
   - JSON fields (forensics, settings_snapshot) invalid
   - File upload handling issue

3. **Runtime Error During Processing**
   - PDF → Image conversion fails
   - Model inference crashes  
   - Data shape mismatch between request and model

4. **Missing Configuration**
   - API_TOKEN not set correctly
   - Model paths point to non-existent files
   - Required environment variables missing

---

## How to Diagnose the ML API Error

### Step 1: Check Where the Python API Is Running

```powershell
Get-Process -Name python | Select-Object Id, Name, CommandLine
```

You should see something like:
```
Id    Name   CommandLine
----  ----   ----
21176 python .../env/Scripts/python.exe -m uvicorn api.main:app --port 7860
```

### Step 2: Look at Python API Output

If the API was started in an interactive terminal, look for error messages printed to stdout/stderr. If it's running in the background, check if there's a log file or restart it with visible output:

```bash
cd python
./env/Scripts/python.exe -m uvicorn api.main:app --port 7860 --log-level debug
```

This will show:
- Model loading status
- Request validation errors
- Stage execution details
- The actual exception causing HTTP 500

### Step 3: Enable Verbose Logging

Add this to `python/.env.api`:
```
LOG_LEVEL=debug
UVICORN_LOG_LEVEL=debug
```

Then restart the API.

### Step 4: Test ML API Directly

Send a minimal request to isolate the problem:

```powershell
$token = "your-api-token"
$file = "C:\xampp\htdocs\projects\advs\storage\app\uploads\test_bir_permit_XXXX.pdf"

$headers = @{"Authorization" = "Bearer $token"}
$form = @{
    "template" = "bir"
    "document_type" = "bir_certificate"
    "issuer_scope" = "national"
    "city" = ""
    "file" = Get-Item $file
}

Invoke-WebRequest -Uri "http://127.0.0.1:7860/v1/validate" `
    -Method POST `
    -Form $form `
    -Headers $headers `
    -UseBasicParsing
```

Capture the full response body (not just status).

### Step 5: Check Python Environment

Verify the ML models and dependencies:

```bash
cd python

# Check if required models exist
ls models/resnet50_best.keras
ls models/yolov8_nano_moredata_best.pt
ls models/siamese_encoder.h5
ls models/efficientnet_feature_extractor.h5

# Verify Python packages
./env/Scripts/pip list | findstr "tensorflow torch pillow fastapi"
```

---

## Debugging Scripts Created

The following PHP scripts are in your project root and can help debug:

1. **check_vendor_status.php**
   - Shows all vendor submissions and document states
   - Run: `php check_vendor_status.php`

2. **simulate_submission.php**
   - Creates a test document and dispatches ProcessDocumentJob
   - Run: `php simulate_submission.php`

3. **test_job_execution.php**
   - Executes a queued job synchronously
   - Shows pipeline runs and errors
   - Run: `php test_job_execution.php`

4. **test_ml_direct.php**
   - Sends a direct HTTP request to the ML API
   - Captures and displays the response
   - Run: `php test_ml_direct.php`

5. **check_ml_data.php**
   - Shows what data is being sent to the ML API
   - Useful for validating request format
   - Run: `php check_ml_data.php`

---

## Current Database State

**Vendor Account (Your Test Case)**
```
User Email: recto_jasonjay@plpasig.edu.ph
User ID: 6
Vendor ID: 2
Company: Musika Digitals Archive
Email Verified: NO (doesn't block processing)
Signature Embedding: ✅ Enrolled (128-dimensional vector)
```

**Latest Test Submission**
```
Submission ID: 6
Document ID: 7
Status: processing (stuck, waiting for ML API fix)
File: storage/app/uploads/test_bir_permit_1787037500.pdf
Pipeline Runs: 3 (all failed with HTTP 500)
```

**Failed Job Details**
```
Job ID: 4
Queue: document-processing
Attempts: 3 (with exponential backoff: 10s, 30s, 60s)
Last Error: "ML API /v1/validate returned HTTP 500."
```

---

## What Needs to Happen Next

### To Get Processing Working Again

1. **Diagnose the ML API error** (Steps 1-4 above)
2. **Fix the root cause** (models, config, code)
3. **Restart the FastAPI service**
4. **Re-queue the document for processing**
   ```bash
   # Option A: Delete failed document and resubmit
   php cleanup_test.php
   php simulate_submission.php
   
   # Option B: Manually retry existing job
   php artisan queue:work document-processing --tries=1
   ```

### To Prevent Future Issues

- Add ML API error logging/alerting
- Implement health check monitoring for `/v1/validate` endpoint
- Add retry mechanism in MlPipelineService with exponential backoff (already present)
- Store detailed error context from ML API responses (enhancement needed)

---

## Key Takeaways

1. ✅ Your ADVS system architecture is sound — queue, database, job dispatch all working
2. ❌ The bottleneck is the ML API returning HTTP 500 (likely model or configuration issue)
3. 📋 Use the debugging scripts provided to systematically isolate the root cause
4. ⚡ Once ML API is fixed, documents should process successfully
5. 🔄 Job retry logic will automatically retry after fixes (up to 3 times)

---

## Files Created for Reference

- **TEST_DRIVEN_DEBUG_REPORT.md** — Detailed technical diagnosis
- **check_vendor_status.php** — Vendor/submission query tool
- **simulate_submission.php** — Test submission creator
- **test_job_execution.php** — Job executor with error capture
- **test_ml_direct.php** — Direct ML API tester
- **check_ml_data.php** — Data structure inspector
- **test_ml_api.py** — Python ML API tester (if you want to test from Python side)

---

## Questions & Next Steps

If you'd like me to help further:
1. **Check the ML API logs** and share any error messages
2. **Verify the model files** are correct format (.keras vs .h5)
3. **Test the ML API endpoint** with the debugging scripts
4. **Check Python environment** setup in `python/.env.api`

The ProcessDocumentJob isn't stuck—it's just waiting for the ML API to be fixed! 🚀
