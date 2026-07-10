import sys
from pathlib import Path
import tensorflow as tf
import numpy as np
from sklearn.metrics import classification_report
import keras_tuner as kt
import json

PY_ROOT = Path(__file__).resolve().parents[1]

def main():
    data_root = PY_ROOT / "data"
    test_dir = data_root / "test" / "classifier_data"
    models_out = PY_ROOT / "models"
    
    if not test_dir.exists():
        print("No test directory found.")
        return
        
    size = 512
    class_names = sorted([d.name for d in (data_root / "training" / "classifier_data").iterdir() if d.is_dir()])
    
    print("Loading test dataset...")
    test_ds = tf.keras.utils.image_dataset_from_directory(
        str(test_dir), image_size=(size, size), batch_size=16,
        label_mode="categorical", class_names=class_names, shuffle=False
    )
    
    from tensorflow.keras import layers, models, regularizers
    from tensorflow.keras.applications import ResNet50
    from tensorflow.keras.applications.resnet50 import preprocess_input
    
    augment = tf.keras.Sequential([
        layers.RandomFlip("horizontal"),
        layers.RandomRotation(10 / 360.0),
        layers.RandomZoom(0.1),
    ], name="augment")

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
        
        outputs = layers.Dense(len(class_names), activation="softmax")(x)
        model = models.Model(inputs, outputs)
        
        lr = hp.Float("lr", min_value=1e-5, max_value=1e-3, sampling="log")
        model.compile(
            optimizer=tf.keras.optimizers.Adam(learning_rate=lr),
            loss="categorical_crossentropy", metrics=["accuracy"]
        )
        return model

    print("Initializing KerasTuner to grab best model from Trial 1...")
    tuner = kt.Hyperband(
        build_model,
        objective="val_accuracy",
        max_epochs=20,
        factor=3,
        directory=str(models_out / "tuning"),
        project_name="advs_resnet50"
    )
    
    print("Loading best model weights...")
    best_model = tuner.get_best_models(num_models=1)[0]
    
    print("Running predictions on test set (this may take a minute)...")
    y_true = []
    y_pred = []
    
    for images, labels in test_ds:
        preds = best_model.predict(images, verbose=0)
        y_true.extend(np.argmax(labels.numpy(), axis=1))
        y_pred.extend(np.argmax(preds, axis=1))
        
    print("\n=== TEST SET F1-SCORE REPORT ===")
    print(classification_report(y_true, y_pred, target_names=class_names, digits=4))
    
    # Save the model so they don't have to train again
    save_path = models_out / "resnet50_best.keras"
    best_model.save(str(save_path))
    (models_out / "class_names.json").write_text(json.dumps(class_names, indent=2))
    print(f"\nModel exported to {save_path}!")

if __name__ == "__main__":
    main()
