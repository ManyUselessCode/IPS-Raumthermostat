<?php

declare(strict_types=1);

/**
 * Ein Raumthermostat je Raum, nach dem Home-Assistant-Blueprint "Heizung | Raumcontroller".
 * Rechnet den Sollwert aus Zeitplan (Tag/Nacht), Heizphase, Urlaub und Fenstern,
 * regelt das Ventil als Zweipunktregler und überwacht den Temperaturfühler.
 * Heizphase, Frostschutz, Urlaub und Urlaubs-Soll kommen aus der Heizzentrale.
 * Der Zustand des Ventils kommt immer aus der Status-Variable, nie aus der Befehlsinstanz.
 */
class Raumthermostat extends IPSModule
{
    private const ARCHIV_MODUL = '{43192F0B-135B-4CE7-A0A7-1475603F3060}';
    private const ZENTRALE_MODUL = '{3581511F-55BB-4D47-A922-CEA2D4118A59}';

    // Darstellungen, GUIDs aus IPS_GetPresentations (Symcon 9.0)
    private const DARSTELLUNG_SCHALTER = '{60AE6B26-B3E2-BDB1-A3A1-BE232940664B}';
    private const DARSTELLUNG_REGLER = '{6B9CAEEC-5958-C223-30F7-BD36569FC57A}';
    private const DARSTELLUNG_WERT = '{3319437D-7CDE-699D-750A-3C6A3841FA75}';
    private const DARSTELLUNG_AUFZAEHLUNG = '{52D9E126-D7D2-2CBB-5E62-4CF7BA7C5D82}';

    // Aktionen des Wochenplans, IDs müssen ab 1 beginnen
    private const AKTION_NACHT = 1;
    private const AKTION_TAG = 2;
    private const AKTION_NAME = [self::AKTION_NACHT => 'Nacht', self::AKTION_TAG => 'Tag'];

    private const SOLL_MIN = 5.0;
    private const SOLL_MAX = 30.0;
    private const SOLL_SCHRITT = 0.1;
    private const FROST_VORGABE = 7.0;

    private const AUSFALL_NEIN = 0;         // Fühler meldet
    private const AUSFALL_JA = 1;           // Fühler ausgefallen, Heizen war schon aus
    private const AUSFALL_ABGESCHALTET = 2; // Fühler ausgefallen, das Modul hat Heizen abgeschaltet

    private const STATUS_FEHLER = 201;
    private const STATUS_KEINE_ZENTRALE = 202;
    private const BEFEHL_SPERRE_S = 5;      // so lange zählt eine Ventiländerung als eigene
    private const START_WARTEN_MS = 60000;  // Gateway und Bus nach dem Start abwarten
    private const ANTWORT_WARTEN_MS = 5000; // Zeit für die Antworten auf die Leseanfragen

    private const ZENTRALE_IDENTS = ['Heizphase', 'Frostschutz', 'Urlaub', 'UrlaubSoll'];

