#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""
Baut ein Release-ZIP, nachdem symcon_lint ohne Fehler durchgelaufen ist.

    python3 .dev/build_zip.py [--base origin/main]

Enthalten: library.json, Modulordner, libs/, actions/, imgs/, docs/, README*.
Nie enthalten: tests/, .dev/, Punkt-Ordner, dist/, vendor/, runtime/ (F3).
Ergebnis: dist/<Bibliothek>-<version>.<build>.zip mit einem Wurzelordner.
"""
import argparse
import json
import re
import sys
import zipfile
from pathlib import Path

ROOT = Path(__file__).resolve().parent.parent
sys.path.insert(0, str(Path(__file__).resolve().parent))
import symcon_lint  # noqa: E402

EXCLUDE_DIRS = {"tests", "dist", "vendor", "node_modules", "__pycache__", "runtime"}
EXCLUDE_FILES = {"CLAUDE.md", "composer.json", "composer.lock", "phpunit.xml", ".gitignore"}


def main() -> int:
    ap = argparse.ArgumentParser()
    ap.add_argument("--base", help="git-Referenz fuer die Release-Pruefung")
    args = ap.parse_args()

    findings = symcon_lint.lint(ROOT, args.base)
    errors = [f for f in findings if f.level == symcon_lint.ERROR]
    for f in findings:
        print(f)
    if errors:
        print(f"Abbruch: {len(errors)} Lint-Fehler.", file=sys.stderr)
        return 1

    lib = json.loads((ROOT / "library.json").read_text(encoding="utf-8-sig"))
    safe = re.sub(r"[^A-Za-z0-9]+", "-", lib["name"]).strip("-")
    out_dir = ROOT / "dist"
    out_dir.mkdir(exist_ok=True)
    target = out_dir / f"{safe}-{lib['version']}.{lib['build']}.zip"

    count = 0
    with zipfile.ZipFile(target, "w", zipfile.ZIP_DEFLATED) as zf:
        for path in sorted(ROOT.rglob("*")):
            rel = path.relative_to(ROOT)
            parts = rel.parts
            if path.is_dir() or any(p.startswith(".") or p in EXCLUDE_DIRS for p in parts):
                continue
            if len(parts) == 1 and parts[0] in EXCLUDE_FILES:
                continue
            if path.suffix == ".pyc":
                continue
            zf.write(path, Path(safe) / rel)
            count += 1
    print(f"{target.relative_to(ROOT)}: {count} Dateien")
    return 0


if __name__ == "__main__":
    sys.exit(main())
