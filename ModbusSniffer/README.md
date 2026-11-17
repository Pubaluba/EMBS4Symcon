# ModbusSniffer (EMBS4Symcon)

Passiver Modbus-Splitter für IP-Symcon. Er liest den Verkehr auf einem Modbus mit, ohne selbst etwas zu senden.

## Schnittstellen

| Schnittstelle | Protokoll |
|---|---|
| Serielle Schnittstelle (lokal) | Modbus RTU |
| Server Socket (TCP) | Modbus RTU over TCP (z. B. RS485-Ethernet-Wandler im transparenten Modus) oder Modbus TCP (MBAP) |

Die passende I/O-Instanz wird über „Schnittstelle“ automatisch angelegt bzw. getauscht (`ForceParent`).
Baudrate und Port stellst du wie gewohnt in der I/O-Instanz ein.

## Blockieren von Anfragen

- Das Modul ruft **an keiner Stelle** `SendDataToParent()` auf. Es gibt also keinen Codepfad, über den etwas auf den seriellen Bus oder an einen TCP-Client gelangt.
- Anfragen untergeordneter Instanzen (native „Modbus-Adresse“) landen in `ForwardData()`:
  - **Schreibanfragen** (FC5/6/15/16): werden immer verworfen und geben `false` zurück.
  - **Leseanfragen** (FC1–4): werden aus dem Cache beantwortet, wenn alle angefragten Adressen mitgelesen wurden und nicht älter als „Maximales Alter“ sind. Sonst wird die Anfrage verworfen.
- Blockierte Anfragen erscheinen im Debug unter „BLOCKIERT“. Auf Wunsch landen sie zusätzlich im Meldungsprotokoll.

Damit können native Modbus-Adress-Instanzen weiter als Kinder genutzt werden. Sie bekommen Werte, die ein anderer Master auf dem Bus abgefragt hat. Die Geräte-ID (Slave) wird wie beim nativen Gateway im Splitter eingestellt.

## Frame-Erkennung

- **RTU:** Die Framegrenzen ergeben sich aus der Länge je Funktionscode und der CRC-Prüfung. Bei Störungen synchronisiert sich der Parser byteweise neu. Eine Ruhepause (Standard 100 ms) gilt zusätzlich als Framegrenze.
- **Modbus TCP:** Der Parser liest den MBAP-Header. Anfragen und Antworten werden über die Transaction-ID zusammengeführt, auch wenn sie verschachtelt eintreffen.
- Unterstützt werden FC 1–8, 11, 12, 15–17, 22, 23 sowie Exceptions. Werte in den Cache übernommen werden aus FC 1–6, 15, 16 und 23.
- Bei mehreren TCP-Clients führt der Parser je Client (IP:Port) einen eigenen Puffer.

## Datenfluss zu eigenen Kind-Modulen

DataID `{E07F7DDA-41A4-2699-6375-0BD4C00B6695}`, JSON-Felder:

```
Type      request | response | exception
Source    "serial" oder "IP:Port"
Unit, Function
Address, Quantity, Values   (Request: Startadresse/Anzahl/Schreibwerte)
Paired, Table (hr/ir/co/di), Address, Values, Raw (hex)   (Response)
Exception                   (Exception-Code)
Timestamp
```

## Öffentliche Funktionen

```php
MBSNIFF_GetCachedValues(int $InstanzID, int $Unit, string $Table, int $Start, int $Quantity): array
MBSNIFF_GetStatistics(int $InstanzID): array   // Frames, Errors, Blocked, CacheAnswers
MBSNIFF_ClearCache(int $InstanzID): void
```

## Im Livesystem prüfen

- Die Datenfluss-GUIDs der nativen Modbus-Adresse (`{E310B701-…}` Kind→Gateway, `{449015FB-…}` Gateway→Kind) und das Antwortformat (`FC + Bytezahl + Daten`, Kinder lesen ab `substr($x, 2)`) stammen aus gängigen Community-Modulen. Symcon dokumentiert sie nicht offiziell.
- Buffer-Kodierung: Das Modul geht vom UTF-8-kodierten Buffer des einfachen Datenflusses aus (siehe `DecodeBuffer()`).
- Was Skripte direkt an die I/O-Instanz senden (z. B. `SPRT_SendText`), kann der Splitter nicht verhindern.