    public function Create()
    {
        parent::Create();

        $this->RegisterPropertyInteger('ZentraleID', 0);
        $this->RegisterPropertyInteger('IstID', 0);
        $this->RegisterPropertyInteger('VentilSchaltenID', 0);
        $this->RegisterPropertyInteger('VentilStatusID', 0);
        $this->RegisterPropertyFloat('Toleranz', 0.3);
        $this->RegisterPropertyInteger('FuehlerZeitgrenze', 30);
        $this->RegisterPropertyFloat('UrlaubSoll', 0);
        $this->RegisterPropertyString('Fenster', '[]');
        $this->RegisterPropertyInteger('FensterVerzoegerung', 2);

        $this->RegisterTimer('Start', 0, 'IPS_RequestAction($_IPS[\'TARGET\'], \'StartSchritt\', 0);');
        $this->RegisterTimer('Fuehler', 0, 'IPS_RequestAction($_IPS[\'TARGET\'], \'FuehlerAusgefallen\', 0);');
        $this->RegisterTimer('Fenster', 0, 'IPS_RequestAction($_IPS[\'TARGET\'], \'FensterPruefen\', 0);');

        $regler = [
            'PRESENTATION' => self::DARSTELLUNG_REGLER,
            'MIN'          => self::SOLL_MIN,
            'MAX'          => self::SOLL_MAX,
            'STEP_SIZE'    => self::SOLL_SCHRITT,
            'DIGITS'       => 1,
            'SUFFIX'       => ' °C'
        ];
        $this->RegisterVariableFloat('Soll', 'Soll', $regler + ['ICON' => 'temperature-half'], 1);
        $this->RegisterVariableFloat('Ist', 'Ist', [
            'PRESENTATION' => self::DARSTELLUNG_WERT,
            'DIGITS'       => 1,
            'SUFFIX'       => ' °C',
            'ICON'         => 'thermometer'
        ], 2);
        $this->RegisterVariableBoolean('Heizen', 'Heizen', [
            'PRESENTATION'   => self::DARSTELLUNG_SCHALTER,
            'ICON_TRUE'      => 'fire',
            'ICON_FALSE'     => 'fire',
            'USE_ICON_FALSE' => false,
            'USAGE_TYPE'     => 0
        ], 3);
        $this->RegisterVariableBoolean('Ventil', 'Ventil', [
            'PRESENTATION' => self::DARSTELLUNG_AUFZAEHLUNG,
            'OPTIONS'      => json_encode([
                ['Value' => false, 'Caption' => 'zu', 'IconActive' => true, 'IconValue' => 'minus'],
                ['Value' => true, 'Caption' => 'offen', 'IconActive' => true, 'IconValue' => 'fire']
            ])
        ], 4);
        $this->RegisterVariableFloat('SollTag', 'Soll Tag', $regler + ['ICON' => 'sun'], 5);
        $this->RegisterVariableFloat('SollNacht', 'Soll Nacht', $regler + ['ICON' => 'moon'], 6);
        $this->RegisterVariableString('Grund', 'Grund', '', 7);
        $this->RegisterVariableInteger('Fuehlerausfall', 'Fühlerausfall', '', 8);
        foreach (['Soll', 'Heizen', 'SollTag', 'SollNacht'] as $ident) {
            $this->EnableAction($ident);
        }
    }

    public function ApplyChanges()
    {
        parent::ApplyChanges();

        foreach ($this->GetMessageList() as $sender => $nachrichten) {
            foreach ($nachrichten as $nachricht) {
                $this->UnregisterMessage($sender, $nachricht);
            }
        }
        foreach ($this->GetReferenceList() as $referenz) {
            $this->UnregisterReference($referenz);
        }
        $this->RegisterMessage(0, IPS_KERNELMESSAGE);

        if (IPS_GetKernelRunlevel() == KR_READY) {
            $this->Einrichten();
        }
    }

    public function MessageSink($TimeStamp, $SenderID, $Message, $Data)
    {
        if ($Message == IPS_KERNELMESSAGE) {
            if ($Data[0] == KR_READY) {
                $this->Einrichten(true);
                $this->SetBuffer('StartSchritt', 'Lesen');
                $this->SetTimerInterval('Start', self::START_WARTEN_MS);
            }
            return;
        }
        if ($Message != VM_UPDATE || $this->GetStatus() != IS_ACTIVE) {
            return;
        }
        if ($SenderID == $this->ReadPropertyInteger('IstID')) {
            $this->IstGemeldet((float) $Data[0]); // jeder Messwert zählt, auch ein unveränderter
            return;
        }
        if (!$Data[1]) {
            return; // alles andere nur, wenn sich der Wert geändert hat
        }
        if ($SenderID == $this->ReadPropertyInteger('VentilStatusID')) {
            $this->VentilGemeldet((bool) $Data[0]);
            return;
        }
        foreach ($this->Liste('Fenster') as $fenster) {
            if ($fenster['VariableID'] == $SenderID) {
                $this->FensterGeaendert((bool) $Data[0]);
                return;
            }
        }
        foreach (self::ZENTRALE_IDENTS as $ident) {
            if ($SenderID == $this->ZentraleVariable($ident)) {
                $this->SollBerechnen('Zentrale ' . $ident);
                return;
            }
        }
    }

    public function RequestAction($Ident, $Value)
    {
        switch ($Ident) {
            case 'Soll':
                $this->SollSetzen((float) $Value, 'App');
                break;
            case 'Heizen':
                $this->HeizenSetzen((bool) $Value, 'App');
                break;
            case 'SollTag':
            case 'SollNacht':
                $this->SetValue($Ident, $this->Begrenzen((float) $Value));
                $this->SollBerechnen($Ident . ' geändert');
                break;
            case 'Zeitplan':
                $aktion = (int) $Value;
                $this->SollBerechnen('Zeitplan ' . (self::AKTION_NAME[$aktion] ?? (string) $aktion));
                break;
            case 'StartSchritt':
                $this->StartSchritt();
                break;
            case 'FuehlerAusgefallen':
                $this->FuehlerAusgefallen();
                break;
            case 'FensterPruefen':
                $this->SetTimerInterval('Fenster', 0);
                $this->SollBerechnen('Fenster');
                break;
            default:
                throw new Exception('Unbekannter Ident: ' . $Ident);
        }
    }

