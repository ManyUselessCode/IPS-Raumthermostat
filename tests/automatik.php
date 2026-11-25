<?php

declare(strict_types=1);

// Prüft Automatik, Raumwert, Vorrang und Neustart des Raumthermostats. Aufruf: php tests/automatik.php
// Nachgebaut ist nur, was das Modul von Symcon braucht. Alle IDs sind erfunden.

const IPS_KERNELMESSAGE = 10100;
const KR_READY = 10103;
const VM_UPDATE = 10603;
const IS_ACTIVE = 102;
const IS_INACTIVE = 104;
const KL_NOTIFY = 10203;
const KL_ERROR = 10206;

set_error_handler(function (int $nr, string $text, string $datei, int $zeile): bool {
    if ((error_reporting() & $nr) == 0) {
        return true;
    }
    throw new ErrorException($text, 0, $nr, $datei, $zeile);
});

const ZENTRALE = 50;
const IST = 60;
const VENTIL_STATUS = 61;
const FENSTER = 62;
const FENSTER2 = 63;
const VENTIL_SCHALTEN = 70;
const PLAN = 900;

class Welt
{
    public static array $v = [];        // ID => Wert, Updated, Changed
    public static array $ident = [];    // Eltern => Ident => ID
    public static array $hidden = [];
    public static array $ereignis = [];
    public static array $ventil = [];   // Befehle auf das Ventil
    public static int $naechste = 100;

    public static function variable(int $id, $wert, int $zeit = 0): void
    {
        self::$v[$id] = ['Wert' => $wert, 'Updated' => $zeit, 'Changed' => $zeit];
    }
    public static function setze(int $id, $wert): void
    {
        if (self::$v[$id]['Wert'] !== $wert) {
            self::$v[$id]['Changed'] = time();
        }
        self::$v[$id]['Wert'] = $wert;
        self::$v[$id]['Updated'] = time();
    }
}

class IPSModule
{
    public $InstanceID = 1;
    public array $eigenschaften = [];
    public array $puffer = [];
    public array $timer = [];
    public int $status = 0;

    public function Create() {}
    public function ApplyChanges() {}
    private function eigenschaft($n, $w) { $this->eigenschaften[$n] ??= $w; }
    protected function RegisterPropertyInteger($n, $w) { $this->eigenschaft($n, $w); }
    protected function RegisterPropertyFloat($n, $w) { $this->eigenschaft($n, $w); }
    protected function RegisterPropertyString($n, $w) { $this->eigenschaft($n, $w); }
    protected function ReadPropertyInteger($n) { return (int) $this->eigenschaften[$n]; }
    protected function ReadPropertyFloat($n) { return (float) $this->eigenschaften[$n]; }
    protected function ReadPropertyString($n) { return (string) $this->eigenschaften[$n]; }
    protected function RegisterTimer($n, $ms, $s) { $this->timer[$n] = $ms; }
    protected function SetTimerInterval($n, $ms) { $this->timer[$n] = $ms; }
    private function variable($ident, $wert)
    {
        if (!isset(Welt::$ident[1][$ident])) {
            $id = Welt::$naechste++;
            Welt::$ident[1][$ident] = $id;
            Welt::variable($id, $wert);
        }
    }
    protected function RegisterVariableBoolean($i, $n, $d, $p) { $this->variable($i, false); }
    protected function RegisterVariableInteger($i, $n, $d, $p) { $this->variable($i, 0); }
    protected function RegisterVariableFloat($i, $n, $d, $p) { $this->variable($i, 0.0); }
    protected function RegisterVariableString($i, $n, $d, $p) { $this->variable($i, ''); }
    protected function EnableAction($i) { if (!isset(Welt::$ident[1][$i])) { throw new Exception($i); } }
    protected function GetIDForIdent($i) { return Welt::$ident[1][$i]; }
    protected function GetValue($i) { return Welt::$v[Welt::$ident[1][$i]]['Wert']; }
    protected function SetValue($i, $w) { Welt::setze(Welt::$ident[1][$i], $w); }
    protected function SetBuffer($n, $w) { $this->puffer[$n] = $w; }
    protected function GetBuffer($n) { return $this->puffer[$n] ?? ''; }
    protected function GetStatus() { return $this->status; }
    protected function SetStatus($s) { $this->status = $s; }
    protected function GetMessageList() { return []; }
    protected function GetReferenceList() { return []; }
    protected function RegisterMessage($s, $n) {}
    protected function UnregisterMessage($s, $n) {}
    protected function RegisterReference($r) {}
    protected function UnregisterReference($r) {}
    protected function SendDebug($a, $b, $c) {}
    protected function LogMessage($t, $a) {}
}

