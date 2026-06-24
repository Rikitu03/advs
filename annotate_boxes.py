"""BIR Permit Template Bounding Box Annotator.

A small Tkinter tool for drawing one bounding box per field on the blank BIR
Certificate of Registration (Form 2303) template, then exporting the boxes as
pixel coordinates. It exists to *calibrate* the dataset generator: the exported
``bounding_boxes.json`` (keyword -> {x, y, w, h}) drops straight into
``python/scripts/bir_template_generator.py``'s ``FIELD_BOXES``.

Run it from the project root with a Python that has Pillow:

    python annotate_boxes.py
    # or, with this repo's venv:
    python/env/Scripts/python.exe annotate_boxes.py

Workflow:
    1. Pick a keyword from the dropdown (the field you're locating).
    2. Click-drag a rectangle over that field on the image.
    3. Repeat for each field (re-drawing a keyword replaces its box).
    4. Save (-> bounding_boxes.json) or Print Result (-> terminal).

Dependencies: Python standard library + Pillow only. Keywords come from
``python/scripts/ocr_dryrun.py`` (variable ``KEYWORD_LIST``), never hardcoded.

----------------------------------------------------------------------------
Coordinate spaces (the crux of the tool)
----------------------------------------------------------------------------
There are two coordinate spaces and we constantly convert between them:

  * IMAGE space  - full-resolution pixels of the original template
                   (origin top-left, 0..img_w, 0..img_h). Everything we store
                   and export lives here, so boxes are resolution-independent.
  * CANVAS space - pixels on the on-screen canvas. The image is drawn scaled to
                   fit the window (preserving aspect ratio) and CENTERED, so it
                   sits at an offset (off_x, off_y) and is `scale` times smaller.

Conversions (see compute_fit / canvas_to_image / image_to_canvas):

    canvas = image * scale + offset
    image  = (canvas - offset) / scale

We always read the mouse via canvas.canvasx()/canvasy() so any scroll offset is
already folded into the canvas coordinate before we undo scale + padding.
"""
from __future__ import annotations

import importlib.util
import json
import sys
import tkinter as tk
from pathlib import Path
from tkinter import messagebox, ttk

# Pillow is a hard requirement; fail loudly with install instructions (per spec).
try:
    from PIL import Image, ImageTk
except ImportError:
    sys.stderr.write(
        "ERROR: this tool requires Pillow (PIL).\n"
        "Install it with:\n"
        "    pip install Pillow\n"
        "or, into this repo's venv:\n"
        "    python/env/Scripts/python.exe -m pip install Pillow\n"
    )
    sys.exit(1)

# Pillow >= 10 moved resampling filters under Image.Resampling; keep a fallback.
try:
    _RESAMPLE = Image.Resampling.LANCZOS
except AttributeError:  # older Pillow
    _RESAMPLE = Image.LANCZOS

APP_TITLE = "BIR Permit Template Bounding Box Annotator"
PROJECT_ROOT = Path(__file__).resolve().parent
TEMPLATE_PATH = PROJECT_ROOT / "python" / "data" / "template" / "BIR_PERMIT_TEMPLATE.png"
OUTPUT_JSON = PROJECT_ROOT / "bounding_boxes.json"  # saved next to this script

# Minimal fallback keywords - only used if ocr_dryrun.py can't be loaded at all,
# so the tool is still usable for debugging.
DEFAULT_KEYWORDS = ["tin", "registered_name", "registration_date", "registered_address"]

# Detection regions that are NOT OCR text fields, so they don't live in
# ocr_dryrun.py's FIELD_SPECS/KEYWORD_LIST. These mark the YOLOv8 signature/stamp
# regions (Stage 4) - the signature written over the registrant name and the BIR
# dry seal - so they can be boxed here too (for detector labels / placing a
# synthetic signature + seal in the dataset generator). Appended after the fields.
REGION_KEYWORDS = ["signature_over_name", "dry_seal"]


# ===========================================================================
# Pure logic (no Tk) - kept import-side-effect-free so it is unit-testable.
# ===========================================================================
def compute_fit(img_w: int, img_h: int, canvas_w: int, canvas_h: int):
    """Fit an (img_w x img_h) image inside a (canvas_w x canvas_h) canvas while
    preserving aspect ratio, centered.

    Returns (scale, off_x, off_y, disp_w, disp_h):
      scale          - multiply IMAGE px by this to get on-screen px
      off_x, off_y   - top-left padding that centers the scaled image
      disp_w, disp_h - on-screen size of the scaled image
    """
    if img_w <= 0 or img_h <= 0 or canvas_w <= 0 or canvas_h <= 0:
        return 1.0, 0.0, 0.0, float(img_w), float(img_h)
    scale = min(canvas_w / img_w, canvas_h / img_h)  # the binding dimension wins
    disp_w = img_w * scale
    disp_h = img_h * scale
    off_x = (canvas_w - disp_w) / 2.0  # leftover width split into left/right pad
    off_y = (canvas_h - disp_h) / 2.0  # leftover height split into top/bottom pad
    return scale, off_x, off_y, disp_w, disp_h


