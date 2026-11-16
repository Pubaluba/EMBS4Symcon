"""Tests fuer .dev/symcon_lint.py: jede Regel muss auf einen bewusst eingebauten Fehler anschlagen.

Aufruf:  python3 -m unittest discover -s tests/lint -v
"""
import json
import shutil
import sys
import tempfile
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[2]
sys.path.insert(0, str(ROOT / ".dev"))
import symcon_lint  # noqa: E402

# (Quelle im Repository, Ziel im Test-Ordner); die Vorlage liegt unter .dev/template
COPY = [("library.json", "library.json"), (".dev/template/MeinModul", "MeinModul"), ("libs", "libs")]


class LintCase(unittest.TestCase):
    def setUp(self):
        self.tmp = Path(tempfile.mkdtemp())
        for item, target in COPY:
            src = ROOT / item
            dst = self.tmp / target
            if src.is_dir():
                shutil.copytree(src, dst, ignore=shutil.ignore_patterns("__pycache__"))
            else:
                shutil.copy(src, dst)
        self.mod = self.tmp / "MeinModul"

    def tearDown(self):
        shutil.rmtree(self.tmp, ignore_errors=True)

    # Hilfen
    def rules(self, base=None):
        return {(f.rule, f.level) for f in symcon_lint.lint(self.tmp, base)}

    def ids(self):
        return {r for r, _ in self.rules()}

    def php(self, old, new, file="module.php"):
        p = self.mod / file
        text = p.read_text(encoding="utf-8")
        self.assertIn(old, text, f"Testvoraussetzung: '{old}' nicht in {file}")
        p.write_text(text.replace(old, new, 1), encoding="utf-8")

    def append_method(self, code):
        p = self.mod / "module.php"
        text = p.read_text(encoding="utf-8").rstrip()
        assert text.endswith("}")
        p.write_text(text[:-1] + "\n" + code + "\n}\n", encoding="utf-8")

    def edit_json(self, rel, fn):
        p = self.tmp / rel
        data = json.loads(p.read_text(encoding="utf-8"))
        fn(data)
        p.write_text(json.dumps(data, indent=4), encoding="utf-8")


class TestCleanTemplate(LintCase):
    def test_template_has_no_findings(self):
        self.assertEqual(symcon_lint.lint(self.tmp), [])

    def test_repo_root_itself_is_clean(self):
        self.assertEqual([str(f) for f in symcon_lint.lint(ROOT)], [])


