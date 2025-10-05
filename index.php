<?php
function parse_codes(string $input): array {
    $parts = preg_split('/[,\s]+/u', trim($input));
    return array_values(array_filter($parts, fn($p) => $p !== ''));
}

function generate_zpl(array $codes, string $barcodeType, string $orientation, string $labelFormat): string {
    $formats = [
        'auto' => [100, 150],
        '100x150' => [100, 150],
        '60x40'   => [60, 40],
        '58x100'  => [58, 100],
        '80x50'   => [80, 50],
    ];
    [$w, $h] = $formats[$labelFormat] ?? $formats['auto'];

    $zpl = "^XA\n^PW{$w}\n^LL{$h}\n";
    $zpl .= ($orientation === 'portrait') ? "^PON\n" : "^POI\n";

    foreach ($codes as $code) {
        $date = date('Y-m-d H:i');
        $zpl .= "^FO20,20^ADN,36,20^FD{$code}^FS\n";
        $zpl .= "^FO20,80^BY2\n";
        if ($barcodeType === 'QR') {
            $zpl .= "^BQN,2,6^FDLA,{$code}^FS\n";
        } elseif ($barcodeType === 'Code39') {
            $zpl .= "^B3N,N,100,Y,N^FD{$code}^FS\n";
        } elseif ($barcodeType === 'EAN13') {
            $zpl .= "^BEN,100,Y,N^FD{$code}^FS\n";
        } else {
            $zpl .= "^BCN,100,Y,N,N^FD{$code}^FS\n";
        }
        $zpl .= "^FO20,200^ADN,18,10^FDData: {$date}^FS\n";
    }
    $zpl .= "^XZ";
    return $zpl;
}