function IPS_GetKernelRunlevel() { return KR_READY; }
function IPS_GetObjectIDByIdent($ident, $eltern) { return Welt::$ident[$eltern][$ident] ?? false; }
function IPS_CreateEvent($typ) { Welt::$ereignis = ['EventActive' => false, 'ScheduleGroups' => []]; return PLAN; }
function IPS_SetParent($id, $e) {}
function IPS_SetIdent($id, $i) { Welt::$ident[1][$i] = $id; }
function IPS_SetName($id, $n) {}
function IPS_GetName($id) { return 'Raum'; }
function IPS_GetParent($id) { return 0; }
function IPS_SetPosition($id, $p) {}
function IPS_SetHidden($id, $h) { Welt::$hidden[$id] = $h; }
function IPS_SetEventScheduleAction($id, $a, $n, $f, $s) {}
function IPS_SetEventScheduleGroup($id, $g, $t) { Welt::$ereignis['ScheduleGroups'][$g] = ['Days' => $t, 'Points' => []]; }
function IPS_SetEventScheduleGroupPoint($id, $g, $p, $h, $m, $s, $a)
{
    Welt::$ereignis['ScheduleGroups'][$g]['Points'][$p] = ['ActionID' => $a, 'Start' => ['Hour' => $h, 'Minute' => $m, 'Second' => $s]];
}
function IPS_SetEventActive($id, $a) { Welt::$ereignis['EventActive'] = $a; }
function IPS_GetEvent($id) { return Welt::$ereignis; }
function IPS_GetInstanceListByModuleID($g) { return []; }
function IPS_VariableExists($id) { return isset(Welt::$v[$id]); }
function IPS_InstanceExists($id) { return in_array($id, [ZENTRALE, VENTIL_SCHALTEN], true); }
function IPS_GetInstance($id) { return ['ModuleInfo' => ['ModuleID' => '{3581511F-55BB-4D47-A922-CEA2D4118A59}']]; }
function IPS_GetVariable($id) { return ['VariableUpdated' => Welt::$v[$id]['Updated'], 'VariableChanged' => Welt::$v[$id]['Changed']]; }
function GetValue($id) { return Welt::$v[$id]['Wert']; }
function GetValueBoolean($id) { return Welt::$v[$id]['Wert']; }
function IPS_Sleep($ms) {}
function HEIZZENTRALE_Push($id, $t, $x) {}
function KNX_WriteDPT1($id, $an)
{
    Welt::$ventil[] = $an;
    Welt::setze(VENTIL_STATUS, $an);
    return true;
}

require __DIR__ . '/../Raumthermostat/module.php';

$fehler = 0;
$pruefungen = 0;
function pruefe($ist, $soll, string $text): void
{
    global $fehler, $pruefungen;
    $pruefungen++;
    if ($ist !== $soll) {
        $fehler++;
        echo "FEHLER: $text: " . var_export($ist, true) . ' statt ' . var_export($soll, true) . "\n";
    }
}

