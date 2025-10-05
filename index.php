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
<title>Generator etykiet ZPL</title>
<script src="https://cdn.jsdelivr.net/npm/bwip-js@3.0.9/dist/bwip-js.min.js"></script>
<style>
:root {
    --primary: #2563eb;
    --primary-dark: #1d4ed8;
    --secondary: #64748b;
    --success: #10b981;
    --error: #ef4444;
    --warning: #f59e0b;
    --background: #f8fafc;
    --surface: #ffffff;
    --border: #e2e8f0;
    --text: #1e293b;
    --text-light: #64748b;
}

* {
    margin: 0;
    padding: 0;
    box-sizing: border-box;
}

body {
    font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
    line-height: 1.6;
    color: var(--text);
    background: var(--background);
    padding: 20px;
    min-height: 100vh;
}

.container {
    max-width: 1200px;
    margin: 0 auto;
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 30px;
    align-items: start;
}

@media (max-width: 768px) {
    .container {
        grid-template-columns: 1fr;
        gap: 20px;
    }
}

.header {
    grid-column: 1 / -1;
    text-align: center;
    margin-bottom: 10px;
}

.header h1 {
    font-size: 2rem;
    font-weight: 700;
    color: var(--primary);
    margin-bottom: 5px;
}

.header p {
    color: var(--text-light);
    font-size: 1.1rem;
}

.card {
    background: var(--surface);
    border-radius: 12px;
    padding: 30px;
    box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1), 0 2px 4px -1px rgba(0, 0, 0, 0.06);
    border: 1px solid var(--border);
}

.card h2 {
    font-size: 1.5rem;
    font-weight: 600;
    margin-bottom: 20px;
    color: var(--text);
    display: flex;
    align-items: center;
    gap: 10px;
}

.card h2::before {
    content: '';
    width: 4px;
    height: 20px;
    background: var(--primary);
    border-radius: 2px;
}

.form-group {
    margin-bottom: 24px;
}

.form-group label {
    display: block;
    font-weight: 500;
    margin-bottom: 8px;
    color: var(--text);
    font-size: 0.95rem;
}

.form-group textarea,
.form-group select,
.form-group input {
    width: 100%;
    padding: 12px 16px;
    border: 2px solid var(--border);
    border-radius: 8px;
    font-size: 1rem;
    transition: all 0.2s ease;
    background: var(--surface);
}

.form-group textarea:focus,
.form-group select:focus,
.form-group input:focus {
    outline: none;
    border-color: var(--primary);
    box-shadow: 0 0 0 3px rgba(37, 99, 235, 0.1);
}

.form-group textarea {
    min-height: 120px;
    resize: vertical;
    font-family: 'Courier New', monospace;
}

.btn {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: 8px;
    padding: 12px 24px;
    border: none;
    border-radius: 8px;
    font-size: 1rem;
    font-weight: 500;
    cursor: pointer;
    transition: all 0.2s ease;
    text-decoration: none;
}

.btn-primary {
    background: var(--primary);
    color: white;
}

.btn-primary:hover {
    background: var(--primary-dark);
    transform: translateY(-1px);
    box-shadow: 0 4px 12px rgba(37, 99, 235, 0.3);
}

.btn-secondary {
    background: var(--secondary);
    color: white;
}

.btn-secondary:hover {
    background: #475569;
    transform: translateY(-1px);
}

.actions {
    display: flex;
    gap: 12px;
    flex-wrap: wrap;
    margin-top: 24px;
}

.actions .btn {
    flex: 1;
    min-width: 140px;
}

.printer-input {
    display: flex;
    gap: 8px;
    align-items: center;
}

.printer-input input {
    flex: 1;
    min-width: 120px;
}

.preview-container {
    text-align: center;
}

#preview {
    min-height: 200px;
    display: flex;
    align-items: center;
    justify-content: center;
    background: var(--surface);
    border: 2px dashed var(--border);
    border-radius: 8px;
    margin-bottom: 20px;
}

#preview canvas {
    max-width: 100%;
    height: auto;
    border: 1px solid var(--border);
    border-radius: 4px;
}

.zpl-view {
    background: #1e293b;
    color: #e2e8f0;
    padding: 20px;
    border-radius: 8px;
    font-family: 'Courier New', monospace;
    font-size: 0.9rem;
    white-space: pre-wrap;
    word-break: break-all;
    max-height: 200px;
    overflow-y: auto;
    margin-bottom: 20px;
}

.codes-list {
    margin-top: 20px;
}

