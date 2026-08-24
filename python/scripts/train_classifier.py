"""ADVS - ResNet-50 document type / authenticity classifier (model 1 of 4).

Trains a multi-class ResNet-50 on document images and saves the model plus its
class-name mapping. Faithful to training_script.md §1.

Data layout (read-only):
    <data-root>/training/classifier_data/<class>/*.jpg|png
    <data-root>/validation/classifier_data/<class>/*.jpg|png
    where <class> matches the canonical classifier taxonomy in
    python/data/CLASSIFIER_DATASET_SPEC.md.

Outputs (under <models-out>):
    resnet50_classifier.keras, resnet50_best.keras, class_names.json

Run:
    python scripts/train_classifier.py                 # full training (needs ML stack + data)
    python scripts/train_classifier.py --dry-run       # validate layout only (stdlib only)
    python scripts/train_classifier.py --smoke         # 1-epoch tiny CPU run (needs ML stack)

Heavy imports (tensorflow) are lazy so --dry-run works with only stdlib installed.
"""

from __future__ import annotations

import argparse
import json
import os
import sys
import time
from pathlib import Path

PY_ROOT = Path(__file__).resolve().parents[1]

CONFIG: dict = {
    "image_size": 512,
    "batch_size": 16,
    "frozen_epochs": 20,
    "finetune_epochs": 10,
    "finetune_unfreeze": 30,    # unfreeze the last N base layers
    "lr_frozen": 1e-4,
    "lr_finetune": 1e-5,
    "seed": 42,
}
SMOKE_OVERRIDES = {
    "image_size": 64,
    "batch_size": 2,
    "frozen_epochs": 1,
    "finetune_epochs": 1,
    "finetune_unfreeze": 5,
}


def log(msg: str) -> None:
    print(f"[classifier] {msg}", flush=True)


def section(title: str) -> None:
    print("\n" + "=" * 70 + f"\n  {title}\n" + "=" * 70, flush=True)


class TrainError(RuntimeError):
    """Expected, user-actionable failure (e.g. missing data)."""


def require_dir(path: Path, what: str) -> None:
    if not path.is_dir():
        raise TrainError(f"Missing {what}: expected directory '{path}'.")


def class_dirs(path: Path) -> list[str]:
    return sorted(d.name for d in path.iterdir() if d.is_dir())


def validate_structure(train_dir: Path, val_dir: Path) -> dict:
    require_dir(train_dir, "classifier training set")
    require_dir(val_dir, "classifier validation set")
    classes = class_dirs(train_dir)
    val_classes = class_dirs(val_dir)
    if len(classes) < 2:
        raise TrainError(
            f"Need >= 2 class subfolders under {train_dir}, found {len(classes)}: {classes}"
        )
    if classes != val_classes:
        raise TrainError(
            "Training/validation classifier folders must match exactly. "
            f"train={classes}, val={val_classes}"
        )
    return {"num_classes": len(classes), "classes": classes}


