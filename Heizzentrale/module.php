<?php

declare(strict_types=1);

/**
 * Gemeinsame Werte der Heizung für alle Raumthermostate: Heizphase, Frostschutz,
 * Urlaub und Urlaubs-Soll. Eine Instanz je Wohnung. Jede Änderung wirkt sofort
 * in allen Raumthermostaten, die diese Zentrale eingestellt haben.
 * Schickt außerdem Push-Nachrichten über die Kachel-Visualisierung, wenn ein
 * Raumthermostat darum bittet (zum Beispiel bei Fühlerausfall).
 */
class Heizzentrale extends IPSModule
{
    // Darstellungen, GUIDs aus IPS_GetPresentations (Symcon 9.0)
    private const DARSTELLUNG_SCHALTER = '{60AE6B26-B3E2-BDB1-A3A1-BE232940664B}';
    private const DARSTELLUNG_REGLER = '{6B9CAEEC-5958-C223-30F7-BD36569FC57A}';

    private const STATUS_FEHLER = 201;

    public function Create()
    {
        parent::Create();

        $this->RegisterPropertyInteger('VisualisierungID', 0);

        $this->RegisterVariableBoolean('Heizphase', 'Heizphase', [
            'PRESENTATION'   => self::DARSTELLUNG_SCHALTER,
            'ICON_TRUE'      => 'fire',
            'ICON_FALSE'     => 'fire',
            'USE_ICON_FALSE' => false,
            'USAGE_TYPE'     => 0
        ], 1);
        $this->RegisterVariableFloat('Frostschutz', 'Frostschutz', [
            'PRESENTATION' => self::DARSTELLUNG_REGLER,
            'MIN'          => 5,
            'MAX'          => 15,
            'STEP_SIZE'    => 0.5,
            'DIGITS'       => 1,
            'SUFFIX'       => ' °C',
            'ICON'         => 'snowflake'
        ], 2);
        $this->RegisterVariableBoolean('Urlaub', 'Urlaub', [
            'PRESENTATION'   => self::DARSTELLUNG_SCHALTER,
            'ICON_TRUE'      => 'umbrella-beach',
            'ICON_FALSE'     => 'umbrella-beach',
            'USE_ICON_FALSE' => false,
            'USAGE_TYPE'     => 0
        ], 3);
        $this->RegisterVariableFloat('UrlaubSoll', 'Urlaubs-Soll', [
            'PRESENTATION' => self::DARSTELLUNG_REGLER,
            'MIN'          => 10,
            'MAX'          => 22,
            'STEP_SIZE'    => 0.5,
            'DIGITS'       => 1,
            'SUFFIX'       => ' °C',
            'ICON'         => 'temperature-low'
        ], 4);
        foreach (['Heizphase', 'Frostschutz', 'Urlaub', 'UrlaubSoll'] as $ident) {
            $this->EnableAction($ident);
        }
    }

    public function ApplyChanges()
    {
        parent::ApplyChanges();

        foreach ($this->GetReferenceList() as $referenz) {
            $this->UnregisterReference($referenz);
        }

        // Vorgaben nur beim ersten Mal, danach gilt, was Sven eingestellt hat
        $this->Vorgabe('Heizphase', true);
        $this->Vorgabe('Frostschutz', 7.0);
        $this->Vorgabe('UrlaubSoll', 16.0);

        $visu = $this->ReadPropertyInteger('VisualisierungID');
        if ($visu > 0) {
            if (!IPS_InstanceExists($visu)) {
                $this->LogMessage('Visualisierung ' . $visu . ' gibt es nicht', KL_ERROR);
                $this->SetStatus(self::STATUS_FEHLER);
                return;
            }
            $this->RegisterReference($visu);
        }
        $this->SetStatus(IS_ACTIVE);
    }

    public function RequestAction($Ident, $Value)
    {
        switch ($Ident) {
            case 'Heizphase':
            case 'Urlaub':
                $this->SetValue($Ident, (bool) $Value);
                break;
            case 'Frostschutz':
                $this->SetValue($Ident, $this->Begrenzen((float) $Value, 5, 15));
                break;
            case 'UrlaubSoll':
                $this->SetValue($Ident, $this->Begrenzen((float) $Value, 10, 22));
                break;
            default:
                throw new Exception('Unbekannter Ident: ' . $Ident);
        }
    }

    /**
     * Schickt eine Push-Nachricht über die eingestellte Kachel-Visualisierung.
     * Ohne Visualisierung landet die Nachricht nur im Log.
     */
    public function Push(string $Titel, string $Text): bool
    {
        $visu = $this->ReadPropertyInteger('VisualisierungID');
        if ($visu == 0 || !IPS_InstanceExists($visu)) {
            $this->LogMessage('Keine Visualisierung für Push: ' . $Titel . ' – ' . $Text, KL_WARNING);
            return false;
        }
        try {
            VISU_PostNotificationEx($visu, $Titel, $Text, 'fire', '', 0);
            return true;
        } catch (Throwable $fehler) {
            $this->LogMessage('Push fehlgeschlagen: ' . $fehler->getMessage(), KL_ERROR);
            return false;
        }
    }

    private function Vorgabe(string $ident, $wert): void
    {
        $id = $this->GetIDForIdent($ident);
        if (IPS_GetVariable($id)['VariableUpdated'] == 0) {
            $this->SetValue($ident, $wert);
        }
    }

    private function Begrenzen(float $wert, float $min, float $max): float
    {
        return round(min(max($wert, $min), $max) * 2) / 2; // halbe Grad
    }
}
