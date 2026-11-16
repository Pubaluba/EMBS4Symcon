#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""
symcon_lint - statische Pruefungen fuer Symcon-PHP-Bibliotheken.

Nur Python-Standardbibliothek; braucht weder PHP noch Symcon.
Die Regeln und ihre Herkunft stehen in docs/RULES.md (gleiche IDs).

Aufruf:
    python3 .dev/symcon_lint.py                 # Fehler => Exit 1
    python3 .dev/symcon_lint.py --strict        # auch Warnungen => Exit 1
    python3 .dev/symcon_lint.py --base origin/main   # zusaetzlich Release-Pruefung (build, GUIDs)
    python3 .dev/symcon_lint.py --hook          # fuer Claude-Code-Hooks (stderr, Exit 2 bei Fehlern)

Unterdruecken (nur mit Begruendung, in der Zeile oder der Zeile davor):
    // lint-ok: P007 Argumente wurden oben validiert und escaped

Das sind Heuristiken (Regex), keine PHP-Analyse: Sie fangen bekannte Fehlerbilder ab
und liefern ab und zu Fehlalarme. Ein sauberer Lauf beweist keine Korrektheit.
"""
from __future__ import annotations

import argparse
import json
import re
import subprocess
import sys
from dataclasses import dataclass
from pathlib import Path

ERROR, WARN = "ERROR", "WARN"

GUID_RE = re.compile(r"^\{[0-9A-F]{8}-[0-9A-F]{4}-[0-9A-F]{4}-[0-9A-F]{4}-[0-9A-F]{12}\}$")
NAME_RE = re.compile(r"^[A-Za-z0-9]([A-Za-z0-9 _]*[A-Za-z0-9])?$")
SUPPRESS_RE = re.compile(r"lint-ok:\s*([A-Z]\d{3})\s+\S")

# Ordner im Wurzelverzeichnis, die ohne module.json erlaubt sind (Doku: Structure).
ALLOWED_ROOT_DIRS = {"libs", "docs", "imgs", "tests", "actions"}
# Praefixe bestehender Module, die schon von Skripten genutzt werden: Umbenennen braeche diese Skripte,
# deshalb gilt die Hausregel (3-5 Grossbuchstaben) fuer sie nicht. Nur fuer Bestand, nie fuer neue Module.
LEGACY_PREFIXES = {"Jahresvariablen", "EMBSVARFloat"}

# Lokale Ordner, die nicht ins Repository gehoeren (.gitignore) und hier ignoriert werden.
LOCAL_IGNORED = {"vendor", "dist", "node_modules", "__pycache__"}

# Formularelemente laut Doku "Configuration Forms" (Stand der Pruefung).
KNOWN_TYPES = {
    "Button", "CheckBox", "ColumnLayout", "Configurator", "ExpansionPanel", "HorizontalSlider",
    "Image", "IntervalBox", "Label", "List", "NumberSpinner", "OpenObjectButton", "PasswordTextBox",
    "PopupAlert", "PopupButton", "ProgressBar", "QrCode", "RadioButtonGroup", "RowLayout",
    "ScriptEditor", "Select", "SelectAction", "SelectCategory", "SelectColor", "SelectCondition",
    "SelectDate", "SelectDateTime", "SelectEvent", "SelectFile", "SelectIcon", "SelectInstance",
    "SelectLink", "SelectLocation", "SelectMedia", "SelectModule", "SelectObject", "SelectProfile",
    "SelectScript", "SelectTime", "SelectValue", "SelectVariable", "TestCenter", "Tree",
    "ValidationTextBox",
}
# Elemente, die einen Wert tragen und deshalb ein "name" brauchen.
INPUT_TYPES = {
    "CheckBox", "Configurator", "HorizontalSlider", "IntervalBox", "List", "NumberSpinner",
    "PasswordTextBox", "RadioButtonGroup", "ScriptEditor", "Select", "SelectAction",
    "SelectCategory", "SelectColor", "SelectCondition", "SelectDate", "SelectDateTime",
    "SelectEvent", "SelectFile", "SelectIcon", "SelectInstance", "SelectLink", "SelectLocation",
    "SelectMedia", "SelectModule", "SelectObject", "SelectProfile", "SelectScript", "SelectTime",
    "SelectValue", "SelectVariable", "Tree", "ValidationTextBox",
}

WRITE_FUNCS = (
    "file_put_contents|fopen|mkdir|rename|unlink|copy|touch|rmdir|tempnam|fwrite|chmod|symlink|"
    "move_uploaded_file"
)
EXEC_FUNCS = "exec|shell_exec|system|passthru|proc_open|popen"


@dataclass
class Finding:
    path: str
    line: int
    level: str
    rule: str
    msg: str

    def __str__(self) -> str:
        return f"{self.path}:{self.line}: [{self.level}] {self.rule} {self.msg}"


# ---------------------------------------------------------------- PHP-Hilfen
def _scan(src: str, blank_strings: bool) -> str:
    """Entfernt PHP-Kommentare (Zeilenzahl bleibt erhalten); optional Stringinhalte leeren."""
    out: list[str] = []
    i, n, state = 0, len(src), None
    while i < n:
        c = src[i]
        nxt = src[i + 1] if i + 1 < n else ""
        if state is None:
            if (c == "/" and nxt == "/") or (c == "#" and nxt != "["):
                while i < n and src[i] != "\n":
                    i += 1
                continue
            if c == "/" and nxt == "*":
                j = src.find("*/", i + 2)
                j = n if j == -1 else j + 2
                out.append(re.sub(r"[^\n]", "", src[i:j]))
                i = j
                continue
            if c == "'":
                state = "s"
            elif c == '"':
                state = "d"
            out.append(c)
            i += 1
            continue
        # innerhalb eines Strings
        if c == "\\" and i + 1 < n:
            out.append("  " if blank_strings and src[i + 1] != "\n" else src[i:i + 2])
            i += 2
            continue
        if (state == "s" and c == "'") or (state == "d" and c == '"'):
            state = None
            out.append(c)
        elif blank_strings and c != "\n":
            out.append(" ")
        else:
            out.append(c)
        i += 1
    return "".join(out)


def strip_comments(src: str) -> str:
    return _scan(src, False)


def skeleton(src: str) -> str:
    return _scan(src, True)


def match_paren(text: str, open_pos: int) -> int:
    """Index der zur '(' an open_pos passenden ')' (oder -1)."""
    depth = 0
    for i in range(open_pos, len(text)):
        if text[i] == "(":
            depth += 1
        elif text[i] == ")":
            depth -= 1
            if depth == 0:
                return i
    return -1


def split_top_level(text: str) -> list[str]:
    parts, depth, cur = [], 0, []
    for ch in text:
        if ch in "([{":
            depth += 1
        elif ch in ")]}":
            depth -= 1
        if ch == "," and depth == 0:
            parts.append("".join(cur))
            cur = []
        else:
            cur.append(ch)
    parts.append("".join(cur))
    return [p for p in parts if p.strip()]


def line_of(text: str, pos: int) -> int:
    return text.count("\n", 0, pos) + 1


def version_tuple(v: str) -> tuple[int, ...]:
    try:
        return tuple(int(x) for x in str(v).split("."))
    except ValueError:
        return (0,)


# ---------------------------------------------------------------- Linter
class Linter:
    def __init__(self, root: Path):
        self.root = root.resolve()
        self.findings: list[Finding] = []
        self.raw: dict[Path, list[str]] = {}
        self.guids: dict[str, str] = {}
        self.prefixes: dict[str, str] = {}
        self.library: dict = {}
        self.module_dirs: list[Path] = []
        self.module_info: dict[str, dict] = {}  # Ordnername -> {prefix, public, classname...}

    # -- Ausgabe
    def rel(self, path: Path) -> str:
        try:
            return str(path.resolve().relative_to(self.root))
        except ValueError:
            return str(path)

    def add(self, path: Path, line: int, level: str, rule: str, msg: str) -> None:
        lines = self.raw.get(path)
        if lines:
            for ln in (line, line - 1):
                if 1 <= ln <= len(lines):
                    for m in SUPPRESS_RE.finditer(lines[ln - 1]):
                        if m.group(1) == rule:
                            return
        self.findings.append(Finding(self.rel(path), max(line, 1), level, rule, msg))

    def read(self, path: Path) -> str:
        text = path.read_text(encoding="utf-8-sig", errors="replace")
        self.raw[path] = text.splitlines()
        return text

    def load_json(self, path: Path, required: bool = True):
        if not path.exists():
            if required:
                self.add(path, 1, ERROR, "L000", "Datei fehlt")
            return None
        text = self.read(path)
        try:
            return json.loads(text)
        except json.JSONDecodeError as exc:
            self.add(path, exc.lineno, ERROR, "L000", f"Ungueltiges JSON: {exc.msg}")
            return None

    def guid(self, path: Path, value, what: str, line: int = 1) -> None:
        if not isinstance(value, str) or not GUID_RE.match(value):
            self.add(path, line, ERROR, "L002",
                     f"{what}: GUID muss Format {{XXXXXXXX-XXXX-XXXX-XXXX-XXXXXXXXXXXX}} in Grossbuchstaben haben")
            return
        if value in self.guids and self.guids[value] != f"{self.rel(path)}:{what}":
            self.add(path, line, ERROR, "L003", f"{what}: GUID {value} doppelt vergeben ({self.guids[value]})")
        self.guids[value] = f"{self.rel(path)}:{what}"

    # -- Gesamtlauf
    def run(self) -> list[Finding]:
        self.check_library()
        self.check_layout()
        for mod in self.module_dirs:
            self.check_module(mod)
        return self.findings

    # -- library.json
    def check_library(self) -> None:
        path = self.root / "library.json"
        data = self.load_json(path)
        if not isinstance(data, dict):
            return
        self.library = data
        for key in ("id", "author", "name", "version", "build"):
            if key not in data:
                self.add(path, 1, ERROR, "L001", f"Pflichtfeld '{key}' fehlt")
        if "id" in data:
            self.guid(path, data["id"], "library.id")
        if "name" in data and not (isinstance(data["name"], str) and NAME_RE.match(data["name"])):
            self.add(path, 1, ERROR, "L001", "name: nur A-Z a-z 0-9, Leerzeichen, Unterstrich; nicht am Anfang/Ende")
        if "version" in data and not isinstance(data["version"], str):
            self.add(path, 1, ERROR, "L001", "version muss ein String sein, z. B. \"1.0\"")
        if "build" in data and (not isinstance(data["build"], int) or isinstance(data["build"], bool)):
            self.add(path, 1, ERROR, "L001", "build muss eine ganze Zahl sein")
        comp = data.get("compatibility")
        if comp is not None and not (isinstance(comp, dict) and isinstance(comp.get("version", ""), str)):
            self.add(path, 1, ERROR, "L001", "compatibility.version muss ein String sein")

    # -- Ordnerstruktur
    def check_layout(self) -> None:
        for child in sorted(self.root.iterdir()):
            if not child.is_dir() or child.name.startswith(".") or child.name in LOCAL_IGNORED:
                continue
            if child.name in ALLOWED_ROOT_DIRS:
                continue
            if not (child / "module.json").exists():
                self.add(child, 1, ERROR, "L008",
                         f"Ordner '{child.name}' hat keine module.json: Symcon markiert ihn als fehlerhaft. "
                         "Werkzeuge gehoeren nach .dev/, Tests nach tests/")
                continue
            self.module_dirs.append(child)

    # -- Modul
    def check_module(self, mod: Path) -> None:
        mj_path = mod / "module.json"
        mj = self.load_json(mj_path)
        info = {"prefix": None, "public": set(), "classname": None}
        self.module_info[mod.name] = info
        name = None
        if isinstance(mj, dict):
            for key in ("id", "name", "type", "prefix"):
                if key not in mj:
                    self.add(mj_path, 1, ERROR, "L004", f"Pflichtfeld '{key}' fehlt")
            if "id" in mj:
                self.guid(mj_path, mj["id"], f"{mod.name}.id")
            name = mj.get("name")
            if "name" in mj and not (isinstance(name, str) and NAME_RE.match(name)):
                self.add(mj_path, 1, ERROR, "L004", "name: nur A-Z a-z 0-9, Leerzeichen, Unterstrich; nicht am Anfang/Ende")
            t = mj.get("type")
            if "type" in mj and (not isinstance(t, int) or isinstance(t, bool) or not 0 <= t <= 6):
                self.add(mj_path, 1, ERROR, "L004", "type muss eine Zahl 0..6 sein")
            prefix = mj.get("prefix")
            if "prefix" in mj:
                if not (isinstance(prefix, str) and re.fullmatch(r"[A-Za-z0-9]+", prefix)):
                    self.add(mj_path, 1, ERROR, "L005", "prefix darf nur Buchstaben und Ziffern enthalten")
                else:
                    info["prefix"] = prefix
                    if not re.fullmatch(r"[A-Z]{3,5}", prefix) and prefix not in LEGACY_PREFIXES:
                        self.add(mj_path, 1, WARN, "L005", "Hausregel: prefix besteht aus 3-5 Grossbuchstaben")
                    if prefix in self.prefixes:
                        self.add(mj_path, 1, ERROR, "L005", f"prefix '{prefix}' doppelt ({self.prefixes[prefix]})")
                    self.prefixes[prefix] = mod.name
            for arr in ("parentRequirements", "childRequirements", "implemented"):
                for g in mj.get(arr, []) or []:
                    if not (isinstance(g, str) and GUID_RE.match(g)):
                        self.add(mj_path, 1, ERROR, "L002", f"{arr}: ungueltige GUID {g!r}")
        if name and mod.name != str(name).replace(" ", ""):
            self.add(mod, 1, WARN, "L010", f"Ordnername '{mod.name}' sollte dem Klassennamen '{str(name).replace(' ', '')}' entsprechen")
        if (mod / "runtime").exists():
            self.add(mod, 1, ERROR, "L011", "Unterordner 'runtime' im Modulordner: Laufzeitdaten gehoeren nach IPS_GetKernelDir() (F3)")

        php_path = mod / "module.php"
        if not php_path.exists():
            self.add(php_path, 1, ERROR, "L008", "module.php fehlt")
            return
        form = self.load_json(mod / "form.json", required=False)
        self.check_php_module(mod, php_path, str(name) if name else None, info, form)
        if isinstance(form, dict):
            self.check_form(mod, mod / "form.json", form, info)
        for extra in sorted(list(mod.glob("*.php")) + list((self.root / "libs").glob("*.php"))):
            if extra != php_path:
                self.check_php_generic(extra)

    # -- PHP: gemeinsame Regeln fuer jede PHP-Datei
    def check_php_generic(self, path: Path, text: str | None = None) -> None:
        if text is None:
            text = self.read(path)
        code = strip_comments(text)
        skel = skeleton(text)

        for m in re.finditer(r"declare\s*\(\s*strict_types", code):
            self.add(path, line_of(code, m.start()), ERROR, "P001",
                     "declare(strict_types=1) ist in Modulen nicht erlaubt (Formularwerte kommen teils als anderer Typ an)")

        # P005: Schreiben in den Modulordner (direkt oder ueber eine Variable, die auf __DIR__ beruht)
        tainted = {m.group(1) for m in re.finditer(r"\$(\w+)\s*=[^;]*(?:__DIR__|__FILE__)", skel + "", re.S)}
        for m in re.finditer(r"(?<![\w>:$])(" + WRITE_FUNCS + r")\s*\(", skel):
            end = match_paren(skel, m.end() - 1)
            args_skel = skel[m.end():end if end != -1 else None]
            args_code = code[m.end():end if end != -1 else None]
            func = m.group(1)
            if func == "fopen" and not re.search(r"""['"][^'"]*[wacx]""", args_code.split(",", 1)[-1] if "," in args_code else ""):
                continue
            hit = bool(re.search(r"__DIR__|__FILE__", args_skel)) or any(
                re.search(r"\$" + re.escape(v) + r"\b", args_skel) for v in tainted)
            if hit:
                self.add(path, line_of(skel, m.start()), ERROR, "P005",
                         f"{func}() schreibt in den Modulordner: Laufzeitdaten ausserhalb ablegen (IPS_GetKernelDir) (F3)")

        # P006: Speicher / Downloads
        for m in re.finditer(r"file_get_contents\s*\(\s*['\"]https?:", code):
            self.add(path, line_of(code, m.start()), ERROR, "P006",
                     "file_get_contents auf URL laedt alles in einen String (32-MB-Limit): in Datei streamen (F2)")
        for m in re.finditer(r"file_get_contents\s*\(\s*\$(?:url|uri|link|remote)\w*", code, re.I):
            self.add(path, line_of(code, m.start()), WARN, "P006",
                     "file_get_contents auf URL-Variable: bei grossen Daten in Datei streamen (F2)")
        for m in re.finditer(r"CURLOPT_RETURNTRANSFER", code):
            self.add(path, line_of(code, m.start()), WARN, "P006",
                     "CURLOPT_RETURNTRANSFER haelt die Antwort im Speicher: ab ca. 1 MB CURLOPT_FILE verwenden (F2)")
        for m in re.finditer(r"(?<![\w>:$])(gzdecode|gzuncompress|gzinflate)\s*\(", skel):
            self.add(path, line_of(skel, m.start()), WARN, "P006",
                     f"{m.group(1)}() arbeitet im Speicher und liest bei mehrteiligem gzip nur das erste Segment: gzopen/gzread (F2, F5)")

        # P007 / P008: Shell
        exec_seen = None
        skel_lines = skel.splitlines()
        for m in re.finditer(r"(?<![\w>:$])(" + EXEC_FUNCS + r")\s*\(", skel):
            if re.search(r"function\s+$", skel[max(0, m.start() - 20):m.start()]):
                continue
            ln = line_of(skel, m.start())
            exec_seen = exec_seen or (path, ln)
            context = "\n".join(skel_lines[max(0, ln - 7):ln])
            if "escapeshellarg" not in context and "escapeshellcmd" not in context:
                self.add(path, ln, ERROR, "P007",
                         f"{m.group(1)}() ohne escapeshellarg in den 6 Zeilen davor (Eingaben validieren UND escapen)")
            if m.group(1) == "exec":
                end = match_paren(skel, m.end() - 1)
                if end != -1 and len(split_top_level(skel[m.end():end])) < 3:
                    self.add(path, ln, WARN, "P008", "exec() ohne $result_code: Rueckgabecode pruefen")
        for m in re.finditer(r"(?:->|::)runCommand\s*\(", skel):
            exec_seen = exec_seen or (path, line_of(skel, m.start()))
        if exec_seen and not re.search(r"PHP_OS_FAMILY|PHP_OS\b|(?:->|::)platform\s*\(", skel):
            self.add(path, exec_seen[1], WARN, "P012",
                     "Programmaufrufe ohne Plattformweiche (PHP_OS_FAMILY / platform()) in dieser Datei: "
                     "Windows-Weg oder klare Meldung vorsehen (F6)")

        for m in re.finditer(r"(?<![\w>:$])symlink\s*\(", skel):
            self.add(path, line_of(skel, m.start()), WARN, "P009",
                     "symlink(): Dateisystem muss Symlinks nicht unterstuetzen, Archiv-Links als Kopien aufloesen (F5)")
        for m in re.finditer(r"(?<![\w>:$])u?sleep\s*\(", skel):
            self.add(path, line_of(skel, m.start()), WARN, "P010",
                     "sleep() blockiert einen der begrenzten PHP-Threads (Standard 50): Timer statt Warteschleife")
        for m in re.finditer(r"['\"]/var/lib/symcon", code):
            self.add(path, line_of(code, m.start()), WARN, "P011",
                     "Fester Pfad /var/lib/symcon: IPS_GetKernelDir() verwenden (Windows/Docker weichen ab)")

    # -- PHP: module.php
    def check_php_module(self, mod: Path, path: Path, name: str | None, info: dict, form) -> None:
        text = self.read(path)
        code = strip_comments(text)
        skel = skeleton(text)
        self.check_php_generic(path, text)

        cls = re.search(r"\bclass\s+(\w+)\s+extends\s+(\w+)", code)
        if not cls:
            self.add(path, 1, ERROR, "P002", "Keine Klasse 'class X extends IPSModuleStrict' gefunden")
        else:
            info["classname"] = cls.group(1)
            expected = name.replace(" ", "") if name else mod.name
            if cls.group(1) != expected:
                self.add(path, line_of(code, cls.start()), ERROR, "P002",
                         f"Klassenname '{cls.group(1)}' muss dem Modulnamen '{expected}' entsprechen (Leerzeichen entfernt)")
            if cls.group(2) == "IPSModule":
                self.add(path, line_of(code, cls.start()), WARN, "P019",
                         "IPSModule: fuer neue Module IPSModuleStrict verwenden (ab Symcon 8.1)")
            if cls.group(2) == "IPSModuleStrict":
                comp = (self.library.get("compatibility") or {}).get("version", "")
                if not comp or version_tuple(comp) < (8, 1):
                    self.add(self.root / "library.json", 1, ERROR, "L009",
                             "IPSModuleStrict braucht compatibility.version >= \"8.1\"")

        # P003 / P004: oeffentliche Funktionen
        for m in re.finditer(r"\bpublic\s+(?:static\s+)?function\s+(&\s*)?(\w+)\s*\(", skel):
            fname = m.group(2)
            ln = line_of(skel, m.start())
            end = match_paren(skel, m.end() - 1)
            if end == -1:
                continue
            info["public"].add(fname)
            params = skel[m.end():end]
            after = skel[end + 1:end + 80]
            if not re.match(r"\s*:\s*\??[\w\\|]+", after):
                self.add(path, ln, ERROR, "P003", f"{fname}(): Rueckgabetyp fehlt (IPSModuleStrict verlangt Type Hints)")
            for p in split_top_level(params):
                head = p.split("$")[0].strip().rstrip("&.").strip()
                if not head:
                    self.add(path, ln, ERROR, "P003", f"{fname}(): Parameter ohne Typ: {p.strip()}")
                if re.search(r"\$InstanceID\b", p):
                    self.add(path, ln, ERROR, "P004", f"{fname}(): '$InstanceID' darf kein Parametername sein")
            if not fname.startswith("__") and not re.fullmatch(r"[A-Za-z0-9]+", fname):
                self.add(path, ln, ERROR, "P004", f"{fname}(): Funktionsnamen duerfen nur a-z, A-Z, 0-9 enthalten")

        # P013
        if "SendDebug" not in code:
            self.add(path, 1, WARN, "P013", "Kein SendDebug: technische Details gehoeren ins Debug-Fenster der Instanz")

        # P020 / P021 / P022: Properties und Attribute
        reg_props = set(re.findall(r"RegisterProperty(?:String|Integer|Boolean|Float)\(\s*'(\w+)'", code))
        for m in re.finditer(r"ReadProperty(?:String|Integer|Boolean|Float)\(\s*'(\w+)'", code):
            if m.group(1) not in reg_props:
                self.add(path, line_of(code, m.start()), ERROR, "P020", f"ReadProperty('{m.group(1)}') ohne RegisterProperty")
        reg_attr = set(re.findall(r"RegisterAttribute(?:String|Integer|Boolean|Float)\(\s*'(\w+)'", code))
        for m in re.finditer(r"(?:Read|Write)Attribute(?:String|Integer|Boolean|Float)\(\s*'(\w+)'", code):
            if m.group(1) not in reg_attr:
                self.add(path, line_of(code, m.start()), ERROR, "P022", f"Attribut '{m.group(1)}' ohne RegisterAttribute")
        info["props"] = reg_props
        info["form_updates"] = [(m.group(1), line_of(code, m.start()))
                                for m in re.finditer(r"UpdateFormField\(\s*'(\w+)'", code)]

        # P023: Timer-Skript muss zu Praefix und oeffentlicher Funktion passen
        for m in re.finditer(r"RegisterTimer\([^;]*?,\s*'(\w+?)_(\w+)\(", code):
            pre, func = m.group(1), m.group(2)
            ln = line_of(code, m.start())
            if info["prefix"] and pre != info["prefix"]:
                self.add(path, ln, ERROR, "P023", f"Timer-Skript nutzt Praefix '{pre}', module.json hat '{info['prefix']}'")
            elif func not in info["public"]:
                self.add(path, ln, ERROR, "P023", f"Timer-Skript ruft {pre}_{func}(), aber '{func}' ist keine oeffentliche Funktion")

        if isinstance(form, dict):
            names = set()
            for area in ("elements", "actions"):
                for el, _ in iter_form(form.get(area)):
                    if isinstance(el.get("name"), str):
                        names.add(el["name"])
            elem_names = {el["name"] for el, _ in iter_form(form.get("elements"))
                          if el.get("type") in INPUT_TYPES and isinstance(el.get("name"), str)}
            for prop in sorted(reg_props - elem_names):
                self.add(path, 1, WARN, "P021", f"Property '{prop}' ist nicht im Formular (elements); Absicht?")
            for fld, ln in info["form_updates"]:
                if fld not in names:
                    self.add(path, ln, WARN, "F009", f"UpdateFormField('{fld}'): kein Formularelement mit diesem name in form.json")

    # -- Formular
    def check_form(self, mod: Path, path: Path, form: dict, info: dict) -> None:
        text = "\n".join(self.raw.get(path, []))

        def where(needle: str) -> int:
            idx = text.find(needle)
            return line_of(text, idx) if idx >= 0 else 1

        for key in form:
            if key not in ("$schema", "elements", "actions", "status"):
                self.add(path, where(f'"{key}"'), ERROR, "F001", f"Unbekannter Schluessel '{key}' (erlaubt: elements, actions, status)")
        for area in ("elements", "actions", "status"):
            if area in form and not isinstance(form[area], list):
                self.add(path, where(f'"{area}"'), ERROR, "F001", f"'{area}' muss eine Liste sein")

        seen: dict[str, str] = {}
        field_area: dict[str, str] = {}
        for area in ("elements", "actions"):
            for el, _ in iter_form(form.get(area)):
                t = el.get("type")
                if t not in KNOWN_TYPES:
                    hint = " (heisst es 'NumberSpinner'?)" if t == "NumberSpinBox" else ""
                    self.add(path, where(f'"{t}"'), ERROR, "F003", f"Unbekannter Elementtyp {t!r}{hint}")
                nm = el.get("name")
                if t in INPUT_TYPES and not isinstance(nm, str):
                    self.add(path, where(f'"{t}"'), ERROR, "F002", f"{t} ohne 'name'")
                if isinstance(nm, str):
                    if nm in seen:
                        self.add(path, where(f'"{nm}"'), ERROR, "F004", f"name '{nm}' kommt mehrfach vor")
                    seen[nm] = area
                    field_area[nm] = area
        for el, _ in iter_form(form.get("elements")):
            nm = el.get("name")
            if el.get("type") in INPUT_TYPES and isinstance(nm, str) and "props" in info and nm not in info["props"]:
                self.add(path, where(f'"{nm}"'), WARN, "F010", f"Element '{nm}' ohne RegisterProperty in module.php")

        for area in ("elements", "actions"):
            for el, _ in iter_form(form.get(area)):
                click = el.get("onClick")
                if not isinstance(click, str):
                    continue
                ln = where(click[:30])
                for v in sorted(set(re.findall(r"\$([A-Za-z_]\w*)", click)) - {"id", "_IPS", "this"}):
                    if v not in field_area:
                        self.add(path, ln, ERROR, "F005", f"onClick nutzt ${v}, aber kein Formularelement mit name '{v}' (F1)")
                        continue
                    if not re.search(r"\$" + v + r"\s*\?\?", click):
                        self.add(path, ln, WARN, "F006", f"onClick: ${v} ohne '?? Standardwert' (kann NULL sein) (F1)")
                    if field_area[v] == "elements":
                        self.add(path, ln, WARN, "F007",
                                 f"Hinweis: ${v} liegt in 'elements'. Die Doku zeigt Eingaben fuer Buttons in 'actions'; "
                                 "Werte aus elements lieber per ReadProperty lesen (F1, Ursache unbestaetigt)")
                if info.get("prefix"):
                    for pre, func in re.findall(r"\b([A-Za-z0-9]+)_([A-Za-z0-9]+)\(\s*\$id", click):
                        if pre != info["prefix"]:
                            self.add(path, ln, ERROR, "F011", f"onClick ruft {pre}_{func}, Praefix des Moduls ist '{info['prefix']}'")
                        elif info["public"] and func not in info["public"]:
                            self.add(path, ln, ERROR, "F011", f"onClick ruft {pre}_{func}, aber '{func}' ist keine oeffentliche Funktion")

        for st in form.get("status", []) or []:
            if not (isinstance(st, dict) and isinstance(st.get("code"), int)
                    and isinstance(st.get("icon"), str) and isinstance(st.get("caption"), str)):
                self.add(path, where('"status"'), ERROR, "F008", "status-Eintrag braucht code (Zahl), icon und caption (Text)")