def train(cfg: dict, train_dir: Path, val_dir: Path, models_out: Path) -> None:
    section("ResNet-50 document classification")
    import tensorflow as tf
    from tensorflow.keras import layers, models, regularizers
    from tensorflow.keras.applications import ResNet50
    from tensorflow.keras.applications.resnet50 import preprocess_input
    from tensorflow.keras.callbacks import (
        EarlyStopping,
        ModelCheckpoint,
        ReduceLROnPlateau,
    )

    size = cfg["image_size"]
    log(f"Loading datasets at {size}x{size} (batch={cfg['batch_size']})")
    class_names = list(cfg["classes"])
    train_ds = tf.keras.utils.image_dataset_from_directory(
        str(train_dir), image_size=(size, size), batch_size=cfg["batch_size"],
        label_mode="categorical", seed=cfg["seed"], class_names=class_names,
    )
    val_ds = tf.keras.utils.image_dataset_from_directory(
        str(val_dir), image_size=(size, size), batch_size=cfg["batch_size"],
        label_mode="categorical", seed=cfg["seed"], class_names=class_names,
    )
    num_classes = len(class_names)
    log(f"Classes ({num_classes}): {class_names}")

    import numpy as np
    from sklearn.utils.class_weight import compute_class_weight

    # Compute class weights based on number of files
    y_train = []
    for idx, c_name in enumerate(class_names):
        c_dir = train_dir / c_name
        num_files = len([f for f in c_dir.iterdir() if f.is_file() and f.suffix.lower() in ['.jpg', '.jpeg', '.png', '.bmp', '.webp']])
        y_train.extend([idx] * num_files)
    
    class_weights_array = compute_class_weight(
        class_weight='balanced',
        classes=np.unique(y_train),
        y=y_train
    )
    class_weight_dict = {i: float(w) for i, w in enumerate(class_weights_array)}
    log(f"Computed class weights: {class_weight_dict}")

    augment = tf.keras.Sequential(
        [
            layers.RandomFlip("horizontal"),
            layers.RandomRotation(10 / 360.0),  # +/- 10 degrees
            layers.RandomZoom(0.1),
        ],
        name="augment",
    )
    autotune = tf.data.AUTOTUNE
    train_ds = train_ds.prefetch(autotune)
    val_ds = val_ds.prefetch(autotune)

    base = ResNet50(weights="imagenet", include_top=False, input_shape=(size, size, 3))
    base.trainable = False

    inputs = layers.Input(shape=(size, size, 3))
    x = augment(inputs)
    x = preprocess_input(x)
    x = base(x, training=False)
    x = layers.GlobalAveragePooling2D()(x)
    x = layers.Dropout(0.5)(x)
    x = layers.Dense(512, activation="relu", kernel_regularizer=regularizers.l2(1e-4))(x)
    x = layers.Dropout(0.3)(x)
    outputs = layers.Dense(num_classes, activation="softmax")(x)
    model = models.Model(inputs, outputs, name="resnet50_classifier")

    model.compile(
        optimizer=tf.keras.optimizers.Adam(learning_rate=cfg["lr_frozen"]),
        loss="categorical_crossentropy", metrics=["accuracy"],
    )
    callbacks = [
        ModelCheckpoint(str(models_out / "resnet50_best.keras"), save_best_only=True, monitor="val_loss"),
        EarlyStopping(patience=2, restore_best_weights=True, monitor="val_loss"),
        ReduceLROnPlateau(factor=0.2, patience=3, monitor="val_loss"),
    ]

    log(f"Phase 1 - frozen base, max {cfg['frozen_epochs']} epochs")
    model.fit(train_ds, validation_data=val_ds, epochs=cfg["frozen_epochs"], callbacks=callbacks, class_weight=class_weight_dict)

    log(f"Phase 2 - fine-tune last {cfg['finetune_unfreeze']} layers, max {cfg['finetune_epochs']} epochs")
    base.trainable = True
    for layer in base.layers[: -cfg["finetune_unfreeze"]]:
        layer.trainable = False
    model.compile(
        optimizer=tf.keras.optimizers.Adam(learning_rate=cfg["lr_finetune"]),
        loss="categorical_crossentropy", metrics=["accuracy"],
    )
    model.fit(train_ds, validation_data=val_ds, epochs=cfg["finetune_epochs"], callbacks=callbacks, class_weight=class_weight_dict)

    model.save(str(models_out / "resnet50_classifier.keras"))
    (models_out / "class_names.json").write_text(json.dumps(class_names, indent=2))
    log(f"Saved resnet50_classifier.keras + class_names.json ({num_classes} classes)")

    test_dir = train_dir.parent.parent / "test" / "classifier_data"
    if test_dir.exists():
        log("Evaluating final model on held-out test split...")
        test_ds = tf.keras.utils.image_dataset_from_directory(
            str(test_dir), image_size=(size, size), batch_size=cfg["batch_size"],
            label_mode="categorical", class_names=class_names,
        )
        test_ds = test_ds.prefetch(autotune)
        best_model = tf.keras.models.load_model(str(models_out / "resnet50_best.keras"))
        test_loss, test_acc = best_model.evaluate(test_ds)
        log(f"=== TEST SET METRICS ===\nAccuracy: {test_acc:.4f} | Loss: {test_loss:.4f}")

    _inference_check(models_out)


