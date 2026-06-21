# ADVS training data layout

Each model reads from its own `*_data` folder under `training/` and `validation/`.
Drop real data into these folders (contents are gitignored; the empty scaffold is
kept via `.gitkeep`). For a tiny synthetic dataset to smoke-test the pipeline, run
the fixture generator:

```bash
python/env/bin/python.exe python/.claude/skills/run-advs-training/make_fixtures.py
```

## Layout

```
data/
├── training/
│   ├── classifier_data/        # ResNet-50 — one subfolder PER CLASS:
│   │   ├── bir_permit/             *.jpg / *.png
│   │   ├── financial_statement/
│   │   ├── business_registration/
│   │   └── fake/
│   ├── detector_data/          # YOLOv8
│   │   ├── images/                 *.jpg / *.png  (full document pages)
│   │   └── labels/                 *.txt  (YOLO format: "<cls> cx cy w h", cls 0=signature 1=stamp)
│   ├── signature_data/         # Siamese — one subfolder PER VENDOR of genuine signatures:
│   │   ├── vendor_001/             *.png
│   │   └── vendor_002/
│   └── stamp_data/             # EfficientNet
│       ├── genuine/                *.png  (genuine stamp crops)
│       └── forged/                 *.png  (forged stamp crops)
└── validation/                 # SAME four subfolders, same internal shape
    ├── classifier_data/  …
    ├── detector_data/    …
    ├── signature_data/   …
    └── stamp_data/       …
```

Image filenames in `detector_data/images/` and `detector_data/labels/` must match
(e.g. `page_3.png` ↔ `page_3.txt`).
