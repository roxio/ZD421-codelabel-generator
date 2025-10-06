<?php
class ZPLViewer {
    private $zpl_content = '';
    private $scale_factor = 2.0;
    private $image = null;
    
    public function __construct() {
        if (!extension_loaded('gd')) {
            throw new Exception('Rozszerzenie GD nie jest dostępne');
        }
    }
    
    public function loadZPLFromFile($file_path) {
        if (!file_exists($file_path)) {
            throw new Exception("Plik nie istnieje: $file_path");
        }
        
        $this->zpl_content = file_get_contents($file_path);
        if ($this->zpl_content === false) {
            throw new Exception("Nie można odczytać pliku: $file_path");
        }
        
        return $this->zpl_content;
    }
    
    public function loadZPLFromString($zpl_string) {
        $this->zpl_content = $zpl_string;
        return $this->zpl_content;
    }
    
    public function setScale($scale) {
        $this->scale_factor = max(0.5, min(5.0, $scale));
    }
    
    public function parseDimensions() {
        $width = 600;
        $height = 400;
        
        if (preg_match('/\^PW(\d+)/', $this->zpl_content, $matches)) {
            $width = (int)$matches[1];
        }
        
        if (preg_match('/\^LL(\d+)/', $this->zpl_content, $matches)) {
            $height = (int)$matches[1];
        }
        
        return ['width' => $width, 'height' => $height];
    }
    
    public function renderPreview() {
        if (empty($this->zpl_content)) {
            throw new Exception('Brak kodu ZPL do renderowania');
        }
        
        $dimensions = $this->parseDimensions();
        $orig_width = $dimensions['width'];
        $orig_height = $dimensions['height'];
        
        $scaled_width = (int)($orig_width * $this->scale_factor);
        $scaled_height = (int)($orig_height * $this->scale_factor);
        
        $this->image = imagecreate($scaled_width, $scaled_height);
        
        $white = imagecolorallocate($this->image, 255, 255, 255);
        $black = imagecolorallocate($this->image, 0, 0, 0);
        
        imagefill($this->image, 0, 0, $white);
        $this->parseZPLElements($orig_width, $orig_height, $scaled_width, $scaled_height);
        
        return $this->image;
    }
    
    private function parseZPLElements($orig_width, $orig_height, $scaled_width, $scaled_height) {
        $lines = explode("\n", $this->zpl_content);
        
        foreach ($lines as $line) {
            $line = trim($line);
            if (empty($line)) continue;
            
            if (strpos($line, '^FD') !== false && strpos($line, '^FS') !== false) {
                $this->drawText($line, $orig_width, $orig_height, $scaled_width, $scaled_height);
            }
            elseif (strpos($line, '^GB') !== false && strpos($line, '^FS') !== false) {
                $this->drawBox($line, $orig_width, $orig_height, $scaled_width, $scaled_height);
            }
            elseif (preg_match('/\^B[3BCYQ][^\\^]*\^FD[^^]+\^FS/', $line)) {
                $this->drawBarcode($line, $orig_width, $orig_height, $scaled_width, $scaled_height);
            }
        }
    }
    
    private function drawText($line, $orig_width, $orig_height, $scaled_width, $scaled_height) {
        if (!preg_match('/\^FO(\d+),(\d+)/', $line, $fo_matches)) return;
        if (!preg_match('/\^FD([^^]+)\^FS/', $line, $fd_matches)) return;
        
        $x = (int)$fo_matches[1];
        $y = (int)$fo_matches[2];
        $text = $fd_matches[1];
        
        $scale_x = $scaled_width / $orig_width;
        $scale_y = $scaled_height / $orig_height;
        
        $scaled_x = (int)($x * $scale_x);
        $scaled_y = (int)($y * $scale_y);
        
        $black = imagecolorallocate($this->image, 0, 0, 0);
        $font_size = max(1, (int)(3 * min($scale_x, $scale_y)));
        
        imagestring($this->image, $font_size, $scaled_x, $scaled_y, $text, $black);
    }
    
