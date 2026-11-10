<?php

declare(strict_types=1);

// Prüft den Wochenplan, den das Raumthermostat anlegt. Aufruf: php tests/test.php
// Nachgebaut sind nur die Symcon-Funktionen, die ZeitplanAnlegen und ZeitplanAktion brauchen.

class IPSModule
{
    public $InstanceID = 1;
}

$EREIGNIS = null;

function IPS_GetObjectIDByIdent($ident, $parent)
{
    global $EREIGNIS;
    return $EREIGNIS ? 2 : false;
}
function IPS_CreateEvent($typ)
{
    global $EREIGNIS;
    $EREIGNIS = ['ScheduleGroups' => [], 'Active' => false];
    return 2;
}
function IPS_SetParent($id, $parent) {}
function IPS_SetIdent($id, $ident) {}
function IPS_SetName($id, $name) {}
function IPS_SetPosition($id, $position) {}
function IPS_SetEventScheduleAction($id, $aktion, $name, $farbe, $skript) {}
function IPS_SetEventScheduleGroup($id, $gruppe, $tage)
{
    global $EREIGNIS;
    $EREIGNIS['ScheduleGroups'][$gruppe] = ['ID' => $gruppe, 'Days' => $tage, 'Points' => []];
}
function IPS_SetEventScheduleGroupPoint($id, $gruppe, $punkt, $h, $m, $s, $aktion)
{
    global $EREIGNIS;
    $EREIGNIS['ScheduleGroups'][$gruppe]['Points'][$punkt] =
        ['ID' => $punkt, 'ActionID' => $aktion, 'Start' => ['Hour' => $h, 'Minute' => $m, 'Second' => $s]];
}
function IPS_SetEventActive($id, $aktiv)
{
    global $EREIGNIS;
    $EREIGNIS['Active'] = $aktiv;
}
function IPS_GetEvent($id)
{
    global $EREIGNIS;
    return ['ScheduleGroups' => array_values($EREIGNIS['ScheduleGroups'])];
}

require __DIR__ . '/../Raumthermostat/module.php';

$fehler = 0;
$pruefungen = 0;
function pruefe(bool $ok, string $text): void
{
    global $fehler, $pruefungen;
    $pruefungen++;
    if (!$ok) {
        $fehler++;
        echo "FEHLER: $text\n";
    }
}

$modul = new Raumthermostat();
$anlegen = new ReflectionMethod($modul, 'ZeitplanAnlegen');
$aktion = new ReflectionMethod($modul, 'ZeitplanAktion');

$anlegen->invoke($modul);
$gruppen = $EREIGNIS['ScheduleGroups'];
pruefe($EREIGNIS['Active'] === true, 'Zeitplan aktiv');
pruefe(count($gruppen) === 7, 'sieben Gruppen');
$alle = 0;
foreach ($gruppen as $nr => $gruppe) {
    pruefe($gruppe['Days'] === 1 << $nr, "Gruppe $nr hat genau einen Tag");
    pruefe(count($gruppe['Points']) === 1, "Gruppe $nr hat einen Punkt");
    $punkt = $gruppe['Points'][0];
    pruefe($punkt['ActionID'] === 2 && $punkt['Start'] === ['Hour' => 0, 'Minute' => 0, 'Second' => 0], "Gruppe $nr: 00:00 Tag");
    $alle |= $gruppe['Days'];
}
pruefe($alle === 127, 'alle Tage abgedeckt');

// Jede Stunde einer Woche ergibt Tag
$start = mktime(0, 0, 0, 11, 9, 2026); // Montag
for ($h = 0; $h < 7 * 24; $h++) {
    pruefe($aktion->invoke($modul, $start + $h * 3600) === 2, "Stunde $h ist Tag");
}

// Ein Nachtpunkt am Dienstag gilt nur dort und bis Mittwoch 00:00
IPS_SetEventScheduleGroupPoint(2, 1, 1, 22, 0, 0, 1);
pruefe($aktion->invoke($modul, mktime(23, 0, 0, 11, 10, 2026)) === 1, 'Dienstag 23:00 Nacht');
pruefe($aktion->invoke($modul, mktime(23, 0, 0, 11, 9, 2026)) === 2, 'Montag 23:00 Tag');
pruefe($aktion->invoke($modul, mktime(1, 0, 0, 11, 11, 2026)) === 2, 'Mittwoch 01:00 Tag');

// Ein bestehender Zeitplan wird nicht angefasst
$vorher = $EREIGNIS;
$anlegen->invoke($modul);
pruefe($EREIGNIS === $vorher, 'bestehender Zeitplan bleibt');

echo "$pruefungen Prüfungen, $fehler Fehler\n";
exit($fehler ? 1 : 0);
