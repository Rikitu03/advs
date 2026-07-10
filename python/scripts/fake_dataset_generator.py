import sys
import random
import json
from pathlib import Path
from PIL import Image, ImageDraw, ImageFont

# Add scripts directory to path to import degrade
PY_ROOT = Path(__file__).resolve().parents[1]
sys.path.append(str(PY_ROOT / "scripts"))
from bir_dataset_generator import degrade, log, FONT_DIR, resolve_font

TEMPLATE_FILES = [
    PY_ROOT / "data" / "template" / "bir_permit" / "BIR_PERMIT_TEMPLATE.png",
    PY_ROOT / "data" / "template" / "dti_registration" / "dti_template.png",
    PY_ROOT / "data" / "template" / "business_permits" / "Business Permit (Digos).png",
    PY_ROOT / "data" / "template" / "business_permits" / "Business Permit (Makati).png",
    PY_ROOT / "data" / "template" / "business_permits" / "Business Permit (Manila).png",
    PY_ROOT / "data" / "template" / "business_permits" / "Business Permit (Marikina).png",
    PY_ROOT / "data" / "template" / "business_permits" / "Business Permit (Taguig).png",
]

OUTPUT_DIR = PY_ROOT / "data" / "training" / "classifier_data" / "fake"
VAL_OUTPUT_DIR = PY_ROOT / "data" / "validation" / "classifier_data" / "fake"

def generate_fake(count: int, seed: int = 42):
    rng = random.Random(seed)
    font_path = resolve_font(FONT_DIR, bold=True)
    
    templates = []
    for f in TEMPLATE_FILES:
        if f.exists():
            templates.append(Image.open(f).convert("RGBA"))
        else:
            log(f"Warning: {f} not found.")
            
    if not templates:
        log("No templates found, generating blank images instead.")
        templates = [Image.new("RGBA", (800, 1000), (255, 255, 255, 255))]
    
    WORDS = ["FAKE", "VOID", "SAMPLE", "FORGERY", "NOT REAL", "DUMMY", "TEST"]
    COLORS = [(255, 0, 0), (0, 255, 0), (0, 0, 255), (255, 0, 255), (0, 0, 0)]
    
    manifest_records = []
    
    OUTPUT_DIR.mkdir(parents=True, exist_ok=True)
    VAL_OUTPUT_DIR.mkdir(parents=True, exist_ok=True)
    
    for i in range(1, count + 1):
        base = rng.choice(templates).copy()
        
        num_words = rng.randint(2, 5)
        for _ in range(num_words):
            word = rng.choice(WORDS)
            color = rng.choice(COLORS)
            font_size = rng.randint(60, 200)
            font = ImageFont.truetype(str(font_path), font_size)
            
            txt_img = Image.new('RGBA', (base.width*2, base.height*2), (255, 255, 255, 0))
            d = ImageDraw.Draw(txt_img)
            
            # Using getbbox to calculate text width and height roughly
            left, top, right, bottom = d.textbbox((0, 0), word, font=font)
            tw, th = right - left, bottom - top
            
            # Draw at center
            cx, cy = base.width, base.height
            d.text((cx - tw//2, cy - th//2), word, font=font, fill=(*color, rng.randint(150, 255)))
            
            angle = rng.uniform(-60, 60)
            txt_img = txt_img.rotate(angle, center=(cx, cy))
            
            # Paste back using center offset
            # We want to paste it randomly shifted
            offset_x = rng.randint(-base.width//2, base.width//2)
            offset_y = rng.randint(-base.height//2, base.height//2)
            
            base.paste(txt_img, (-base.width//2 + offset_x, -base.height//2 + offset_y), txt_img)
            
        clean = base.convert("RGB")
        scan = degrade(clean, rng)
        
        if i <= 1000:
            stem = f"synthetic_fake_{i:05d}"
            clean.save(OUTPUT_DIR / f"{stem}_clean.png")
            scan.save(OUTPUT_DIR / f"{stem}_scan.jpg", quality=85)
            manifest_records.append({"id": stem, "files": [f"{stem}_clean.png", f"{stem}_scan.jpg"]})
        else:
            stem = f"synthetic_fake_val_{i:05d}"
            clean.save(VAL_OUTPUT_DIR / f"{stem}_clean.png")
            scan.save(VAL_OUTPUT_DIR / f"{stem}_scan.jpg", quality=85)
            
        if i % 25 == 0:
            log(f"Generated {i} fake samples...")
            
    with open(OUTPUT_DIR / "_synthetic_manifest.json", "w") as f:
        json.dump({"next_index": 1001, "records": manifest_records}, f, indent=2)
        
if __name__ == "__main__":
    generate_fake(1500)
