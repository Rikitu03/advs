"""Business Permit Template Bounding Box Annotator.

A small Tkinter tool for drawing one bounding box per field on a *blank* business
permit template (e.g. the City of Digos Business Permit) and exporting the boxes
as pixel coordinates -- the same calibration job ``annotate_boxes.py`` does for
the BIR Form 2303, but for the business-permit layout.

The one real difference from ``annotate_boxes.py``: the field labels are NOT read
from ``ocr_dryrun.py``. They are typed by the USER AT RUNTIME -- you are prompted
to enter the labels before the window opens -- so the same tool annotates any
municipality's permit layout without code changes.

Run it from the repo root with a Python that has Pillow:

    python python/scripts/business_permit_annotator.py
    # or, with this repo's venv:
    python/env/Scripts/python.exe python/scripts/business_permit_annotator.py
    # optionally point it at a specific template image:
    python python/scripts/business_permit_annotator.py "path/to/some_permit.png"

Workflow:
    1. At the prompt, type the field labels to annotate, separated by commas:
         name_of_proprietor, trade_name, business_location, kind_of_business
       Press Enter -> the annotation window opens with those labels in the
       dropdown (labels are normalized to snake_case keys).
    2. Pick a label, then click-drag a rectangle over that field on the image.
    3. Repeat for each label (re-drawing a label replaces its box).
    4. Save (-> business_permit_boxes.json) or Print Result (-> terminal).

The tricky coordinate math (image<->canvas scale + centering, box normalization,
JSON export) is REUSED from ``annotate_boxes.py`` so the two annotators stay
pixel-for-pixel consistent and the geometry lives in exactly one place. The GUI
sub-classes ``annotate_boxes.AnnotatorApp`` and only re-skins the title and the
export destination.

Dependencies: Python standard library + Pillow only (Pillow is required by
``annotate_boxes`` and enforced there).
"""
from __future__ import annotations

import json
import re
import sys
import tkinter as tk
from pathlib import Path
from tkinter import messagebox

# Reuse the proven, unit-tested coordinate + box helpers AND the GUI from the BIR
# annotator (a sibling in python/scripts/). Make sure this script's own directory
# is importable first, so the sibling import works even when this module is loaded
# by path (e.g. from a test) rather than run as a script.
SCRIPT_DIR = Path(__file__).resolve().parent
if str(SCRIPT_DIR) not in sys.path:
    sys.path.insert(0, str(SCRIPT_DIR))

# Importing the module also enforces the shared Pillow requirement (its top-level
# guard exits with install instructions if Pillow is missing).
import annotate_boxes as ab  # noqa: E402
from annotate_boxes import (  # noqa: E402  -- re-export the SAME objects so both tools agree
    boxes_to_json,
    canvas_to_image,
    compute_fit,
    image_to_canvas,
    normalize_box,
)

APP_TITLE = "Business Permit Template Bounding Box Annotator"
PY_ROOT = Path(__file__).resolve().parents[1]            # .../python
TEMPLATE_DIR = PY_ROOT / "data" / "template" / "business_permits"
DEFAULT_TEMPLATE = TEMPLATE_DIR / "Business Permit (Digos).png"
OUTPUT_JSON = PY_ROOT / "json_data" / "business_permit_boxes.json"  # exported boxes

PROMPT_TEXT = (
    "Enter the field labels to annotate, separated by commas.\n"
    "  e.g. name_of_proprietor, trade_name, business_location, kind_of_business\n"
    "Labels> "
)


# ===========================================================================
# Pure logic (no Tk) - kept import-side-effect-free so it is unit-testable.
# ===========================================================================
def slugify_label(label: str) -> str:
    """Normalize one free-typed field label into a snake_case key, matching the
    existing keyword style (``tin``, ``registered_name``) so the exported keys
    drop straight into a ``FIELD_BOXES`` map. Lowercases, collapses any run of
    non-alphanumeric chars into a single underscore, and trims stray underscores.

    ``"NAME OF PROPRIETOR"`` -> ``"name_of_proprietor"``; ``"--/--"`` -> ``""``.
    """
    slug = re.sub(r"[^a-z0-9]+", "_", label.strip().lower())
    return slug.strip("_")