def canvas_to_image(cx: float, cy: float, scale: float, off_x: float, off_y: float,
                    img_w: int, img_h: int):
    """Canvas px -> original IMAGE px. Undo the centering offset, then the scale,
    then clamp into the image so a drag that strays off the page still yields a
    valid in-bounds coordinate."""
    ix = (cx - off_x) / scale
    iy = (cy - off_y) / scale
    ix = max(0.0, min(float(img_w), ix))
    iy = max(0.0, min(float(img_h), iy))
    return ix, iy


def image_to_canvas(ix: float, iy: float, scale: float, off_x: float, off_y: float):
    """Original IMAGE px -> canvas px (the inverse of canvas_to_image, no clamp)."""
    return ix * scale + off_x, iy * scale + off_y


def normalize_box(x0: float, y0: float, x1: float, y1: float):
    """Two opposite corners (in any drag direction) -> (x, y, w, h) with a
    top-left origin and non-negative width/height, rounded to int pixels."""
    x = int(round(min(x0, x1)))
    y = int(round(min(y0, y1)))
    w = int(round(abs(x1 - x0)))
    h = int(round(abs(y1 - y0)))
    return x, y, w, h


def boxes_to_json(store: dict) -> dict:
    """Internal store (keyword -> (x, y, w, h)) -> export dict
    (keyword -> {"x","y","w","h"}) with plain ints."""
    return {
        kw: {"x": int(x), "y": int(y), "w": int(w), "h": int(h)}
        for kw, (x, y, w, h) in store.items()
    }


def resolve_keywords(loaded) -> list:
    """Use the loaded keywords if there are any, else the debug default list."""
    return list(loaded) if loaded else list(DEFAULT_KEYWORDS)


def _load_module_from_path(path: Path):
    """Import a .py file directly by path (fallback when the package import fails)."""
    if not Path(path).exists():
        return None
    spec = importlib.util.spec_from_file_location("ocr_dryrun_annotate", str(path))
    module = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(module)
    return module


def keywords_from_ocr_module() -> list:
    """Load the annotation keywords from python/scripts/ocr_dryrun.py.

    Strategy 1 is the documented package import
    (``from python.scripts.ocr_dryrun import KEYWORD_LIST``); strategy 2 loads the
    file directly by path and derives the list from KEYWORD_LIST or FIELD_SPECS,
    so the tool still works if run from an unexpected CWD. Returns [] on total
    failure (the caller falls back to DEFAULT_KEYWORDS)."""
    # Make sure the project root is importable for the package-style import.
    if str(PROJECT_ROOT) not in sys.path:
        sys.path.insert(0, str(PROJECT_ROOT))

    # Strategy 1: the import named in the spec.
    try:
        from python.scripts.ocr_dryrun import KEYWORD_LIST

        if KEYWORD_LIST:
            return list(KEYWORD_LIST)
    except Exception as exc:  # ImportError, AttributeError, ...
        print(f"[annotate] package import failed ({exc}); trying direct file load",
              file=sys.stderr)

    # Strategy 2: load the file directly and derive the keywords.
    try:
        module = _load_module_from_path(PROJECT_ROOT / "python" / "scripts" / "ocr_dryrun.py")
        if module is not None:
            if getattr(module, "KEYWORD_LIST", None):
                return list(module.KEYWORD_LIST)
            if getattr(module, "FIELD_SPECS", None):
                return [spec["key"] for spec in module.FIELD_SPECS]
    except Exception as exc:
        print(f"[annotate] could not load keywords from ocr_dryrun.py: {exc}",
              file=sys.stderr)

    return []


def load_keywords() -> list:
    """The dropdown keyword list: OCR fields (from ocr_dryrun.py, or the debug
    default) followed by the non-OCR detection regions, de-duplicated."""
    keywords = resolve_keywords(keywords_from_ocr_module())
    for region in REGION_KEYWORDS:
        if region not in keywords:
            keywords.append(region)
    return keywords


