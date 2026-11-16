# Neue Erkenntnis festhalten

Jeder Fehler, der auf dem Zielsystem auftritt, wird so dokumentiert, dass er **nicht wieder passieren kann**.
Kopiervorlage für `docs/LESSONS-<Modul>.md`:

```markdown
### F<n> – <kurzer Titel / Fehlermeldung>
- **Symptom:** <vollständiger Fehlertext, Schritt, bei dem er auftritt>
- **Ursache:** <bestätigt | vermutet – so kennzeichnen>
- **Lösung:** <was geändert wurde>
- **Regel:** <ein Satz, als Verbot oder Gebot>
- **Absicherung:** <Lint-Regel P/F/L/R… | Test | Manuell – warum nicht automatisierbar>
```

Ablauf:

1. Fehler reproduzieren oder genau beschreiben (Fehlertext, Schritt, Ausgabe von `.dev/diagnose.php`).
2. Eintrag nach Vorlage schreiben. Ursache ehrlich als *bestätigt* oder *vermutet* kennzeichnen.
3. Wenn möglich: neue Regel in `.dev/symcon_lint.py` **plus** Test in `tests/lint/test_symcon_lint.py`, der den Fehler absichtlich einbaut.
4. Regel mit ID in `docs/RULES.md` eintragen (Herkunft, Durchsetzung).
5. `python3 -m unittest discover -s tests/lint` und `python3 .dev/symcon_lint.py --strict` ausführen.
6. Ist die Regel nicht automatisierbar, in `CLAUDE.md` unter „Manuell prüfen“ aufnehmen.