def parse_labels(raw: str) -> list[str]:
    """Split a raw prompt string into ordered, de-duplicated snake_case labels.

    Accepts commas, semicolons, or newlines as separators; slugifies each piece;
    drops blanks; and keeps first-seen order so the dropdown matches the typing
    order. Returns ``[]`` when nothing usable was entered.
    """
    labels: list[str] = []
    for piece in re.split(r"[,\n;]+", raw or ""):
        slug = slugify_label(piece)
        if slug and slug not in labels:
            labels.append(slug)
    return labels


def prompt_labels(input_func=input, output=print) -> list[str]:
    """Ask the user for the annotation labels at runtime (BEFORE any window
    opens), re-prompting until at least one valid label is given.

    ``input_func``/``output`` are injectable so the loop is unit-testable. Returns
    ``[]`` only if the user aborts (EOF / closed stdin).
    """
    while True:
        try:
            raw = input_func(PROMPT_TEXT)
        except EOFError:
            return []
        labels = parse_labels(raw)
        if labels:
            output(f"Annotating {len(labels)} label(s): {', '.join(labels)}")
            return labels
        output("No valid labels entered. Type at least one label (or press Ctrl+C to quit).")


def resolve_template(arg: str | None = None) -> Path | None:
    """Pick the template image to annotate.

    Priority: an explicit path argument -> the bundled Digos permit -> the first
    ``*.png`` in the ``business_permits`` folder. Returns ``None`` if nothing
    usable is found (the caller surfaces a friendly error).
    """
    if arg:
        candidate = Path(arg)
        return candidate if candidate.exists() else None
    if DEFAULT_TEMPLATE.exists():
        return DEFAULT_TEMPLATE
    if TEMPLATE_DIR.exists():
        pngs = sorted(TEMPLATE_DIR.glob("*.png"))
        if pngs:
            return pngs[0]
    return None


# ===========================================================================
# GUI - the BIR annotator's window, re-skinned for business permits.
# ===========================================================================
class BusinessPermitAnnotatorApp(ab.AnnotatorApp):
    """Same draw/replace-one-box-per-label behavior as ``AnnotatorApp``, but the
    labels come from the user at runtime and the export goes to a
    business-permit-specific JSON file under a business-permit title."""

    def __init__(self, root: tk.Tk, image_path: Path, labels: list,
                 *, app_title: str = APP_TITLE, output_json: Path = OUTPUT_JSON):
        self.app_title = app_title
        self.output_json = Path(output_json)
        super().__init__(root, image_path, labels)
        # The parent built the UI with the BIR title + a portrait window; correct
        # both for this tool (permits are landscape).
        self.root.title(self.app_title)
        self.root.geometry("1180x940")

    def _on_save(self) -> None:
        data = boxes_to_json(self.boxes)
        self.output_json.parent.mkdir(parents=True, exist_ok=True)
        self.output_json.write_text(json.dumps(data, indent=2), encoding="utf-8")
        messagebox.showinfo(self.app_title, f"Saved {len(data)} box(es) to:\n{self.output_json}")
        self.status.configure(text=f"Saved {len(data)} box(es) -> {self.output_json.name}")

    def _on_print(self) -> None:
        data = boxes_to_json(self.boxes)
        print(json.dumps(data, indent=2))  # to stdout / terminal
        messagebox.showinfo(self.app_title, "Printed to terminal.")
        self.status.configure(text=f"Printed {len(data)} box(es) to terminal.")

    def _on_reset(self) -> None:
        if messagebox.askyesno(self.app_title, "Clear ALL bounding boxes?"):
            self.boxes.clear()
            self.status.configure(text="All boxes cleared.")
            self._render()


def main(argv: list[str] | None = None) -> int:
    argv = sys.argv[1:] if argv is None else argv
    template = resolve_template(argv[0] if argv else None)

    # Prompt for labels FIRST (before opening any window), per the tool's spec.
    labels = prompt_labels()
    if not labels:
        print("No labels entered; nothing to annotate.", file=sys.stderr)
        return 1

    root = tk.Tk()
    root.title(APP_TITLE)

    # Fail gracefully if the template image is missing.
    if template is None or not template.exists():
        root.withdraw()
        messagebox.showerror(
            APP_TITLE,
            "Business permit template not found.\n"
            f"Pass a path as the first argument, or add a PNG under:\n{TEMPLATE_DIR}",
        )
        root.destroy()
        return 1

    BusinessPermitAnnotatorApp(root, template, labels)
    root.mainloop()
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