class TestPhpRules(LintCase):
    def test_P001_strict_types(self):
        self.php("<?php\n", "<?php\ndeclare(strict_types=1);\n")
        self.assertIn("P001", self.ids())

    def test_P002_class_name(self):
        self.php("class MeinModul", "class Anders")
        self.assertIn("P002", self.ids())

    def test_P003_missing_return_type(self):
        self.php("public function Check(?string $Host = ''): string", "public function Check(?string $Host = '')")
        self.assertIn("P003", self.ids())

    def test_P003_missing_param_type(self):
        self.php("public function Check(?string $Host = '')", "public function Check($Host = '')")
        self.assertIn("P003", self.ids())

    def test_P004_underscore_name_and_instanceid(self):
        self.append_method("public function Do_It(int $InstanceID): void {}")
        self.assertIn("P004", self.ids())

    def test_P005_write_into_module_dir_direct(self):
        self.append_method("public function W(): void { file_put_contents(__DIR__ . '/runtime/x.txt', 'a'); }")
        self.assertIn(("P005", "ERROR"), self.rules())

    def test_P005_write_via_variable(self):
        self.append_method("public function W(): void { $d = __DIR__ . '/runtime'; mkdir($d); }")
        self.assertIn(("P005", "ERROR"), self.rules())

    def test_P005_read_is_fine(self):
        self.assertNotIn("P005", self.ids())

    def test_P005_kernel_dir_is_fine(self):
        self.append_method("public function W(): void { $d = IPS_GetKernelDir() . 'x'; mkdir($d); }")
        self.assertNotIn("P005", self.ids())

    def test_P006_url_string_and_curl(self):
        self.append_method("public function D(): void { $s = file_get_contents('https://example.org/x'); }")
        self.assertIn(("P006", "ERROR"), self.rules())

    def test_P006_returntransfer_warns(self):
        self.append_method("public function D(): void { $c = curl_init(); curl_setopt($c, CURLOPT_RETURNTRANSFER, true); }")
        self.assertIn(("P006", "WARN"), self.rules())

    def test_P007_exec_without_escape(self):
        self.append_method("public function E(string $x): void { exec('ls ' . $x, $o, $rc); }")
        self.assertIn(("P007", "ERROR"), self.rules())

    def test_P007_exec_with_escape_ok(self):
        self.append_method("public function E(string $x): void { $a = escapeshellarg($x); exec('ls ' . $a, $o, $rc); }")
        self.assertNotIn("P007", self.ids())

    def test_P007_in_string_or_comment_ignored(self):
        self.append_method("public function E(): string { // exec('rm -rf /')\n return 'exec(x)'; }")
        self.assertNotIn("P007", self.ids())

    def test_P007_suppression_needs_reason(self):
        self.append_method("public function E(string $x): void {\n exec('ls ' . $x, $o, $rc); // lint-ok: P007\n}")
        self.assertIn("P007", self.ids())
        self.php("exec('ls ' . $x, $o, $rc); // lint-ok: P007", "exec('ls ' . $x, $o, $rc); // lint-ok: P007 Eingabe oben validiert")
        self.assertNotIn("P007", self.ids())

    def test_P008_exec_without_return_code(self):
        self.append_method("public function E(string $x): void { $a = escapeshellarg($x); exec('ls ' . $a); }")
        self.assertIn(("P008", "WARN"), self.rules())

    def test_P009_P010_P011_warnings(self):
        self.append_method("public function X(): void { symlink('a', 'b'); sleep(5); $p = '/var/lib/symcon/x'; }")
        got = self.ids()
        self.assertTrue({"P009", "P010", "P011"} <= got)

    def test_P012_exec_without_platform_switch(self):
        self.append_method("public function E(): void { $a = escapeshellarg('x'); exec($a, $o, $rc); }")
        self.assertIn("P012", self.ids())
        self.append_method("public function F(): string { return PHP_OS_FAMILY; }")
        self.assertNotIn("P012", self.ids())

    def test_P012_runCommand_without_platform_switch(self):
        self.append_method("public function R(): void { $this->runCommand('ls', ['-l']); }")
        self.assertIn("P012", self.ids())
        self.append_method("public function F(): string { return $this->platform(); }")
        self.assertNotIn("P012", self.ids())

    def test_P012_helper_with_own_switch_is_fine(self):
        # libs/EPHelper.php enthaelt exec() UND platform(): darf nicht anschlagen
        self.assertNotIn("P012", self.ids())

    def test_P013_no_senddebug(self):
        self.php("$this->SendDebug('Check', $message, 0);", "")
        self.assertIn("P013", self.ids())

    def test_P019_IPSModule_warns(self):
        self.php("extends IPSModuleStrict", "extends IPSModule")
        self.assertIn(("P019", "WARN"), self.rules())

    def test_L009_strict_needs_compat_8_1(self):
        self.edit_json("library.json", lambda d: d["compatibility"].update(version="6.0"))
        self.assertIn("L009", self.ids())

    def test_P020_read_unregistered_property(self):
        self.php("$this->ReadPropertyString('Host')", "$this->ReadPropertyString('Unbekannt')")
        self.assertIn("P020", self.ids())

    def test_P021_registered_property_not_in_form(self):
        self.php("$this->RegisterPropertyInteger('Interval', 5);",
                 "$this->RegisterPropertyInteger('Interval', 5);\n        $this->RegisterPropertyString('Extra', '');")
        self.assertIn(("P021", "WARN"), self.rules())

    def test_P022_attribute_not_registered(self):
        self.php("$this->RegisterAttributeString('LastMessage', '');", "")
        self.assertIn("P022", self.ids())

    def test_P023_timer_wrong_prefix_or_function(self):
        self.php("MMOD_TimerCheck(", "XXXX_TimerCheck(")
        self.assertIn("P023", self.ids())

    def test_P023_timer_unknown_function(self):
        self.php("MMOD_TimerCheck(", "MMOD_Gibtsnicht(")
        self.assertIn("P023", self.ids())