/** Neue Welt mit einer frisch übernommenen Instanz. $vorher läuft zwischen Create und ApplyChanges. */
function raum(?callable $vorher = null, array $eigenschaften = []): Raumthermostat
{
    Welt::$v = Welt::$ident = Welt::$hidden = Welt::$ereignis = Welt::$ventil = [];
    Welt::$naechste = 100;
    Welt::variable(IST, 21.0, time());
    Welt::variable(VENTIL_STATUS, false, time());
    Welt::variable(FENSTER, false, time() - 9999);
    Welt::variable(FENSTER2, false, time() - 9999);
    foreach (['Heizphase' => true, 'Frostschutz' => 7.0, 'Urlaub' => false, 'UrlaubSoll' => 16.0] as $ident => $wert) {
        $id = Welt::$naechste++;
        Welt::$ident[ZENTRALE][$ident] = $id;
        Welt::variable($id, $wert, time());
    }
    $m = new Raumthermostat();
    $m->eigenschaften = $eigenschaften + [
        'ZentraleID' => ZENTRALE, 'IstID' => IST, 'VentilSchaltenID' => VENTIL_SCHALTEN, 'VentilStatusID' => VENTIL_STATUS,
        'Fenster' => json_encode([['VariableID' => FENSTER], ['VariableID' => FENSTER2]])
    ];
    $m->Create();
    if ($vorher) {
        $vorher($m);
    }
    $m->ApplyChanges();
    return $m;
}

function w(string $ident) { return Welt::$v[Welt::$ident[1][$ident]]['Wert']; }
function versteckt(string $ident): bool { return Welt::$hidden[Welt::$ident[1][$ident]]; }
function setzeWert(string $ident, $wert): void { Welt::setze(Welt::$ident[1][$ident], $wert); }

function zentrale(Raumthermostat $m, string $ident, $wert): void
{
    $id = Welt::$ident[ZENTRALE][$ident];
    Welt::setze($id, $wert);
    $m->MessageSink(0, $id, VM_UPDATE, [$wert, true]);
}

function fenster(Raumthermostat $m, int $id, bool $offen, int $seit): void
{
    Welt::variable($id, $offen, time() - $seit);
    $m->MessageSink(0, $id, VM_UPDATE, [$offen, true]);
    $m->RequestAction('FensterPruefen', 0);
}

/** Symcon-Neustart: Puffer und Timer sind weg, Variablen bleiben. */
function neustart(Raumthermostat $alt): Raumthermostat
{
    $m = new Raumthermostat();
    $m->eigenschaften = $alt->eigenschaften;
    $m->Create();
    $m->MessageSink(0, 0, IPS_KERNELMESSAGE, [KR_READY]);
    $m->RequestAction('StartSchritt', 0);
    $m->RequestAction('StartSchritt', 0);
    return $m;
}

function plan(int $aktion): void
{
    for ($tag = 0; $tag < 7; $tag++) {
        IPS_SetEventScheduleGroupPoint(PLAN, $tag, 0, 0, 0, 0, $aktion);
    }
}

// Neue Instanz: Automatik aus, Soll Tag, Zeitplan inaktiv und versteckt
$m = raum();
pruefe($m->status, IS_ACTIVE, 'neu: aktiv');
pruefe(w('Automatik'), false, 'neu: Automatik aus');
pruefe(w('Soll'), 21.0, 'neu: Soll Tag');
pruefe(w('SollGemerkt'), 21.0, 'neu: gemerkt');
pruefe(Welt::$ereignis['EventActive'], false, 'neu: Zeitplan inaktiv');
foreach (['SollTag', 'SollNacht', 'Zeitplan', 'Grund', 'Fuehlerausfall', 'Sollabweichung', 'SollGemerkt'] as $i) {
    pruefe(versteckt($i), true, "neu: $i versteckt");
}
foreach (['Ist', 'Soll', 'Ventil', 'Heizen', 'Automatik'] as $i) {
    pruefe(versteckt($i), false, "neu: $i sichtbar");
}

// Update von 1.2: Soll 21,5, Soll Tag 21. Automatik folgt dem Zeitplan
$bestand = function (bool $aktiv): callable {
    return function (Raumthermostat $m) use ($aktiv): void {
        (new ReflectionMethod($m, 'ZeitplanAnlegen'))->invoke($m);
        Welt::$ereignis['EventActive'] = $aktiv;
        foreach (['Soll' => 21.5, 'SollTag' => 21.0, 'SollNacht' => 18.0, 'Heizen' => true] as $i => $wert) {
            setzeWert($i, $wert);
        }
    };
};
$m = raum($bestand(false));
pruefe(w('Automatik'), false, 'Update: Automatik aus');
pruefe(w('Soll'), 21.5, 'Update: Soll bleibt');
pruefe(w('SollGemerkt'), 21.5, 'Update: gemerkt');
pruefe(Welt::$ventil, [true], 'Update: regelt auf 21,5 (21,0 < 21,2)');
$m = raum($bestand(true));
pruefe(w('Automatik'), true, 'Update mit Zeitplan: Automatik an');
pruefe(w('Soll'), 21.0, 'Update mit Zeitplan: Soll Tag');

