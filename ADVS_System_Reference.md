# Automated Document Validation System (ADVS) for Vendor Accreditation

## Comprehensive System Reference — Core Logic, Operations, and Implementation Details

---

## 1. System Purpose and Core Logic

The ADVS is a web-based system built on a **Laravel 11 backend with Python inference scripts** that automates the verification of vendor accreditation documents. Its core problem statement is direct: manual document review is slow, error-prone, and vulnerable to fraud. The system replaces that process with a multi-stage machine learning pipeline that examines every document from four independent angles — text content, document classification, signature authenticity (vs a per-vendor reference enrolled at registration), and stamp/logo authenticity (vs a per-issuer reference library — per-agency for national logos like BIR/SEC, per-city for LGU seals) — then fuses those signals into a single composite risk score for a human officer to act on.

The system is **on-demand, not calendar-driven**. It activates whenever a vendor submits documents — whether that's an initial application, a renewal, or an update triggered by an expiring credential or new regulation. The implementing organization decides the cadence; ADVS simply processes whatever arrives.

Three distinct user roles interact with the system: **Vendors** (who submit documents), **Compliance Officers** (who review validation results and render accreditation decisions), and **System Administrators** (who manage users, configure thresholds, and oversee the platform). Each role has a scoped view of the system enforced by role-based access control.

---

## 2. File Upload Constraints

### Accepted Formats

The thesis specifies that vendors upload **images or PDFs** through a submission portal. In the Laravel implementation, this translates to the following accepted MIME types:

| Format | Extension | Notes |
|---|---|---|
| PDF | `.pdf` | Multi-page supported; first two pages are extracted |
| PNG | `.png` | Preferred raster format after conversion |
| JPEG | `.jpg`, `.jpeg` | Common scan output format |

PDF handling is explicit in the manuscript: the system uses the **pdf2image Python library** to convert the **first two pages** of any multi-page PDF into high-resolution PNG images at **300 DPI**. This conversion serves two purposes — it standardizes all input into a consistent image format for the pipeline, and it prevents class imbalance that would arise if some documents contributed many more pages than others.

### Size Limits (Configurable)

The thesis does not prescribe a fixed file size cap. In practice, the implementing organization would configure Laravel's upload validation rules. A reasonable production configuration would be:

- **Per-file limit**: 10–20 MB (configurable via Laravel's `upload_max_filesize` and validation rules)
- **Per-submission batch**: 50–100 MB total across all documents in a single submission
- **Minimum resolution**: The system resizes all images to **512 × 512 pixels** for ResNet-50 and applies preprocessing for OCR, so extremely low-resolution scans (below ~150 DPI) will degrade OCR accuracy and model confidence. The system does not reject low-resolution files outright — it processes them and lets the confidence scores and OCR quality metrics reflect the degradation, which then surfaces in the risk score.

### Multi-Page Document Handling

When a vendor uploads a multi-page PDF, the system extracts only the **first two pages** as PNG images at 300 DPI. Each extracted page is treated as a separate image flowing independently through the full pipeline (preprocessing → OCR → classification → detection → verification). The manuscript is explicit about this design choice: it avoids overwhelming the training set with documents that happen to have many pages, keeping class balance stable.

If the organization requires validation of pages beyond the first two, this is a configurable parameter (`MAX_PDF_PAGES`) that can be increased, though it would proportionally increase processing time.

---

## 3. Access Control and User Roles

### Authentication

The thesis mandates **Secure Login** as a core scope item: "Access to the system will be limited to authorized users only, ensuring data protection and confidentiality through account-based authentication." In the Laravel 11 implementation, this means:

- **Session-based authentication** managed by Laravel's built-in auth scaffolding
- **Password hashing** via bcrypt (Laravel default)
- **CSRF protection** on all forms
- **Account-based access** — no anonymous or public access to any system feature

### Role Definitions and Permissions

| Role | Can Access | Cannot Access |
|---|---|---|
| **Vendor** | Submission portal, own submission history, own validation status and notifications | Other vendors' data, officer dashboard, admin settings, risk scores, validation reports |
| **Compliance Officer** | Validation results dashboard, pending submissions queue, risk score drill-downs, approve/reject actions, archived reports | System configuration, user management, ML threshold tuning, other officers' decision logs (unless admin grants it) |
| **System Administrator** | Everything the officer can see, plus: user management, vendor account management, ML model configuration, threshold tuning, audit trail, system settings, data retention policies | N/A — full access |

### Permission Enforcement

Permissions are enforced at the **middleware layer** in Laravel. Each route group is gated by role-checking middleware (`role:vendor`, `role:officer`, `role:admin`). The database stores a `role` column on the users table. Blade templates conditionally render navigation elements based on the authenticated user's role, so vendors never see officer-only menu items and officers never see admin-only configuration panels.

---

## 4. Dashboard Navigation Structure

The system features a **sidebar navigation layout** with role-scoped visibility. The sidebar persists on the left side of every authenticated page, with the main content area occupying the remaining viewport. Here is the full navigation tree, annotated by which roles see each item:

### Vendor Sidebar

| Tab / Section | What It Shows |
|---|---|
| **Dashboard (Home)** | Summary cards: total submissions, pending count, approved count, rejected count. Recent activity feed. |
| **Submit Documents** | Upload form with drag-and-drop zone. Format guidance (accepted types, size limits). Submission confirmation after upload. |
| **My Submissions** | Table of all past submissions with columns: date, document name, status (Processing / Pending Review / Approved / Rejected), and a detail link. Clicking a row shows which documents were included and their individual statuses. |
| **Notifications** | Chronological list of in-app alerts: "Your submission has been received," "Your BIR Permit has been flagged for review," "Your accreditation has been approved." |
| **Profile** | Account settings, password change, contact information. |

### Compliance Officer Sidebar

| Tab / Section | What It Shows |
|---|---|
| **Dashboard (Home)** | KPI cards: submissions pending review, flagged documents today, approval rate this period. Quick-access list of the five most urgent flagged submissions. |
| **Pending Submissions** | Queue of submissions awaiting officer review, sorted by risk score (highest first). Each row shows: vendor name, submission date, composite risk score (color-coded: green/yellow/red), number of flags. Clicking opens the full validation report. |
| **Validation Results** | Detailed per-document breakdown for a selected submission. Shows each stage's output: OCR extracted text, classification result and confidence %, signature similarity score, logo similarity score (vs the detected city's reference), individual pass/fail indicators, and the composite risk score. This is the **drill-down view** (see Section 6). |
| **Archived Reports** | Searchable, filterable archive of all past validation reports. Filters include: date range, vendor name, decision (approved/rejected), risk score range. Each archived report is viewable in full. |
| **Vendor Profiles** | Directory of all registered vendors. Each profile shows: company name, registration date, submission history, accreditation status, stored reference signature embedding info (enrolled at registration). Logo/stamp/seal references are **not** stored per vendor — they live in a per-issuer reference library (per-agency for national logos, per-city for LGU seals; see §4b / §8). |
| **Risk Logs** | Chronological audit log of every flag the system has raised. Filterable by flag type (text mismatch, low classification confidence, signature mismatch, stamp mismatch), severity, date range, and vendor. |
| **Notifications** | Officer-specific alerts: "New submission from [Vendor] requires review," "High-risk submission flagged," "Submission #1042 has been pending for 48+ hours." |

### System Administrator Sidebar

The admin sees **everything the officer sees**, plus additional tabs:

| Tab / Section | What It Shows |
|---|---|
| **User Management** | CRUD interface for all user accounts. Assign/change roles, activate/deactivate accounts, reset passwords. |
| **System Settings** | Configurable parameters panel (see Section 8 for the full list of tunable thresholds). Includes: file size limits, accepted formats, OCR confidence floor, ResNet-50 classification threshold, signature distance threshold, stamp/logo similarity threshold (vs city reference), risk score weights. |
| **ML Model Management** | Status of each ML model (ResNet-50, YOLOv8, Siamese CNN, EfficientNet). Last training date, validation accuracy, model file paths. Interface to trigger retraining or swap model versions. |
| **Audit Trail** | Immutable log of every significant system event: logins, submissions, validation runs, officer decisions, configuration changes, user account modifications. Each entry is timestamped and attributed to a user. |
| **Data Retention** | Configuration for archival and purging policies. Set retention periods for uploaded documents, processed images, validation reports, and embedding vectors. |

---

## 5. Processing Pipeline — Complete Document Flow

This section walks through exactly what happens from the moment a file enters the system to the moment a decision is recorded. Each stage describes the physical data transformations, the success path, and the failure path.

### Stage 0: Document Submission and Intake

**Trigger**: Vendor clicks "Submit" after uploading one or more files.