    /**
     * Setzt den Sollwert aus einem Skript. Er gilt wie ein Soll aus der App bis zum
     * nächsten Schaltpunkt oder Zustandswechsel.
     */
    public function SetzeSoll(float $Soll, string $Grund): void
    {
        $this->SollSetzen($Soll, $Grund);
    }

    /**
     * Rechnet Soll und Ventil neu, zum Beispiel nach Änderungen am Zeitplan.
     */
    public function Aktualisieren(): void
    {
        $this->SollBerechnen('Aktualisieren');
    }

    /**
     * Beim Start ($start) wartet das Modul mit Rechnen und Schalten auf die vom Bus
     * gelesenen Werte in StartSchritt, statt mit dem alten Stand zu schalten.
     */
    private function Einrichten(bool $start = false): void
    {
        $this->ZeitplanAnlegen();
        $this->AufzeichnungEinschalten();

        $ist = $this->ReadPropertyInteger('IstID');
        $schalten = $this->ReadPropertyInteger('VentilSchaltenID');
        $status = $this->ReadPropertyInteger('VentilStatusID');
        if ($ist == 0 || $schalten == 0 || $status == 0) {
            $this->TimerStoppen();
            $this->SetStatus(IS_INACTIVE);
            return;
        }
        $zentrale = $this->ReadPropertyInteger('ZentraleID');
        if (!$this->IstZentrale($zentrale)) {
            $this->TimerStoppen();
            $this->SetStatus(self::STATUS_KEINE_ZENTRALE);
            return;
        }
        $fehler = $this->Pruefen();
        if ($fehler != '') {
            $this->LogMessage($fehler, KL_ERROR);
            $this->TimerStoppen();
            $this->SetStatus(self::STATUS_FEHLER);
            return;
        }

        $this->RegisterMessage($ist, VM_UPDATE);
        $this->RegisterMessage($status, VM_UPDATE);
        $this->RegisterReference($ist);
        $this->RegisterReference($schalten);
        $this->RegisterReference($status);
        $this->RegisterReference($zentrale);
        foreach (self::ZENTRALE_IDENTS as $ident) {
            $this->RegisterMessage($this->ZentraleVariable($ident), VM_UPDATE);
        }
        foreach ($this->Liste('Fenster') as $fenster) {
            $this->RegisterMessage($fenster['VariableID'], VM_UPDATE);
            $this->RegisterReference($fenster['VariableID']);
        }

        // Vorgaben nur beim ersten Mal, danach gilt, was in der App eingestellt wurde
        $this->Vorgabe('SollTag', 21.0);
        $this->Vorgabe('SollNacht', 18.0);
        $this->Vorgabe('Heizen', true);
        IPS_SetHidden($this->GetIDForIdent('Grund'), true);
        IPS_SetHidden($this->GetIDForIdent('Fuehlerausfall'), true);

        $this->SetStatus(IS_ACTIVE);
        if (!$start) {
            $this->ZustandZeigen();
            $this->FuehlerTimerStarten();
            $this->SollBerechnen('Einstellungen übernommen');
        }
    }

    /**
     * Schaltet die Aufzeichnung im Archiv für alle Variablen der Instanz ein.
     * Was schon aufgezeichnet wird, bleibt unverändert. Ausgeschaltet wird nie.
     */
    private function AufzeichnungEinschalten(): void
    {
        $archive = IPS_GetInstanceListByModuleID(self::ARCHIV_MODUL);
        if (count($archive) == 0) {
            return;
        }
        $archiv = $archive[0];
        $geaendert = false;
        foreach (IPS_GetChildrenIDs($this->InstanceID) as $kind) {
            if (!IPS_VariableExists($kind) || AC_GetLoggingStatus($archiv, $kind)) {
                continue;
            }
            AC_SetLoggingStatus($archiv, $kind, true);
            $geaendert = true;
        }
        if ($geaendert) {
            IPS_ApplyChanges($archiv);
        }
    }