def iter_form(nodes):
    """Alle Formularelemente rekursiv (items, popup.items). Liefert (element, ebene)."""
    if not isinstance(nodes, list):
        return
    for el in nodes:
        if not isinstance(el, dict):
            continue
        yield el, 0
        if isinstance(el.get("items"), list):
            yield from iter_form(el["items"])
        pop = el.get("popup")
        if isinstance(pop, dict) and isinstance(pop.get("items"), list):
            yield from iter_form(pop["items"])


# ---------------------------------------------------------------- Release-Pruefung
def git(root: Path, *args: str) -> subprocess.CompletedProcess:
    return subprocess.run(["git", "-C", str(root), *args], capture_output=True, text=True)


def check_release(linter: Linter, base: str) -> None:
    root = linter.root
    lib_path = root / "library.json"
    try:
        ok = git(root, "rev-parse", "--verify", f"{base}^{{commit}}").returncode == 0
    except FileNotFoundError:
        ok = False
    if not ok:
        linter.add(lib_path, 1, WARN, "R000", f"Basis '{base}' nicht aufloesbar (kein git oder unbekannte Referenz): Release-Pruefung uebersprungen")
        return
    changed = git(root, "diff", "--name-only", base, "--", ".").stdout.split()
    watched = [d.name + "/" for d in linter.module_dirs] + ["libs/", "actions/", "imgs/"]
    relevant = [f for f in changed if f.startswith(tuple(watched))]
    old = git(root, "show", f"{base}:library.json")
    old_lib = {}
    if old.returncode == 0:
        try:
            old_lib = json.loads(old.stdout)
        except json.JSONDecodeError:
            pass
    new_build = linter.library.get("build")
    old_build = old_lib.get("build")
    if relevant and isinstance(new_build, int) and isinstance(old_build, int) and new_build <= old_build:
        linter.add(lib_path, 1, ERROR, "R001",
                   f"{len(relevant)} Moduldatei(en) geaendert, aber build ({new_build}) nicht hoeher als in {base} ({old_build})")
    if old_lib.get("id") and old_lib.get("id") != linter.library.get("id"):
        linter.add(lib_path, 1, ERROR, "R002", "Bibliotheks-GUID geaendert: bei Updates nie aendern")
    for mod in linter.module_dirs:
        res = git(root, "show", f"{base}:{mod.name}/module.json")
        if res.returncode != 0:
            continue
        try:
            old_id = json.loads(res.stdout).get("id")
            new_id = json.loads((mod / "module.json").read_text(encoding="utf-8-sig")).get("id")
        except (json.JSONDecodeError, OSError):
            continue
        if old_id and new_id and old_id != new_id:
            linter.add(mod / "module.json", 1, ERROR, "R002", f"Modul-GUID von {mod.name} geaendert: bei Updates nie aendern")


