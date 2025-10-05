<?php
function parse_codes(string $input): array {
    $parts = preg_split('/[,\s]+/u', trim($input));
    return array_values(array_filter($parts, fn($p) => $p !== ''));
}

function generate_zpl(array $codes, string $barcodeType, string $orientation, string $labelFormat): string {
    // dostępne formaty (mm -> dots przy 203 dpi)
    $formats = [
        'auto' => [1200, 800], // domyślne 100x150 landscape
        '100x150' => [1200, 800],
        '60x40'   => [480, 320],
        '58x100'  => [800, 464],
        '80x50'   => [600, 400],
    ];
    [$w, $h] = $formats[$labelFormat] ?? $formats['auto'];

    $leftPart = (int)($w * 0.8);
    $zpl = "^XA\n^PW{$w}\n^LL{$h}\n";
    $zpl .= ($orientation === 'portrait') ? "^PON\n" : "^POI\n"; // normal/rotated

    foreach ($codes as $code) {
        $len = strlen($code);
        $fontSize = 250;
        if ($len > 14) $fontSize = 140;
        elseif ($len > 10) $fontSize = 180;
        elseif ($len > 8) $fontSize = 200;

        $xText = 50;
        $yText = ($h / 2) - ($fontSize / 4);
        $zpl .= "^CF0,{$fontSize}\n";
        $zpl .= "^FO{$xText},{$yText}^FB{$leftPart},1,0,C,0^FD{$code}^FS\n";

        $barcodeX = $leftPart + 20;
        $barcodeY = 150;
        $barcodeH = $h * 0.4;
        $date = date('Y-m-d H:i');

        switch ($barcodeType) {
            case 'EAN13':
                $zpl .= "^BY3,3,{$barcodeH}\n";
                $zpl .= "^FO{$barcodeX},{$barcodeY}^BEN,{$barcodeH},Y,N^FD{$code}^FS\n";
                break;
            case 'Code39':
                $zpl .= "^BY3,3,{$barcodeH}\n";
                $zpl .= "^FO{$barcodeX},{$barcodeY}^B3N,N,{$barcodeH},Y,N^FD{$code}^FS\n";
                break;
            case 'QR':
                $zpl .= "^FO{$barcodeX},{$barcodeY}^BQN,2,6^FDLA,{$code}^FS\n";
                break;
            default:
                $zpl .= "^BY3,3,{$barcodeH}\n";
                $zpl .= "^FO{$barcodeX},{$barcodeY}^BCN,{$barcodeH},N,N,N^FD{$code}^FS\n";
        }
        $zpl .= "^CF0,40\n";
        $zpl .= "^FO{$barcodeX}," . ($barcodeY + $barcodeH + 40) . "^FDData: {$date}^FS\n";
        $zpl .= "^XZ\n^XA\n";
    }
    $zpl .= "^XZ";
    return $zpl;
}

function send_to_printer(string $ip, string $data): bool {
    $fp = @fsockopen($ip, 9100, $errno, $errstr, 5);
    if (!$fp) return false;
    fwrite($fp, $data);
    fclose($fp);
    return true;
}

$zplOutput = '';
$message = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['action']) && $_POST['action'] === 'generate') {
        $codes = parse_codes($_POST['codes']);
        $type = $_POST['barcode_type'] ?? 'Code128';
        $orient = $_POST['orientation'] ?? 'landscape';
        $format = $_POST['label_format'] ?? 'auto';
        if (!empty($codes)) {
            $zplOutput = generate_zpl($codes, $type, $orient, $format);
        } else {
            $message = "⚠️ Nie podano żadnych kodów.";
        }
    }

    if (isset($_POST['action']) && $_POST['action'] === 'send') {
        $ip = trim($_POST['printer_ip']);
        $zpl = $_POST['zpl_raw'] ?? '';
        if ($ip && $zpl) {
            $ok = send_to_printer($ip, $zpl);
            $message = $ok ? "✅ Wysłano do drukarki {$ip}" : "❌ Nie udało się połączyć z drukarką {$ip}";
        } else {
            $message = "⚠️ Podaj IP drukarki i ZPL.";
        }
    }

    if (isset($_POST['action']) && $_POST['action'] === 'import') {
        if (!empty($_FILES['zpl_file']['tmp_name'])) {
            $zplOutput = file_get_contents($_FILES['zpl_file']['tmp_name']);
        } else {
            $message = "⚠️ Nie wybrano pliku do importu.";
        }
    }
}
?>
<!doctype html>
<html lang="pl">
<head>
<meta charset="utf-8">
<title>Zebra Label Tool (ZPL Generator & Sender)</title>
<style>
body{font-family:Arial,Helvetica,sans-serif;margin:20px;display:grid;grid-template-columns:1fr 1fr;gap:20px}
textarea{width:100%;height:200px}
input,select,button{margin-top:5px;padding:5px}
.note{font-size:0.9em;color:#555;margin-top:10px}
#zpl_view{width:100%;height:300px;font-family:monospace;white-space:pre;overflow:auto;background:#f4f4f4;border:1px solid #ccc;padding:10px}
</style>
</head>
<body>

<!-- Lewa kolumna -->
<div>
<h2>Generator etykiet ZPL</h2>
<form method="post">
<input type="hidden" name="action" value="generate">
<label>Kody (spacje, przecinki, nowe linie):</label><br>
<textarea name="codes" placeholder="np. 1234567890123, 987654321, 00012345"></textarea><br>

<label>Typ kodu:</label><br>
<select name="barcode_type">
<option>Code128</option>
<option>EAN13</option>
<option>Code39</option>
<option>QR</option>
</select><br>

<label>Format etykiety:</label><br>
<select name="label_format">
<option value="auto">Automatyczny (100x150 mm)</option>
<option value="100x150">100x150 mm</option>
<option value="60x40">60x40 mm</option>
<option value="58x100">58x100 mm</option>
<option value="80x50">80x50 mm</option>
</select><br>

<label>Orientacja:</label><br>
<select name="orientation">
<option value="landscape">Pozioma</option>
<option value="portrait">Pionowa</option>
</select><br><br>

<button type="submit">Generuj ZPL</button>
</form>

<div class="note">
<ul>
<li>Wygenerowany ZPL możesz zapisać lub wysłać bezpośrednio do drukarki Zebra.</li>
<li>Standardowy port drukarki sieciowej: <code>9100</code>.</li>
</ul>
</div>
</div>

<div>
<h2>Podgląd / Wysyłka</h2>
<form method="post">
<input type="hidden" name="action" value="send">
<label>Adres IP drukarki:</label><br>
<input type="text" name="printer_ip" placeholder="np. 192.168.0.50"><br>
<label>ZPL do wysłania:</label><br>
<textarea name="zpl_raw" id="zpl_raw"><?=htmlspecialchars($zplOutput)?></textarea><br>
<button type="submit">Wyślij do drukarki</button>
</form>

<h3>Import pliku ZPL</h3>
<form method="post" enctype="multipart/form-data">
<input type="hidden" name="action" value="import">
<input type="file" name="zpl_file" accept=".zpl">
<button type="submit">Importuj</button>
</form>

<h3>Podgląd ZPL</h3>
<div id="zpl_view"><?=htmlspecialchars($zplOutput)?></div>

<?php if ($message): ?>
<p><strong><?=$message?></strong></p>
<?php endif; ?>
</div>

</body>
</html>