    private function Pruefen(): string
    {
        $falsch = [];
        if (!IPS_VariableExists($this->ReadPropertyInteger('IstID'))) {
            $falsch[] = 'Ist-Temperatur ' . $this->ReadPropertyInteger('IstID');
        }
        if (!IPS_InstanceExists($this->ReadPropertyInteger('VentilSchaltenID'))) {
            $falsch[] = 'Ventil Schalten ' . $this->ReadPropertyInteger('VentilSchaltenID');
        }
        if (!IPS_VariableExists($this->ReadPropertyInteger('VentilStatusID'))) {
            $falsch[] = 'Ventil Status ' . $this->ReadPropertyInteger('VentilStatusID');
        }
        foreach ($this->Liste('Fenster') as $fenster) {
            if (!IPS_VariableExists($fenster['VariableID'])) {
                $falsch[] = 'Fenster ' . $fenster['VariableID'];
            }
        }
        foreach (self::ZENTRALE_IDENTS as $ident) {
            if ($this->ZentraleVariable($ident) == 0) {
                $falsch[] = 'Zentrale ohne ' . $ident;
            }
        }
        return count($falsch) ? 'Ungültige IDs: ' . implode(', ', $falsch) : '';
    }

    /**
     * Legt den Wochenplan "Zeitplan" unter der Instanz an, mit den Aktionen Nacht und Tag.
     * Vorgabe: rund um die Uhr Tag. Jeder Wochentag hat eine eigene Gruppe, damit er sich in
     * App und WebFront einzeln ändern lässt. Jeder Schaltpunkt ruft RequestAction('Zeitplan') auf.
     */
    private function ZeitplanAnlegen(): void
    {
        if (@IPS_GetObjectIDByIdent('Zeitplan', $this->InstanceID) !== false) {
            return;
        }
        $skript = 'IPS_RequestAction($_IPS[\'TARGET\'], \'Zeitplan\', $_IPS[\'ACTION\']);';
        $id = IPS_CreateEvent(2); // 2 = Wochenplan
        IPS_SetParent($id, $this->InstanceID);
        IPS_SetIdent($id, 'Zeitplan');
        IPS_SetName($id, 'Zeitplan');
        IPS_SetPosition($id, 9);
        IPS_SetEventScheduleAction($id, self::AKTION_NACHT, 'Nacht', 0x3F51B5, $skript);
        IPS_SetEventScheduleAction($id, self::AKTION_TAG, 'Tag', 0xFF9800, $skript);
        for ($tag = 0; $tag < 7; $tag++) { // Gruppe 0 = Montag (Bit 1) bis 6 = Sonntag (Bit 64)
            IPS_SetEventScheduleGroup($id, $tag, 1 << $tag);
            IPS_SetEventScheduleGroupPoint($id, $tag, 0, 0, 0, 0, self::AKTION_TAG); // 00:00 Tag, also rund um die Uhr
        }
        IPS_SetEventActive($id, true);
    }

    /**
     * Aktion des Wochenplans zur angegebenen Zeit, berechnet aus den Schaltpunkten:
     * Es gilt der letzte Schaltpunkt vor der Zeit, notfalls der letzte eines Vortags.
     */
    private function ZeitplanAktion(int $zeit): int
    {
        $id = @IPS_GetObjectIDByIdent('Zeitplan', $this->InstanceID);
        if ($id === false) {
            return self::AKTION_TAG;
        }
        $gruppen = IPS_GetEvent($id)['ScheduleGroups'];
        for ($zurueck = 0; $zurueck < 8; $zurueck++) {
            $tag = $zeit - $zurueck * 86400;
            $bit = 1 << ((int) date('N', $tag) - 1); // Montag = Bit 0
            $grenze = $zurueck == 0
                ? (int) date('G', $zeit) * 3600 + (int) date('i', $zeit) * 60 + (int) date('s', $zeit)
                : 86400;
            $beste = -1;
            $aktion = 0;
            foreach ($gruppen as $gruppe) {
                if (!($gruppe['Days'] & $bit)) {
                    continue;
                }
                foreach ($gruppe['Points'] as $punkt) {
                    $sekunde = $punkt['Start']['Hour'] * 3600 + $punkt['Start']['Minute'] * 60 + $punkt['Start']['Second'];
                    if ($sekunde <= $grenze && $sekunde > $beste) {
                        $beste = $sekunde;
                        $aktion = $punkt['ActionID'];
                    }
                }
            }
            if (isset(self::AKTION_NAME[$aktion])) {
                return $aktion;
            }
        }
        return self::AKTION_TAG;
    }