    private function drawBarcode($line, $orig_width, $orig_height, $scaled_width, $scaled_height) {
        if (!preg_match('/\^FO(\d+),(\d+)/', $line, $fo_matches)) return;
        if (!preg_match('/\^FD([^^]+)\^FS/', $line, $fd_matches)) return;
        
        $x = (int)$fo_matches[1];
        $y = (int)$fo_matches[2];
        $barcode_data = $fd_matches[1];
        
        $scale_x = $scaled_width / $orig_width;
        $scale_y = $scaled_height / $orig_height;
        
        $scaled_x = (int)($x * $scale_x);
        $scaled_y = (int)($y * $scale_y);
        
        $black = imagecolorallocate($this->image, 0, 0, 0);
        
        // Wymiary kodu kreskowego - pełna szerokość
        $bar_width = (int)($orig_width * 0.9 * $scale_x);
        $bar_height = (int)(60 * $scale_y);
        
        // Wyśrodkuj kod kreskowy
        $scaled_x = (int)(($scaled_width - $bar_width) / 2);
        
        // Narysuj kontur kodu kreskowego
        imagerectangle($this->image, $scaled_x, $scaled_y, $scaled_x + $bar_width, $scaled_y + $bar_height, $black);
        
        // Określ typ kodu kreskowego
        $barcode_type = 'CODE128';
        if (strpos($line, '^BQ') !== false) {
            $barcode_type = 'QR';
        } elseif (strpos($line, '^B3') !== false) {
            $barcode_type = 'CODE39';
        } elseif (strpos($line, '^BE') !== false) {
            $barcode_type = 'EAN13';
        }
        
        // Narysuj symulację kodu kreskowego
        if ($barcode_type === 'QR') {
            $cell_size = max(3, (int)($bar_width / 12));
            for ($i = 0; $i < 12; $i++) {
                for ($j = 0; $j < 12; $j++) {
                    if (($i < 3 && $j < 3) || ($i < 3 && $j >= 9) || ($i >= 9 && $j < 3) || 
                        ($i % 4 === 0 && $j % 4 === 0) || (($i + $j) % 3 === 0)) {
                        imagefilledrectangle(
                            $this->image,
                            $scaled_x + ($i * $cell_size),
                            $scaled_y + ($j * $cell_size),
                            $scaled_x + (($i + 1) * $cell_size) - 1,
                            $scaled_y + (($j + 1) * $cell_size) - 1,
                            $black
                        );
                    }
                }
            }
        } else {
            $num_bars = 15;
            $bar_spacing = $bar_width / $num_bars;
            
            for ($i = 0; $i < $num_bars; $i++) {
                $bar_x = $scaled_x + ($i * $bar_spacing);
                $bar_width_single = max(2, (int)($bar_spacing * 0.7));
                
                $bar_pattern = [true, false, true, true, false, true, false, false, true, false, true, true, false, true, true];
                
                if ($bar_pattern[$i % count($bar_pattern)]) {
                    $bar_height_actual = $bar_height - 10;
                    imagefilledrectangle(
                        $this->image,
                        (int)$bar_x,
                        $scaled_y + 5,
                        (int)($bar_x + $bar_width_single),
                        $scaled_y + $bar_height_actual,
                        $black
                    );
                }
            }
        }
        
        // Dodaj tekst z danymi pod kodem
        $text_y = $scaled_y + $bar_height + 10;
        $font_size = max(1, (int)(2 * min($scale_x, $scale_y)));
        imagestring($this->image, $font_size, $scaled_x + 10, $text_y, $barcode_data, $black);
    }
    
    public function getImageData() {
        if (!$this->image) {
            throw new Exception('Brak obrazu');
        }
        
        ob_start();
        imagepng($this->image);
        $image_data = ob_get_clean();
        return base64_encode($image_data);
    }
    
    public function __destruct() {
        if ($this->image) {
            imagedestroy($this->image);
        }
    }
}

