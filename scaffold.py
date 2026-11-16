#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""
Geruest-Werkzeug: neue Module und Bibliothek aus der Vorlage MeinModul erzeugen.

    python3 .dev/scaffold.py library "Meine Bibliothek" "Autorname"
    python3 .dev/scaffold.py module  MeinZweitesModul MZM

- 'library' setzt eine neue Bibliotheks-GUID, Namen und Autor in library.json.
- 'module' kopiert .dev/template/MeinModul nach <Name>, ersetzt Klassenname und Praefix und vergibt eine neue GUID.
Der Modulname ist zugleich Klassen- und Ordnername (nur Buchstaben/Ziffern, beginnt mit Buchstabe).
"""
import json
import re
import shutil
import sys
import uuid
from pathlib import Path

ROOT = Path(__file__).resolve().parent.parent
TEMPLATE, T_PREFIX = "MeinModul", "MMOD"


def new_guid() -> str:
    return "{" + str(uuid.uuid4()).upper() + "}"


def die(msg: str) -> None:
    print("Fehler: " + msg, file=sys.stderr)
    sys.exit(1)


def cmd_library(name: str, author: str) -> None:
    if not re.fullmatch(r"[A-Za-z0-9]([A-Za-z0-9 _]*[A-Za-z0-9])?", name):
        die("Bibliotheksname: nur A-Z a-z 0-9, Leerzeichen, Unterstrich; nicht am Anfang/Ende")
    path = ROOT / "library.json"
    data = json.loads(path.read_text(encoding="utf-8"))
    data.update(id=new_guid(), name=name, author=author, build=1)
    path.write_text(json.dumps(data, indent=4, ensure_ascii=False) + "\n", encoding="utf-8")
    print(f"library.json aktualisiert: {name} ({data['id']})")


def cmd_module(name: str, prefix: str) -> None:
    if not re.fullmatch(r"[A-Za-z][A-Za-z0-9]*", name):
        die("Modulname = Klassenname: nur Buchstaben und Ziffern, beginnt mit Buchstabe")
    if not re.fullmatch(r"[A-Z]{3,5}", prefix):
        die("Praefix: 3-5 Grossbuchstaben")
    target = ROOT / name
    if target.exists():
        die(f"'{name}' existiert bereits")
    for other in ROOT.glob("*/module.json"):
        if json.loads(other.read_text(encoding="utf-8-sig")).get("prefix") == prefix:
            die(f"Praefix '{prefix}' wird bereits von {other.parent.name} verwendet")
    shutil.copytree(ROOT / ".dev" / "template" / TEMPLATE, target)
    for fname in ("module.php", "form.json", "module.json"):
        p = target / fname
        text = p.read_text(encoding="utf-8")
        text = text.replace(TEMPLATE, name).replace(T_PREFIX, prefix)
        p.write_text(text, encoding="utf-8")
    mj = target / "module.json"
    data = json.loads(mj.read_text(encoding="utf-8"))
    data["id"] = new_guid()
    mj.write_text(json.dumps(data, indent=4, ensure_ascii=False) + "\n", encoding="utf-8")
    print(f"Modul '{name}' (Praefix {prefix}) angelegt. Danach: library.json build erhoehen, "
          "python3 .dev/symcon_lint.py ausfuehren.")


def main() -> None:
    a = sys.argv[1:]
    if len(a) == 3 and a[0] == "library":
        cmd_library(a[1], a[2])
    elif len(a) == 3 and a[0] == "module":
        cmd_module(a[1], a[2])
    else:
        print(__doc__)
        sys.exit(2)


if __name__ == "__main__":
    main()