.codes-list h4 {
    font-size: 1.1rem;
    margin-bottom: 12px;
    color: var(--text);
}

.codes-list ul {
    list-style: none;
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(100px, 1fr));
    gap: 8px;
}

.codes-list li {
    background: var(--background);
    padding: 8px 12px;
    border-radius: 6px;
    text-align: center;
    font-family: 'Courier New', monospace;
    font-weight: 500;
    border: 1px solid var(--border);
}

.status {
    padding: 12px 16px;
    border-radius: 8px;
    margin-top: 16px;
    font-weight: 500;
}

.status.success {
    background: #dcfce7;
    color: #166534;
    border: 1px solid #bbf7d0;
}

.status.error {
    background: #fee2e2;
    color: #991b1b;
    border: 1px solid #fecaca;
}

.empty-state {
    color: var(--text-light);
    font-style: italic;
    padding: 40px 20px;
    text-align: center;
}

/* Loading animation */
.loading {
    display: inline-block;
    width: 20px;
    height: 20px;
    border: 3px solid rgba(255,255,255,.3);
    border-radius: 50%;
    border-top-color: #fff;
    animation: spin 1s ease-in-out infinite;
}

@keyframes spin {
    to { transform: rotate(360deg); }
}
</style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1>Generator etykiet ZPL</h1>
            <p>Twórz i podglądaj etykiety dla drukarek Zebra</p>
        </div>

        <div class="card">
            <h2>Konfiguracja etykiety</h2>
            <form method="post">
                <div class="form-group">
                    <label for="codes">Kody (oddzielone przecinkami lub spacjami)</label>
                    <textarea 
                        name="codes" 
                        id="codes"
                        placeholder="np. 1234567890, 0987654321, ABC123456"
                    ><?= htmlspecialchars($_POST['codes'] ?? '') ?></textarea>
                </div>

                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 20px;">
                    <div class="form-group">
                        <label for="barcode_type">Typ kodu kreskowego</label>
                        <select name="barcode_type" id="barcode_type">
                            <option value="Code128" <?= ($_POST['barcode_type'] ?? 'Code128') === 'Code128' ? 'selected' : '' ?>>Code128</option>
                            <option value="EAN13" <?= ($_POST['barcode_type'] ?? '') === 'EAN13' ? 'selected' : '' ?>>EAN13</option>
                            <option value="Code39" <?= ($_POST['barcode_type'] ?? '') === 'Code39' ? 'selected' : '' ?>>Code39</option>
                            <option value="QR" <?= ($_POST['barcode_type'] ?? '') === 'QR' ? 'selected' : '' ?>>QR Code</option>
                        </select>
                    </div>

                    <div class="form-group">
                        <label for="orientation">Orientacja</label>
                        <select name="orientation" id="orientation">
                            <option value="landscape" <?= ($_POST['orientation'] ?? 'landscape') === 'landscape' ? 'selected' : '' ?>>Pozioma</option>
                            <option value="portrait" <?= ($_POST['orientation'] ?? '') === 'portrait' ? 'selected' : '' ?>>Pionowa</option>
                        </select>
                    </div>
                </div>

                <div class="form-group">
                    <label for="label_format">Format etykiety</label>
                    <select name="label_format" id="label_format">
                        <option value="auto" <?= ($_POST['label_format'] ?? 'auto') === 'auto' ? 'selected' : '' ?>>Auto (100×150 mm)</option>
                        <option value="100x150" <?= ($_POST['label_format'] ?? '') === '100x150' ? 'selected' : '' ?>>100×150 mm</option>
                        <option value="60x40" <?= ($_POST['label_format'] ?? '') === '60x40' ? 'selected' : '' ?>>60×40 mm</option>
                        <option value="58x100" <?= ($_POST['label_format'] ?? '') === '58x100' ? 'selected' : '' ?>>58×100 mm</option>
                        <option value="80x50" <?= ($_POST['label_format'] ?? '') === '80x50' ? 'selected' : '' ?>>80×50 mm</option>
                    </select>
                </div>

                <button type="submit" class="btn btn-primary" style="width: 100%;">
                    Generuj etykiety
                </button>
            </form>
        </div>

        <div class="card">
            <h2>Podgląd i eksport</h2>
            
            <div class="preview-container">
                <div id="preview">
                    <div class="empty-state">
                        Wygeneruj etykiety, aby zobaczyć podgląd
                    </div>
                </div>
            </div>

            <div class="actions">
                <div class="printer-input">
                    <input 
                        type="text" 
                        id="printer_ip" 
                        placeholder="IP drukarki" 
                        value="<?= htmlspecialchars($_POST['printer_ip'] ?? '') ?>"
                    >
                    <button type="button" onclick="sendToPrinter()" class="btn btn-primary">
                        Wyślij do drukarki
                    </button>
                </div>
                <button type="button" onclick="saveToFile()" class="btn btn-secondary">
                    Zapisz ZPL
                </button>
            </div>

            <div id="status"></div>

            <h3 style="margin: 24px 0 12px 0;">Kod ZPL</h3>
            <div class="zpl-view"><?= htmlspecialchars($zplOutput) ?: '// Brak wygenerowanego kodu ZPL' ?></div>

            <?php if (!empty($codes)): ?>
            <div class="codes-list">
                <h4>Wygenerowane kody (<?= count($codes) ?>)</h4>
                <ul>
                    <?php foreach ($codes as $code): ?>
                        <li><?= htmlspecialchars($code) ?></li>
                    <?php endforeach; ?>
                </ul>
            </div>
            <?php endif; ?>
        </div>
    </div>

    <script>
    // ... (pozostała część kodu JavaScript pozostaje bez zmian)
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
            preview.innerHTML = '<div class="status error">Nie udało się załadować biblioteki bwip-js z CDN.</div>';
            return;
        }

        const bwipGlobal = window.bwipjs || window.BWIPJS || null;
        if (!bwipGlobal) {
            document.getElementById('preview').innerHTML = '<div class="status error">Biblioteka załadowana ale nie znaleziono obiektu bwipjs.</div>';
            return;
        }
        const bwip = bwipGlobal;

        // ... (funkcja createLabelCanvas pozostaje bez zmian)
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

            const margin = mmToPx(5);
            const contentWidth = pxW - (2 * margin);

            // 1) GŁÓWNY TEKST - BARDZO AGRESYWNE DOPASOWANIE DO SZEROKOŚCI
            ctx.fillStyle = '#000';
            ctx.textBaseline = 'middle';
            
            const compactFonts = [
                "Arial Narrow, Arial, sans-serif",
                "Arial, sans-serif"
            ];
            
            let bestFont = compactFonts[0];
            let bestFontSize = 0;
            let textFits = false;
            
            let startSize = Math.min(pxH * 0.5, pxW * 0.2);
            
            for (const font of compactFonts) {
                let currentFontSize = startSize;
                
                while (currentFontSize <= Math.min(pxH * 0.8, pxW * 0.4)) {
                    ctx.font = `bold ${currentFontSize}px ${font}`;
                    const metrics = ctx.measureText(code);
                    const textW = metrics.width;
                    
                    if (textW <= contentWidth * 0.98) {
                        if (currentFontSize > bestFontSize) {
                            bestFontSize = currentFontSize;
                            bestFont = font;
                            textFits = true;
                        }
                        currentFontSize = Math.floor(currentFontSize * 1.05);
                    } else {
                        break;
                    }
                }
                
                if (!textFits) {
                    currentFontSize = startSize;
                    while (currentFontSize > 8) {
                        ctx.font = `bold ${currentFontSize}px ${font}`;
                        const metrics = ctx.measureText(code);
                        const textW = metrics.width;
                        
                        if (textW <= contentWidth) {
                            if (currentFontSize > bestFontSize) {
                                bestFontSize = currentFontSize;
                                bestFont = font;
                                textFits = true;
                            }
                            break;
                        }
                        currentFontSize = Math.floor(currentFontSize * 0.7);
                    }
                }
            }
            
            if (!textFits || bestFontSize === 0) {
                bestFontSize = Math.max(8, Math.min(pxH * 0.3, pxW * 0.15));
                bestFont = compactFonts[0];
            }
            
            ctx.font = `bold ${bestFontSize}px ${bestFont}`;
            
            const textX = pxW / 2;
            const textY = margin + (bestFontSize / 2);
            ctx.textAlign = 'center';
            ctx.fillText(code, textX, textY);

            // 2) KOD KRESKOWY POD TEKSTEM
            const textBottom = textY + (bestFontSize / 2) + mmToPx(2);
            const barcodeTop = textBottom + mmToPx(1);
            
            const barcodeHeight = Math.max(mmToPx(6), Math.round(pxH * 0.15));
            
            const dateAreaHeight = mmToPx(6);
            const availableSpace = pxH - barcodeTop - dateAreaHeight - margin;
            const finalBarcodeHeight = Math.min(barcodeHeight, availableSpace);

            const barcodeCanvas = document.createElement('canvas');
            const bcW = contentWidth;
            const bcH = finalBarcodeHeight;
            barcodeCanvas.width = bcW;
            barcodeCanvas.height = bcH;

            let bcid = 'code128';
            if (type === 'EAN13') bcid = 'ean13';
            if (type === 'Code39') bcid = 'code39';
            if (type === 'QR') bcid = 'qrcode';

            try {
                const opts = {
                    bcid: bcid,
                    text: '',
                    scale: Math.max(1, Math.round(bcW / 100)),
                    height: Math.round(bcH / 8),
                    includetext: false,
                    textxalign: 'center'
                };
                
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
                ctx.font = '10px Arial';
                ctx.fillText('Błąd kodu', pxW/2, barcodeTop + (bcH/2));
            }

            // 3) DATA WYDRUKU NA DOLE
            ctx.fillStyle = '#000';
            const dateFontSize = Math.max(6, Math.round(pxH * 0.02));
            ctx.font = `bold ${dateFontSize}px Arial`;
            ctx.textAlign = 'center';
            const now = new Date();
            const dateStr = now.getFullYear()+'-'+String(now.getMonth()+1).padStart(2,'0')+'-'+String(now.getDate()).padStart(2,'0')+' '+String(now.getHours()).padStart(2,'0')+':'+String(now.getMinutes()).padStart(2,'0');
            const dateX = pxW / 2;
            const dateY = pxH - margin - (dateFontSize / 2);
            ctx.fillText('Data: ' + dateStr, dateX, dateY);

            ctx.strokeStyle = '#ddd';
            ctx.strokeRect(1, 1, pxW-2, pxH-2);

            return canvas;
        }

        const zplText = `<?= addslashes($zplOutput ?? '') ?>`;
        const preview = document.getElementById('preview');
        preview.innerHTML = '';

        if (!zplText.trim()) {
            return;
        }

        const barcodeType = (document.querySelector('select[name="barcode_type"]') || {value: 'Code128'}).value;
        const labelFormat = (document.querySelector('select[name="label_format"]') || {value: 'auto'}).value;
        const orientation = (document.querySelector('select[name="orientation"]') || {value: 'landscape'}).value;

        const codesToRender = <?= json_encode($codes ?? []) ?>;

        if (codesToRender.length > 0) {
            const firstCode = codesToRender[0];
            try {
                const c = createLabelCanvas(firstCode, barcodeType, labelFormat, orientation);
                preview.innerHTML = '';
                preview.appendChild(c);
            } catch (e) {
                preview.innerHTML = '<div class="status error">Błąd renderowania etykiety: ' + (e.message || e) + '</div>';
            }
        }
    })();

    async function sendToPrinter() {
        const printerIp = document.getElementById('printer_ip').value.trim();
        const zplContent = `<?= addslashes($zplOutput ?? '') ?>`;
        const statusDiv = document.getElementById('status');
        const btn = event.target;
        const originalText = btn.textContent;
        
        if (!printerIp) {
            showStatus('Proszę podać adres IP drukarki', 'error');
            return;
        }
        
        if (!zplContent) {
            showStatus('Brak danych ZPL do wysłania', 'error');
            return;
        }
        
        btn.innerHTML = '<div class="loading"></div> Wysyłanie...';
        btn.disabled = true;
        
        showStatus('Wysyłanie do drukarki...', '');
        
        try {
            await fetch(`http://${printerIp}:9100`, {
                method: 'POST',
                body: zplContent,
                mode: 'no-cors'
            });
            
            showStatus('Pomyślnie wysłano do drukarki', 'success');
            
        } catch (error) {
            showStatus('Błąd wysyłania do drukarki: ' + error.message, 'error');
        } finally {
            btn.innerHTML = originalText;
            btn.disabled = false;
        }
    }

    function saveToFile() {
        const zplContent = `<?= addslashes($zplOutput ?? '') ?>`;
        const statusDiv = document.getElementById('status');
        
        if (!zplContent) {
            showStatus('Brak danych ZPL do zapisania', 'error');
            return;
        }
        
        try {
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

    function showStatus(message, type) {
        const statusDiv = document.getElementById('status');
        statusDiv.textContent = message;
        statusDiv.className = 'status';
        if (type) {
            statusDiv.classList.add(type);
        }
        
        setTimeout(() => {
            statusDiv.textContent = '';
            statusDiv.className = 'status';
        }, 5000);
    }
    </script>
</body>
</html>
