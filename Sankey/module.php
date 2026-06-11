<?php

declare(strict_types=1);

class Sankey extends IPSModule
{
    public function Create(): void
    {
        parent::Create();

        $this->RegisterPropertyInteger('ColorLabel', 0x1e293b);
        $this->RegisterPropertyBoolean('ShowValues', false);
        $this->RegisterPropertyBoolean('StaticMode', false);
        $this->RegisterPropertyString('Links', '[]');

        $this->RegisterVariableInteger('StartDate', 'Startdatum', '~UnixTimestamp', 10);
        $this->RegisterVariableInteger('EndDate',   'Enddatum',   '~UnixTimestamp', 11);
        $this->EnableAction('StartDate');
        $this->EnableAction('EndDate');
        $this->EnableAction('ToggleMode');

        $this->SetVisualizationType(1);
    }

    public function ApplyChanges(): void
    {
        parent::ApplyChanges();

        $this->SetVisualizationType(1);

        $staticMode = $this->ReadPropertyBoolean('StaticMode');

        // Datumsvariablen nur im statischen Modus sichtbar und bedienbar
        IPS_SetHidden($this->GetIDForIdent('StartDate'),   !$staticMode);
        IPS_SetHidden($this->GetIDForIdent('EndDate'),     !$staticMode);
        IPS_SetDisabled($this->GetIDForIdent('StartDate'), !$staticMode);
        IPS_SetDisabled($this->GetIDForIdent('EndDate'),   !$staticMode);

        // Standardzeitraum setzen wenn noch kein Wert vorhanden
        if ($staticMode && GetValue($this->GetIDForIdent('StartDate')) === 0) {
            $now = time();
            SetValue($this->GetIDForIdent('StartDate'), mktime(0, 0, 0, (int) date('n', $now), 1, (int) date('Y', $now)));
            SetValue($this->GetIDForIdent('EndDate'), $now);
        }

        // Alle bisherigen Nachrichten abmelden
        foreach ($this->GetMessageList() as $senderID => $messages) {
            foreach ($messages as $message) {
                $this->UnregisterMessage($senderID, $message);
            }
        }

        // Im Archivmodus entscheidet MessageSink(), ob der gewählte Zeitraum aktualisiert werden muss.
        $links = json_decode($this->ReadPropertyString('Links'), true) ?? [];

        foreach ($links as $link) {
            $varID = intval($link['VariableID'] ?? 0);

            if ($varID > 0 && IPS_VariableExists($varID)) {
                $this->RegisterMessage($varID, VM_UPDATE);
            }

            $infoVarID = intval($link['InfoVariableID'] ?? 0);

            if ($infoVarID > 0 && IPS_VariableExists($infoVarID)) {
                $this->RegisterMessage($infoVarID, VM_UPDATE);
            }
        }

        $this->UpdateVisualizationValue(json_encode($this->CollectData(), JSON_UNESCAPED_UNICODE));
    }

    public function MessageSink($TimeStamp, $SenderID, $Message, $Data): void
    {
        if ($Message !== VM_UPDATE) {
            return;
        }

        if (!$this->ReadPropertyBoolean('StaticMode')) {
            $this->UpdateDiagram();
            return;
        }

        if ($this->IsCurrentTimeInsideSelectedRange()) {
            $this->UpdateDiagram();
        }
    }

    public function RequestAction($Ident, $Value): void
    {
        if ($Ident === 'StartDate' || $Ident === 'EndDate') {
            SetValue($this->GetIDForIdent($Ident), $Value);
            $this->UpdateDiagram();
            return;
        }

        if ($Ident === 'ToggleMode') {
            $newMode = intval($Value) === 1;

            IPS_SetProperty($this->InstanceID, 'StaticMode', $newMode);
            IPS_ApplyChanges($this->InstanceID);

            return;
        }
    }