def lint(root: Path, base: str | None = None) -> list[Finding]:
    linter = Linter(root)
    linter.run()
    if base:
        check_release(linter, base)
    return linter.findings


def main(argv: list[str] | None = None) -> int:
    default_root = Path(__file__).resolve().parent.parent
    ap = argparse.ArgumentParser(description="Statische Pruefungen fuer Symcon-Module")
    ap.add_argument("path", nargs="?", default=str(default_root), help="Wurzel der Bibliothek")
    ap.add_argument("--strict", action="store_true", help="Warnungen wie Fehler behandeln")
    ap.add_argument("--base", help="git-Referenz fuer die Release-Pruefung (build, GUIDs)")
    ap.add_argument("--hook", action="store_true", help="Ausgabe auf stderr, Exit 2 bei Fehlern (Claude-Code-Hook)")
    args = ap.parse_args(argv)

    findings = lint(Path(args.path), args.base)
    errors = [f for f in findings if f.level == ERROR]
    warns = [f for f in findings if f.level == WARN]
    stream = sys.stderr if args.hook else sys.stdout
    for f in sorted(findings, key=lambda x: (x.level != ERROR, x.path, x.line)):
        print(f, file=stream)
    print(f"symcon_lint: {len(errors)} Fehler, {len(warns)} Warnungen", file=stream)
    if args.hook:
        return 2 if errors else 0
    return 1 if errors or (args.strict and warns) else 0


if __name__ == "__main__":
    sys.exit(main())