class TestJsonRules(LintCase):
    def test_L000_invalid_json(self):
        (self.mod / "form.json").write_text("{ kaputt", encoding="utf-8")
        self.assertIn("L000", self.ids())

    def test_L001_library_fields(self):
        self.edit_json("library.json", lambda d: d.pop("build"))
        self.assertIn("L001", self.ids())

    def test_L002_guid_lowercase(self):
        self.edit_json("MeinModul/module.json", lambda d: d.update(id=d["id"].lower()))
        self.assertIn("L002", self.ids())

    def test_L003_duplicate_guid(self):
        lib_id = json.loads((self.tmp / "library.json").read_text())["id"]
        self.edit_json("MeinModul/module.json", lambda d: d.update(id=lib_id))
        self.assertIn("L003", self.ids())

    def test_L004_missing_module_fields_and_type(self):
        self.edit_json("MeinModul/module.json", lambda d: (d.pop("prefix"), d.update(type=9)))
        self.assertIn("L004", self.ids())

    def test_L005_prefix_rules(self):
        self.edit_json("MeinModul/module.json", lambda d: d.update(prefix="ab"))
        self.assertIn(("L005", "WARN"), self.rules())
        self.edit_json("MeinModul/module.json", lambda d: d.update(prefix="A_B"))
        self.assertIn(("L005", "ERROR"), self.rules())

    def test_L008_folder_without_module_json(self):
        (self.tmp / "tools").mkdir()
        self.assertIn("L008", self.ids())

    def test_L008_dot_and_allowed_dirs_ignored(self):
        for d in (".dev", ".github", "docs", "tests", "libs"):
            (self.tmp / d).mkdir(exist_ok=True)
        self.assertNotIn("L008", self.ids())

    def test_L010_folder_name(self):
        shutil.move(str(self.mod), str(self.tmp / "Anders"))
        self.assertIn(("L010", "WARN"), self.rules())

    def test_L011_runtime_dir(self):
        (self.mod / "runtime").mkdir()
        self.assertIn("L011", self.ids())


class TestFormRules(LintCase):
    def test_F001_unknown_top_level_key(self):
        self.edit_json("MeinModul/form.json", lambda d: d.update(extras=[]))
        self.assertIn("F001", self.ids())

    def test_F002_input_without_name(self):
        self.edit_json("MeinModul/form.json", lambda d: d["actions"].append({"type": "CheckBox", "caption": "x"}))
        self.assertIn("F002", self.ids())

    def test_F003_unknown_type_NumberSpinBox(self):
        self.edit_json("MeinModul/form.json",
                       lambda d: d["elements"][1].update(type="NumberSpinBox"))
        self.assertIn("F003", self.ids())

    def test_F004_duplicate_name(self):
        self.edit_json("MeinModul/form.json", lambda d: d["actions"][0].update(name="Host"))
        self.assertIn("F004", self.ids())

    def test_F005_onclick_unknown_variable(self):
        self.edit_json("MeinModul/form.json",
                       lambda d: d["actions"][1].update(onClick="echo MMOD_Check($id, $Network ?? '');"))
        self.assertIn("F005", self.ids())

    def test_F006_onclick_without_default(self):
        self.edit_json("MeinModul/form.json",
                       lambda d: d["actions"][1].update(onClick="echo MMOD_Check($id, $TestHost);"))
        self.assertIn(("F006", "WARN"), self.rules())

    def test_F007_onclick_variable_from_elements(self):
        self.edit_json("MeinModul/form.json",
                       lambda d: d["actions"][1].update(onClick="echo MMOD_Check($id, $Host ?? '');"))
        self.assertIn(("F007", "WARN"), self.rules())

    def test_F008_bad_status_entry(self):
        self.edit_json("MeinModul/form.json", lambda d: d["status"].append({"code": "x"}))
        self.assertIn("F008", self.ids())

    def test_F009_updateformfield_target_missing(self):
        self.php("'StatusLabel', 'caption'", "'GibtEsNicht', 'caption'")
        self.assertIn(("F009", "WARN"), self.rules())

    def test_F010_element_without_property(self):
        self.edit_json("MeinModul/form.json",
                       lambda d: d["elements"].append({"type": "CheckBox", "name": "Ohne", "caption": "x"}))
        self.assertIn(("F010", "WARN"), self.rules())

    def test_F011_onclick_unknown_function_or_prefix(self):
        self.edit_json("MeinModul/form.json",
                       lambda d: d["actions"][1].update(onClick="echo MMOD_Gibtsnicht($id);"))
        self.assertIn("F011", self.ids())
        self.edit_json("MeinModul/form.json",
                       lambda d: d["actions"][1].update(onClick="echo ZZZ_Check($id);"))
        self.assertIn("F011", self.ids())


