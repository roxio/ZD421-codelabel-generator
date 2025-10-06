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
        
        $current_x = 0;
        $current_y = 0;
        $current_font = '0';
        $current_font_size = 20;
        
        foreach ($lines as $line) {
            $line = trim($line);
            if (empty($line)) continue;
            
            // Parse FO - Field Origin
            if (preg_match('/\^FO(\d+),(\d+)/', $line, $matches)) {
                $current_x = (int)$matches[1];
                $current_y = (int)$matches[2];
            }
            
            // Parse FD - Field Data (text)
            if (strpos($line, '^FD') !== false && strpos($line, '^FS') !== false) {
                $this->drawText($line, $current_x, $current_y, $orig_width, $orig_height, $scaled_width, $scaled_height);
            }
            
            // Parse GB - Graphic Box
            if (strpos($line, '^GB') !== false && strpos($line, '^FS') !== false) {
                $this->drawBox($line, $current_x, $current_y, $orig_width, $orig_height, $scaled_width, $scaled_height);
            }
            
            // Parse Barcode commands
            if (preg_match('/\^B[3BCYQEN][^\\^]*\^FD[^^]+\^FS/', $line)) {
                $this->drawBarcode($line, $current_x, $current_y, $orig_width, $orig_height, $scaled_width, $scaled_height);
            }
            
            // Parse font settings
            if (preg_match('/\^A([0-9A-Z]+),(\d+),(\d+)/', $line, $matches)) {
                $current_font = $matches[1];
                $current_font_size = (int)$matches[2];
            }
        }
    }
    
    private function drawText($line, $x, $y, $orig_width, $orig_height, $scaled_width, $scaled_height) {
        if (!preg_match('/\^FD([^^]+)\^FS/', $line, $fd_matches)) return;
        
        $text = $fd_matches[1];
        
        $scale_x = $scaled_width / $orig_width;
        $scale_y = $scaled_height / $orig_height;
        
        $scaled_x = (int)($x * $scale_x);
        $scaled_y = (int)($y * $scale_y);
        
        $black = imagecolorallocate($this->image, 0, 0, 0);
        
        // Calculate font size based on original ZPL font size and scale
        if (preg_match('/\^A[0-9A-Z]+,(\d+),(\d+)/', $line, $font_matches)) {
            $font_width = (int)$font_matches[1];
            $font_height = (int)$font_matches[2];
            $font_size = max(10, (int)($font_height * $scale_y * 0.6));
        } else {
            // Default font size for large numbers
            $font_size = max(20, (int)(60 * min($scale_x, $scale_y)));
        }
        
        // For large numbers, make them even bigger to occupy 80% of label height
        if (strlen($text) > 5 && is_numeric($text)) {
            $font_size = max(30, (int)($orig_height * 0.6 * $scale_y));
        }
        
        // Use GD built-in fonts (1-5) with appropriate sizing
        $gd_font = 5; // Largest built-in font
        
        // Calculate text width for centering
        $text_width = imagefontwidth($gd_font) * strlen($text);
        $text_height = imagefontheight($gd_font);
        
        // Center large numbers horizontally and position vertically to occupy 80% height
        if (strlen($text) > 5 && is_numeric($text)) {
            $scaled_x = (int)(($scaled_width - $text_width) / 2);
            $scaled_y = (int)(($scaled_height * 0.1)); // Top 10% position
        }
        
        imagestring($this->image, $gd_font, $scaled_x, $scaled_y, $text, $black);
    }
    
    private function drawBarcode($line, $x, $y, $orig_width, $orig_height, $scaled_width, $scaled_height) {
        if (!preg_match('/\^FD([^^]+)\^FS/', $line, $fd_matches)) return;
        
        $barcode_data = $fd_matches[1];
        
        $scale_x = $scaled_width / $orig_width;
        $scale_y = $scaled_height / $orig_height;
        
        $scaled_x = (int)($x * $scale_x);
        $scaled_y = (int)($y * $scale_y);
        
        $black = imagecolorallocate($this->image, 0, 0, 0);
        
        // Wymiary kodu kreskowego - mniejszy, bo cyfry zajmują więcej miejsca
        $bar_width = (int)($orig_width * 0.8 * $scale_x);
        $bar_height = (int)(40 * $scale_y); // Mniejsza wysokość kodu
        
        // Wyśrodkuj kod kreskowy
        $scaled_x = (int)(($scaled_width - $bar_width) / 2);
        
        // Określ typ kodu kreskowego
        $barcode_type = 'CODE128';
        if (strpos($line, '^BQ') !== false) {
            $barcode_type = 'QR';
        } elseif (strpos($line, '^B3') !== false) {
            $barcode_type = 'CODE39';
        } elseif (strpos($line, '^BE') !== false) {
            $barcode_type = 'EAN13';
        }
        
        // Narysuj prawdziwy kod kreskowy
        if ($barcode_type === 'QR') {
            $this->drawQRCode($barcode_data, $scaled_x, $scaled_y, $bar_width, $bar_height);
        } elseif ($barcode_type === 'CODE39') {
            $this->drawCode39($barcode_data, $scaled_x, $scaled_y, $bar_width, $bar_height);
        } elseif ($barcode_type === 'EAN13') {
            $this->drawEAN13($barcode_data, $scaled_x, $scaled_y, $bar_width, $bar_height);
        } else {
            $this->drawCode128($barcode_data, $scaled_x, $scaled_y, $bar_width, $bar_height);
        }
        
        // Dodaj tekst z danymi pod kodem kreskowym - mniejszy tekst
        $text_y = $scaled_y + $bar_height + 5;
        $font_size = max(4, (int)(6 * min($scale_x, $scale_y)));
        
        // Center the barcode text
        $text_width = strlen($barcode_data) * $font_size * 0.6;
        $text_x = (int)(($scaled_width - $text_width) / 2);
        
        imagestring($this->image, 2, $text_x, $text_y, $barcode_data, $black);
    }
    
    private function drawQRCode($data, $x, $y, $width, $height) {
        $black = imagecolorallocate($this->image, 0, 0, 0);
        
        // Rysuj prosty kwadrat jako placeholder dla QR
        $qr_size = min($width, $height);
        $padding = 5;
        
        // Outer border
        imagerectangle($this->image, $x, $y, $x + $qr_size, $y + $qr_size, $black);
        
        // Inner pattern suggestion
        $cell_size = $qr_size / 7;
        for ($i = 1; $i < 6; $i++) {
            for ($j = 1; $j < 6; $j++) {
                if (($i == 1 || $i == 5 || $j == 1 || $j == 5) && 
                    !(($i > 1 && $i < 5) && ($j > 1 && $j < 5))) {
                    imagefilledrectangle(
                        $this->image,
                        $x + ($i * $cell_size),
                        $y + ($j * $cell_size),
                        $x + (($i + 1) * $cell_size) - 2,
                        $y + (($j + 1) * $cell_size) - 2,
                        $black
                    );
                }
            }
        }
        
        // Add "QR" text
        $text_x = $x + ($qr_size / 2) - 10;
        $text_y = $y + ($qr_size / 2) - 5;
        imagestring($this->image, 2, $text_x, $text_y, "QR", $black);
    }
    
    private function drawCode39($data, $x, $y, $width, $height) {
        $black = imagecolorallocate($this->image, 0, 0, 0);
        
        // Code39 characters encoding (narrow-wide pattern)
        $code39_chars = [
            '0' => '101001101101', '1' => '110100101011', '2' => '101100101011',
            '3' => '110110010101', '4' => '101001101011', '5' => '110100110101',
            '6' => '101100110101', '7' => '101001011011', '8' => '110100101101',
            '9' => '101100101101', 'A' => '110101001011', 'B' => '101101001011',
            'C' => '110110100101', 'D' => '101011001011', 'E' => '110101100101',
            'F' => '101101100101', 'G' => '101010011011', 'H' => '110101001101',
            'I' => '101101001101', 'J' => '101011001101', 'K' => '110101010011',
            'L' => '101101010011', 'M' => '110110101001', 'N' => '101011010011',
            'O' => '110101101001', 'P' => '101101101001', 'Q' => '101010110011',
            'R' => '110101011001', 'S' => '101101011001', 'T' => '101011011001',
            'U' => '110010101011', 'V' => '100110101011', 'W' => '110011010101',
            'X' => '100101101011', 'Y' => '110010110101', 'Z' => '100110110101',
            '-' => '100101011011', '.' => '110010101101', ' ' => '100110101101',
            '*' => '100101101101', '$' => '100100100101', '/' => '100100101001',
            '+' => '100101001001', '%' => '101001001001'
        ];
        
        // Start with asterisk
        $encoded = $code39_chars['*'];
        
        // Encode each character
        $data = strtoupper($data);
        for ($i = 0; $i < strlen($data); $i++) {
            $char = $data[$i];
            if (isset($code39_chars[$char])) {
                $encoded .= '0' . $code39_chars[$char];
            }
        }
        
        // End with asterisk
        $encoded .= '0' . $code39_chars['*'];
        
        $this->drawLinearBarcode($encoded, $x, $y, $width, $height, $black);
    }
    
    private function drawCode128($data, $x, $y, $width, $height) {
        $black = imagecolorallocate($this->image, 0, 0, 0);
        
        // Code128 character set B (simplified)
        $code128_b = [
            ' ' => '11011001100', '!' => '11001101100', '"' => '11001100110',
            '#' => '10010011000', '$' => '10010001100', '%' => '10001001100',
            '&' => '10011001000', "'" => '10011000100', '(' => '10001100100',
            ')' => '11001001000', '*' => '11001000100', '+' => '11000100100',
            ',' => '10110011100', '-' => '10011011100', '.' => '10011001110',
            '/' => '10111001100', '0' => '10011101100', '1' => '10011100110',
            '2' => '11001110010', '3' => '11001011100', '4' => '11001001110',
            '5' => '11011100100', '6' => '11001110100', '7' => '11101101110',
            '8' => '11101001100', '9' => '11100101100'
        ];
        
        // Start code B
        $encoded = '11010010000';
        
        // Encode each character (simplified - only numbers and basic chars)
        for ($i = 0; $i < strlen($data); $i++) {
            $char = $data[$i];
            if (isset($code128_b[$char])) {
                $encoded .= $code128_b[$char];
            } elseif (is_numeric($char) && $i + 1 < strlen($data) && is_numeric($data[$i + 1])) {
                // For numbers, we could use code C for better density, but keeping it simple
                $encoded .= $code128_b[$char];
            } else {
                // Fallback for unsupported characters
                $encoded .= $code128_b[' '];
            }
        }
        
        // Checksum (simplified)
        $checksum = 104; // Start B
        for ($i = 0; $i < strlen($data); $i++) {
            $char = $data[$i];
            $checksum += (ord($char) - 32) * ($i + 1);
        }
        $checksum = $checksum % 103;
        
        // Add checksum (simplified)
        $encoded .= '1100011101011'; // Stop pattern
        
        $this->drawLinearBarcode($encoded, $x, $y, $width, $height, $black);
    }
    
    private function drawEAN13($data, $x, $y, $width, $height) {
        $black = imagecolorallocate($this->image, 0, 0, 0);
        
        // Clean data - only numbers
        $data = preg_replace('/[^0-9]/', '', $data);
        $data = str_pad($data, 13, '0', STR_PAD_LEFT);
        $data = substr($data, 0, 13);
        
        // EAN-13 patterns
        $left_patterns = [
            '0' => '0001101', '1' => '0011001', '2' => '0010011',
            '3' => '0111101', '4' => '0100011', '5' => '0110001',
            '6' => '0101111', '7' => '0111011', '8' => '0110111',
            '9' => '0001011'
        ];
        
        $right_patterns = [
            '0' => '1110010', '1' => '1100110', '2' => '1101100',
            '3' => '1000010', '4' => '1011100', '5' => '1001110',
            '6' => '1010000', '7' => '1000100', '8' => '1001000',
            '9' => '1110100'
        ];
        
        $first_digit_patterns = [
            '0' => 'LLLLLL', '1' => 'LLGLGG', '2' => 'LLGGLG',
            '3' => 'LLGGGL', '4' => 'LGLLGG', '5' => 'LGGLLG',
            '6' => 'LGGGLL', '7' => 'LGLGLG', '8' => 'LGLGGL',
            '9' => 'LGGLGL'
        ];
        
        $first_digit = $data[0];
        $pattern = $first_digit_patterns[$first_digit] ?? 'LLLLLL';
        
        // Start pattern
        $encoded = '101';
        
        // First 6 digits
        for ($i = 1; $i <= 6; $i++) {
            $digit = $data[$i];
            $encoding = $pattern[$i - 1] == 'L' ? $left_patterns[$digit] : $right_patterns[$digit];
            $encoded .= $encoding;
        }
        
        // Center pattern
        $encoded .= '01010';
        
        // Last 6 digits (always right pattern)
        for ($i = 7; $i <= 12; $i++) {
            $digit = $data[$i];
            $encoded .= $right_patterns[$digit];
        }
        
        // Stop pattern
        $encoded .= '101';
        
        $this->drawLinearBarcode($encoded, $x, $y, $width, $height, $black);
    }
    
    private function drawLinearBarcode($pattern, $x, $y, $width, $height, $color) {
        $pattern_length = strlen($pattern);
        $bar_width = $width / $pattern_length;
        $bar_height = $height - 10; // Mniejsza wysokość dla kodu
        
        for ($i = 0; $i < $pattern_length; $i++) {
            if ($pattern[$i] === '1') {
                $bar_x = $x + ($i * $bar_width);
                imagefilledrectangle(
                    $this->image,
                    (int)$bar_x,
                    $y,
                    (int)($bar_x + $bar_width),
                    $y + $bar_height,
                    $color
                );
            }
        }
    }
    
    private function drawBox($line, $x, $y, $orig_width, $orig_height, $scaled_width, $scaled_height) {
        if (!preg_match('/\^GB(\d+),(\d+),(\d+)/', $line, $matches)) return;
        
        $width = (int)$matches[1];
        $height = (int)$matches[2];
        $thickness = (int)$matches[3];
        
        $scale_x = $scaled_width / $orig_width;
        $scale_y = $scaled_height / $orig_height;
        
        $scaled_x = (int)($x * $scale_x);
        $scaled_y = (int)($y * $scale_y);
        $scaled_width_box = (int)($width * $scale_x);
        $scaled_height_box = (int)($height * $scale_y);
        $scaled_thickness = max(1, (int)($thickness * min($scale_x, $scale_y)));
        
        $black = imagecolorallocate($this->image, 0, 0, 0);
        
        for ($i = 0; $i < $scaled_thickness; $i++) {
            imagerectangle(
                $this->image,
                $scaled_x + $i,
                $scaled_y + $i,
                $scaled_x + $scaled_width_box - $i,
                $scaled_y + $scaled_height_box - $i,
                $black
            );
        }
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

    foreach ($codes as $index => $code) {
        $date = date('Y-m-d H:i');
        
        // Calculate positions - DUŻE CYFRY NA GÓRZE (80% wysokości)
        $barcode_width = $width - 40;
        $barcode_height = 40; // Mniejszy kod kreskowy
        
        // Bardzo duże cyfry na górze - zajmują 80% wysokości
        $large_font_height = (int)($height * 0.6); // 60% wysokości etykiety
        $large_font_width = (int)($large_font_height * 0.6);
        
        // Pozycja dużych cyfr - wyśrodkowane na górze
        $large_text_y = 10;
        $zpl .= "^FO0,$large_text_y^A0N,$large_font_height,$large_font_width^FB$width,1,0,C^FD$code^FS\n";
        
        // Kod kreskowy - poniżej dużych cyfr
        $barcode_y = $large_text_y + $large_font_height + 10;
        
        if ($barcodeType === 'QR') {
            $zpl .= "^FO" . (($width - 100) / 2) . ",$barcode_y^BQN,2,6^FDQA,$code^FS\n";
        } elseif ($barcodeType === 'Code39') {
            $zpl .= "^FO20,$barcode_y^BY2^B3N,N,$barcode_width,Y,N^FD$code^FS\n";
        } elseif ($barcodeType === 'EAN13') {
            $zpl .= "^FO20,$barcode_y^BY2^BEN,$barcode_width,Y,N^FD$code^FS\n";
        } else {
            // Code128 - default
            $zpl .= "^FO20,$barcode_y^BY2^BCN,$barcode_height,Y,N,N^FD$code^FS\n";
        }
        
        // Data na dole - mniejsza czcionka
        $date_y = $barcode_y + $barcode_height + 15;
        $small_font_size = (int)($height * 0.08);
        $zpl .= "^FO" . (($width - 120) / 2) . ",$date_y^A0N,$small_font_size,$small_font_size^FD$date^FS\n";
        
        // Add page break if multiple codes
        if ($index < count($codes) - 1) {
            $zpl .= "^XB\n"; // Page break
        }
    }
    
    $zpl .= "^XZ";
    
    return $zpl;
}

