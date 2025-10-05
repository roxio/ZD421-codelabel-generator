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
</style>
</head>
<body>

<div>
<h2>Generator etykiet ZPL</h2>
<form method="post">
<textarea name="codes" placeholder="np. 1234567890, 0987654321"></textarea><br>

<label>Typ kodu:</label><br>
<select name="barcode_type">
<option>Code128</option>
<option>EAN13</option>
<option>Code39</option>
<option>QR</option>
</select><br>

<label>Format etykiety:</label><br>
<select name="label_format">
<option value="auto">Auto (100×150 mm)</option>
<option value="100x150">100×150 mm</option>
<option value="60x40">60×40 mm</option>
<option value="58x100">58×100 mm</option>
<option value="80x50">80×50 mm</option>
</select><br>

<label>Orientacja:</label><br>
<select name="orientation">
<option value="landscape">Pozioma</option>
<option value="portrait">Pionowa</option>
</select><br><br>

<button type="submit">Generuj + Podgląd</button>
</form>
</div>

<div>
<h2>Podgląd etykiety</h2>
<div id="preview"></div>

<h3>Kod ZPL</h3>
<div id="zpl_view"><?=htmlspecialchars($zplOutput)?></div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bwip-js@3.0.9/dist/bwip-js-min.js"></script>

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

    // Spróbuj załadować z listy CDN-ów (pierwszy, który zadziała)
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

    // Jeśli nie udało się z CDN, pokaż pomoc
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
    // parametry: code (tekst), type ('Code128','EAN13','Code39','QR'), format string i orientation
    function mmToPx(mm, dpmm = 8) { return Math.round(mm * dpmm); } // dpmm ≈ 8 dla 203 dpi
    function createLabelCanvas(code, type, labelFormat, orientation) {
        // formaty w mm (szer x wys) - interpretujemy wartości jako "podstawowe w mm"
        const formats = {
            'auto': [100, 150], // domyślnie 100x150 mm (portrait); orientacja potem zamienia
            '100x150': [100, 150],
            '60x40': [60, 40],
            '58x100': [58, 100],
            '80x50': [80, 50]
        };
        let [wmm, hmm] = formats[labelFormat] || formats['auto'];
        // jeżeli wybrano orientację poziomą -> swap: width = większy bok
        if (orientation === 'landscape') {
            // dla wygody wymieniamy tak, by szerokość była dłuższym bokiem
            if (hmm > wmm) { const t = wmm; wmm = hmm; hmm = t; }
        } else {
            // portrait -> tak jak jest
            if (wmm > hmm) { const t = wmm; wmm = hmm; hmm = t; }
        }

        const dpmm = 8; // 203 dpi ~ 8 dots/mm -> dobre do preview
        const pxW = mmToPx(wmm, dpmm);
        const pxH = mmToPx(hmm, dpmm);

        const canvas = document.createElement('canvas');
        canvas.width = pxW;
        canvas.height = pxH;
        canvas.style.width = Math.min(pxW, 800) + 'px'; 
        canvas.style.height = 'auto';
        const ctx = canvas.getContext('2d');

        // tło białe
        ctx.fillStyle = '#fff';
        ctx.fillRect(0,0,pxW,pxH);

        // marginesy (w px)
        const margin = Math.round(pxW * 0.02);

        // obszary
        const leftW = Math.round(pxW * 0.80);
        const rightW = pxW - leftW - margin;
        const leftX = margin;
        const rightX = leftW + margin*1.5;

        ctx.fillStyle = '#000';
        ctx.textBaseline = 'middle';
        // próbujemy znaleźć największy rozmiar który zmieści się w obszarze leftW x pxH
        let fontSize = Math.floor(pxH * 0.5); // start
        ctx.font = fontSize + 'px Arial';
        while (fontSize > 6) {
            ctx.font = fontSize + 'px Arial';
            const metrics = ctx.measureText(code);
            const textW = metrics.width;
            const textH = fontSize; // przybliżenie
            if (textW <= leftW - margin && textH <= pxH - margin*2) break;
            fontSize = Math.floor(fontSize * 0.85);
        }

        const textX = leftX + Math.round(leftW/2);
        const textY = Math.round(pxH/2);
        ctx.textAlign = 'center';
        ctx.fillText(code, textX, textY);
        const barcodeCanvas = document.createElement('canvas');
        // wyznacz rozmiar barcode: width = rightW * 0.9, height = 0.4 * pxH
        const bcW = Math.max(80, Math.round(rightW * 0.9));
        const bcH = Math.max(40, Math.round(pxH * 0.40));
        barcodeCanvas.width = bcW;
        barcodeCanvas.height = bcH;

        // wybór bcid zgodny z bwip-js
        let bcid = 'code128';
        if (type === 'EAN13') bcid = 'ean13';
        if (type === 'Code39') bcid = 'code39';
        if (type === 'QR') bcid = 'qrcode';

        try {
            // opcje ogólne
            const opts = {
                bcid: bcid,
                text: String(code),
                scale: Math.max(2, Math.round(bcW / 200)), 
                height: Math.round(bcH / 3), 
                includetext: (bcid !== 'qrcode'), 
                textxalign: 'center'
            };
            bwip.toCanvas(barcodeCanvas, opts);
            // wklejamy barcode do głównego kontekstu, wyśrodkowany w prawej kolumnie
            const bcX = rightX + Math.round((rightW - bcW)/2);
            const bcY = Math.round((pxH - bcH)/2) - 20;
            ctx.drawImage(barcodeCanvas, bcX, bcY);
        } catch (err) {

            ctx.fillStyle = '#c00';
            ctx.textAlign = 'left';
            ctx.font = '14px Arial';
            ctx.fillText('Błąd generowania kodu: ' + (err && err.message ? err.message : err), rightX, Math.round(pxH/2));
        }

        // 3) data wydruku pod barcode (po prawej)
        ctx.fillStyle = '#000';
        ctx.font = Math.max(12, Math.round(pxH * 0.04)) + 'px Arial';
        ctx.textAlign = 'center';
        const now = new Date();
        const dateStr = now.getFullYear()+'-'+String(now.getMonth()+1).padStart(2,'0')+'-'+String(now.getDate()).padStart(2,'0')+' '+String(now.getHours()).padStart(2,'0')+':'+String(now.getMinutes()).padStart(2,'0');
        const dateX = rightX + Math.round(rightW/2);
        const dateY = Math.round(pxH - margin*2);
        ctx.fillText('Data: ' + dateStr, dateX, dateY);

        // ctx.strokeStyle = '#ddd'; ctx.strokeRect(1,1,pxW-2,pxH-2);

        return canvas;
    }
    const zplText = `<?= addslashes($zplOutput ?? '') ?>`;
    const preview = document.getElementById('preview');
    preview.innerHTML = ''; // czyścimy

    if (!zplText.trim()) {
        preview.innerHTML = '<div style="color:#666">Brak wygenerowanego ZPL do podglądu.</div>';
        return;
    }
    const barcodeType = (document.querySelector('select[name="barcode_type"]') || {value: 'Code128'}).value;
    const labelFormat = (document.querySelector('select[name="label_format"]') || {value: 'auto'}).value;
    const orientation = (document.querySelector('select[name="orientation"]') || {value: 'landscape'}).value;

    const fdRegex = /\^FD([^\\^]*)\^FS/g;
    let match;
    const found = [];
    while ((match = fdRegex.exec(zplText)) !== null) {
        // filtrujemy pola typu "Data: ..." (zawierające 'Data:') — chcemy głównie wartości kodów
        const val = match[1].trim();
        if (!val) continue;
        // jeżeli pole wygląda jak "Data: ..." - pomijamy
        if (/^Data:/i.test(val)) continue;
        found.push(val);
    }

    const listToRender = found.length ? found : [zplText];

    listToRender.forEach((code) => {
        try {
            const c = createLabelCanvas(code, barcodeType, labelFormat, orientation);
            preview.appendChild(c);
        } catch (e) {
            const err = document.createElement('div');
            err.style.color = '#c00';
            err.textContent = 'Błąd renderowania etykiety: ' + (e.message || e);
            preview.appendChild(err);
        }
    });

})();
</script>
</body>
</html>