**What happens**:
1. Laravel validates the upload: checks file type against the whitelist (PDF, PNG, JPG), checks file size against the configured limit, checks for upload errors.
2. Valid files are stored to a secure, non-public directory on the server filesystem (or cloud storage, depending on deployment).
3. A `Submission` record is created in the database with status `PROCESSING`.
4. If the file is a **PDF**, the system invokes a Python script using `pdf2image` to convert the first two pages into PNG images at **300 DPI**. Each page becomes a separate processing unit.
5. If the file is already a **PNG or JPG**, it proceeds directly.
6. A processing job is dispatched (via Laravel's queue system) for each image.

**Failure path**: If the file fails validation (wrong type, too large, corrupt), the upload is rejected immediately with an error message to the vendor. No processing job is created.

---

### Stage 1: Image Preprocessing (OpenCV)

**Input**: Raw PNG/JPG image from the upload or PDF conversion.

**Operations** (in this exact sequence):

1. **Grayscale conversion** — `cv2.cvtColor(img, cv2.COLOR_BGR2GRAY)` using BT.601 coefficients (`Gray = 0.114B + 0.587G + 0.299R`). Reduces the 3-channel color image to a single intensity channel, removing color information that is irrelevant for text and structural analysis.

2. **Binarization with inversion** — `cv2.threshold(gray, 150, 255, cv2.THRESH_BINARY_INV)`. Pixels darker than threshold 150 become white (255); brighter pixels become black (0). This isolates text (originally dark ink) as white foreground on a black background. The threshold of 150 was chosen empirically by the researchers as a balance between capturing text and excluding background noise.

3. **Morphological opening** — `cv2.morphologyEx(thresh, cv2.MORPH_OPEN, kernel)` with a 2×2 kernel. Erosion followed by dilation removes small white noise spots (dust, scan artifacts) while preserving the structural integrity of text strokes.

4. **Bitwise inversion** — `cv2.bitwise_not(cleaned)`. Flips the image back to black text on white background, which is the format PyTesseract expects for optimal recognition.

**Output**: A clean, binarized image optimized for OCR.

**Failure path**: This stage is deterministic — it does not "fail" in the traditional sense. However, if the input image is extremely degraded (blank page, completely black/white, severely corrupted), the output will be a meaningless image. This degradation propagates forward: OCR will extract little or no text, and the classification model will produce a low confidence score. Both of these outcomes are captured in the risk score, effectively flagging the document without needing a separate "preprocessing failed" check.

---

### Stage 2: Text Extraction and Validation (PyTesseract OCR)

**Input**: Preprocessed image from Stage 1.

**What happens**:
1. The preprocessed image is passed to **PyTesseract** (`pytesseract.image_to_string()`), which performs its own internal processing (segmentation into lines/words/characters, feature extraction, deep-learning-based character classification, and post-processing with dictionary correction).
2. The raw extracted text is passed through **NLP post-processing** for further correction and sentence structuring.
3. The cleaned text is compared against **predefined templates** — expected field names, required keywords, formatting patterns specific to each document type (e.g., a BIR permit must contain certain registration numbers, a financial statement must contain specific headers).

**Validation logic**: The system checks for:
- **Required fields present**: Does the extracted text contain the expected sections/keywords for this document type?
- **Format compliance**: Do registration numbers, dates, and amounts match expected patterns (regex-based)?
- **Completeness**: Are all mandatory fields populated, or are critical sections missing?
- **Issuing city / LGU**: The text is scanned for the issuing **city name** (e.g., "Pasig"). This city key is passed to **Stage 4b**, which uses it to look up the correct reference logo(s) for stamp/logo verification. If no city can be identified, Stage 4b cannot scope its lookup and raises a `City not identified` flag.

**Output**: A structured text validation result containing: extracted text blob, list of matched fields, list of missing/inconsistent fields, the detected issuing city (for the §4b logo lookup), and an overall text validation score.

**Failure path**: If OCR produces little or no recognizable text (due to a blank page, an image without text, or extremely poor scan quality), the text validation score will be very low. The system does **not** abort processing — it records the poor OCR result as a flag ("Insufficient text extracted" or "Required fields missing") and continues to the next stages. This flag contributes to the composite risk score. There is no automatic retry mechanism; the document is flagged for manual review, and the vendor may be asked to resubmit.

---

### Stage 3: Document Classification (ResNet-50)

**Input**: The original uploaded image, resized to **512 × 512 pixels**, converted to a NumPy array of shape `(1, 512, 512, 3)`, and normalized using ResNet-50's `preprocess_input` function (ImageNet standardization).

**Model architecture**:
- Base: ResNet-50 pre-trained on ImageNet, with the final 30 layers fine-tuned on vendor document datasets
- Custom head: Global Average Pooling → Dropout (0.5) → Dense 512 (ReLU, L2 regularization) → Dropout (0.3) → Softmax output layer
- Training: Two-phase — first phase (20 epochs, frozen base, lr=1e-4), second phase (10 epochs, final 30 layers unfrozen, lr=1e-5)
- Loss: Categorical cross-entropy with class weights (computed via scikit-learn's `compute_class_weight`) to handle imbalanced data
- Optimizer: Adam
- Saved format: HDF5 (`resnet50_authenticity.h5`) with pickled LabelEncoder

**What happens**:
1. The model produces a **probability distribution** over all document classes (e.g., `[0.02, 0.01, 0.96, 0.04]` for classes like BIR Permit, Financial Statement, etc.).
2. The index with the highest probability is decoded back to the class label using the saved LabelEncoder.
3. The **confidence score** is the maximum probability value (e.g., 96%).

**Output**: Predicted document class label + confidence score.

**Configurable parameter**: `CLASSIFICATION_CONFIDENCE_THRESHOLD` (e.g., 70%). If the confidence falls below this threshold, the document is flagged as "Unknown document type" or "Potential fake."

**Failure path**: A low confidence score does **not** halt the pipeline. The classification result and its below-threshold confidence are recorded as a flag. Processing continues to Stages 4–5 because even a poorly classified document may still have detectable signatures and stamps that provide additional forensic information. All flags are aggregated in Stage 6.

---

### Stage 4: Signature and Stamp Detection (YOLOv8)

**Input**: The original document image (or the preprocessed version, depending on configuration).

**What happens**:
1. The image is passed through a **YOLOv8 object detection model** trained to detect two object classes: `signature` and `stamp`.
2. YOLOv8 processes the image in a single forward pass through its Backbone (C2f modules + SPPF), Neck (PAN-FPN multi-scale fusion), and decoupled detection Head.
3. The model outputs **bounding box coordinates** with associated **confidence scores** for each detected region.
4. Detected regions are **cropped** from the original image using the bounding box coordinates.
5. **Signature crops** are forwarded to Stage 4a (Siamese CNN).
6. **Stamp / logo / seal crops** are forwarded to Stage 4b (EfficientNet), together with the issuing city OCR extracted in Stage 2.

**Document misalignment handling**: YOLOv8's multi-scale feature extraction inherently handles variations in position, scale, and rotation. There is no need for predefined ROI zones or homography-based alignment — the model locates signatures and stamps wherever they appear in the document.

**Failure path — no signature detected**: If YOLOv8 finds no signature region (confidence below its detection threshold), the system records a flag: "No signature detected." The signature verification stage is skipped for this document, and the absence is recorded as a risk factor in the composite score.

**Failure path — no stamp/logo detected**: Same logic. "No stamp/logo detected" is recorded as a flag. The stamp/logo authentication stage is skipped.

**Failure path — multiple detections**: If YOLOv8 detects multiple signature or stamp regions, the system uses the detection with the highest confidence score as the primary region. Additional detections may be logged for officer review.

---

### Stage 4a: Signature Verification (Siamese CNN)

**Input**: Cropped signature region from YOLOv8.

> **The signature reference is enrolled at vendor registration — not during the pipeline.** Every vendor captures a reference signature during sign-up (registration **step 2**, before email verification): they photograph three signatures on white bond paper, an authenticity check rejects software-edited/filtered images, and the accepted capture is embedded once into the vendor's **128-dimensional reference embedding** and stored on the vendor record (`users.signature_path` / `vendor_embeddings.signature_embedding`). Because the reference exists **before any document is ever submitted**, the pipeline **always runs in verification mode** — there is no "first submission auto-enrolls" branch here. (This is the opposite of the logo handling in §4b, where references are keyed by city and seeded on first approval.)

**Verification (every submission)**:
1. The new cropped signature is preprocessed (resized to fixed input size, pixel values normalized).
2. It is passed through the Siamese CNN to produce a **query embedding vector** (128 dimensions).
3. The system retrieves the vendor's stored reference embedding (captured at registration).
4. **Euclidean distance** is computed between the query and reference vectors.
5. The distance is converted to a **similarity score** (smaller distance = higher similarity).

**Configurable parameter**: `SIGNATURE_DISTANCE_THRESHOLD` — determined empirically during model validation to balance false acceptance rate (FAR) and false rejection rate (FRR). If the distance exceeds this threshold, the signature is flagged as a possible forgery.

**Output**: Similarity score + pass/fail indicator.

**Failure paths**:
- **Signature mismatch** (distance above threshold) → "Signature mismatch — possible forgery" flag. The document is not rejected outright; the flag feeds the composite risk score, and the officer sees the specific similarity percentage and makes the judgment call.
- **No signature detected** by YOLOv8 (Stage 4) → this verification stage is skipped and "No signature detected" is recorded as a risk factor.

**Cross-document consistency**: Because the reference embedding is fixed at registration and stored persistently, every later submission is checked against the same baseline. If a vendor's signature drifts dramatically from the enrolled reference, every subsequent document triggers a mismatch flag.

---

### Stage 4b: Stamp / Logo / Seal Authentication (EfficientNet)

**Input**: Cropped stamp / logo / seal region from YOLOv8, **plus the city name extracted by OCR in Stage 2**.

> **Logo references are keyed by the document's issuer, not by the vendor.** Official stamps, logos, and seals belong to whoever **issues** the document. `document_types.issuer_scope` records which kind, and that drives how the reference is keyed:
> - **`national`** (e.g. BIR Permit, SEC GIS) — one logo agency-wide; the reference is keyed by **document type alone** (the BIR logo is identical on every BIR document, in any city).
> - **`lgu`** (e.g. Business Permit) — one seal per city; the reference is keyed by **(document type, city)** (Pasig's business-permit seal differs from Quezon City's).
> - **`null`** (e.g. audited Financial Statement, Signed Contract) — no official issuer logo; the reference lookup is skipped (only the tamper check runs).
>
> References live in the **`logo_references`** table. There is **no per-vendor stamp embedding** and no per-vendor enrollment step. (This is the opposite of the signature handling in §4a, which uses one per-vendor reference enrolled at registration.)

**Tamper check (always runs, reference or not)**:
1. The cropped region is preprocessed (resized, normalized).
2. It is passed through an **EfficientNet model** (pre-trained, classification head removed, used as a fixed feature extractor) to produce a **compact feature vector** and analyze texture.
3. EfficientNet's MBConv blocks distinguish genuine **wet-ink impressions** from **photocopied, scanned, or digitally edited reproductions** regardless of whether an issuer reference exists. A detected reproduction/edit raises a `Stamp/logo tampered` flag. (Halftone dot patterns in photocopies, for example, produce a different texture signature than genuine wet ink.)

**Issuer lookup & verification**:
1. Classification (Stage 3) gives the **document type**; the pipeline reads its `issuer_scope`.
2. It retrieves the reference logo for that issuer — by **document type** (`national`) or by **(document type, detected city)** (`lgu`, where the city comes from OCR in Stage 2; for `national` the detected city is ignored).
3. The query feature vector is compared to the issuer reference vector using a **distance metric** (cosine similarity or Euclidean distance), yielding a **similarity percentage** (e.g., "95.2% match").

**Configurable parameter**: `STAMP_SIMILARITY_THRESHOLD` (default: **85%**). If the similarity to the issuer reference meets or exceeds this threshold, the logo is validated; below it, it is flagged for manual review.

**Reference seeding (per issuer, on first approval)**: An issuer's reference logo is **not** created automatically on first sight — trusting an unverified logo would let a forgery become the standard. Instead, when a logo is detected for an issuer that has **no reference yet**, the document is flagged `Unknown / unreferenced logo` and the feature vector is held pending. The **first time a compliance officer approves a document carrying that issuer's logo**, that logo is enrolled as the reference — stored against the **document type** (`national`) or **(document type, city)** (`lgu`); the officer's approval decision is the trust gate. Every subsequent submission for that issuer is then verified against it.

**Output**: Tamper result + (when an issuer reference exists) similarity percentage and pass/fail indicator; otherwise an `Unknown / unreferenced logo` flag — or, when `issuer_scope` is `null`, only the tamper result.

**Failure paths**:
- **No issuer logo expected** (`issuer_scope = null`) → the reference lookup is skipped; only the tamper-check result is recorded.
- **City not identified** (an `lgu` document type where OCR found no recognizable city) → `City not identified` flag; the lookup cannot be scoped, so verification is skipped and the absence feeds the risk score.
- **No reference for that issuer yet** → `Unknown / unreferenced logo` flag; contributes to the composite risk score and becomes the reference only if an officer later approves the document.
- **Tampering detected** → `Stamp/logo tampered — suspect reproduction/edit` flag (raised even before any reference exists).
- **Below-threshold similarity** (issuer reference exists) → `Logo mismatch — suspect reproduction` flag.

All feed into the composite risk score.

---

### Stage 5: Risk Score Computation and Report Generation

**Input**: All outputs from Stages 1–4b.

**What happens**:
1. The system **aggregates** results from all validation components:
   - Text validation score (from OCR template matching)
   - Document classification confidence (from ResNet-50)
   - Signature similarity score (from Siamese CNN) — or "not detected" flag
   - Logo similarity score (from EfficientNet, vs the issuer's reference) — or "not detected / unreferenced / city not identified / no issuer logo" flag
2. Each component contributes to a **composite risk score** using configurable weights.

**Risk score formula** (conceptual):

```
Composite Risk = w1 × (1 - text_validation_score)
               + w2 × (1 - classification_confidence)
               + w3 × (1 - signature_similarity)
               + w4 × (1 - stamp_similarity)
               + penalty_flags
```

Where `w1 + w2 + w3 + w4 = 1.0` and `penalty_flags` adds additional risk for missing/unverifiable components (no signature detected, no logo detected, unreferenced/unidentified city logo, insufficient OCR text).

**Configurable parameters**:
- `RISK_WEIGHT_TEXT` (w1) — e.g., 0.25
- `RISK_WEIGHT_CLASSIFICATION` (w2) — e.g., 0.25
- `RISK_WEIGHT_SIGNATURE` (w3) — e.g., 0.25
- `RISK_WEIGHT_STAMP` (w4) — e.g., 0.25
- `MISSING_COMPONENT_PENALTY` — additional risk points for undetected signatures/stamps

**Output**: A **Validation Report** containing:
- Per-document summary (each page processed)
- Individual component scores with pass/fail indicators
- List of all flags raised, with explanations
- Composite risk score (0–100 scale, higher = more risk)
- Risk level classification: Low (0–30), Medium (31–60), High (61–100)
- All reports are stored in the database and linked to the submission record

The submission status changes from `PROCESSING` to `PENDING_REVIEW`.

---

### Stage 6: Officer Review and Decision

**Input**: The generated Validation Report, displayed on the Compliance Officer's dashboard.

**What happens**:
1. The submission appears in the officer's **Pending Submissions** queue, sorted by risk score (highest risk first).
2. The officer clicks into the submission to view the **full Validation Report**.
3. The officer reviews:
   - The uploaded document images
   - OCR-extracted text and any text mismatch flags
   - Document classification result and confidence
   - Signature comparison visual (reference vs. query) with similarity percentage
   - Stamp comparison visual with similarity percentage
   - The composite risk score breakdown showing which components contributed most
4. The officer renders a decision: **Approve** or **Reject**.
5. The decision is recorded with a timestamp, the officer's user ID, and optional comments.
6. The submission status changes to `APPROVED` or `REJECTED`.
7. The vendor is notified of the decision.

**The system is explicitly human-in-the-loop**: The thesis states that the compliance officer "reviews the system's findings and makes the final accreditation decision (approve/reject)." The ML pipeline produces recommendations and flags, but a human always makes the final call. All findings are auditable.

---

## 6. Risk Score Drill-Down in the Dashboard

The composite risk score is not a black box. When an officer clicks on a submission's risk score, the dashboard presents a **component-level breakdown**:

| Component | Score | Threshold | Status | Detail |
|---|---|---|---|---|
| Text Validation (OCR) | 82% | 70% | ✓ Pass | 2 of 14 expected fields could not be matched |
| Document Classification (ResNet-50) | 96% confidence | 70% | ✓ Pass | Classified as "BIR Permit" |
| Signature Match (Siamese CNN) | 43% similarity | 75% | ✗ Fail | Distance: 1.87 (threshold: 1.20) |
| Logo Match (EfficientNet, Pasig city ref) | 91% similarity | 85% | ✓ Pass | Cosine similarity: 0.91 vs Pasig reference logo |
| **Composite Risk Score** | **62 / 100** | — | ⚠ Medium | Signature mismatch is primary driver |

Each row is expandable. Clicking **Signature Match**, for example, shows:
- The reference signature image (enrolled at vendor registration)
- The query signature image (from this submission)
- The 128-D embedding distance value
- A visual overlay or side-by-side comparison

This drill-down allows the officer to see exactly **which component** caused a high risk rating and make an informed judgment. A high risk score driven by a stamp mismatch might lead to a different decision than one driven by a text extraction failure on a poorly scanned document.

---

## 7. Notification and Alerting

### In-Dashboard Notifications

Every significant event generates an in-app notification visible in the user's **Notifications** tab:

| Event | Recipient | Notification |
|---|---|---|
| Document uploaded | Vendor | "Your submission has been received and is being processed." |
| Processing complete | Vendor | "Your submission has been processed and is awaiting review." |
| Document flagged | Officer | "Submission from [Vendor] has been flagged: [flag type]." |
| High-risk submission | Officer | "High-risk submission detected (score: 78/100) from [Vendor]." |
| Decision made (approved) | Vendor | "Your accreditation has been approved." |
| Decision made (rejected) | Vendor | "Your accreditation has been rejected. Reason: [officer's comment]." |
| Submission pending > 48h | Officer | "Submission #1042 has been pending review for 48+ hours." |
| Configuration changed | Admin | "System threshold updated by [Admin User]." |

### Email Notifications (Configurable)

The implementing organization can enable email notifications for critical events (submission received, decision made, high-risk flag). This would be implemented via Laravel's built-in Mail system with configurable toggles in System Settings. The thesis does not mandate email notifications, but the architecture supports them as an optional layer.

### No Mobile Push

The thesis explicitly states the system "will not provide mobile access" within the current scope, so mobile push notifications are out of scope.

---

## 8. Database and Storage Considerations

### Document Storage

| Data Type | Storage Location | Format |
|---|---|---|
| Uploaded raw files (PDF, PNG, JPG) | Server filesystem or cloud storage (e.g., Laravel's `storage/app/private/submissions/`) | Original format |
| Converted PNG pages (from PDF) | Same storage, under a processing subdirectory | PNG at 300 DPI |
| Preprocessed images | Temporary storage during processing; may be discarded after pipeline completion or retained for audit | PNG |

All file paths are stored as references in the database, not the files themselves. Files are organized by vendor ID and submission ID for easy retrieval.

### Reference Embeddings

| Data Type | Storage | Format |
|---|---|---|
| Signature reference embedding | Database, **per vendor** (`users.signature_path` + `vendor_embeddings.signature_embedding`) | 128-dimensional float vector, serialized as JSON or binary blob |
| Logo / stamp / seal reference vector | Database, **per issuer** (the `logo_references` table, keyed by `document_type` for national issuers and `(document_type, city)` for LGU issuers) | Float vector per issuer (dimension depends on EfficientNet variant), serialized similarly |

The **signature** reference embedding is created during the vendor's **registration** (step 2, before email verification) — it exists before any document is submitted, so the pipeline only ever *verifies* against it. The **logo / stamp / seal** reference is **not** stored per vendor and **not** created automatically: it is keyed by the **issuer** — `document_type` for national agencies (BIR/SEC) and `(document_type, city)` for LGUs — and is seeded only when a compliance officer **approves the first document carrying that issuer's logo** (see §4b). Document types with `issuer_scope = null` (e.g. financial statements, signed contracts) have no logo reference. Once stored, the signature reference persists for the lifetime of the vendor's account; an issuer's logo reference persists for the lifetime of the issuer entry and is updated only by an explicit re-enrollment. The legacy per-vendor `vendor_embeddings.stamp_*` columns are **superseded** by this per-issuer model.

### Model Files

| Model | File | Format |
|---|---|---|
| ResNet-50 | `resnet50_authenticity.h5` | HDF5 (Keras) |
| LabelEncoder | `label_encoder.pkl` | Pickle (scikit-learn) |
| YOLOv8 | `yolov8_detector.pt` | PyTorch |
| Siamese CNN | Model weights file | HDF5 or PyTorch |
| EfficientNet | Pre-trained weights | HDF5 or PyTorch |

Model files are stored on the server filesystem and loaded into memory by the Python inference scripts when processing jobs run.

### Validation Reports and Audit Logs

All validation reports, officer decisions, and audit trail entries are stored in the **relational database** (MySQL, managed via Laravel migrations). These records include timestamps, user attribution, and are designed to be immutable once written (append-only audit trail).

### Data Retention (Configurable)

The thesis's ethical considerations section states that "after the research, the documents will be disposed of appropriately," indicating awareness of data lifecycle management. In production, the admin can configure:

- **Active retention period**: How long submitted documents and reports remain in the primary database (e.g., 3–5 years, aligned with the organization's compliance requirements).
- **Archival policy**: After the active period, records can be moved to cold storage (compressed archives) while retaining the metadata and decision records in the database.
- **Purging policy**: After the archival period, documents can be permanently deleted. Reference embeddings may be retained longer if the vendor remains active.
- **Audit trail**: Audit logs are **never purged** — they represent the compliance record.

---

## 9. Complete Parameter Reference

All configurable parameters that the implementing organization would set:

| Parameter | Default | Description |
|---|---|---|
| `MAX_FILE_SIZE_MB` | 10 | Maximum upload size per file in megabytes |
| `MAX_BATCH_SIZE_MB` | 50 | Maximum total upload size per submission |
| `ACCEPTED_FORMATS` | pdf, png, jpg, jpeg | Allowed file MIME types |
| `MAX_PDF_PAGES` | 2 | Number of PDF pages to extract and process |
| `PDF_DPI` | 300 | Resolution for PDF-to-PNG conversion |
| `RESIZE_DIMENSION` | 512 × 512 | Image resize target for ResNet-50 input |
| `BINARIZATION_THRESHOLD` | 150 | Grayscale threshold for binarization step |
| `MORPH_KERNEL_SIZE` | 2 × 2 | Kernel dimensions for morphological opening |
| `CLASSIFICATION_CONFIDENCE_THRESHOLD` | 0.70 | Minimum ResNet-50 confidence to pass |
| `YOLO_DETECTION_CONFIDENCE` | 0.50 | Minimum YOLOv8 detection confidence |
| `SIGNATURE_DISTANCE_THRESHOLD` | Empirical | Maximum Euclidean distance for signature match |
| `STAMP_SIMILARITY_THRESHOLD` | 0.85 | Minimum cosine similarity for a logo match against the detected city's reference (85%) |
| `RISK_WEIGHT_TEXT` | 0.25 | Weight of text validation in composite risk |
| `RISK_WEIGHT_CLASSIFICATION` | 0.25 | Weight of classification confidence in composite risk |
| `RISK_WEIGHT_SIGNATURE` | 0.25 | Weight of signature score in composite risk |
| `RISK_WEIGHT_STAMP` | 0.25 | Weight of stamp score in composite risk |
| `MISSING_COMPONENT_PENALTY` | 15 | Additional risk points per missing component |
| `HIGH_RISK_THRESHOLD` | 61 | Score above which submission is classified High Risk |
| `MEDIUM_RISK_THRESHOLD` | 31 | Score above which submission is classified Medium Risk |
| `DATA_RETENTION_YEARS` | 5 | Years before documents are eligible for archival |

---

## 10. End-to-End Flow Summary

```
VENDOR                          SYSTEM                              OFFICER / ADMIN
──────                          ──────                              ───────────────

1. Login ──────────────────────► Auth check (Laravel middleware)
                                 Role-based routing

2. Upload documents ───────────► Validate (type, size)
                                 Store to filesystem
                                 Create Submission record
                                 Dispatch processing job

                                3. If PDF → pdf2image (300 DPI,
                                   first 2 pages → PNG)

                                4. Image Preprocessing (OpenCV)
                                   Grayscale → Binarize(150) →
                                   Morph Open(2×2) → Invert

                                5. OCR (PyTesseract)
                                   Extract text → NLP cleanup →
                                   Template matching → Score

                                6. Classification (ResNet-50)
                                   512×512 input → Softmax →
                                   Class label + confidence

                                7. Detection (YOLOv8)
                                   Locate signature + stamp →
                                   Crop regions

                                8a. Signature (Siamese CNN)
                                    Embed → Compare to the per-vendor
                                    registration reference →
                                    Euclidean distance → Score

                                8b. Stamp / Logo (EfficientNet)
                                    Tamper check (always) → look up the
                                    OCR city's reference logo; if none →
                                    flag "Unknown/unreferenced logo";
                                    else compare → Cosine sim → Score

                                9. Aggregate all scores →
                                   Compute composite risk →
                                   Generate Validation Report →
                                   Status: PENDING_REVIEW

10. Receive "processing                                            11. See submission in
    complete" notification                                             Pending queue

                                                                    12. Open Validation Report
                                                                        Review each component
                                                                        Drill into flagged items

                                                                    13. Decide: Approve / Reject
                                                                        Record decision + comments
                                                                        Status: APPROVED / REJECTED
                                                                        If approved doc carried a logo for
                                                                        a city with no reference yet → seed
                                                                        it as that city's reference logo

14. Receive decision            ◄────────────────────────────────── Decision recorded
    notification                                                    Audit trail updated

15. View updated status
    in My Submissions
```

---

*This document is grounded in the thesis manuscript by Lopez, Inocencio, and Recto (Pamantasan ng Lungsod ng Pasig, August 2025), advised by Riegie Dy Tan, DIT. Implementation details for areas not explicitly specified by the thesis (file size limits, dashboard layout, notification triggers, retention policies) represent reasonable architectural recommendations consistent with the system's stated scope and Laravel 11 technology stack.*
