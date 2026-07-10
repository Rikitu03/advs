import random
import shutil
from pathlib import Path

def split_val_to_test(data_root: Path):
    val_dir = data_root / "validation" / "classifier_data"
    test_dir = data_root / "test" / "classifier_data"
    
    if not val_dir.exists():
        print(f"Validation directory not found: {val_dir}")
        return
        
    test_dir.mkdir(parents=True, exist_ok=True)
    
    for class_dir in val_dir.iterdir():
        if not class_dir.is_dir():
            continue
            
        test_class_dir = test_dir / class_dir.name
        test_class_dir.mkdir(parents=True, exist_ok=True)
        
        # Group files by their base name (without _clean.png or _scan.jpg)
        images = [img for img in class_dir.iterdir() if img.is_file() and img.suffix.lower() in ['.png', '.jpg', '.jpeg']]
        
        # Grouping
        stems = set()
        for img in images:
            # Handle synthetic_fake_val_00101_clean.png -> synthetic_fake_val_00101
            name = img.stem
            if name.endswith("_clean"):
                stems.add(name[:-6])
            elif name.endswith("_scan"):
                stems.add(name[:-5])
            else:
                stems.add(name)
                
        stems = sorted(list(stems))
        random.seed(42)
        random.shuffle(stems)
        
        # Move half the stems to test
        half = len(stems) // 2
        test_stems = set(stems[:half])
        
        moved = 0
        for img in images:
            name = img.stem
            base_stem = name
            if name.endswith("_clean"):
                base_stem = name[:-6]
            elif name.endswith("_scan"):
                base_stem = name[:-5]
                
            if base_stem in test_stems:
                shutil.move(str(img), str(test_class_dir / img.name))
                moved += 1
                
        print(f"{class_dir.name}: Moved {moved} images to test split.")
        
if __name__ == "__main__":
    split_val_to_test(Path("C:/xampp/htdocs/projects/advs/python/data"))