function parse_codes($input) {
    if (empty(trim($input))) return [];
    $parts = preg_split('/[,\s]+/u', trim($input));
    return array_values(array_filter($parts, fn($p) => $p !== ''));
}

function generate_zpl($codes, $barcodeType, $orientation, $labelFormat) {
    $formats = [
        'auto' => [600, 400],
        '100x150' => [600, 400],
        '60x40'   => [240, 160],
        '58x100'  => [232, 400],
        '80x50'   => [320, 200],
    ];
    [$width, $height] = $formats[$labelFormat] ?? $formats['auto'];

    $zpl = "^XA\n";
    $zpl .= "^PW$width\n";
    $zpl .= "^LL$height\n";
    
    if ($orientation === 'portrait') {
        $zpl .= "^PON\n";
    } else {
        $zpl .= "^POI\n";
    }

    foreach ($codes as $code) {
        $date = date('Y-m-d H:i');
        
        // Tekst z kodem
        $zpl .= "^FO20,20^A0N,25,25^FD$code^FS\n";
        
        // Kod kreskowy - pełna szerokość
        $barcode_y = 50;
        $barcode_width = $width - 40;
        
        if ($barcodeType === 'QR') {
            $zpl .= "^FO" . (($width - 150) / 2) . ",$barcode_y^BQN,2,8^FDQA,$code^FS\n";
        } elseif ($barcodeType === 'Code39') {
            $zpl .= "^FO20,$barcode_y^BY3^B3N,N,$barcode_width,Y,N^FD$code^FS\n";
        } elseif ($barcodeType === 'EAN13') {
            $zpl .= "^FO20,$barcode_y^BY3^BEN,$barcode_width,Y,N^FD$code^FS\n";
        } else {
            $zpl .= "^FO20,$barcode_y^BY3^BCN,$barcode_width,Y,N,N^FD$code^FS\n";
        }
        
        // Data
        $date_y = $barcode_y + 70;
        $zpl .= "^FO20,$date_y^A0N,18,18^FD$date^FS\n";
    }
    
    $zpl .= "^XZ";
    
    return $zpl;
}

// Start sesji
session_start();

// Domyślny ZPL
$default_zpl = "^XA
^PW600
^LL400
^FO20,20^A0N,25,25^FD123456789^FS
^FO20,50^BY3^BCN,560,Y,N,N^FD123456789^FS
^FO20,130^A0N,18,18^FD" . date('Y-m-d H:i') . "^FS
^XZ";

// Inicjalizacja zmiennych
$message = ''; 
$message_type = ''; 
$zpl_content = ''; 
$preview_data = ''; 
$dimensions = '';
$zplOutput = ''; 
$codes = []; 
$uploadedFileContent = '';

// Obsługa formularzy
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        // Wczytywanie pliku ZPL
        if (isset($_FILES['zpl_file']) && $_FILES['zpl_file']['error'] === UPLOAD_ERR_OK) {
            $viewer = new ZPLViewer();
            $uploadedFileContent = $viewer->loadZPLFromFile($_FILES['zpl_file']['tmp_name']);
            $zplOutput = $uploadedFileContent;
            $message = "Plik ZPL załadowany pomyślnie";
            $message_type = 'success';
        }
        
        // Generowanie nowych kodów
        if (isset($_POST['generate']) && !empty($_POST['codes'])) {
            $codes = parse_codes($_POST['codes']);
            if (!empty($codes)) {
                $zplOutput = generate_zpl(
                    $codes,
                    $_POST['barcode_type'] ?? 'Code128',
                    $_POST['orientation'] ?? 'landscape',
                    $_POST['label_format'] ?? 'auto'
                );
                $message = "Wygenerowano " . count($codes) . " etykiet";
                $message_type = 'success';
            }
        }
        
        // Renderowanie podglądu
        if (isset($_POST['render_preview']) || !empty($zplOutput)) {
            $viewer = new ZPLViewer();
            $zpl_content = $zplOutput ?: ($_SESSION['zpl_content'] ?? $default_zpl);
            $viewer->loadZPLFromString($zpl_content);
            
            $scale = $_SESSION['scale'] ?? 2.0;
            $viewer->setScale($scale);
            $viewer->renderPreview();
            $preview_data = $viewer->getImageData();
            
            $dim = $viewer->parseDimensions();
            $dimensions = "Etykieta: {$dim['width']}x{$dim['height']} | Skala: {$scale}x";
            
            $_SESSION['zpl_content'] = $zpl_content;
        }
        
        // Aktualizacja skali
        if (isset($_POST['apply_scale'])) {
            $_SESSION['scale'] = floatval($_POST['scale']);
            $message = "Skala zastosowana";
            $message_type = 'success';
        }
        
        // Czyszczenie
        if (isset($_POST['clear'])) {
            session_destroy();
            session_start();
            $zpl_content = $default_zpl;
            $_SESSION['zpl_content'] = $default_zpl;
            $zplOutput = ''; 
            $codes = [];
            $message = "Wyczyszczono";
            $message_type = 'success';
        }
        
    } catch (Exception $e) {
        $message = "Błąd: " . $e->getMessage();
        $message_type = 'error';
    }
}