// Ohne Automatik ändern nur App und Skript den Raumwert
$m = raum($bestand(false));
$m->RequestAction('Soll', 20.0);
pruefe([w('Soll'), w('SollGemerkt'), w('Grund')], [20.0, 20.0, 'Ventil zu: 21,0 > 20,3'], 'manuell: App');
$m->SetzeSoll(19.5, 'Skript X');
pruefe([w('Soll'), w('Grund')], [19.5, 'Soll 19,5: Skript X'], 'manuell: Skript');
$m->RequestAction('SollTag', 23.0);
$m->RequestAction('SollNacht', 16.0);
pruefe(w('Soll'), 19.5, 'manuell: Soll Tag/Nacht wirkungslos');
$m->RequestAction('Zeitplan', 1);
pruefe(w('Soll'), 19.5, 'manuell: Zeitplan wirkungslos');
$m->Aktualisieren();
pruefe(w('Soll'), 19.5, 'manuell: Aktualisieren');
$m->ApplyChanges();
pruefe(w('Soll'), 19.5, 'manuell: Übernehmen');
$m = neustart($m);
pruefe([w('Soll'), w('SollGemerkt')], [19.5, 19.5], 'manuell: Neustart');

// Vorrang ohne Automatik, danach wieder der Raumwert
zentrale($m, 'Urlaub', true);
pruefe([w('Soll'), w('SollGemerkt'), w('Grund')], [16.0, 19.5, 'Soll 16,0: Urlaub'], 'Urlaub an');
$m = neustart($m);
pruefe([w('Soll'), w('SollGemerkt')], [16.0, 19.5], 'Urlaub: Neustart');
$m->RequestAction('Soll', 22.0);
pruefe([w('Soll'), w('SollGemerkt'), w('Grund')], [16.0, 22.0, 'Soll gemerkt 22,0: App, zurzeit Urlaub'], 'Urlaub: Handwert gemerkt');
zentrale($m, 'Heizphase', false);
pruefe(w('Soll'), 7.0, 'Heizphase aus vor Urlaub');
fenster($m, FENSTER, true, 200);
pruefe(w('Soll'), 7.0, 'Fenster offen');
zentrale($m, 'Heizphase', true);
zentrale($m, 'Urlaub', false);
pruefe([w('Soll'), w('SollGemerkt')], [7.0, 22.0], 'Fenster hält');
$m = neustart($m);
pruefe([w('Soll'), w('SollGemerkt')], [7.0, 22.0], 'Fenster: Neustart');
fenster($m, FENSTER, false, 0);
pruefe([w('Soll'), w('Grund')], [22.0, 'Ventil auf: 21,0 < 21,7'], 'Fenster zu: Raumwert, Ventil auf');
pruefe(w('SollGemerkt'), 22.0, 'Fenster zu: gemerkt');

// Vorrang beginnt während eines Neustarts (Zentrale inzwischen geändert)
Welt::setze(Welt::$ident[ZENTRALE]['Urlaub'], true);
$m = neustart($m);
pruefe([w('Soll'), w('SollGemerkt')], [16.0, 22.0], 'Neustart prüft den Vorrang');
zentrale($m, 'Urlaub', false);
pruefe(w('Soll'), 22.0, 'Urlaub vorbei');

// Frostschutz hebt an, der Raumwert bleibt
$m->RequestAction('Soll', 5.0);
pruefe([w('Soll'), w('SollGemerkt'), w('Grund')], [7.0, 5.0, 'Ventil zu: 21,0 > 7,3'], 'Soll unter Frostschutz');
zentrale($m, 'Frostschutz', 10.0);
pruefe([w('Soll'), w('Grund')], [10.0, 'Soll 10,0: gemerkter Wert, angehoben auf Frostschutz'], 'Frostschutz erhöht');
zentrale($m, 'Frostschutz', 7.0);
$m->RequestAction('Soll', 21.0);

