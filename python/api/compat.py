"""Import the existing pipeline modules in place — reuse, never duplicate.

``python/scripts`` is not a package (repo convention loads its modules by path
via ``importlib.util``, exactly as ``python/tests`` do); ``python/forensics``
IS a package and only needs the python root on ``sys.path``.
"""

from __future__ import annotations

import importlib.util
import sys
from pathlib import Path

PY_ROOT = Path(__file__).resolve().parents[1]

if str(PY_ROOT) not in sys.path:
    sys.path.insert(0, str(PY_ROOT))


def load_script(name: str):
    """Load ``python/scripts/<name>.py`` by path, once per process."""
    key = f"advs_api_script_{name}"
    if key in sys.modules:
        return sys.modules[key]

    path = PY_ROOT / "scripts" / f"{name}.py"
    spec = importlib.util.spec_from_file_location(key, path)
    if spec is None or spec.loader is None:
        raise ImportError(f"Cannot load pipeline script: {path}")
    module = importlib.util.module_from_spec(spec)
    sys.modules[key] = module
    spec.loader.exec_module(module)
    return module