class TestRelease(LintCase):
    """Release-Pruefung (--base) gegen ein echtes git-Repository."""

    def git(self, *args):
        import subprocess
        return subprocess.run(["git", "-C", str(self.tmp), "-c", "user.email=t@t", "-c", "user.name=t", *args],
                              capture_output=True, text=True, check=True)

    def setUp(self):
        super().setUp()
        self.git("init", "-q", "-b", "main")
        self.git("add", "-A")
        self.git("commit", "-q", "-m", "basis")

    def test_changed_module_without_build_bump(self):
        self.php("'Bereit.'", "'Fertig.'")
        self.assertIn(("R001", "ERROR"), self.rules("main"))

    def test_changed_module_with_build_bump_is_ok(self):
        self.php("'Bereit.'", "'Fertig.'")
        self.edit_json("library.json", lambda d: d.update(build=d["build"] + 1))
        self.assertNotIn("R001", self.ids_with_base())

    def test_docs_only_change_needs_no_bump(self):
        (self.tmp / "docs").mkdir(exist_ok=True)
        (self.tmp / "docs" / "x.md").write_text("x")
        self.git("add", "-A")
        self.assertNotIn("R001", self.ids_with_base())

    def test_changed_module_guid_is_error(self):
        self.edit_json("MeinModul/module.json", lambda d: d.update(id="{11111111-2222-3333-4444-555555555555}"))
        self.edit_json("library.json", lambda d: d.update(build=d["build"] + 1))
        self.assertIn(("R002", "ERROR"), self.rules("main"))

    def test_changed_library_guid_is_error(self):
        self.edit_json("library.json", lambda d: d.update(id="{11111111-2222-3333-4444-555555555555}", build=d["build"] + 1))
        self.assertIn(("R002", "ERROR"), self.rules("main"))

    def test_unknown_base_only_warns(self):
        self.assertIn(("R000", "WARN"), self.rules("gibt-es-nicht"))

    def ids_with_base(self):
        return {r for r, _ in self.rules("main")}


class TestScaffold(unittest.TestCase):
    def test_new_module_passes_lint(self):
        import subprocess
        tmp = Path(tempfile.mkdtemp())
        try:
            for item in ["library.json", "libs", ".dev"]:
                src = ROOT / item
                if src.is_dir():
                    shutil.copytree(src, tmp / item, ignore=shutil.ignore_patterns("__pycache__"))
                else:
                    shutil.copy(src, tmp / item)
            res = subprocess.run([sys.executable, str(tmp / ".dev" / "scaffold.py"), "module", "Zweites", "ZWEI"],
                                 cwd=tmp, capture_output=True, text=True)
            self.assertEqual(res.returncode, 0, res.stdout + res.stderr)
            self.assertEqual([str(f) for f in symcon_lint.lint(tmp)], [])
            data = json.loads((tmp / "Zweites" / "module.json").read_text())
            self.assertEqual(data["prefix"], "ZWEI")
            self.assertEqual(data["name"], "Zweites")
            orig = json.loads((ROOT / ".dev" / "template" / "MeinModul" / "module.json").read_text())
            self.assertNotEqual(data["id"], orig["id"])
            self.assertIn("class Zweites extends", (tmp / "Zweites" / "module.php").read_text())
            self.assertIn("ZWEI_Check", (tmp / "Zweites" / "form.json").read_text())
        finally:
            shutil.rmtree(tmp, ignore_errors=True)


if __name__ == "__main__":
    unittest.main()
