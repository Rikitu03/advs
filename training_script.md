You are an expert machine learning engineer. I need you to produce a **single, well‑commented Python training script** (or a set of clearly named functions inside one script) that trains all the models for my Automated Document Validation System (ADVS). The script must be self‑contained, reading data from the directory structure described below, and saving trained models. Assume all imports are available.

> **This brief is operationalized in [`docs/phases/MODEL_TRAINING_PHASES.md`](docs/phases/MODEL_TRAINING_PHASES.md)** — the phased plan covering datasets (including the **Negofood** food-business document classes), training order, empirical thresholds, and the named inference contracts the Laravel pipeline calls. Keep the classifier's class list in lockstep with `DocumentTypeSeeder` (see [`docs/phases/PIPELINE_INTEGRATION_PHASES.md`](docs/phases/PIPELINE_INTEGRATION_PHASES.md) Phase P1).

### Project Overview
- **Goal**: Automate vendor document accreditation by verifying document type, authenticity, signature, and stamp.
- **Models to train**:
  1. ResNet‑50 for document type/authenticity classification (multi‑class).
  2. YOLOv8 for signature and stamp detection on full document images.
  3. Siamese CNN (using ResNet‑50 backbone) for signature verification.
  4. EfficientNet‑B0 feature extractor for stamp verification (with threshold optimisation).

### Directory Structure (read-only)
```
project_root/
├── data/
│   ├── classification/
│   │   ├── train/   # subfolders per class, e.g., 'bir_permit', 'financial_statement', ..., 'fake'
│   │   └── val/     # same structure
│   ├── detection/
│   │   ├── images/        # .jpg/.png full document pages
│   │   └── labels/        # YOLO format .txt files (class 0=signature, 1=stamp)
│   ├── signatures/
│   │   └── raw/           # subfolders per vendor ID, each containing genuine signature images
│   └── stamps/
│       ├── genuine/       # genuine stamp crops
│       └── forged/        # forged stamp crops
└── models/               # (script will create subfolders)
```

### Detailed Requirements for Each Model

#### 1. ResNet‑50 Classification
- Input: 512×512 RGB images.
- Use `tf.keras.preprocessing.image_dataset_from_directory` to load training/validation sets.
- Apply data augmentation within the training pipeline: random horizontal flip, rotation (±10°), zoom (±10%).
- Build model:
  - Base: `ResNet50(weights='imagenet', include_top=False, input_shape=(512,512,3))` – freeze initially.
  - Head: `GlobalAveragePooling2D → Dropout(0.5) → Dense(512, activation='relu', kernel_regularizer=l2(1e-4)) → Dropout(0.3) → Dense(num_classes, activation='softmax')`.
- Compile with `Adam(learning_rate=1e-4)`, categorical crossentropy, metric `accuracy`.
- Use callbacks:
  - `ModelCheckpoint` saving best weights to `models/resnet50_best.h5`
  - `EarlyStopping(patience=5, restore_best_weights=True)`
  - `ReduceLROnPlateau(factor=0.2, patience=3)`
- Train first phase (frozen base) for max 20 epochs.
- Then unfreeze the last 30 layers of ResNet‑50, recompile with `Adam(1e-5)`, fine-tune for max 10 epochs.
- Save the final model, and also save the class names mapping (e.g., as a JSON file `models/class_names.json`).

#### 2. YOLOv8 Detection
- Use Ultralytics YOLOv8.
- Create a `data/detection/data.yaml` dynamically in the script (or from provided paths).
- Train `yolov8n.pt` (nano) for 50 epochs, imgsz=640, batch=16, patience=10, device 0 if GPU, cache=True.
- After training, evaluate on the validation set (the split will be handled by the dataset.yaml). Print mAP@0.5.
- Export best model to ONNX: `models/yolov8_stamp_signature.onnx`.

#### 3. Siamese CNN for Signature Verification
- **Data preparation for Siamese**:
  - Because the dataset only contains genuine signatures per vendor, the script must:
    - Create synthetic forgeries by applying elastic deformation + rotation + noise to 30% of each vendor’s samples (use `cv2.remap` for elastic deformation, or use `scipy.ndimage`). Save these forged images temporarily (or create on-the-fly).
    - Build a pairwise dataset: for each vendor, generate genuine pairs (two different authentic signatures from the same vendor) and forged pairs (genuine vs synthetic forgery). Label 1 = genuine, 0 = forgery.
  - Use a custom data generator that yields random pairs each batch to avoid pre‑storing all pairs.
- **Model architecture**:
  - Shared backbone: `ResNet50(weights='imagenet', include_top=False, pooling='avg')`.
  - Add a `Dense(128)` embedding layer (no activation) after the backbone, with L2 normalisation.
  - Input two images (pair), run through the same backbone (Siamese twin) to obtain two 128‑D vectors.
  - Compute Euclidean distance (or cosine similarity) between the two embeddings.
  - Use a final `Dense(1, activation='sigmoid')` on the distance vector to output match probability. (Alternative: contrastive loss – choose whichever you prefer; mention which you used.)
- Compile with binary crossentropy and Adam(1e-4).
- Train for 20 epochs (or use early stopping on validation accuracy). Use a validation split (e.g., hold out 20% of vendors).
- After training, save the full Siamese model and the shared encoder separately as `models/siamese_signature.h5` and `models/siamese_encoder.h5`.
- Determine optimal threshold: compute Equal Error Rate (EER) on validation pairs, and store the threshold in `models/signature_threshold.txt`.

#### 4. EfficientNet for Stamp Verification
- Use pre‑trained `EfficientNetB0(weights='imagenet', include_top=False, pooling='avg')` as a feature extractor (1280‑D).
- **Data**: `data/stamps/genuine/` and `data/stamps/forged/`.
- Load all images (resize to 224×224), extract feature vectors, and store them in a pandas DataFrame with labels (genuine=1, forged=0).
- Train a simple logistic regression (or small MLP) on these features to distinguish genuine vs forged. Alternatively, use cosine similarity directly – pick the simpler method that yields a threshold.
- If using logistic regression: split data 80/20, train, evaluate accuracy, and save the model as `models/stamp_classifier.pkl`.
- If using pure feature extraction + threshold: compute optimal cosine similarity threshold on a validation set (genuine vs genuine stored reference should have high similarity, genuine vs forged low). Save threshold value in `models/stamp_threshold.txt`.
- Also save the EfficientNet feature extractor as `models/efficientnet_feature_extractor.h5`.

### Outputs (all in `models/`)
The script must produce these files:
- `resnet50_classifier.h5` and `class_names.json`
- `yolov8_stamp_signature.onnx`
- `siamese_signature.h5`, `siamese_encoder.h5`, `signature_threshold.txt`
- `efficientnet_feature_extractor.h5`, `stamp_classifier.pkl` (or `stamp_threshold.txt`)

### Additional Requirements
- The script must run from end to end without user intervention after setting the project root path.
- Print clear progress messages.
- Handle common errors (e.g., missing folders) gracefully with descriptive messages.
- Use a main function that allows easy configuration of hyperparameters at the top.
- Include a small test at the end: for each model, run a quick inference on a sample image from the validation set and print results.

Write the complete Python script now.