    /**
     * Rechnet den Sollwert in der Reihenfolge des Blueprints: Fenster offen, Heizphase aus,
     * Urlaub, sonst Zeitplan. Nie unter Frostschutz. Danach wird geregelt.
     */
    private function SollBerechnen(string $ausloeser): void
    {
        if ($this->GetStatus() != IS_ACTIVE) {
            return;
        }
        $frost = (float) $this->ZentraleWert('Frostschutz', self::FROST_VORGABE);
        if ($this->FensterOffen()) {
            $ziel = $frost;
            $grund = 'Fenster offen';
        } elseif (!$this->ZentraleWert('Heizphase', true)) {
            $ziel = $frost;
            $grund = 'Heizphase aus';
        } elseif ($this->ZentraleWert('Urlaub', false)) {
            $eigen = $this->ReadPropertyFloat('UrlaubSoll');
            $ziel = $eigen > 0 ? $eigen : (float) $this->ZentraleWert('UrlaubSoll', $frost);
            $grund = 'Urlaub';
        } else {
            $aktion = $this->ZeitplanAktion(time());
            $ziel = $this->GetValue($aktion == self::AKTION_NACHT ? 'SollNacht' : 'SollTag');
            $grund = 'Zeitplan ' . self::AKTION_NAME[$aktion];
        }
        $ziel = $this->Begrenzen(max((float) $ziel, $frost));
        $this->SendDebug('Soll', $ausloeser . ': ' . $this->Temperatur($ziel) . ' (' . $grund . ')', 0);
        if (abs($ziel - $this->GetValue('Soll')) >= self::SOLL_SCHRITT / 2) {
            $this->SetValue('Soll', $ziel);
            $this->Grund('Soll ' . $this->Temperatur($ziel) . ': ' . $grund);
        }
        $this->Regeln();
    }

    /**
     * Zweipunktregler: Ventil auf, wenn Ist unter Soll minus Toleranz fällt, zu, wenn Ist
     * über Soll plus Toleranz steigt. Ein Befehl geht nur, wenn der Status abweicht.
     */
    private function Regeln(): void
    {
        if ($this->GetStatus() != IS_ACTIVE) {
            return;
        }
        $offen = $this->VentilOffen();
        if (!$this->GetValue('Heizen')) {
            if ($offen) {
                $this->VentilSchalten(false, 'Ventil zu: Heizen aus');
            }
            return;
        }
        if ($this->GetValue('Fuehlerausfall') != self::AUSFALL_NEIN) {
            if ($offen) {
                $this->VentilSchalten(false, 'Ventil zu: Fühler ausgefallen');
            }
            return;
        }
        $ist = (float) GetValue($this->ReadPropertyInteger('IstID'));
        $soll = (float) $this->GetValue('Soll');
        $toleranz = $this->ReadPropertyFloat('Toleranz');
        if ($ist < $soll - $toleranz && !$offen) {
            $this->VentilSchalten(true, 'Ventil auf: ' . $this->Temperatur($ist) . ' < ' . $this->Temperatur($soll - $toleranz));
        } elseif ($ist > $soll + $toleranz && $offen) {
            $this->VentilSchalten(false, 'Ventil zu: ' . $this->Temperatur($ist) . ' > ' . $this->Temperatur($soll + $toleranz));
        }
    }

    private function SollSetzen(float $soll, string $ausloeser): void
    {
        $soll = $this->Begrenzen($soll);
        $this->SetValue('Soll', $soll);
        $this->Grund('Soll ' . $this->Temperatur($soll) . ': ' . $ausloeser);
        $this->Regeln();
    }

    private function HeizenSetzen(bool $an, string $ausloeser): void
    {
        $this->SetValue('Heizen', $an);
        if ($an && $this->GetValue('Fuehlerausfall') == self::AUSFALL_ABGESCHALTET) {
            // von Hand wieder an: dann nicht noch einmal automatisch einschalten
            $this->SetValue('Fuehlerausfall', self::AUSFALL_JA);
        }
        $this->Grund('Heizen ' . ($an ? 'an' : 'aus') . ': ' . $ausloeser);
        $this->Regeln();
    }