    public function UpdateDiagram(): void
    {
        $this->UpdateVisualizationValue(json_encode($this->CollectData(), JSON_UNESCAPED_UNICODE));
    }

    public function GetVisualizationTile()
    {
        return $this->GenerateHTML();
    }

    // --- private ---

    private function IsCurrentTimeInsideSelectedRange(): bool
    {
        $startTime = (int) GetValue($this->GetIDForIdent('StartDate'));
        $endTime   = (int) GetValue($this->GetIDForIdent('EndDate'));
        $now       = time();

        if ($startTime <= 0 || $endTime <= 0) {
            return false;
        }

        return $startTime <= $now && $endTime >= $now;
    }

    private function GetVariableUnit(int $varID): string
    {
        // Neue Darstellungen (Symcon 6.x+) haben Vorrang
        if (function_exists('IPS_GetVariablePresentation')) {
            $pres = IPS_GetVariablePresentation($varID);
            if (!empty($pres['SUFFIX'])) {
                return $pres['SUFFIX'];
            }
        }

        // Klassisches Profil-System
        $varInfo     = IPS_GetVariable($varID);
        $profileName = $varInfo['VariableCustomProfile'] !== ''
            ? $varInfo['VariableCustomProfile']
            : $varInfo['VariableProfile'];

        if ($profileName === '' || !IPS_VariableProfileExists($profileName)) {
            return '';
        }
        return IPS_GetVariableProfile($profileName)['Suffix'];
    }


    private function GetAggregationLevel(int $startTime, int $endTime): int
    {
        $diff = $endTime - $startTime;

        if ($diff <= 2 * 86400)         return 0; // bis 2 Tage   → stündlich
        if ($diff <= 28 * 86400)        return 1; // bis 4 Wochen → täglich
        if ($diff <= 180 * 86400)       return 2; // bis 6 Monate → wöchentlich
        if ($diff <= 3 * 365 * 86400)   return 3; // bis 3 Jahre  → monatlich
        return 4;                                  // über 3 Jahre → jährlich
    }

    private function GetValueFromArchive(int $varID, int $startTime, int $endTime): float
    {
        $archiveID = IPS_GetInstanceListByModuleID('{43192F0B-135B-4CE7-A0A7-1475603F3060}')[0] ?? 0;

        if ($archiveID === 0) {
            return 0.0;
        }

        if (!AC_GetLoggingStatus($archiveID, $varID)) {
            return 0.0;
        }

        $isCounter = boolval(AC_GetAggregationType($archiveID, $varID));

        $level = $this->GetAggregationLevel($startTime, $endTime);

        $agg = AC_GetAggregatedValues(
            $archiveID,
            $varID,
            $level,
            $startTime,
            $endTime,
            0
        );

        if (empty($agg)) {

            $name = IPS_GetName($varID);

            $this->LogMessage(
                "Sankey: Keine Archivdaten für Variable \"$name\" ($varID) im Zeitraum "
                . date('d.m.Y', $startTime) . ' – ' . date('d.m.Y', $endTime) . '.',
                KL_WARNING
            );

            return 0.0;
        }

        $sum = 0.0;

        foreach ($agg as $a) {
            $sum += floatval($a['Avg']);
        }

        // Zähler:
        // Summe aller Intervallwerte
        if ($isCounter) {
            return $sum;
        }

        // Nicht-Zähler:
        // normaler Durchschnitt MIT Vorzeichen
        return $sum / count($agg);
    }

