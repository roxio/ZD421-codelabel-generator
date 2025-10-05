<?php
function parse_codes(string $input): array {
    $parts = preg_split('/[,\s]+/u', trim($input));
    return array_values(array_filter($parts, fn($p) => $p !== ''));
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['codes'])) {
    $codes = parse_codes($_POST['codes']);

    if (empty($codes)) {
        $error = "Nie wprowadzono żadnych kodów.";
    } else {
        // Stałe ZPL dla formatu 100x150 mm (czyli 4x6 cali)
        // Zebra używa cali × 203 dpi (dla 203 dpi = 8 punkty/mm)
        // 100 mm = 3.94 cala ≈ 800 punktów
        // 150 mm = 5.9 cala ≈ 1200 punktów
        $labelWidth = 1200;   // szerokość etykiety w punktach
        $labelHeight = 800;   // wysokość etykiety
        $leftPart = (int)($labelWidth * 0.8); // 80% - cyfry
        $rightPart = $labelWidth - $leftPart; // 20% - barcode + data

        $zpl = "^XA\n"; // start form

        foreach ($codes as $code) {
            // Dla każdej etykiety generujemy osobny blok ZPL (oddzielony ^XZ / ^XA)
            $zpl .= "^PW{$labelWidth}\n";
            $zpl .= "^LL{$labelHeight}\n";
            $zpl .= "^POI\n";              // orientacja landscape (I=rotacja 180°, można zamienić na N)

            $len = strlen($code);
            $baseFontSize = 250; // dla krótkich kodów
            if ($len > 14) $baseFontSize = 140;
            elseif ($len > 10) $baseFontSize = 180;
            elseif ($len > 8) $baseFontSize = 200;

            $xText = 50;
            $yText = ($labelHeight / 2) - ($baseFontSize / 4);

            $zpl .= "^CF0,{$baseFontSize}\n";
            $zpl .= "^FO{$xText},{$yText}^FB{$leftPart},1,0,C,0^FD{$code}^FS\n";

            $barcodeX = $leftPart + 20;
            $barcodeY = 200;
            $barcodeHeight = 300;

            $zpl .= "^BY3,3,{$barcodeHeight}\n";
            $zpl .= "^FO{$barcodeX},{$barcodeY}^BCN,{$barcodeHeight},N,N,N^FD{$code}^FS\n";

            $date = date('Y-m-d H:i');
            $zpl .= "^CF0,40\n";
            $zpl .= "^FO{$barcodeX}," . ($barcodeY + $barcodeHeight + 40) . "^FDData: {$date}^FS\n";

            $zpl .= "^XZ\n^XA\n";
        }

        $zpl .= "^XZ"; // koniec pliku

        // Wysyłka pliku do przeglądarki
        header('Content-Type: text/plain');
        header('Content-Disposition: attachment; filename="etikiety_' . date('Ymd_His') . '.zpl"');
        echo $zpl;
        exit;
    }
}
?>
<!doctype html>
<html lang="pl">
<head>
<meta charset="utf-8">
<title>Generator ZPL - etykiety Zebra</title>
<style>
body{font-family:Arial,Helvetica,sans-serif;margin:20px}
textarea{width:100%;height:200px}
.note{font-size:0.9em;color:#555;margin-top:10px}
</style>
</head>
<body>
<h2>Generator etykiet ZPL (Zebra ZD421)</h2>

<form method="post">
<label for="codes">Wklej kody (rozdzielone spacjami, przecinkami lub w osobnych liniach):</label><br>
<textarea id="codes" name="codes" placeholder="np. 1234567890123, 987654321, 00012345"></textarea><br><br>
<button type="submit">Generuj plik ZPL</button>
</form>

<?php if (!empty($error)): ?>
<p style="color:red"><?=htmlspecialchars($error)?></p>
<?php endif; ?>

<div class="note">
    <ul>
        <li>Plik <code>.zpl</code> możesz wysłać bezpośrednio do drukarki Zebra (np. przez Zebra Setup Utilities → Tools → Send File).</li>
        <li>Format etykiety: <strong>100×150 mm</strong> (landscape). Sterownik automatycznie dopasuje długość — drukarka ZD421 wykryje długość papieru.</li>
        <li>Jeśli drukarka obraca etykietę o 180°, zmień komendę <code>^POI</code> na <code>^PON</code> w kodzie.</li>
        <li>Skrypt automatycznie dobiera wielkość czcionki do długości kodu (maksymalnie ok. 14 cyfr widocznych w pełnej szerokości).</li>
    </ul>
</div>
</body>
</html>