// ... RESZTA KODU (sesja, obsługa formularzy) pozostaje bez zmian ...
// Start sesji
session_start();

// Domyślny ZPL z dużymi cyframi
$default_zpl = "^XA
^PW600
^LL400
^FO0,10^A0N,240,144^FB600,1,0,C^FD123456789^FS
^FO20,260^BY2^BCN,40,Y,N,N^FD123456789^FS
^FO240,320^A0N,32,32^FD" . date('Y-m-d H:i') . "^FS
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
    <title>Generator ZPL - Duże cyfry</title>
    <style>
        /* STYLE POZOSTAJĄ TAKIE SAME, MOŻNA DODAĆ INFO O DUŻYCH CYFRACH */
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

        .feature-badge {
            background: linear-gradient(135deg, var(--primary), var(--primary-dark));
            color: white;
            padding: 4px 8px;
            border-radius: 4px;
            font-size: 0.8rem;
            font-weight: 600;
            margin-left: 10px;
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
            <h1>Generator ZPL <span class="feature-badge">DUŻE CYFRY</span></h1>
            <p>Twórz etykiety z dużymi, czytelnymi numerami</p>
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
                    ><?= htmlspecialchars($_POST['codes'] ?? '1234567890, 1234567890128') ?></textarea>
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
                            <p><small>Uwaga: Podgląd kodów kreskowych to symulacja. Rzeczywiste kody generowane są przez drukarkę ZPL.</small></p>
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
        const zplContent = `<?= addslashes($zplOutput ?: '') ?>`;
        
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
        const zplContent = `<?= addslashes($zplOutput ?: '') ?>`;
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