def _inference_check(models_out: Path) -> None:
    section("Inference sanity check")
    import numpy as np
    import tensorflow as tf

    m = tf.keras.models.load_model(str(models_out / "resnet50_classifier.keras"))
    size = m.input_shape[1]
    pred = m.predict(np.random.rand(1, size, size, 3).astype("float32"), verbose=0)
    names = json.loads((models_out / "class_names.json").read_text())
    log(f"sample -> {names[int(pred.argmax())]} (conf {float(pred.max()):.3f})")


def tune(cfg: dict, train_dir: Path, val_dir: Path, models_out: Path) -> None:
    section("ResNet-50 hyperparameter tuning")
    import tensorflow as tf
    from tensorflow.keras import layers, models, regularizers
    from tensorflow.keras.applications import ResNet50
    from tensorflow.keras.applications.resnet50 import preprocess_input
    from tensorflow.keras.callbacks import EarlyStopping
    import keras_tuner as kt
    import numpy as np
    from sklearn.utils.class_weight import compute_class_weight
    import json

    size = cfg["image_size"]
    log(f"Loading datasets at {size}x{size} for tuning (batch={cfg['batch_size']})")
    class_names = list(cfg["classes"])
    train_ds = tf.keras.utils.image_dataset_from_directory(
        str(train_dir), image_size=(size, size), batch_size=cfg["batch_size"],
        label_mode="categorical", seed=cfg["seed"], class_names=class_names,
    )
    val_ds = tf.keras.utils.image_dataset_from_directory(
        str(val_dir), image_size=(size, size), batch_size=cfg["batch_size"],
        label_mode="categorical", seed=cfg["seed"], class_names=class_names,
    )
    num_classes = len(class_names)
    log(f"Classes ({num_classes}): {class_names}")

    y_train = []
    for idx, c_name in enumerate(class_names):
        c_dir = train_dir / c_name
        num_files = len([f for f in c_dir.iterdir() if f.is_file() and f.suffix.lower() in ['.jpg', '.jpeg', '.png', '.bmp', '.webp']])
        y_train.extend([idx] * num_files)
    
    class_weights_array = compute_class_weight('balanced', classes=np.unique(y_train), y=y_train)
    class_weight_dict = {i: float(w) for i, w in enumerate(class_weights_array)}

    augment = tf.keras.Sequential([
        layers.RandomFlip("horizontal"),
        layers.RandomRotation(10 / 360.0),
        layers.RandomZoom(0.1),
    ])
    autotune = tf.data.AUTOTUNE
    train_ds = train_ds.prefetch(autotune)
    val_ds = val_ds.prefetch(autotune)

    def build_model(hp):
        base = ResNet50(weights="imagenet", include_top=False, input_shape=(size, size, 3))
        base.trainable = False
        
        inputs = layers.Input(shape=(size, size, 3))
        x = augment(inputs)
        x = preprocess_input(x)
        x = base(x, training=False)
        x = layers.GlobalAveragePooling2D()(x)
        
        dropout_1 = hp.Float("dropout_1", min_value=0.2, max_value=0.6, step=0.1)
        x = layers.Dropout(dropout_1)(x)
        
        dense_units = hp.Int("dense_units", min_value=256, max_value=1024, step=256)
        l2_reg = hp.Choice("l2_reg", values=[1e-3, 1e-4, 1e-5])
        x = layers.Dense(dense_units, activation="relu", kernel_regularizer=regularizers.l2(l2_reg))(x)
        
        dropout_2 = hp.Float("dropout_2", min_value=0.2, max_value=0.6, step=0.1)
        x = layers.Dropout(dropout_2)(x)
        
        outputs = layers.Dense(num_classes, activation="softmax")(x)
        model = models.Model(inputs, outputs)
        
        lr = hp.Float("lr", min_value=1e-5, max_value=1e-3, sampling="log")
        model.compile(
            optimizer=tf.keras.optimizers.Adam(learning_rate=lr),
            loss="categorical_crossentropy", metrics=["accuracy"]
        )
        return model

    tuner = kt.Hyperband(
        build_model,
        objective="val_accuracy",
        max_epochs=cfg.get("frozen_epochs", 10),
        factor=3,
        directory=str(models_out / "tuning"),
        project_name="advs_resnet50"
    )
    
    log("Starting KerasTuner Hyperband search (frozen phase)...")
    tuner.search(train_ds, validation_data=val_ds, class_weight=class_weight_dict, callbacks=[EarlyStopping(patience=3)])
    
    best_hps = tuner.get_best_hyperparameters(num_trials=1)[0]
    log("Best hyperparameters found:")
    log(f"- dropout_1: {best_hps.get('dropout_1')}")
    log(f"- dense_units: {best_hps.get('dense_units')}")
    log(f"- l2_reg: {best_hps.get('l2_reg')}")
    log(f"- dropout_2: {best_hps.get('dropout_2')}")
    log(f"- lr: {best_hps.get('lr')}")
    
    with open(models_out / "best_hyperparameters.json", "w") as f:
        json.dump(best_hps.values, f, indent=2)
    log(f"Saved best hyperparameters to {models_out / 'best_hyperparameters.json'}")

    test_dir = val_dir.parent.parent / "test" / "classifier_data"
    if test_dir.exists():
        log("Evaluating best tuned model on held-out test split...")
        test_ds = tf.keras.utils.image_dataset_from_directory(
            str(test_dir), image_size=(size, size), batch_size=cfg["batch_size"],
            label_mode="categorical", class_names=class_names,
        )
        test_ds = test_ds.prefetch(autotune)
        # get_best_models returns the trained models
        best_model = tuner.get_best_models(num_models=1)[0]
        test_loss, test_acc = best_model.evaluate(test_ds)
        log(f"=== TUNED TEST SET METRICS ===\nAccuracy: {test_acc:.4f} | Loss: {test_loss:.4f}")