    private function CollectData(): array
    {
        $links        = json_decode($this->ReadPropertyString('Links'), true) ?? [];
        $staticMode   = $this->ReadPropertyBoolean('StaticMode');

        $rows         = [];
        $nodeColorMap = [];

        $startTime = $staticMode ? (int) GetValue($this->GetIDForIdent('StartDate')) : 0;
        $endTime   = $staticMode ? (int) GetValue($this->GetIDForIdent('EndDate'))   : 0;

        foreach ($links as $link) {
            $source = trim($link['Source'] ?? '');
            $target = trim($link['Target'] ?? '');
            $varID  = intval($link['VariableID'] ?? 0);
            $infoVarID = intval($link['InfoVariableID'] ?? 0);

            if ($source === '' || $target === '' || $varID <= 0 || !IPS_VariableExists($varID)) {
                continue;
            }

            if ($staticMode) {
                $values = $this->GetValuesFromArchive($varID, $startTime, $endTime);
            } else {
                $v = floatval(GetValue($varID));
                $values = [
                    [
                        'value'   => abs($v),
                        'reverse' => $v < 0,
                        'split'   => false
                    ]
                ];
            }

            foreach ($values as $entry) {
                $value   = floatval($entry['value']);
                $reverse = boolval($entry['reverse']);
                $split   = boolval($entry['split']);

                $invert         = boolval($link['Invert'] ?? false);
                $ignoreNegative = boolval($link['IgnoreNegative'] ?? false);

                if ($invert) {
                    $reverse = !$reverse;
                }

                if ($value <= 0) {
                    continue;
                }

                if ($reverse) {
                    if ($ignoreNegative) {
                        continue;
                    }

                    $rowSource = $target;
                    $rowTarget = $split ? $source . '-' : $source;
                } else {
                    $rowSource = $source;
                    $rowTarget = $target;
                }

                $unit = $this->GetVariableUnit($varID);

                $infoText = '';

                if ($infoVarID > 0 && IPS_VariableExists($infoVarID)) {
                    if ($staticMode) {
                        $infoValues = $this->GetValuesFromArchive($infoVarID, $startTime, $endTime);
                        $infoValue = 0.0;

                        foreach ($infoValues as $infoEntry) {
                            $infoValue += floatval($infoEntry['value']);
                        }
                    } else {
                        $infoValue = abs(floatval(GetValue($infoVarID)));
                    }

                    $infoVar = IPS_GetVariable($infoVarID);

                    $profileName = $infoVar['VariableCustomProfile'] !== ''
                        ? $infoVar['VariableCustomProfile']
                        : $infoVar['VariableProfile'];

                    if ($profileName !== '' && IPS_VariableProfileExists($profileName)) {
                        $infoText = GetValueFormattedEx($infoVarID, $infoValue);
                    } else {
                        $infoUnit = $this->GetVariableUnit($infoVarID);
                        $infoText = number_format($infoValue, 2, ',', "'") . ($infoUnit !== '' ? ' ' . $infoUnit : '');
                    }
                }

                $rows[] = [
                    htmlspecialchars($rowSource, ENT_QUOTES, 'UTF-8'),
                    htmlspecialchars($rowTarget, ENT_QUOTES, 'UTF-8'),
                    $value,
                    $unit,
                    $infoText,
                ];

                $color = intval($link['Color'] ?? 0);

                if ($color > 0) {
                    $hex = sprintf('#%06x', $color);

                    if (!array_key_exists($rowSource, $nodeColorMap)) {
                        $nodeColorMap[$rowSource] = $hex;
                    }

                    if (!array_key_exists($rowTarget, $nodeColorMap)) {
                        $nodeColorMap[$rowTarget] = $hex;
                    }
                }
            }
        }

        return [
            'rows'       => $rows,
            'nodeColors' => $nodeColorMap,
            'staticMode' => $staticMode,
            'startTs'    => $staticMode ? $startTime : null,
            'endTs'      => $staticMode ? $endTime   : null,
        ];
    }

    // Liefert ['rows' => [...], 'nodeColors' => [...]]
    // rows-Format: [source, target, value, unit]