    private function IstGemeldet(float $wert): void
    {
        $this->SetValue('Ist', $wert);
        if ($this->GetValue('Fuehlerausfall') != self::AUSFALL_NEIN) {
            $this->FuehlerErholt();
        }
        $this->FuehlerTimerStarten();
        $this->Regeln();
    }

    private function VentilGemeldet(bool $offen): void
    {
        $this->SetValue('Ventil', $offen);
        if (time() - (int) $this->GetBuffer('Befehl') > self::BEFEHL_SPERRE_S) {
            $this->Grund('Ventil ' . ($offen ? 'offen' : 'zu') . ': von Extern');
        }
    }

    private function FensterGeaendert(bool $offen): void
    {
        $verzoegerung = $this->ReadPropertyInteger('FensterVerzoegerung') * 60;
        if ($offen && $verzoegerung > 0) {
            // erst nach der Verzögerung zählt das Fenster als offen
            $this->SetTimerInterval('Fenster', $verzoegerung * 1000 + 500);
            return;
        }
        $this->SollBerechnen('Fenster ' . ($offen ? 'offen' : 'zu'));
    }

    private function FensterOffen(): bool
    {
        $verzoegerung = $this->ReadPropertyInteger('FensterVerzoegerung') * 60;
        foreach ($this->Liste('Fenster') as $fenster) {
            $id = $fenster['VariableID'];
            if (IPS_VariableExists($id) && GetValueBoolean($id)
                && time() - IPS_GetVariable($id)['VariableChanged'] >= $verzoegerung) {
                return true;
            }
        }
        return false;
    }

    /**
     * Startet die Frist für den Fühler neu, gerechnet ab dem letzten Messwert.
     * So gilt die Frist auch über einen Neustart hinweg.
     */
    private function FuehlerTimerStarten(): void
    {
        $zuletzt = IPS_GetVariable($this->ReadPropertyInteger('IstID'))['VariableUpdated'];
        $rest = $this->ReadPropertyInteger('FuehlerZeitgrenze') * 60 - (time() - $zuletzt);
        if ($rest <= 0) {
            $this->FuehlerAusgefallen();
        } else {
            $this->SetTimerInterval('Fuehler', $rest * 1000);
        }
    }

    private function FuehlerAusgefallen(): void
    {
        $this->SetTimerInterval('Fuehler', 0);
        if ($this->GetValue('Fuehlerausfall') != self::AUSFALL_NEIN) {
            return; // schon gemeldet
        }
        $raum = $this->Raumname();
        if ($this->GetValue('Heizen')) {
            $this->SetValue('Fuehlerausfall', self::AUSFALL_ABGESCHALTET);
            $this->SetValue('Heizen', false);
            $this->Grund('Heizen aus: Fühler ausgefallen');
            $this->Push('Der Temperaturfühler im Raum ' . $raum . ' ist ausgefallen – Heizen aus, Ventil zu.');
        } else {
            $this->SetValue('Fuehlerausfall', self::AUSFALL_JA);
            $this->Grund('Fühler ausgefallen');
            $this->Push('Der Temperaturfühler im Raum ' . $raum . ' ist ausgefallen.');
        }
        if ($this->VentilOffen()) {
            $this->VentilSchalten(false, 'Ventil zu: Fühler ausgefallen');
        }
    }

    private function FuehlerErholt(): void
    {
        $abgeschaltet = $this->GetValue('Fuehlerausfall') == self::AUSFALL_ABGESCHALTET;
        $this->SetValue('Fuehlerausfall', self::AUSFALL_NEIN);
        $raum = $this->Raumname();
        if ($abgeschaltet && !$this->GetValue('Heizen')) {
            $this->SetValue('Heizen', true);
            $this->Grund('Heizen an: Fühler meldet wieder');
            $this->Push('Der Temperaturfühler im Raum ' . $raum . ' meldet wieder – Heizen an.');
        } else {
            $this->Grund('Fühler meldet wieder');
            $this->Push('Der Temperaturfühler im Raum ' . $raum . ' meldet wieder.');
        }
    }

    private function VentilSchalten(bool $offen, string $grund): void
    {
        $this->SetBuffer('Befehl', (string) time());
        $this->Grund($grund);
        if (!@KNX_WriteDPT1($this->ReadPropertyInteger('VentilSchaltenID'), $offen)) {
            $this->LogMessage('Ventil schalten fehlgeschlagen: ' . $this->ReadPropertyInteger('VentilSchaltenID'), KL_ERROR);
        }
    }