$zplOutput = '';
$codes = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $codes = parse_codes($_POST['codes'] ?? '');
    if (!empty($codes)) {
        $zplOutput = generate_zpl(
            $codes,
            $_POST['barcode_type'] ?? 'Code128',
            $_POST['orientation'] ?? 'landscape',
            $_POST['label_format'] ?? 'auto'
        );
    }
}
?>
<!doctype html>
<html lang="pl">
<head>
<meta charset="utf-8">
<title>Zebra Label Tool (lokalny podgląd)</title>
<script src="https://cdn.jsdelivr.net/npm/bwip-js@3.0.9/dist/bwip-js.min.js"></script>
<style>
body{font-family:Arial,sans-serif;margin:20px;display:grid;grid-template-columns:1fr 1fr;gap:20px}
textarea{width:100%;height:200px}
canvas{border:1px solid #999;margin-top:10px;max-width:100%;}
#zpl_view{background:#f4f4f4;border:1px solid #ccc;padding:10px;font-family:monospace;white-space:pre;overflow:auto;height:200px}
.actions {margin-top:20px;display:flex;gap:10px;flex-wrap:wrap;}
.actions input, .actions button {padding:8px 12px;}
.ip-input {width:120px;}
.status {margin-top:10px;padding:10px;border-radius:4px;}
.status.success {background:#d4edda;color:#155724;border:1px solid #c3e6cb;}
.status.error {background:#f8d7da;color:#721c24;border:1px solid #f5c6cb;}
</style>
</head>
<body>

<div>
<h2>Generator etykiet ZPL</h2>
<form method="post">
<textarea name="codes" placeholder="np. 1234567890, 0987654321"><?= htmlspecialchars($_POST['codes'] ?? '') ?></textarea><br>

<label>Typ kodu:</label><br>
<select name="barcode_type">
<option value="Code128" <?= ($_POST['barcode_type'] ?? 'Code128') === 'Code128' ? 'selected' : '' ?>>Code128</option>
<option value="EAN13" <?= ($_POST['barcode_type'] ?? '') === 'EAN13' ? 'selected' : '' ?>>EAN13</option>
<option value="Code39" <?= ($_POST['barcode_type'] ?? '') === 'Code39' ? 'selected' : '' ?>>Code39</option>
<option value="QR" <?= ($_POST['barcode_type'] ?? '') === 'QR' ? 'selected' : '' ?>>QR</option>
</select><br>

<label>Format etykiety:</label><br>
<select name="label_format">
<option value="auto" <?= ($_POST['label_format'] ?? 'auto') === 'auto' ? 'selected' : '' ?>>Auto (100×150 mm)</option>
<option value="100x150" <?= ($_POST['label_format'] ?? '') === '100x150' ? 'selected' : '' ?>>100×150 mm</option>
<option value="60x40" <?= ($_POST['label_format'] ?? '') === '60x40' ? 'selected' : '' ?>>60×40 mm</option>
<option value="58x100" <?= ($_POST['label_format'] ?? '') === '58x100' ? 'selected' : '' ?>>58×100 mm</option>
<option value="80x50" <?= ($_POST['label_format'] ?? '') === '80x50' ? 'selected' : '' ?>>80×50 mm</option>
</select><br>

<label>Orientacja:</label><br>
<select name="orientation">
<option value="landscape" <?= ($_POST['orientation'] ?? 'landscape') === 'landscape' ? 'selected' : '' ?>>Pozioma</option>
<option value="portrait" <?= ($_POST['orientation'] ?? '') === 'portrait' ? 'selected' : '' ?>>Pionowa</option>
</select><br><br>

<button type="submit">Generuj + Podgląd</button>
</form>
</div>

<div>
<h2>Podgląd etykiety (pierwszy kod)</h2>
<div id="preview"></div>

<div class="actions">
    <div>
        <input type="text" id="printer_ip" class="ip-input" placeholder="IP drukarki" value="<?= htmlspecialchars($_POST['printer_ip'] ?? '') ?>">
        <button type="button" onclick="sendToPrinter()">Wyślij do drukarki</button>
    </div>
    <button type="button" onclick="saveToFile()">Zapisz ZPL do pliku</button>
</div>

<div id="status"></div>

<h3>Kod ZPL</h3>
<div id="zpl_view"><?=htmlspecialchars($zplOutput)?></div>

<?php if (!empty($codes)): ?>
<div style="margin-top: 20px;">
    <h4>Wygenerowane kody (<?= count($codes) ?>):</h4>
    <ul>
        <?php foreach ($codes as $index => $code): ?>
            <li><?= htmlspecialchars($code) ?></li>
        <?php endforeach; ?>
    </ul>
</div>
<?php endif; ?>
</div>

<script>
(async function(){
    const cdns = [
        'https://cdnjs.cloudflare.com/ajax/libs/bwip-js/4.7.0/bwip-js-min.js',
        'https://cdn.jsdelivr.net/npm/bwip-js@4.7.0/dist/bwip-js-min.js',
        'https://unpkg.com/bwip-js@4.7.0/dist/bwip-js-min.js'
    ];

    function loadScript(url, timeout = 8000){
        return new Promise((resolve, reject) => {
            const s = document.createElement('script');
            s.src = url;
            s.async = true;
            const t = setTimeout(() => {
                s.onerror = s.onload = null;
                s.remove();
                reject(new Error('timeout'));
            }, timeout);
            s.onload = () => {
                clearTimeout(t);
                resolve(url);
            };
            s.onerror = (e) => {
                clearTimeout(t);
                s.remove();
                reject(e || new Error('load error'));
            };
            document.head.appendChild(s);
        });
    }

    let loadedUrl = null;
    for (const url of cdns) {
        try {
            await loadScript(url, 7000);
            loadedUrl = url;
            break;
        } catch (e) {
            // console.warn('CDN failed:', url, e);
        }
    }

    if (!loadedUrl) {
        const preview = document.getElementById('preview');
        preview.innerHTML = '<div style="color:#c00">Nie udało się załadować biblioteki bwip-js z CDN. <br>Możesz pobrać ją lokalnie i umieścić np. /js/bwip-js-min.js, a potem dodać: <code>&lt;script src=\"/js/bwip-js-min.js\"&gt;&lt;/script&gt;</code></div>';
        return;
    }

    const bwipGlobal = window.bwipjs || window.BWIPJS || null;
    if (!bwipGlobal) {
        document.getElementById('preview').innerHTML = '<div style="color:#c00">Biblioteka załadowana (URL: ' + loadedUrl + ') ale nie znaleziono globalnego obiektu bwipjs / BWIPJS.</div>';
        return;
    }
    const bwip = bwipGlobal;

    // ---- renderowanie etykiety (główna funkcja) ----
    function mmToPx(mm, dpmm = 8) { return Math.round(mm * dpmm); }
    function createLabelCanvas(code, type, labelFormat, orientation) {
        const formats = {
            'auto': [100, 150],
            '100x150': [100, 150],
            '60x40': [60, 40],
            '58x100': [58, 100],
            '80x50': [80, 50]
        };
        let [wmm, hmm] = formats[labelFormat] || formats['auto'];
        if (orientation === 'landscape') {
            if (hmm > wmm) { const t = wmm; wmm = hmm; hmm = t; }
        } else {
            if (wmm > hmm) { const t = wmm; wmm = hmm; hmm = t; }
        }

        const dpmm = 8;
        const pxW = mmToPx(wmm, dpmm);
        const pxH = mmToPx(hmm, dpmm);

        const canvas = document.createElement('canvas');
        canvas.width = pxW;
        canvas.height = pxH;
        canvas.style.width = Math.min(pxW, 800) + 'px';
        canvas.style.height = 'auto';
        const ctx = canvas.getContext('2d');

        ctx.fillStyle = '#fff';
        ctx.fillRect(0,0,pxW,pxH);

        // Marginesy 5mm
        const margin = mmToPx(5);
        const contentWidth = pxW - (2 * margin);

        // 1) GŁÓWNY TEKST - MAKSYMALNIE DUŻY
        ctx.fillStyle = '#000';
        ctx.textBaseline = 'middle';
        
        // Zacznij od bardzo dużego rozmiaru i zmniejszaj aż zmieści się w szerokości
        let fontSize = Math.min(pxH * 0.4, pxW * 0.3); // Start od 40% wysokości lub 30% szerokości
        let textFits = false;
        
        while (fontSize > 10 && !textFits) {
            ctx.font = 'bold ' + fontSize + 'px Arial';
            const metrics = ctx.measureText(code);
            const textW = metrics.width;
            const textH = fontSize;
            
            // Sprawdź czy tekst mieści się w szerokości i zostaje miejsce na kod kreskowy
            if (textW <= contentWidth && textH <= pxH * 0.5) {
                textFits = true;
                break;
            }
            fontSize = Math.floor(fontSize * 0.9);
        }
        
        // Jeśli nadal nie mieści się, użyj maksymalnego możliwego rozmiaru
        if (!textFits) {
            fontSize = Math.min(pxH * 0.3, pxW * 0.2);
            ctx.font = 'bold ' + fontSize + 'px Arial';
        }
        
        const textX = pxW / 2;
        const textY = margin + (fontSize / 2) + mmToPx(2);
        ctx.textAlign = 'center';
        ctx.fillText(code, textX, textY);

        // 2) KOD KRESKOWY POD TEKSTEM - BEZ ZDUPLIKOWANEGO NUMERU
        const textBottom = textY + (fontSize / 2) + mmToPx(3);
        const barcodeTop = textBottom + mmToPx(2);
        
        // Wysokość kodu kreskowego - reszta dostępnego miejsca po odjęciu tekstu i daty
        const dateAreaHeight = mmToPx(8);
        const availableBarcodeHeight = pxH - barcodeTop - dateAreaHeight - margin;
        const barcodeHeight = Math.max(mmToPx(10), availableBarcodeHeight);

        const barcodeCanvas = document.createElement('canvas');
        const bcW = contentWidth;
        const bcH = barcodeHeight;
        barcodeCanvas.width = bcW;
        barcodeCanvas.height = bcH;

        let bcid = 'code128';
        if (type === 'EAN13') bcid = 'ean13';
        if (type === 'Code39') bcid = 'code39';
        if (type === 'QR') bcid = 'qrcode';

        try {
            const opts = {
                bcid: bcid,
                text: '', // PUSTY TEKST - NIE POKAZUJ NUMERU POD KODEM KRESKOWYM
                scale: Math.max(1, Math.round(bcW / 100)),
                height: Math.round(bcH / 8),
                includetext: false, // WYŁĄCZ TEKST POD KODEM KRESKOWYM
                textxalign: 'center'
            };
            
            // Dla kodów które wymagają tekstu, użyj pustego stringa
            if (bcid === 'qrcode') {
                opts.text = String(code);
            } else {
                opts.text = String(code);
                opts.includetext = false;
            }
            
            bwip.toCanvas(barcodeCanvas, opts);
            
            const bcX = margin;
            const bcY = barcodeTop;
            ctx.drawImage(barcodeCanvas, bcX, bcY, bcW, bcH);
            
        } catch (err) {
            ctx.fillStyle = '#c00';
            ctx.textAlign = 'center';
            ctx.font = '12px Arial';
            ctx.fillText('Błąd generowania kodu', pxW/2, barcodeTop + (bcH/2));
        }

        // 3) DATA WYDRUKU NA DOLE
        ctx.fillStyle = '#000';
        const dateFontSize = Math.max(10, Math.round(pxH * 0.04));
        ctx.font = dateFontSize + 'px Arial';
        ctx.textAlign = 'center';
        const now = new Date();
        const dateStr = now.getFullYear()+'-'+String(now.getMonth()+1).padStart(2,'0')+'-'+String(now.getDate()).padStart(2,'0')+' '+String(now.getHours()).padStart(2,'0')+':'+String(now.getMinutes()).padStart(2,'0');
        const dateX = pxW / 2;
        const dateY = pxH - margin - (dateFontSize / 2);
        ctx.fillText('Data: ' + dateStr, dateX, dateY);

        // Opcjonalna ramka pomocnicza
        ctx.strokeStyle = '#ddd';
        ctx.strokeRect(1, 1, pxW-2, pxH-2);

        return canvas;
    }

    const zplText = `<?= addslashes($zplOutput ?? '') ?>`;
    const preview = document.getElementById('preview');
    preview.innerHTML = '';

    if (!zplText.trim()) {
        preview.innerHTML = '<div style="color:#666">Brak wygenerowanego ZPL do podglądu.</div>';
        return;
    }

    const barcodeType = (document.querySelector('select[name="barcode_type"]') || {value: 'Code128'}).value;
    const labelFormat = (document.querySelector('select[name="label_format"]') || {value: 'auto'}).value;
    const orientation = (document.querySelector('select[name="orientation"]') || {value: 'landscape'}).value;

    const codesToRender = <?= json_encode($codes ?? []) ?>;

    // Renderuj tylko pierwszy kod
    if (codesToRender.length > 0) {
        const firstCode = codesToRender[0];
        try {
            const c = createLabelCanvas(firstCode, barcodeType, labelFormat, orientation);
            preview.appendChild(c);
        } catch (e) {
            const err = document.createElement('div');
            err.style.color = '#c00';
            err.textContent = 'Błąd renderowania etykiety: ' + (e.message || e);
            preview.appendChild(err);
        }
    }
})();

// Funkcja do wysyłania do drukarki
async function sendToPrinter() {
    const printerIp = document.getElementById('printer_ip').value.trim();
    const zplContent = `<?= addslashes($zplOutput ?? '') ?>`;
    const statusDiv = document.getElementById('status');
    
    if (!printerIp) {
        showStatus('Proszę podać adres IP drukarki', 'error');
        return;
    }
    
    if (!zplContent) {
        showStatus('Brak danych ZPL do wysłania', 'error');
        return;
    }
    
    showStatus('Wysyłanie do drukarki...', '');
    
    try {
        // Wysyłanie ZPL do drukarki przez HTTP (port 9100)
        const response = await fetch(`http://${printerIp}:9100`, {
            method: 'POST',
            body: zplContent,
            mode: 'no-cors'
        });
        
        // Uwaga: no-cors nie pozwala na odczyt odpowiedzi, więc zakładamy sukces
        showStatus('Pomyślnie wysłano do drukarki', 'success');
        
    } catch (error) {
        showStatus('Błąd wysyłania do drukarki: ' + error.message, 'error');
    }
}

// Funkcja do zapisu do pliku
function saveToFile() {
    const zplContent = `<?= addslashes($zplOutput ?? '') ?>`;
    const statusDiv = document.getElementById('status');
    
    if (!zplContent) {
        showStatus('Brak danych ZPL do zapisania', 'error');
        return;
    }
    
    try {
        // Tworzenie pliku do pobrania
        const blob = new Blob([zplContent], { type: 'text/plain' });
        const url = URL.createObjectURL(blob);
        const a = document.createElement('a');
        a.href = url;
        a.download = 'etykiety_zpl_' + new Date().toISOString().slice(0,19).replace(/:/g,'-') + '.zpl';
        document.body.appendChild(a);
        a.click();
        document.body.removeChild(a);
        URL.revokeObjectURL(url);
        
        showStatus('Plik ZPL został pobrany', 'success');
    } catch (error) {
        showStatus('Błąd zapisywania pliku: ' + error.message, 'error');
    }
}

// Funkcja do wyświetlania statusu
function showStatus(message, type) {
    const statusDiv = document.getElementById('status');
    statusDiv.textContent = message;
    statusDiv.className = 'status';
    if (type) {
        statusDiv.classList.add(type);
    }
    
    // Autoukrywanie po 5 sekundach
    setTimeout(() => {
        statusDiv.textContent = '';
        statusDiv.className = 'status';
    }, 5000);
}
</script>
</body>
</html>