# ===========================================================================
# GUI
# ===========================================================================
class AnnotatorApp:
    """Tkinter app: draw/replace one box per keyword on a scaled, centered image."""

    def __init__(self, root: tk.Tk, image_path: Path, keywords: list):
        self.root = root
        self.image_path = Path(image_path)
        self.keywords = keywords

        # Original full-resolution image - the single source of truth for size.
        self.original = Image.open(self.image_path).convert("RGB")
        self.img_w, self.img_h = self.original.size

        self.boxes: dict = {}     # keyword -> (x, y, w, h) in ORIGINAL image px

        # Current view transform, refreshed every _render().
        self.scale = 1.0
        self.off_x = 0.0
        self.off_y = 0.0

        self._photo = None        # keep a ref or Tk garbage-collects the image
        self._drag_start = None   # (canvas_x, canvas_y) at button press
        self._temp_rect = None    # id of the live "rubber-band" rectangle
        self._last_size = None     # debounce duplicate <Configure> events

        self._build_ui()
        self.root.update_idletasks()           # let widgets get real sizes
        self.canvas.bind("<Configure>", self._on_configure)  # rescale on resize
        self._render()

    # ----- UI construction ------------------------------------------------
    def _build_ui(self) -> None:
        self.root.title(APP_TITLE)
        self.root.geometry("900x820")
        self.root.minsize(520, 520)

        # Top bar: keyword dropdown + current-selection label.
        top = tk.Frame(self.root)
        top.pack(side=tk.TOP, fill=tk.X, padx=8, pady=6)
        tk.Label(top, text="Keyword:").pack(side=tk.LEFT)
        self.selected = tk.StringVar()
        self.combo = ttk.Combobox(top, textvariable=self.selected, values=self.keywords,
                                  state="readonly", width=34)
        self.combo.pack(side=tk.LEFT, padx=(4, 12))
        if self.keywords:
            self.combo.current(0)
        self.combo.bind("<<ComboboxSelected>>", self._on_keyword_change)
        self.sel_label = tk.Label(top, text=self._sel_label_text(), fg="#0a7d28",
                                  font=("Segoe UI", 10, "bold"))
        self.sel_label.pack(side=tk.LEFT)

        # Middle: scrollable canvas.
        mid = tk.Frame(self.root)
        mid.pack(side=tk.TOP, fill=tk.BOTH, expand=True)
        self.canvas = tk.Canvas(mid, background="#2f2f33", cursor="crosshair",
                                highlightthickness=0)
        hbar = tk.Scrollbar(mid, orient=tk.HORIZONTAL, command=self.canvas.xview)
        vbar = tk.Scrollbar(mid, orient=tk.VERTICAL, command=self.canvas.yview)
        self.canvas.configure(xscrollcommand=hbar.set, yscrollcommand=vbar.set)
        vbar.pack(side=tk.RIGHT, fill=tk.Y)
        hbar.pack(side=tk.BOTTOM, fill=tk.X)
        self.canvas.pack(side=tk.LEFT, fill=tk.BOTH, expand=True)

        # Mouse: press = start corner, motion = stretch, release = commit.
        self.canvas.bind("<ButtonPress-1>", self._on_press)
        self.canvas.bind("<B1-Motion>", self._on_drag)
        self.canvas.bind("<ButtonRelease-1>", self._on_release)

        # Bottom: action buttons + status line.
        bottom = tk.Frame(self.root)
        bottom.pack(side=tk.BOTTOM, fill=tk.X, padx=8, pady=8)
        tk.Button(bottom, text="Save", width=12, command=self._on_save).pack(side=tk.LEFT)
        tk.Button(bottom, text="Print Result", width=12,
                  command=self._on_print).pack(side=tk.LEFT, padx=6)
        tk.Button(bottom, text="Reset", width=12, command=self._on_reset).pack(side=tk.LEFT)
        self.status = tk.Label(bottom, text="Ready.", fg="#666", anchor="e")
        self.status.pack(side=tk.RIGHT, fill=tk.X, expand=True)

    def _sel_label_text(self) -> str:
        return f"Selected: {self.selected.get() or '(none)'}"

    # ----- Rendering ------------------------------------------------------
    def _render(self) -> None:
        """Redraw the scaled image + every stored box at the current canvas size."""
        cw = max(1, self.canvas.winfo_width())
        ch = max(1, self.canvas.winfo_height())

        # Recompute the fit transform for this canvas size.
        self.scale, self.off_x, self.off_y, disp_w, disp_h = compute_fit(
            self.img_w, self.img_h, cw, ch)
        disp_w_i = max(1, int(round(disp_w)))
        disp_h_i = max(1, int(round(disp_h)))

        # Resize a fresh copy to the display size (keep `original` pristine).
        resized = self.original.resize((disp_w_i, disp_h_i), _RESAMPLE)
        self._photo = ImageTk.PhotoImage(resized)

        self.canvas.delete("all")
        self.canvas.create_image(self.off_x, self.off_y, anchor=tk.NW, image=self._photo)
        self._draw_boxes()
        # Scroll over whatever was drawn (image fits, so this is usually inert).
        self.canvas.configure(scrollregion=self.canvas.bbox("all") or (0, 0, cw, ch))

    def _draw_boxes(self) -> None:
        """Draw every stored box, converting IMAGE px -> canvas px. The selected
        keyword's box is highlighted RED (it will be replaced if you draw again);
        all others are GREEN."""
        active = self.selected.get()
        for kw, (x, y, w, h) in self.boxes.items():
            cx0, cy0 = image_to_canvas(x, y, self.scale, self.off_x, self.off_y)
            cx1, cy1 = image_to_canvas(x + w, y + h, self.scale, self.off_x, self.off_y)
            color = "#e11d48" if kw == active else "#16a34a"
            self.canvas.create_rectangle(cx0, cy0, cx1, cy1, outline=color, width=2)
            # Keyword label at the box's top-left corner.
            self.canvas.create_text(cx0 + 3, cy0 + 2, anchor=tk.NW, text=kw,
                                    fill=color, font=("Segoe UI", 9, "bold"))

    # ----- Events ---------------------------------------------------------
    def _on_configure(self, event) -> None:
        """Window/canvas resized: rescale image + boxes. Debounced so we don't
        re-render on every intermediate pixel of a drag-resize."""
        size = (event.width, event.height)
        if size != self._last_size:
            self._last_size = size
            self._render()

    def _on_keyword_change(self, _event=None) -> None:
        self.sel_label.configure(text=self._sel_label_text())
        self._render()  # re-highlight the newly selected keyword's box (if any)

    def _on_press(self, event) -> None:
        if not self.selected.get():
            messagebox.showwarning(APP_TITLE, "Select a keyword before drawing a box.")
            return
        # canvasx/canvasy fold in any scroll offset -> true canvas coordinates.
        self._drag_start = (self.canvas.canvasx(event.x), self.canvas.canvasy(event.y))
        self._temp_rect = self.canvas.create_rectangle(
            *self._drag_start, *self._drag_start, outline="#16a34a", width=2, dash=(3, 2))

    def _on_drag(self, event) -> None:
        if self._temp_rect is None or self._drag_start is None:
            return
        x0, y0 = self._drag_start
        x1, y1 = self.canvas.canvasx(event.x), self.canvas.canvasy(event.y)
        self.canvas.coords(self._temp_rect, x0, y0, x1, y1)  # live stretch

    def _on_release(self, event) -> None:
        if self._temp_rect is None or self._drag_start is None:
            return
        x0, y0 = self._drag_start
        x1, y1 = self.canvas.canvasx(event.x), self.canvas.canvasy(event.y)
        self._drag_start = None
        self.canvas.delete(self._temp_rect)
        self._temp_rect = None

        # Convert BOTH corners from canvas space back to original IMAGE pixels,
        # then normalize so storage is always (top-left x, y, positive w, h).
        ix0, iy0 = canvas_to_image(x0, y0, self.scale, self.off_x, self.off_y,
                                   self.img_w, self.img_h)
        ix1, iy1 = canvas_to_image(x1, y1, self.scale, self.off_x, self.off_y,
                                   self.img_w, self.img_h)
        x, y, w, h = normalize_box(ix0, iy0, ix1, iy1)
        if w < 2 or h < 2:  # a stray click, not a real box
            self.status.configure(text="Box too small - ignored.")
            return

        kw = self.selected.get()
        self.boxes[kw] = (x, y, w, h)  # one box per keyword: this replaces any old one
        self.status.configure(text=f"{kw}:  x={x}  y={y}  w={w}  h={h}")
        self._render()

    # ----- Buttons --------------------------------------------------------
    def _on_save(self) -> None:
        data = boxes_to_json(self.boxes)
        OUTPUT_JSON.write_text(json.dumps(data, indent=2), encoding="utf-8")
        messagebox.showinfo(APP_TITLE, f"Saved {len(data)} box(es) to:\n{OUTPUT_JSON}")
        self.status.configure(text=f"Saved {len(data)} box(es) -> {OUTPUT_JSON.name}")

    def _on_print(self) -> None:
        data = boxes_to_json(self.boxes)
        print(json.dumps(data, indent=2))  # to stdout / terminal
        messagebox.showinfo(APP_TITLE, "Printed to terminal.")
        self.status.configure(text=f"Printed {len(data)} box(es) to terminal.")

    def _on_reset(self) -> None:
        if messagebox.askyesno(APP_TITLE, "Clear ALL bounding boxes?"):
            self.boxes.clear()
            self.status.configure(text="All boxes cleared.")
            self._render()


def main() -> int:
    keywords = load_keywords()

    root = tk.Tk()
    root.title(APP_TITLE)

    # Fail gracefully if the template image is missing.
    if not TEMPLATE_PATH.exists():
        root.withdraw()
        messagebox.showerror(APP_TITLE, f"Template image not found:\n{TEMPLATE_PATH}")
        root.destroy()
        return 1

    AnnotatorApp(root, TEMPLATE_PATH, keywords)
    root.mainloop()
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