def parse_args(argv: list[str]) -> argparse.Namespace:
    ap = argparse.ArgumentParser(description="Train the ADVS ResNet-50 classifier.")
    ap.add_argument("--data-root", default=str(PY_ROOT / "data"))
    ap.add_argument("--models-out", default=str(PY_ROOT / "models"))
    ap.add_argument("--dry-run", action="store_true",
                    help="Validate layout/config only; no heavy imports, no training.")
    ap.add_argument("--smoke", action="store_true",
                    help="Tiny 1-epoch CPU run (needs ML stack + fixtures).")
    ap.add_argument("--tune", action="store_true",
                    help="Run automated hyperparameter tuning instead of training.")
    return ap.parse_args(argv)


def main(argv: list[str] | None = None) -> int:
    args = parse_args(argv if argv is not None else sys.argv[1:])
    cfg = dict(CONFIG)
    if args.smoke:
        cfg.update(SMOKE_OVERRIDES)
        os.environ.setdefault("CUDA_VISIBLE_DEVICES", "-1")
        log("SMOKE MODE: reduced epochs/sizes, CPU only.")

    data_root = Path(args.data_root)
    train_dir = data_root / "training" / "classifier_data"
    val_dir = data_root / "validation" / "classifier_data"
    models_out = Path(args.models_out)
    log(f"data-root={data_root}  models-out={models_out}")

    try:
        summary = validate_structure(train_dir, val_dir)
    except TrainError as exc:
        log(f"STRUCTURE ERROR: {exc}")
        return 2
    log(f"ok: {summary}")
    
    cfg.update(summary)

    if args.dry_run:
        section("DRY RUN - structure valid, skipping training")
        return 0

    models_out.mkdir(parents=True, exist_ok=True)
    started = time.time()
    
    if args.tune:
        tune(cfg, train_dir, val_dir, models_out)
    else:
        train(cfg, train_dir, val_dir, models_out)
        
    section(f"Done in {time.time() - started:.1f}s - artefacts in {models_out}")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