// Heizen aus = Frostschutz
$m->RequestAction('Heizen', false);
pruefe([w('Soll'), w('SollGemerkt'), w('Grund')], [7.0, 21.0, 'Soll 7,0: Heizen aus'], 'Heizen aus');
pruefe(w('Sollabweichung'), 14.0, 'Heizen aus: kein Bedarf');
Welt::$ventil = [];
Welt::setze(IST, 6.0);
$m->MessageSink(0, IST, VM_UPDATE, [6.0, true]);
pruefe([Welt::$ventil, w('Sollabweichung')], [[true], -1.0], 'Heizen aus: Frostschutz heizt');
$m = neustart($m);
pruefe([w('Soll'), w('SollGemerkt')], [7.0, 21.0], 'Heizen aus: Neustart');
Welt::setze(IST, 21.0);
$m->MessageSink(0, IST, VM_UPDATE, [21.0, true]);
pruefe(end(Welt::$ventil), false, 'Heizen aus: Frostschutz schließt');
$m->RequestAction('Heizen', true);
pruefe(w('Soll'), 21.0, 'Heizen an: Raumwert');

// Fühlerausfall: Ventil zu, Soll bleibt
Welt::setze(IST, 15.0);
$m->MessageSink(0, IST, VM_UPDATE, [15.0, true]);
pruefe(w('Ventil') || Welt::$v[VENTIL_STATUS]['Wert'], true, 'kalt: Ventil auf');
$m->RequestAction('FuehlerAusgefallen', 0);
pruefe([Welt::$v[VENTIL_STATUS]['Wert'], w('Heizen'), w('Soll'), w('Sollabweichung')], [false, false, 21.0, 0.0], 'Fühlerausfall');
zentrale($m, 'Urlaub', true);
zentrale($m, 'Urlaub', false);
pruefe([w('Soll'), Welt::$v[VENTIL_STATUS]['Wert']], [21.0, false], 'Fühlerausfall: Soll bleibt, Ventil zu');
Welt::setze(IST, 15.0);
$m->MessageSink(0, IST, VM_UPDATE, [15.0, false]);
pruefe([Welt::$v[VENTIL_STATUS]['Wert'], w('Heizen'), w('Soll')], [true, true, 21.0], 'Fühler wieder da');

// Mit Automatik
$m = raum($bestand(false));
$m->RequestAction('Automatik', true);
pruefe([w('Soll'), w('SollGemerkt'), w('Grund')], [21.0, 21.0, 'Soll 21,0: Zeitplan Tag'], 'Automatik an: Zeitplan gilt sofort');
pruefe(Welt::$ereignis['EventActive'], true, 'Automatik an: Zeitplan aktiv');
foreach (['SollTag', 'SollNacht', 'Zeitplan'] as $i) {
    pruefe(versteckt($i), false, "Automatik an: $i sichtbar");
}
$m->RequestAction('SollTag', 20.5);
pruefe(w('Soll'), 20.5, 'Automatik: Soll Tag wirkt sofort');
$m->RequestAction('SollNacht', 17.0);
pruefe(w('Soll'), 20.5, 'Automatik: Soll Nacht am Tag ohne Wirkung');
$m->RequestAction('Soll', 23.0);
pruefe([w('Soll'), w('SollGemerkt')], [23.0, 23.0], 'Automatik: Handwert');
$m->RequestAction('SollTag', 20.0);
pruefe(w('Soll'), 23.0, 'Automatik: Handwert bleibt bei Soll Tag');
$m->RequestAction('Automatik', true);
pruefe(w('Soll'), 23.0, 'Automatik schon an: nichts');
zentrale($m, 'Urlaub', true);
pruefe(w('Soll'), 16.0, 'Automatik: Urlaub');
zentrale($m, 'Urlaub', false);
pruefe([w('Soll'), w('Grund')], [23.0, 'Ventil auf: 21,0 < 22,7'], 'Automatik: Handwert nach Urlaub');
plan(1);
pruefe(w('Soll'), 23.0, 'geänderter Zeitplan wirkt erst am Schaltpunkt');
$m->RequestAction('Zeitplan', 1);
pruefe([w('Soll'), w('SollGemerkt')], [17.0, 17.0], 'Schaltpunkt beendet den Handwert');
$m->RequestAction('Soll', 22.0);
$m = neustart($m);
pruefe([w('Soll'), w('SollGemerkt')], [17.0, 17.0], 'Automatik: Neustart setzt den Zeitplanwert');
$m->RequestAction('Soll', 22.0);
$m->ApplyChanges();
pruefe(w('Soll'), 17.0, 'Automatik: Übernehmen setzt den Zeitplanwert');

