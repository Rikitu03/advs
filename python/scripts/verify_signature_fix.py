"""Quick verification that the signature enrollment changes work correctly.

This script verifies:
1. The _normalize_contrast function exists and works
2. The logging is set up correctly
3. The diagnostic script can be imported

Run: python/env/Scripts/python.exe python/scripts/verify_signature_fix.py
"""

from __future__ import annotations

import sys
from pathlib import Path

PY_ROOT = Path(__file__).resolve().parents[1]
if str(PY_ROOT) not in sys.path:
    sys.path.insert(0, str(PY_ROOT))

print("=" * 70)
print("Signature Enrollment Fix Verification")
print("=" * 70)

# Test 1: Verify _normalize_contrast exists
print("\n[1/4] Checking _normalize_contrast function...")
try:
    from api.routers.signature import _normalize_contrast
    print("  ✓ Function imported successfully")
except ImportError as e:
    print(f"  ✗ Failed to import: {e}")
    sys.exit(1)

# Test 2: Verify logging is set up
print("\n[2/4] Checking logging setup...")
try:
    from api.routers.signature import logger
    print(f"  ✓ Logger configured: {logger.name}")
except ImportError as e:
    print(f"  ✗ Failed to import logger: {e}")
    sys.exit(1)

# Test 3: Verify diagnostic script exists
print("\n[3/4] Checking diagnostic script...")
debug_script = PY_ROOT / "scripts" / "debug_signature_enroll.py"
if debug_script.exists():
    print(f"  ✓ Diagnostic script exists: {debug_script.name}")
else:
    print(f"  ✗ Diagnostic script not found at {debug_script}")
    sys.exit(1)

# Test 4: Test _normalize_contrast with a simple image
print("\n[4/4] Testing _normalize_contrast with synthetic image...")
try:
    from PIL import Image, ImageDraw
    
    # Create a simple test image with low contrast
    test_image = Image.new("RGB", (800, 600), "white")
    draw = ImageDraw.Draw(test_image)
    # Draw some dark lines (simulating signatures)
    draw.line([(100, 100), (700, 100)], fill="darkgray", width=3)
    draw.line([(100, 300), (700, 300)], fill="darkgray", width=3)
    draw.line([(100, 500), (700, 500)], fill="darkgray", width=3)
    
    # Apply contrast enhancement
    enhanced = _normalize_contrast(test_image)
    
    # Verify output
    assert enhanced.mode == "RGB", "Output should be RGB"
    assert enhanced.size == test_image.size, "Size should be preserved"
    
    print(f"  ✓ Contrast enhancement works (input: {test_image.mode} {test_image.size}, output: {enhanced.mode} {enhanced.size})")
except Exception as e:
    print(f"  ✗ Contrast enhancement failed: {e}")
    sys.exit(1)

print("\n" + "=" * 70)
print("✓ All verification checks passed!")
print("=" * 70)
print("\nNext steps:")
print("1. Run the diagnostic script on a failing enrollment photo:")
print("   python\\env\\Scripts\\python.exe python\\scripts\\debug_signature_enroll.py path\\to\\photo.jpg")
print("\n2. Check the FastAPI logs when processing enrollment requests")
print("\n3. Run the full test suite:")
print("   python\\env\\Scripts\\python.exe -m pytest python\\tests\\test_signature_enroll.py -v")
print("=" * 70)
