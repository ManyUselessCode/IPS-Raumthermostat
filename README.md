# IPS-Raumthermostat

Symcon-Modul für die Heizung mit KNX-Schaltventilen. Vorbild ist der Home-Assistant-Blueprint „Heizung | Raumcontroller“. Die Bibliothek enthält zwei Module:

- **Raumthermostat:** eine Instanz je Raum. Rechnet den Sollwert, regelt das Ventil und überwacht den Temperaturfühler.
- **Heizzentrale:** eine Instanz je Wohnung. Hält die Werte, die für alle Räume gelten, und schickt Push-Nachrichten.

## Heizzentrale

Variablen, in WebFront und App bedienbar:

- **Heizphase:** an = die Räume heizen nach Zeitplan, aus = alle Räume auf Frostschutz.
- **Frostschutz:** Untergrenze für alle Räume (5–15 °C, Vorgabe 7 °C).
- **Urlaub:** an = alle Räume auf den Urlaubs-Soll.
- **Urlaubs-Soll:** Temperatur im Urlaub (10–22 °C, Vorgabe 16 °C).

Einstellung: die Kachel-Visualisierung, über die Push-Nachrichten gehen (optional). Aus Skripten: `HEIZZENTRALE_Push(ID, Titel, Text)`.

## Raumthermostat

### Einstellungen

- Heizzentrale, Ist-Temperatur (Variable), Ventil Schalten (KNX-DPT-1-Instanz), Ventil Status (Variable).
- Toleranz (Vorgabe 0,3 K), Fühler ausgefallen nach (Vorgabe 30 Minuten), Abweichender Urlaubs-Soll (0 = Wert der Zentrale).
- Fensterkontakte (optional, an = offen) und Fenster offen ab (Vorgabe 2 Minuten).

### Variablen

- **Soll:** Sollwert, 5–30 °C in 0,1-K-Schritten. Ein von Hand gesetzter Wert gilt bis zum nächsten Schaltpunkt oder Zustandswechsel. Aus Skripten: `RAUMTHERMOSTAT_SetzeSoll(ID, Soll, Grund)`.
- **Ist:** aktuelle Temperatur.
- **Heizen:** aus = Ventil zu, egal was der Sollwert sagt.
- **Ventil:** offen oder zu, aus der Status-Variable.
- **Soll Tag, Soll Nacht:** Temperaturen für die beiden Stufen des Zeitplans (Vorgabe 21 °C und 18 °C).
- **Grund:** Text zur letzten Entscheidung, zum Beispiel „Soll 21,0: Zeitplan Tag“ oder „Ventil auf: 20,4 < 20,7“. Versteckt, zum Auswerten im Archiv.
- **Sollabweichung:** Heizbedarf des Raums für die Heizung, Ist minus Soll in K (negativ = Bedarf). Sollbereich ist Soll ± Toleranz. Außerhalb gilt Ist minus Soll, im Sollbereich mit offenem Ventil Ist minus (Soll + Toleranz), mit geschlossenem Ventil 0. So bleibt der Wert negativ, solange das Ventil offen ist. Ohne Heizen, bei Fühlerausfall oder inaktiver Instanz 0. Versteckt.
- **Zeitplan:** Wochenplan mit den Aktionen Tag und Nacht, den die Instanz selbst anlegt. Vorgabe: rund um die Uhr Tag.

### Sollwert

In dieser Reihenfolge: Fenster länger offen → Frostschutz. Heizphase aus → Frostschutz. Urlaub → Urlaubs-Soll. Sonst Zeitplan (Tag oder Nacht). Nie unter Frostschutz.

### Regelung

Zweipunktregler: Das Ventil öffnet, wenn die Ist-Temperatur unter Soll minus Toleranz fällt, und schließt, wenn sie über Soll plus Toleranz steigt. Der Zustand kommt aus der Status-Variable, ein Befehl geht nur bei Abweichung. Schaltet jemand das Ventil von außen, steht „von Extern“ im Grund.

### Fühlerüberwachung

Kommt länger als die Zeitgrenze kein Messwert, gilt der Fühler als ausgefallen: Push-Nachricht, Heizen aus, Ventil zu. Meldet der Fühler wieder, kommt eine Push-Nachricht, und Heizen geht wieder an, wenn das Modul es abgeschaltet hatte. Die Frist läuft über einen Neustart weiter. Der Fühler muss dafür zyklisch senden (KNX: zum Beispiel alle 10 Minuten).

### Start

Nach dem Start wartet das Modul eine Minute, liest Ventil Status und Temperatur vom Bus und rechnet dann.

## Aufzeichnung

Raumthermostat und Heizzentrale schalten beim Übernehmen die Aufzeichnung im Archiv für alle ihre Variablen ein. Ausgeschaltet wird sie vom Modul nie.

## Voraussetzung

KNX-DPT-Instanzen: DPT 1 für Ventil Schalten und Ventil Status (getrennte Instanzen), DPT 9 für die Temperatur. Symcon ab 8.0.