// Schaltpunkt während Urlaub, Automatik im Urlaub aus
zentrale($m, 'Urlaub', true);
plan(2);
$m->RequestAction('Zeitplan', 2);
pruefe([w('Soll'), w('SollGemerkt')], [16.0, 20.0], 'Schaltpunkt im Urlaub');
$m->RequestAction('Automatik', false);
pruefe([w('Soll'), w('SollGemerkt')], [16.0, 20.0], 'Automatik im Urlaub aus');
pruefe(Welt::$ereignis['EventActive'], false, 'Automatik aus: Zeitplan inaktiv');
foreach (['SollTag', 'SollNacht', 'Zeitplan'] as $i) {
    pruefe(versteckt($i), true, "Automatik aus: $i versteckt");
}
zentrale($m, 'Urlaub', false);
pruefe(w('Soll'), 20.0, 'nach Urlaub der letzte Raumwert');

// Handwert übersteht das Ausschalten der Automatik
$m->RequestAction('Automatik', true);
$m->RequestAction('Soll', 19.0);
$m->RequestAction('Automatik', false);
$m = neustart($m);
pruefe(w('Soll'), 19.0, 'Automatik aus: Handwert bleibt');

// Von Hand umgestellter Zeitplan wird beim Übernehmen wieder an die Automatik angeglichen
Welt::$ereignis['EventActive'] = true;
$m->ApplyChanges();
pruefe([Welt::$ereignis['EventActive'], w('Automatik'), w('Soll')], [false, false, 19.0], 'Automatik bestimmt den Zeitplan');

// Fensterverzögerung läuft nach dem Neustart weiter
Welt::variable(FENSTER, true, time() - 30);
$m = neustart($m);
pruefe($m->timer['Fenster'] >= 89000 && $m->timer['Fenster'] <= 90500, true, 'Fensterfrist läuft weiter: ' . $m->timer['Fenster']);
pruefe(w('Soll'), 19.0, 'Fenster noch in der Verzögerung');
Welt::variable(FENSTER, true, time() - 121);
$m->RequestAction('FensterPruefen', 0);
pruefe([w('Soll'), w('SollGemerkt')], [7.0, 19.0], 'Fenster nach der Frist');
Welt::variable(FENSTER, false, time());
$m = neustart($m);
pruefe([$m->timer['Fenster'], w('Soll')], [0, 19.0], 'Fenster zu: keine Frist');

// Nicht eingestellt (104): Bedienung ohne Regeln
$m = raum(null, ['IstID' => 0]);
pruefe($m->status, IS_INACTIVE, 'ohne Ist inaktiv');
$m->RequestAction('SollTag', 14.0);
$m->RequestAction('Soll', 18.0);
pruefe([w('Soll'), w('SollGemerkt'), Welt::$ventil], [18.0, 18.0, []], 'inaktiv: kein Befehl');
$m->RequestAction('Automatik', true);
pruefe(Welt::$ereignis['EventActive'], true, 'inaktiv: Automatik schaltet den Zeitplan');

// Neu: erst Soll Tag setzen, dann konfigurieren
$m = raum(fn (Raumthermostat $m) => $m->RequestAction('SollTag', 14.0));
pruefe([w('Soll'), w('SollGemerkt'), Welt::$ventil], [14.0, 14.0, []], 'neu mit Soll Tag 14: kein Ventil auf');

echo "$pruefungen Prüfungen, $fehler Fehler\n";
exit($fehler ? 1 : 0);