    private function GetValuesFromArchive(int $varID, int $startTime, int $endTime): array
    {
        $archiveID = IPS_GetInstanceListByModuleID('{43192F0B-135B-4CE7-A0A7-1475603F3060}')[0] ?? 0;

        if ($archiveID === 0 || !AC_GetLoggingStatus($archiveID, $varID)) {
            return [];
        }

        $isCounter = boolval(AC_GetAggregationType($archiveID, $varID));
        $level = $this->GetAggregationLevel($startTime, $endTime);

        $agg = AC_GetAggregatedValues($archiveID, $varID, $level, $startTime, $endTime, 0);

        if (empty($agg)) {
            return [];
        }

        if ($isCounter) {
            $sum = 0.0;

            foreach ($agg as $a) {
                $sum += floatval($a['Avg']);
            }

            return [
                ['value' => $sum, 'reverse' => false, 'split' => false]
            ];
        }

        $posSum = 0.0;
        $posCnt = 0;
        $negSum = 0.0;
        $negCnt = 0;

        foreach ($agg as $a) {
            $avg = floatval($a['Avg']);

            if ($avg > 0) {
                $posSum += $avg;
                $posCnt++;
            } elseif ($avg < 0) {
                $negSum += abs($avg);
                $negCnt++;
            }
        }

        $hasBoth = $posCnt > 0 && $negCnt > 0;
        $result = [];

        if ($posCnt > 0) {
            $result[] = [
                'value'   => $posSum / $posCnt,
                'reverse' => false,
                'split'   => $hasBoth
            ];
        }

        if ($negCnt > 0) {
            $result[] = [
                'value'   => $negSum / $negCnt,
                'reverse' => true,
                'split'   => $hasBoth
            ];
        }

        return $result;
    }

    private function GenerateHTML(): string
    {
        $colorLabel = sprintf('#%06x', $this->ReadPropertyInteger('ColorLabel'));
        $showValues = $this->ReadPropertyBoolean('ShowValues') ? 'true' : 'false';
        $data       = $this->CollectData();

        return $this->GenerateLocalHTML($colorLabel, $showValues, $data);
    }

    private function GenerateLocalHTML(string $colorLabel, string $showValues, array $data): string
    {
        $jsRows   = json_encode($data['rows'], JSON_UNESCAPED_UNICODE);
        $jsColors = json_encode($data['nodeColors'], JSON_UNESCAPED_UNICODE);
        $jsStatic = ($data['staticMode'] ?? false) ? 'true' : 'false';
        $jsStartTs = json_encode($data['startTs'] ?? null);
        $jsEndTs   = json_encode($data['endTs'] ?? null);
        $sankeyJS = file_get_contents(__DIR__ . '/libs/sankey.js');

        return <<<HTML
<!DOCTYPE html>
<html lang="de">
<head>
<meta charset="UTF-8">
<style>
  * { margin:0; padding:0; box-sizing:border-box; }
  body { background:transparent; font-family:'Segoe UI',Arial,sans-serif; padding:12px; }
  html, body {
    width:100%;
    height:100%;
    margin:0;
    padding:0;
    overflow:hidden;
    background:transparent;
    font-family:'Segoe UI',Arial,sans-serif;
    }

    body {
    display:flex;
    flex-direction:column;
    }

    #chart_div {
        flex:1 1 auto;
        width:100%;
        min-height:120px;
        padding-bottom:18px;
        opacity:1;
        transition:opacity .25s ease;
    }

    #date_range {
        display:none;
        flex-shrink:0;
        align-items:center;
        justify-content:center;
        gap:8px;
        padding:5px 8px 3px;
    }

    .dp-input {
        background:rgba(255,255,255,0.07);
        border:1px solid rgba(255,255,255,0.13);
        border-radius:6px;
        padding:4px 8px;
        font-size:12px;
        font-family:'Segoe UI',Arial,sans-serif;
        color:#94a3b8;
        cursor:pointer;
        outline:none;
        color-scheme:dark;
    }

    .dp-input:hover {
        background:rgba(255,255,255,0.13);
        border-color:rgba(255,255,255,0.25);
    }

    .dp-sep {
        font-size:12px;
        color:#475569;
    }