// Przywracanie zawartości
$zpl_content = $_SESSION['zpl_content'] ?? $default_zpl;
if (!isset($_SESSION['scale'])) {
    $_SESSION['scale'] = 2.0;
}
?>
<!DOCTYPE html>
<html lang="pl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Generator ZPL</title>
    <style>
        :root {
            --primary: #3b82f6;
            --primary-dark: #2563eb;
            --secondary: #64748b;
            --success: #10b981;
            --error: #ef4444;
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
            font-family: 'Inter', -apple-system, BlinkMacSystemFont, sans-serif;
            line-height: 1.6;
            color: var(--text);
            background: var(--background);
            padding: 20px;
            min-height: 100vh;
        }

        .container {
            max-width: 800px;
            margin: 0 auto;
        }

        .header {
            text-align: center;
            margin-bottom: 30px;
        }

        .header h1 {
            font-size: 2rem;
            font-weight: 700;
            background: linear-gradient(135deg, var(--primary), var(--primary-dark));
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            margin-bottom: 8px;
        }

        .header p {
            color: var(--text-light);
            font-size: 1.1rem;
        }

        .card {
            background: var(--surface);
            border-radius: 12px;
            padding: 25px;
            box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1);
            border: 1px solid var(--border);
            margin-bottom: 20px;
        }

        .card h2 {
            font-size: 1.3rem;
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
            background: linear-gradient(135deg, var(--primary), var(--primary-dark));
            border-radius: 2px;
        }

        .form-group {
            margin-bottom: 20px;
        }

        .form-group label {
            display: block;
            font-weight: 500;
            margin-bottom: 6px;
            color: var(--text);
            font-size: 0.9rem;
        }

        .form-group textarea,
        .form-group select,
        .form-group input {
            width: 100%;
            padding: 12px;
            border: 2px solid var(--border);
            border-radius: 8px;
            font-size: 0.95rem;
            transition: all 0.2s ease;
            background: var(--surface);
            font-family: inherit;
        }

        .form-group textarea:focus,
        .form-group select:focus,
        .form-group input:focus {
            outline: none;
            border-color: var(--primary);
            box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.1);
        }

        .form-group textarea {
            min-height: 80px;
            resize: vertical;
            font-family: 'JetBrains Mono', monospace;
            font-size: 0.9rem;
        }

        .form-group input[type="file"] {
            padding: 8px;
            border: 2px dashed var(--border);
            background: var(--background);
            cursor: pointer;
        }

        .form-group input[type="file"]::file-selector-button {
            background: var(--primary);
            color: white;
            border: none;
            padding: 8px 16px;
            border-radius: 6px;
            margin-right: 12px;
            cursor: pointer;
            transition: background 0.2s ease;
        }

        .form-group input[type="file"]::file-selector-button:hover {
            background: var(--primary-dark);
        }

        .btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 6px;
            padding: 12px 20px;
            border: none;
            border-radius: 8px;
            font-size: 0.95rem;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.2s ease;
            text-decoration: none;
            font-family: inherit;
        }

        .btn-primary {
            background: linear-gradient(135deg, var(--primary), var(--primary-dark));
            color: white;
        }

        .btn-primary:hover {
            transform: translateY(-1px);
            box-shadow: 0 4px 12px rgba(59, 130, 246, 0.3);
        }

        .btn-secondary {
            background: var(--secondary);
            color: white;
        }

        .btn-secondary:hover {
            background: #475569;
            transform: translateY(-1px);
        }

        .btn-danger {
            background: var(--error);
            color: white;
        }

        .btn-danger:hover {
            background: #dc2626;
            transform: translateY(-1px);
        }

        .actions {
            display: flex;
            gap: 10px;
            flex-wrap: wrap;
            margin-top: 20px;
        }

        .actions .btn {
            flex: 1;
            min-width: 120px;
        }

        .printer-input {
            display: flex;
            gap: 10px;
            align-items: center;
        }

        .printer-input input {
            flex: 1;
        }

        .preview-container {
            text-align: center;
            margin: 20px 0;
        }

        .preview-image {
            max-width: 100%;
            height: auto;
            border: 2px solid var(--border);
            border-radius: 8px;
            box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
        }

        .zpl-view {
            background: #1e293b;
            color: #e2e8f0;
            padding: 15px;
            border-radius: 8px;
            font-family: 'JetBrains Mono', monospace;
            font-size: 0.85rem;
            white-space: pre-wrap;
            word-break: break-all;
            max-height: 200px;
            overflow-y: auto;
            margin-bottom: 15px;
            border: 1px solid #334155;
        }

        .codes-list {
            margin-top: 20px;
        }

        .codes-list h4 {
            font-size: 1rem;
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
            background: #f1f5f9;
            padding: 10px;
            border-radius: 6px;
            text-align: center;
            font-family: 'JetBrains Mono', monospace;
            font-weight: 600;
            border: 1px solid var(--border);
            font-size: 0.9rem;
        }

        .status {
            padding: 12px 16px;
            border-radius: 8px;
            margin-top: 15px;
            font-weight: 500;
            text-align: center;
            font-size: 0.9rem;
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
            background: var(--background);
            border: 2px dashed var(--border);
            border-radius: 8px;
            font-size: 0.9rem;
        }

        .scale-control {
            display: flex;
            align-items: center;
            gap: 10px;
            margin: 15px 0;
            padding: 12px;
            background: #f8fafc;
            border-radius: 8px;
            border: 1px solid var(--border);
            font-size: 0.9rem;
        }

        .scale-control label {
            font-weight: 600;
            color: var(--text);
        }

        .scale-control input {
            width: 70px;
            padding: 8px;
            border: 2px solid var(--border);
            border-radius: 6px;
            text-align: center;
        }

        .tab-buttons {
            display: flex;
            border-bottom: 2px solid var(--border);
            margin-bottom: 15px;
        }

        .tab-button {
            padding: 12px 20px;
            background: none;
            border: none;
            cursor: pointer;
            font-weight: 600;
            color: var(--text-light);
            border-bottom: 2px solid transparent;
            transition: all 0.2s ease;
            font-size: 0.9rem;
        }

        .tab-button.active {
            color: var(--primary);
            border-bottom-color: var(--primary);
        }

        .tab-content {
            display: none;
        }

        .tab-content.active {
            display: block;
        }

        .info-panel {
            background: #f0f9ff;
            border: 1px solid #bae6fd;
            border-radius: 8px;
            padding: 15px;
            margin-top: 15px;
            font-size: 0.9rem;
        }

        .form-row {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 15px;
        }

        .loading {
            display: inline-block;
            width: 16px;
            height: 16px;
            border: 2px solid rgba(255,255,255,.3);
            border-radius: 50%;
            border-top-color: #fff;
            animation: spin 1s ease-in-out infinite;
        }

        @keyframes spin {
            to { transform: rotate(360deg); }
        }

        @media (max-width: 640px) {
            .container {
                padding: 10px;
            }
            
            .card {
                padding: 20px;
            }
            
            .form-row {
                grid-template-columns: 1fr;
            }
            
            .actions {
                flex-direction: column;
            }
            
            .tab-buttons {
                flex-direction: column;
            }
            
            .tab-button {
                text-align: center;
            }
            
            .printer-input {
                flex-direction: column;
            }
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1>Generator ZPL</h1>
            <p>Twórz i drukuj etykiety z kodami kreskowymi</p>
        </div>

        <div class="card">
            <h2>Konfiguracja</h2>
            <form method="post" enctype="multipart/form-data">
                <div class="form-group">
                    <label for="codes">Kody (oddzielone przecinkami)</label>
                    <textarea 
                        name="codes" 
                        id="codes"
                        placeholder="1234567890, ABC123456, 987654321"
                    ><?= htmlspecialchars($_POST['codes'] ?? '1234567890, ABC123456') ?></textarea>
                </div>

                <div class="form-group">
                    <label for="zpl_file">Lub wczytaj plik ZPL</label>
                    <input 
                        type="file" 
                        name="zpl_file" 
                        id="zpl_file" 
                        accept=".zpl,.txt"
                    >
                </div>

                <div class="form-row">
                    <div class="form-group">
                        <label for="barcode_type">Typ kodu</label>
                        <select name="barcode_type" id="barcode_type">
                            <option value="Code128" <?= ($_POST['barcode_type'] ?? 'Code128') === 'Code128' ? 'selected' : '' ?>>Code128</option>
                            <option value="Code39" <?= ($_POST['barcode_type'] ?? '') === 'Code39' ? 'selected' : '' ?>>Code39</option>
                            <option value="EAN13" <?= ($_POST['barcode_type'] ?? '') === 'EAN13' ? 'selected' : '' ?>>EAN13</option>
                            <option value="QR" <?= ($_POST['barcode_type'] ?? '') === 'QR' ? 'selected' : '' ?>>QR Code</option>
                        </select>
                    </div>

                    <div class="form-group">
                        <label for="label_format">Format etykiety</label>
                        <select name="label_format" id="label_format">
                            <option value="auto" <?= ($_POST['label_format'] ?? 'auto') === 'auto' ? 'selected' : '' ?>>Auto (100×150)</option>
                            <option value="100x150" <?= ($_POST['label_format'] ?? '') === '100x150' ? 'selected' : '' ?>>100×150 mm</option>
                            <option value="60x40" <?= ($_POST['label_format'] ?? '') === '60x40' ? 'selected' : '' ?>>60×40 mm</option>
                        </select>
                    </div>
                </div>

                <div class="actions">
                    <button type="submit" name="generate" class="btn btn-primary">
                        🏷️ Generuj
                    </button>
                    <button type="submit" name="render_preview" class="btn btn-secondary">
                        👁️ Podgląd
                    </button>
                </div>
            </form>

            <?php if (!empty($codes)): ?>
            <div class="codes-list">
                <h4>📋 Wygenerowane kody (<?= count($codes) ?>)</h4>
                <ul>
                    <?php foreach ($codes as $code): ?>
                        <li><?= htmlspecialchars($code) ?></li>
                    <?php endforeach; ?>
                </ul>
            </div>
            <?php endif; ?>
        </div>

        <div class="card">
            <h2>Podgląd i druk</h2>
            
            <div class="preview-container">
                <?php if ($preview_data): ?>
                    <img src="data:image/png;base64,<?= $preview_data ?>" 
                         alt="Podgląd ZPL" class="preview-image">
                    <?php if ($dimensions): ?>
                        <div class="info-panel">
                            <p><strong>📐 <?= htmlspecialchars($dimensions) ?></strong></p>
                        </div>
                    <?php endif; ?>
                <?php else: ?>
                    <div class="empty-state">
                        <p>Wygeneruj etykiety, aby zobaczyć podgląd</p>
                    </div>
                <?php endif; ?>
            </div>

            <div class="tab-buttons">
                <button type="button" class="tab-button active" onclick="showTab('zplTab')">📝 Kod ZPL</button>
                <button type="button" class="tab-button" onclick="showTab('actionsTab')">⚡ Akcje</button>
            </div>

            <div id="zplTab" class="tab-content active">
                <div class="form-group">
                    <textarea 
                        class="zpl-view"
                        rows="8"
                        readonly
                    ><?= htmlspecialchars($zplOutput ?: $zpl_content) ?></textarea>
                </div>
                
                <form method="post" class="scale-control">
                    <label>🔍 Skala:</label>
                    <input type="number" name="scale" step="0.1" min="0.5" max="5.0" 
                           value="<?= htmlspecialchars($_SESSION['scale']) ?>">
                    <button type="submit" name="apply_scale" class="btn btn-secondary">Zastosuj</button>
                </form>
            </div>

            <div id="actionsTab" class="tab-content">
                <div class="form-group">
                    <label for="printer_ip">Adres IP drukarki Zebra</label>
                    <div class="printer-input">
                        <input 
                            type="text" 
                            id="printer_ip" 
                            placeholder="192.168.1.100"
                            value="<?= htmlspecialchars($_POST['printer_ip'] ?? '') ?>"
                        >
                        <button type="button" onclick="sendToPrinter()" class="btn btn-primary">
                            🖨️ Drukuj
                        </button>
                    </div>
                </div>

                <div class="actions">
                    <button type="button" onclick="saveToFile()" class="btn btn-secondary">
                        💾 Zapisz ZPL
                    </button>
                    <button type="submit" name="clear" class="btn btn-danger">
                        🗑️ Wyczyść
                    </button>
                </div>
            </div>

            <?php if ($message): ?>
                <div class="status <?= $message_type ?>">
                    <?= htmlspecialchars($message) ?>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <script>
    function showTab(tabName) {
        document.querySelectorAll('.tab-content').forEach(tab => tab.classList.remove('active'));
        document.querySelectorAll('.tab-button').forEach(btn => btn.classList.remove('active'));
        
        document.getElementById(tabName).classList.add('active');
        event.target.classList.add('active');
    }

    function saveToFile() {
        const zplContent = `<?= addslashes($zplOutput ?? '') ?>`;
        
        if (!zplContent) {
            alert('Brak danych ZPL do zapisania');
            return;
        }
        
        try {
            const blob = new Blob([zplContent], { type: 'text/plain' });
            const url = URL.createObjectURL(blob);
            const a = document.createElement('a');
            a.href = url;
            a.download = 'etykiety_' + new Date().toISOString().slice(0,19).replace(/:/g,'-') + '.zpl';
            document.body.appendChild(a);
            a.click();
            document.body.removeChild(a);
            URL.revokeObjectURL(url);
        } catch (error) {
            alert('Błąd zapisywania pliku: ' + error.message);
        }
    }

    async function sendToPrinter() {
        const printerIp = document.getElementById('printer_ip').value.trim();
        const zplContent = `<?= addslashes($zplOutput ?? '') ?>`;
        const btn = event.target;
        const originalText = btn.innerHTML;
        
        if (!printerIp) {
            alert('Proszę podać adres IP drukarki');
            return;
        }
        
        if (!zplContent) {
            alert('Brak danych ZPL do wysłania');
            return;
        }
        
        btn.innerHTML = '<div class="loading"></div> Wysyłanie...';
        btn.disabled = true;
        
        try {
            const response = await fetch(`http://${printerIp}:9100`, {
                method: 'POST',
                body: zplContent,
                mode: 'no-cors'
            });
            
            alert('Pomyślnie wysłano do drukarki');
            
        } catch (error) {
            alert('Błąd wysyłania do drukarki: ' + error.message);
        } finally {
            btn.innerHTML = originalText;
            btn.disabled = false;
        }
    }

    <?php if ($preview_data): ?>
    setTimeout(() => showTab('actionsTab'), 100);
    <?php endif; ?>
    </script>
</body>
</html>