    private function StartSchritt(): void
    {
        if ($this->GetBuffer('StartSchritt') == 'Lesen') {
            $this->SetBuffer('StartSchritt', 'Pruefen');
            $this->SetTimerInterval('Start', self::ANTWORT_WARTEN_MS);
            if ($this->GetStatus() == IS_ACTIVE) {
                $this->StatusAnfragen($this->ReadPropertyInteger('VentilStatusID'));
                $this->StatusAnfragen($this->ReadPropertyInteger('IstID'));
            }
            return;
        }

        $this->SetTimerInterval('Start', 0);
        $this->SetBuffer('StartSchritt', '');
        if ($this->GetStatus() != IS_ACTIVE) {
            return;
        }
        $this->ZustandZeigen();
        $this->FuehlerTimerStarten();
        $this->SollBerechnen('Start');
    }

    private function StatusAnfragen(int $variable): void
    {
        $instanz = IPS_GetParent($variable);
        if (IPS_InstanceExists($instanz) && function_exists('KNX_RequestStatus')) {
            @KNX_RequestStatus($instanz);
            IPS_Sleep(200);
        }
    }

    private function ZustandZeigen(): void
    {
        $this->SetValue('Ist', (float) GetValue($this->ReadPropertyInteger('IstID')));
        $this->SetValue('Ventil', $this->VentilOffen());
    }

    private function Push(string $text): void
    {
        $zentrale = $this->ReadPropertyInteger('ZentraleID');
        if ($this->IstZentrale($zentrale)) {
            @HEIZZENTRALE_Push($zentrale, 'Heizung', $text);
        }
        $this->LogMessage($text, KL_NOTIFY);
    }

    /**
     * Name des Raums für Nachrichten: der Raumordner über dem Ordner "Logik", auch
     * wenn die Instanz in einem Unterordner von "Logik" liegt. Ohne "Logik" der
     * Ordner über der Instanz.
     */
    private function Raumname(): string
    {
        $id = IPS_GetParent($this->InstanceID);
        while ($id > 0) {
            if (IPS_GetName($id) == 'Logik') {
                $raum = IPS_GetParent($id);
                return IPS_GetName($raum > 0 ? $raum : $id);
            }
            $id = IPS_GetParent($id);
        }
        $eltern = IPS_GetParent($this->InstanceID);
        return IPS_GetName($eltern > 0 ? $eltern : $this->InstanceID);
    }

    private function IstZentrale(int $id): bool
    {
        return $id > 0 && IPS_InstanceExists($id)
            && IPS_GetInstance($id)['ModuleInfo']['ModuleID'] == self::ZENTRALE_MODUL;
    }

    private function ZentraleVariable(string $ident): int
    {
        $zentrale = $this->ReadPropertyInteger('ZentraleID');
        if ($zentrale == 0) {
            return 0;
        }
        $id = @IPS_GetObjectIDByIdent($ident, $zentrale);
        return $id === false ? 0 : $id;
    }

    private function ZentraleWert(string $ident, $vorgabe)
    {
        $id = $this->ZentraleVariable($ident);
        return $id > 0 ? GetValue($id) : $vorgabe;
    }

    private function VentilOffen(): bool
    {
        $id = $this->ReadPropertyInteger('VentilStatusID');
        return IPS_VariableExists($id) && GetValueBoolean($id);
    }

    private function Grund(string $text): void
    {
        $this->SetValue('Grund', $text);
    }

    private function Vorgabe(string $ident, $wert): void
    {
        $id = $this->GetIDForIdent($ident);
        if (IPS_GetVariable($id)['VariableUpdated'] == 0) {
            $this->SetValue($ident, $wert);
        }
    }

    private function TimerStoppen(): void
    {
        $this->SetTimerInterval('Fuehler', 0);
        $this->SetTimerInterval('Fenster', 0);
    }

    private function Begrenzen(float $wert): float
    {
        $wert = min(max($wert, self::SOLL_MIN), self::SOLL_MAX);
        return round(round($wert / self::SOLL_SCHRITT) * self::SOLL_SCHRITT, 1);
    }

    private function Temperatur(float $wert): string
    {
        return number_format($wert, 1, ',', '');
    }

    private function Liste(string $name): array
    {
        return json_decode($this->ReadPropertyString($name), true) ?: [];
    }
}