</style>
</head>
<body>
<div id="chart_div"></div>
<div id="date_range">
    <button id="btn_mode" class="dp-input">Archiv</button>
    <input type="datetime-local" id="inp_start" class="dp-input">
    <span class="dp-sep">→</span>
    <input type="datetime-local" id="inp_end" class="dp-input">
</div>
<script>{$sankeyJS}</script>
<script>
    var rows       = {$jsRows};
    var nodeColors = {$jsColors};
    var staticMode = {$jsStatic};
    var startTs    = {$jsStartTs};
    var endTs      = {$jsEndTs};

    function tsToInputVal(ts) {
        if (!ts) return '';

        var d = new Date(ts * 1000);

        return d.getFullYear() + '-' +
            String(d.getMonth() + 1).padStart(2, '0') + '-' +
            String(d.getDate()).padStart(2, '0') + 'T' +
            String(d.getHours()).padStart(2, '0') + ':' +
            String(d.getMinutes()).padStart(2, '0');
    }

    function dateToTs(val) {
        if (!val) return 0;

        var d = new Date(val);

        return Math.floor(d.getTime() / 1000);
    }

    function updateDateRange() {
        var el = document.getElementById('date_range');
        if (!el) return;

        var btn = document.getElementById('btn_mode');
        if (btn) {
            btn.textContent = staticMode ? 'Archiv' : 'Live';
        }

        el.style.display = 'flex';

        document.getElementById('inp_start').style.display = staticMode ? 'inline-block' : 'none';
        document.getElementById('inp_end').style.display   = staticMode ? 'inline-block' : 'none';

        var sep = document.querySelector('.dp-sep');
        if (sep) {
            sep.style.display = staticMode ? 'inline-block' : 'none';
        }

        if (staticMode) {
            var inpStart = document.getElementById('inp_start');
            var inpEnd   = document.getElementById('inp_end');

            if (document.activeElement !== inpStart) {
                inpStart.value = tsToInputVal(startTs);
            }

            if (document.activeElement !== inpEnd) {
                inpEnd.value = tsToInputVal(endTs);
            }
        }
    }

    function render() {
        var chart = document.getElementById('chart_div');
        chart.innerHTML = '';

        drawSankey('chart_div', rows, {
            nodeColors:  nodeColors,
            linkOpacity: 0.45,
            labelColor:  '{$colorLabel}',
            showValues:  {$showValues},
            staticMode:  staticMode
        });

        updateDateRange();
    }

    function handleMessage(message) {
        var data = typeof message === 'string' ? JSON.parse(message) : message;

        rows       = data.rows       || [];
        nodeColors = data.nodeColors || {};
        if (data.staticMode !== undefined) staticMode = data.staticMode;
        if (data.startTs    !== undefined) startTs    = data.startTs;
        if (data.endTs      !== undefined) endTs      = data.endTs;

        render();
    }

    document.getElementById('inp_start').addEventListener('change', function () {
        startTs = dateToTs(this.value);
        requestAction('StartDate', startTs);
    });

    document.getElementById('inp_end').addEventListener('change', function () {
        endTs = dateToTs(this.value);
        requestAction('EndDate', endTs);
    });

    document.getElementById('btn_mode').addEventListener('click', function () {
            var newMode = staticMode ? 0 : 1;
            requestAction('ToggleMode', newMode);
        });

    window.addEventListener('message', function(event) {
        handleMessage(event.data);
    });

    render();

    var resizeTimer;

    if (window.ResizeObserver) {
        var observer = new ResizeObserver(function() {
            clearTimeout(resizeTimer);
            resizeTimer = setTimeout(render, 150);
        });

        observer.observe(document.body);

    } else {

        window.addEventListener('resize', function() {
            clearTimeout(resizeTimer);
            resizeTimer = setTimeout(render, 150);
        });

    }
</script>
</body>
</html>
HTML;
    }
}
