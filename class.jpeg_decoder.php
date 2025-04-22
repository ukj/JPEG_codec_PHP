<?php

/*
This is an experimental JPEG encoder and decoder ported to PHP from the JavaScript JPEG codec.  

Ported from https://github.com/jpeg-js/jpeg-js/blob/master/lib/decoder.js
Ported with https://aistudio.google.com/app/prompts/new_chat 
Gemini Expirimental 1206
Gemini 2.5 Pro Preview 03-25, especially decodeHuffman() part

ukj@ukj.ee
*/


set_time_limit(0);
//error_reporting(E_ALL); // Enable for debugging, disable for production if needed
error_reporting(E_ALL & ~E_NOTICE & ~E_WARNING); // Suppress notices/warnings for cleaner output initially


// Global variable to simulate static variables in JavaScript's JpegImage
$GLOBALS['dctZigZag'] = [
     0,
     1,  8,
    16,  9,  2,
     3, 10, 17, 24,
    32, 25, 18, 11, 4,
     5, 12, 19, 26, 33, 40,
    48, 41, 34, 27, 20, 13,  6,
     7, 14, 21, 28, 35, 42, 49, 56,
    57, 50, 43, 36, 29, 22, 15,
    23, 30, 37, 44, 51, 58,
    59, 52, 45, 38, 31,
    39, 46, 53, 60,
    61, 54, 47,
    55, 62,
    63
];

$GLOBALS['dctCos1'] = 4017;   // cos(pi/16)
$GLOBALS['dctSin1'] = 799;   // sin(pi/16)
$GLOBALS['dctCos3'] = 3406;   // cos(3*pi/16)
$GLOBALS['dctSin3'] = 2276;   // sin(3*pi/16)
$GLOBALS['dctCos6'] = 1567;   // cos(6*pi/16)
$GLOBALS['dctSin6'] = 3784;   // sin(6*pi/16)
$GLOBALS['dctSqrt2'] = 5793;   // sqrt(2)
$GLOBALS['dctSqrt1d2'] = 2896;  // sqrt(2) / 2

class JPEGFileReader {
    const TABLE_SIZE_BITS = 16; // Unused in current read methods for tables
    const BLOCKS_COUNT_BITS = 32; // Unused in current read methods

    // Constants below seem related to a different encoding format, not JPEG markers
    // const DC_CODE_LENGTH_BITS = 4;
    // const CATEGORY_BITS = 4;
    // const AC_CODE_LENGTH_BITS = 8;
    // const RUN_LENGTH_BITS = 4;
    // const SIZE_BITS = 4;

    public $file;
    private $bitBuffer = 0;
    private $bitBufferLength = 0;
    private $byteBuffer = ''; // Buffer for marker lookahead

    public function __construct($filepath) {
        $this->file = fopen($filepath, 'rb');
        if (!$this->file) {
            throw new Exception("Could not open file: " . $filepath);
        }
        $this->bitBuffer = 0;
        $this->bitBufferLength = 0;
        $this->byteBuffer = '';
    }

    // --- Unused/Potentially Incorrect Methods based on Constants ---
    // public function readInt($size) { ... } // Logic depends on custom format, not standard JPEG bit reading
    // public function readDCTable() { ... } // Logic depends on custom format
    // public function readACTable() { ... } // Logic depends on custom format
    // public function readBlocksCount() { ... } // Logic depends on custom format
    // public function int2($binNum) { ... } // Helper for custom format
    // public function binstrFlip($binstr) { ... } // Helper for custom format

    // --- Core JPEG Reading Methods ---

    public function readByte() {
        // Check buffer first
        if (strlen($this->byteBuffer) > 0) {
            $byte = $this->byteBuffer[0];
            $this->byteBuffer = substr($this->byteBuffer, 1);
            return ord($byte);
        }
        // Read from file
        $byte = fread($this->file, 1);
        return ($byte === false || $byte === '') ? null : ord($byte); // Return null on EOF
    }

    public function readBytes($length) {
        $bytes = '';
        // Drain buffer first
        $fromBuffer = min($length, strlen($this->byteBuffer));
        if ($fromBuffer > 0) {
            $bytes .= substr($this->byteBuffer, 0, $fromBuffer);
            $this->byteBuffer = substr($this->byteBuffer, $fromBuffer);
        }
        // Read remaining from file
        $remaining = $length - $fromBuffer;
        if ($remaining > 0) {
            $readFromFile = fread($this->file, $remaining);
            if ($readFromFile === false || strlen($readFromFile) !== $remaining) {
                 // Allow partial reads if at EOF, but signal error if unexpected
                 if (feof($this->file) && ($bytes . $readFromFile) !== '') {
                    $bytes .= $readFromFile;
                 } else {
                     throw new Exception("Could not read $length bytes. Got " . strlen($bytes . $readFromFile) . ". Error or EOF.");
                 }
            } else {
                $bytes .= $readFromFile;
            }
        }

        if (strlen($bytes) !== $length && !feof($this->file)) {
           throw new Exception("Unexpected end of file or read error while reading $length bytes. Got ".strlen($bytes));
        }
        return $bytes;
    }

    public function readUInt16BE() {
        $bytes = $this->readBytes(2);
        if (strlen($bytes) < 2) return null; // Handle EOF
        $val = unpack("n", $bytes); // 'n' is for big-endian 16-bit
        return $val[1];
    }

     public function peekMarker() {
        // Ensure we have at least 2 bytes available, reading if necessary
        while (strlen($this->byteBuffer) < 2 && !feof($this->file)) {
            $char = fread($this->file, 1);
            if ($char === false || $char === '') break; // Stop if read fails or EOF
            $this->byteBuffer .= $char;
        }

        if (strlen($this->byteBuffer) < 2) {
            return null; // Not enough data to peek a marker
        }

        // Peek at the first byte
        if (ord($this->byteBuffer[0]) !== 0xFF) {
            return null; // Not a marker prefix
        }

        // Return the marker code
        $val = unpack("n", substr($this->byteBuffer, 0, 2));
        return $val[1];
    }


    public function readMarker() {
         // Markers are aligned to byte boundaries, discard any remaining bits
         $this->bitBuffer = 0;
         $this->bitBufferLength = 0;

         // Read the marker (must be 0xFFXX)
         $b1 = $this->readByte();
         if ($b1 === null) return null; // EOF

         // Skip fill bytes (0xFF)
         while($b1 === 0xFF) {
             $b2 = $this->readByte();
             if ($b2 === null) return null; // EOF after 0xFF
             if ($b2 !== 0xFF) { // Found the marker code byte
                 // Check for 0xFF00 stuffed byte - treat as 0xFF
                 if ($b2 === 0x00) {
                     // This case happens *within* entropy-coded data,
                     // handled by readBit. readMarker should only see valid markers.
                     // If we encounter 0xFF00 here, it might indicate corruption
                     // or incorrect positioning. For robustness, maybe treat it as a fill byte.
                      $b1 = 0xFF; // Continue skipping FFs
                      continue;
                 }
                 return 0xFF00 | $b2; // Return the full marker
             }
             // If b2 is 0xFF, loop continues to read the next byte
             $b1 = $b2; // Treat the second 0xFF as the start of the next potential marker/fill
         }
         // If loop terminates without finding a non-FF byte after an initial FF,
         // it means we only saw non-FF bytes, which is an error if expecting a marker.
         // However, readMarker is usually called *when* a marker is expected (e.g., after SOS data).
         // If called incorrectly, it might return the last non-FF byte as part of 0xFF00 | byte.
         // Let's return null if no marker was found.
         return null; // Should not happen if called correctly after 0xFF
     }

    public function skipLength() {
        $length = $this->readUInt16BE();
        if ($length === null) throw new Exception("EOF while reading segment length");
        if ($length < 2) throw new Exception("Invalid segment length: $length");
        $bytesToSkip = $length - 2;
        $skipped = $this->readBytes($bytesToSkip); // Read and discard
        if (strlen($skipped) !== $bytesToSkip) {
             throw new Exception("Could not skip $bytesToSkip bytes for segment. Got " . strlen($skipped));
        }
    }


    function readBit() {
        if ($this->bitBufferLength === 0) {
            $byte = $this->readByte();
            if ($byte === null) {
                // echo "<p>DEBUG: readBit EOF</p>";
                return null; // End of stream
            }

            $this->bitBuffer = $byte;
            $this->bitBufferLength = 8;

            // Handle 0xFF00 byte stuffing -> treat as 0xFF
            if ($this->bitBuffer === 0xFF) {
                $nextByte = $this->readByte();
                if ($nextByte === null) {
                     // EOF right after 0xFF is unusual but possible in truncated files
                     // Let the caller handle the null bit. The 0xFF is consumed.
                     // The alternative is to throw an error here. Let's return the bits from 0xFF first.
                } else if ($nextByte !== 0x00) {
                    // This is a marker (0xFFXX where XX != 00).
                    // Push the non-zero byte back into a buffer so the main loop can find the marker.
                    $this->byteBuffer = chr($nextByte) . $this->byteBuffer; // Prepend the marker byte
                    // Invalidate the current bit buffer as we've hit a marker boundary
                    $this->bitBufferLength = 0;
                     // echo "<p>DEBUG: readBit detected marker: FF".dechex($nextByte)."</p>";
                    return null; // Signal marker encountered
                }
                // If nextByte was 0x00, we just continue, the 0xFF is in bitBuffer,
                // and the 0x00 has been consumed. We proceed to extract bits from 0xFF.
            }
        }

        // Extract the most significant bit
        $bit = ($this->bitBuffer >> 7) & 1;
        $this->bitBuffer <<= 1; // Shift left
        $this->bitBuffer &= 0xFF; // Keep it within 8 bits (optional, good practice)
        $this->bitBufferLength--;

        return $bit;
    }

    // --- Deprecated/Incorrect Huffman Reading ---
    // public function readHuffmanCode($table) { ... } // Uses wrong bit reading logic

    public function __destruct() {
        if ($this->file) {
            fclose($this->file);
        }
    }
} // class JPEGFileReader{}


// --- Utility functions ---

function zigzag_to_block($zigzag) {
    // assuming that the width and the height of the block are equal
    $rows = $cols = intval(sqrt(count($zigzag)));

    if ($rows * $cols != count($zigzag)) {
        throw new ValueError("length of zigzag should be a perfect square");
    }

    $block = [];
    for($i=0; $i<$rows; $i++)
    {
        $block[$i] = array_fill(0, $cols, 0);
    }

    $i=0;
    foreach (zigzag_points($rows, $cols) as $point) {
        $block[$point[0]][$point[1]] = $zigzag[$i];
        $i++;
    }

    return $block;
}

function dequantize($block, $component) {
    $q = load_quantization_table($component); // This uses hardcoded tables, real JPEGs have DQT
    $result = [];
    for ($i=0; $i<count($block); $i++)
    {
        $result[$i] = [];
        for ($j=0; $j<count($block[$i]); $j++)
        {
            // This function isn't actually used in the main flow which uses quantizeAndInverse
            $result[$i][$j] = $block[$i][$j] * $q[$i][$j];
        }
    }
    return $result;
}

function idct_2d($image) {
    // This is a basic placeholder IDCT, the `quantizeAndInverse` function uses a faster integer AAN IDCT.
    // This function isn't actually used in the main processing flow.
    $height = count($image);
    $width = count($image[0]);
    $temp = [];

    // Perform IDCT on rows
    for ($i = 0; $i < $height; $i++) {
        $temp[$i] = idct($image[$i]);
    }

    // Transpose the matrix
    $transposed = [];
    for ($i = 0; $i < $width; $i++) {
        for ($j = 0; $j < $height; $j++) {
            $transposed[$i][$j] = $temp[$j][$i];
        }
    }

    // Perform IDCT on columns (now rows)
    for ($i = 0; $i < $width; $i++) {
        $temp[$i] = idct($transposed[$i]);
    }

    // Transpose back
    $result = [];
    for ($i = 0; $i < $height; $i++) {
        for ($j = 0; $j < $width; $j++) {
            $result[$i][$j] = $temp[$j][$i];
        }
    }

    return $result;
}

// Basic IDCT implementation (you might need a more optimized one for real-world use)
function idct($input) {
     // Placeholder, not the one used in main flow.
    $output = [];
    $n = count($input);
    for ($k = 0; $k < $n; $k++) {
        $sum = 0;
        for ($i = 0; $i < $n; $i++) {
            // Note: JPEG IDCT formula is slightly different (uses PI / (2*N))
            // but this function isn't used anyway.
            $sum += $input[$i] * cos((M_PI / $n) * ($i + 0.5) * $k);
        }
         // Scaling factors differ for DCT types
        $output[$k] = round($sum * (($k == 0) ? (1 / sqrt($n)) : sqrt(2/$n))); // Approximation
    }
    return $output;
}

// Utility functions:
function load_quantization_table($component) {
    // THIS IS A HARDCODED EXAMPLE - Real JPEGs define tables in the DQT marker.
    // The main code correctly reads DQT, so this function is effectively unused there.
    if ($component == 'lum') {
		
        // Example Luminance table (Annex K.1) - Scaled usually
        $q = [
           [16, 11, 10, 16, 24, 40, 51, 61],
           [12, 12, 14, 19, 26, 58, 60, 55],
           [14, 13, 16, 24, 40, 57, 69, 56],
           [14, 17, 22, 29, 51, 87, 80, 62],
           [18, 22, 37, 56, 68, 109, 103, 77],
           [24, 35, 55, 64, 81, 104, 113, 92],
           [49, 64, 78, 87, 103, 121, 120, 101],
           [72, 92, 95, 98, 112, 100, 103, 99]
        ];
        
    } elseif ($component == 'chrom') {
		
         // Example Chrominance table (Annex K.1) - Scaled usually
        $q = [
           [17, 18, 24, 47, 99, 99, 99, 99],
           [18, 21, 26, 66, 99, 99, 99, 99],
           [24, 26, 56, 99, 99, 99, 99, 99],
           [47, 66, 99, 99, 99, 99, 99, 99],
           [99, 99, 99, 99, 99, 99, 99, 99],
           [99, 99, 99, 99, 99, 99, 99, 99],
           [99, 99, 99, 99, 99, 99, 99, 99],
           [99, 99, 99, 99, 99, 99, 99, 99]
        ];
    } else {
        throw new ValueError("component should be either 'lum' or 'chrom', but '$component' was found");
    }

    return $q; // Note: DQT uses zigzag order, this example is in natural order
}

function zigzag_points($rows, $cols) {
    // constants for directions
    $UP = 0; $DOWN = 1; $RIGHT = 2; $LEFT = 3; $UP_RIGHT = 4; $DOWN_LEFT = 5;

    // move the point in different directions
    $move = function ($direction, $point) use ($UP, $DOWN, $LEFT, $RIGHT, $UP_RIGHT, $DOWN_LEFT) {
        switch ($direction) {
            case $UP: return [$point[0] - 1, $point[1]];
            case $DOWN: return [$point[0] + 1, $point[1]];
            case $LEFT: return [$point[0], $point[1] - 1];
            case $RIGHT: return [$point[0], $point[1] + 1];
            case $UP_RIGHT: return $move($UP, $move($RIGHT, $point));
            case $DOWN_LEFT: return $move($DOWN, $move($LEFT, $point));
        };
        return $point; // Should not happen
    };

    // return true if point is inside the block bounds
    $inbounds = function ($point) use ($rows, $cols) {
        return 0 <= $point[0] && $point[0] < $rows && 0 <= $point[1] && $point[1] < $cols;
    };

    // start in the top-left cell
    $point = [0, 0];
    $move_up = true; // True when moving up-right, False when moving down-left

    for ($i = 0; $i < $rows * $cols; $i++) {
        yield $point;
        if ($move_up) {
            $next_point_diag = $move($UP_RIGHT, $point);
            if ($inbounds($next_point_diag)) {
                $point = $next_point_diag;
            } else {
                $move_up = false;
                $next_point_right = $move($RIGHT, $point);
                if ($inbounds($next_point_right)) {
                    $point = $next_point_right;
                } else {
                    $point = $move($DOWN, $point); // Must be in bounds if not right
                }
            }
        } else { // Moving down-left
            $next_point_diag = $move($DOWN_LEFT, $point);
            if ($inbounds($next_point_diag)) {
                $point = $next_point_diag;
            } else {
                $move_up = true;
                $next_point_down = $move($DOWN, $point);
                if ($inbounds($next_point_down)) {
                    $point = $next_point_down;
                } else {
                    $point = $move($RIGHT, $point); // Must be in bounds if not down
                }
            }
        }
    }
} // zigzag_points()


// --- New and Modified Functions based on decoder.js ---

function prepareComponents(&$frame) {
    // Find max sampling factors
    $maxH = 0;
    $maxV = 0;
    if (!isset($frame['components']) || empty($frame['components'])) {
        throw new Exception("SOF marker missing or invalid (no components found)");
    }
    foreach ($frame['components'] as $component) {
        if (!isset($component['h']) || !isset($component['v'])) {
             throw new Exception("Component is missing sampling factors (h/v)");
        }
        if ($component['h'] <= 0 || $component['v'] <= 0 || $component['h'] > 4 || $component['v'] > 4) {
             throw new Exception("Invalid sampling factors H={$component['h']}, V={$component['v']}");
        }
        $maxH = max($maxH, $component['h']);
        $maxV = max($maxV, $component['v']);
    }

     if ($maxH <= 0 || $maxV <= 0) {
        throw new Exception("Invalid maximum sampling factors MaxH={$maxH}, MaxV={$maxV}");
    }


    // Calculate MCU dimensions in pixels
    $mcuWidth = $maxH * 8;
    $mcuHeight = $maxV * 8;

    // Calculate total MCUs
    $mcusPerLine = ceil($frame['samplesPerLine'] / $mcuWidth);
    $mcusPerColumn = ceil($frame['scanLines'] / $mcuHeight);

    // Prepare each component
    foreach ($frame['components'] as $componentId => &$component) {
        // Calculate how many blocks of this component are in a single MCU
        $blocksInMcuH = $component['h'];
        $blocksInMcuV = $component['v'];

        // Calculate total blocks needed for this component across the image
        // This needs careful calculation based on sampling factors relative to maxH/maxV
        // Option 1: Calculate based on MCU counts (simpler if padding is acceptable)
        $component['blocksPerLine'] = $mcusPerLine * $blocksInMcuH;
        $component['blocksPerColumn'] = $mcusPerColumn * $blocksInMcuV;

        // Option 2: Calculate more precisely based on image dimensions and component scaling
        // $componentWidth = ceil($frame['samplesPerLine'] * $component['h'] / $maxH);
        // $componentHeight = ceil($frame['scanLines'] * $component['v'] / $maxV);
        // $component['blocksPerLine'] = ceil($componentWidth / 8);
        // $component['blocksPerColumn'] = ceil($componentHeight / 8);

        // Let's stick with Option 1 for consistency with potential MCU-based processing

        // Allocate storage for blocks (initialize with zeros)
        // Use a flat array for blocks, indexing with [row * blocksPerLine + col] later?
        // Or keep 2D structure as before. 2D might be easier for MCU logic.
        $component['blocks'] = [];
        for ($i = 0; $i < $component['blocksPerColumn']; $i++) {
            $row = [];
            for ($j = 0; $j < $component['blocksPerLine']; $j++) {
                // Each block is 64 coefficients, initialized to 0
                $row[] = array_fill(0, 64, 0);
            }
            $component['blocks'][] = $row;
        }
        // Add predictor state for DC coefficient decoding
        $component['pred'] = 0;
    }
    unset($component); // Unset reference

    // Store frame-level MCU info
    $frame['maxH'] = $maxH;
    $frame['maxV'] = $maxV;
    $frame['mcusPerLine'] = $mcusPerLine;
    $frame['mcusPerColumn'] = $mcusPerColumn;

    echo "<p>Prepared components: MaxH=$maxH, MaxV=$maxV, MCUs={$mcusPerLine}x{$mcusPerColumn}</p>";
}


function read_image_file($filepath) {
    $reader = new JPEGFileReader($filepath);

    $quantizationTables = array_fill(0, 4, null); // Max 4 tables
    $huffmanTablesAC = array_fill(0, 4, null);    // Max 4 AC tables
    $huffmanTablesDC = array_fill(0, 4, null);    // Max 4 DC tables
    $frame = null;
    $resetInterval = 0; // Default: no restart markers
    $jfif = null;
    $adobe = null;
    $comments = [];
    $sosSeen = false; // Flag to know if we are processing scan data

    // --- Read SOI ---
    $marker = $reader->readUInt16BE();
    if ($marker !== 0xFFD8) { // SOI (Start of Image)
        throw new Exception("Invalid JPEG: SOI marker (FFD8) not found. Found: 0x" . dechex($marker ?? 0));
    }
    echo "<p>SOI (0xFFD8) found.</p>";

    // --- Read segments until EOI ---
    while (true) {
        $marker = $reader->readMarker();

        if ($marker === null) {
             // Check if we are past SOS and just hit EOF without EOI
            if ($sosSeen && feof($reader->file)) {
                 echo "<p>Warning: Reached EOF after SOS without EOI marker.</p>";
                 break; // Assume end of image data
            }
            throw new Exception("EOF or invalid data found while expecting JPEG marker.");
        }

        echo "<p>Marker: 0x" . dechex($marker) . " at position " . ftell($reader->file) . "</p>";

        // Handle EOI
        if ($marker === 0xFFD9) { // EOI (End of image)
            echo "<p>EOI (0xFFD9) found.</p>";
            break;
        }

        // Handle Markers without lengths (RSTm)
        if ($marker >= 0xFFD0 && $marker <= 0xFFD7) { // RSTm
            // These markers appear within the scan data and have no length field.
            // They are handled by the decodeScan function's marker detection logic.
            // Finding one here means something is wrong, perhaps misplaced SOS logic.
             echo "<p>Warning: Unexpected RSTm marker (0x" . dechex($marker) . ") outside scan data.</p>";
            continue; // Try to continue parsing
        }
        // Handle SOI (shouldn't happen again)
        if ($marker === 0xFFD8) {
            throw new Exception("Unexpected SOI marker (0xFFD8) inside file.");
        }
        // Handle TEM (shouldn't happen in baseline)
        if ($marker === 0xFF01) {
            echo "<p>TEM marker (0xFF01) found - ignored.</p>";
             continue; // No length field
        }


        // --- Most markers have a length field ---
        $length = $reader->readUInt16BE();
        if ($length === null) throw new Exception("EOF while reading segment length for marker 0x" . dechex($marker));
        if ($length < 2) throw new Exception("Invalid segment length ($length) for marker 0x" . dechex($marker));
        $segmentEndPos = ftell($reader->file) + $length - 2;

        echo "<p>  Length: $length bytes</p>";

        switch ($marker) {
            // --- Application markers ---
            case 0xFFE0: // APP0
            case 0xFFE1: // APP1 (Exif)
            case 0xFFE2: // APP2 (ICC Profile)
            case 0xFFE3: // APP3
            case 0xFFE4: // APP4
            case 0xFFE5: // APP5
            case 0xFFE6: // APP6
            case 0xFFE7: // APP7
            case 0xFFE8: // APP8
            case 0xFFE9: // APP9
            case 0xFFEA: // APP10
            case 0xFFEB: // APP11
            case 0xFFEC: // APP12
            case 0xFFED: // APP13
            case 0xFFEE: // APP14 (Adobe)
            case 0xFFEF: // APP15
                $appData = $reader->readBytes($length - 2);
                echo "<p>  APP" . ($marker & 0x0F) . " data (first 20 bytes): " . substr(bin2hex($appData), 0, 40) . "...</p>";

                if ($marker === 0xFFE0) { // JFIF
                    if (substr($appData, 0, 5) === "JFIF\x00") {
                        if (strlen($appData) >= 14) {
                           $jfif = [
                                'version' => ['major' => ord($appData[5]), 'minor' => ord($appData[6])],
                                'densityUnits' => ord($appData[7]),
                                'xDensity' => unpack('n', substr($appData, 8, 2))[1],
                                'yDensity' => unpack('n', substr($appData, 10, 2))[1],
                                'thumbWidth' => ord($appData[12]),
                                'thumbHeight' => ord($appData[13]),
                                // Thumbnail data parsing omitted for simplicity
                            ];
                            echo "<p>  Found JFIF v{$jfif['version']['major']}.{$jfif['version']['minor']}</p>";
                        } else {
                             echo "<p>  Warning: JFIF segment too short.</p>";
                        }
                    }
                } elseif ($marker === 0xFFEE) { // Adobe
                    if (substr($appData, 0, 6) === "Adobe\x00") {
                        if (strlen($appData) >= 12) {
                           $adobe = [
                                'version' => ord($appData[6]),
                                'flags0' => unpack('n', substr($appData, 7, 2))[1],
                                'flags1' => unpack('n', substr($appData, 9, 2))[1],
                                'transformCode' => ord($appData[11])
                            ];
                            echo "<p>  Found Adobe marker. Transform code: {$adobe['transformCode']}</p>";
                        } else {
                            echo "<p>  Warning: Adobe segment too short.</p>";
                        }
                    }
                }
                // Other APP markers skipped
                break;

            // --- Comment ---
            case 0xFFFE: // COM
                $commentData = $reader->readBytes($length - 2);
                // Assuming comment is likely text, try detecting encoding or assume common ones
                $detectedEncoding = mb_detect_encoding($commentData, ['ASCII', 'UTF-8', 'ISO-8859-1'], true);
                if ($detectedEncoding) {
                    $commentText = mb_convert_encoding($commentData, 'UTF-8', $detectedEncoding);
                } else {
                    $commentText = bin2hex($commentData); // Fallback to hex
                }
                $comments[] = $commentText;
                echo "<p>  Comment: " . htmlspecialchars(substr($commentText, 0, 100)) . "...</p>";
                break;

            // --- Define Quantization Tables ---
            case 0xFFDB: // DQT
                echo "<p>  Reading DQT...</p>";
                $bytesRead = 0;
                while ($bytesRead < $length - 2) {
                    $qtInfo = $reader->readByte();
                    if ($qtInfo === null) throw new Exception("EOF in DQT segment");
                    $bytesRead++;

                    $tableId = $qtInfo & 0x0F; // Lower 4 bits
                    $precision = $qtInfo >> 4; // Upper 4 bits (0=8bit, 1=16bit)

                    if ($tableId > 3) throw new Exception("Invalid quantization table ID: $tableId");

                    echo "<p>    Table ID: $tableId, Precision: " . ($precision == 0 ? '8-bit' : '16-bit') . "</p>";

                    $tableData = array_fill(0, 64, 0);
                    $bytesToRead = 64 * ($precision == 0 ? 1 : 2);

                     if ($bytesRead + $bytesToRead > $length - 2) {
                          throw new Exception("DQT segment length mismatch. Declared: " . ($length - 2) . ", Needed: " . ($bytesRead + $bytesToRead));
                     }

                    if ($precision == 0) { // 8-bit values
                        for ($j = 0; $j < 64; $j++) {
                            $val = $reader->readByte();
                             if ($val === null) throw new Exception("EOF reading 8-bit DQT data");
                            $tableData[$GLOBALS['dctZigZag'][$j]] = $val; // Store in zigzag order
                        }
                        $bytesRead += 64;
                    } else { // 16-bit values
                        for ($j = 0; $j < 64; $j++) {
                             $val = $reader->readUInt16BE();
                             if ($val === null) throw new Exception("EOF reading 16-bit DQT data");
                             $tableData[$GLOBALS['dctZigZag'][$j]] = $val; // Store in zigzag order
                        }
                        $bytesRead += 128;
                    }
                    $quantizationTables[$tableId] = $tableData;
                    // Debug: print first few values
                    //echo "<p>      Values (zigzag): [" . implode(',', array_slice($tableData, 0, 8)) . "...]</p>";
                }
                if ($bytesRead !== $length - 2) {
                     echo "<p>    Warning: DQT segment length mismatch. Declared: ".($length-2).", Read: $bytesRead</p>";
                     // Try to seek to the expected end if mismatch occurs
                     fseek($reader->file, $segmentEndPos, SEEK_SET);
                 }
                break;

            // --- Start of Frame markers ---
            case 0xFFC0: // SOF0 (Baseline DCT)
            case 0xFFC1: // SOF1 (Extended sequential DCT)
            case 0xFFC2: // SOF2 (Progressive DCT)
                 echo "<p>  Reading SOF" . ($marker & 0x0F) . "...</p>";
                 if ($frame !== null) {
                    echo "<p>  Warning: Multiple SOF markers found. Using the last one.</p>";
                    // Potentially handle multi-scan images later if needed
                 }

                $precision = $reader->readByte(); // P: Sample precision (usually 8 bits)
                $scanLines = $reader->readUInt16BE(); // Y: Number of lines
                $samplesPerLine = $reader->readUInt16BE(); // X: Samples per line
                $componentsCount = $reader->readByte(); // Nf: Number of components

                if ($precision === null || $scanLines === null || $samplesPerLine === null || $componentsCount === null) {
                    throw new Exception("EOF reading SOF parameters");
                }
                 if ($componentsCount == 0) throw new Exception("SOF specifies 0 components");

                echo "<p>    Precision: $precision bits</p>";
                echo "<p>    Dimensions: {$samplesPerLine}x{$scanLines}</p>";
                echo "<p>    Components: $componentsCount</p>";

                $frame = [
                    'type' => $marker, // SOF0, SOF1, SOF2 etc.
                    'baseline' => ($marker === 0xFFC0),
                    'extended' => ($marker === 0xFFC1), // Note: Baseline check takes precedence in some libs
                    'progressive' => ($marker === 0xFFC2),
                    'precision' => $precision,
                    'scanLines' => $scanLines,
                    'samplesPerLine' => $samplesPerLine,
                    'components' => [],
                    'componentsOrder' => [] // Store the order they appear in SOF
                ];

                $expectedLength = 8 + ($componentsCount * 3);
                if ($length !== $expectedLength) {
                     echo "<p>    Warning: SOF segment length mismatch. Expected: $expectedLength, Found: $length</p>";
                     // Allow processing but be wary. Some tools might add extra data.
                }

                $bytesRead = 6; // For P, Y, X, Nf
                for ($i = 0; $i < $componentsCount; $i++) {
                    $componentId = $reader->readByte(); // Ci: Component ID (1=Y, 2=Cb, 3=Cr typical)
                    $hv = $reader->readByte();          // Hi, Vi: Sampling factors
                    $qTableId = $reader->readByte();    // Tqi: Quantization table ID

                     if ($componentId === null || $hv === null || $qTableId === null) {
                         throw new Exception("EOF reading component data in SOF");
                     }
                     $bytesRead += 3;

                     if ($qTableId > 3) throw new Exception("Invalid quantization table ID ($qTableId) for component $componentId");

                    $h = $hv >> 4; // Horizontal sampling factor
                    $v = $hv & 0x0F; // Vertical sampling factor

                    echo "<p>    Component ID: $componentId, Sampling: {$h}x{$v}, Q-Table: $qTableId</p>";

                    // Store component info using Component ID as the key
                    $frame['components'][$componentId] = [
                        'id' => $componentId, // Store ID within component too
                        'h' => $h,
                        'v' => $v,
                        'quantizationTableId' => $qTableId,
                        'blocksPerLine' => 0, // Will be calculated by prepareComponents
                        'blocksPerColumn' => 0, // Will be calculated by prepareComponents
                        'blocks' => [],      // Will be allocated by prepareComponents
                        'pred' => 0          // DC predictor state
                    ];
                    $frame['componentsOrder'][] = $componentId; // Keep track of SOF order
                }

                // Validate required tables exist later, after all headers are read

                // Skip any remaining bytes in the SOF segment if length was unusual
                 if (ftell($reader->file) < $segmentEndPos) {
                     echo "<p>    Skipping " . ($segmentEndPos - ftell($reader->file)) . " extra bytes in SOF segment.</p>";
                     if (fseek($reader->file, $segmentEndPos, SEEK_SET) === -1) {
                        throw new Exception("Failed to seek past extra SOF data");
                     }
                 } else if (ftell($reader->file) > $segmentEndPos) {
                     throw new Exception("Read past SOF segment end. Indicated: $segmentEndPos, Current: " . ftell($reader->file));
                 }

                // Now that we have SOF info, prepare component structures
                 prepareComponents($frame);

                break;

            // --- Unsupported SOF types (common) ---
            case 0xFFC3: case 0xFFC5: case 0xFFC6: case 0xFFC7:
            case 0xFFC9: case 0xFFCA: case 0xFFCB: case 0xFFCD:
            case 0xFFCE: case 0xFFCF:
                echo "<p>  Unsupported SOF marker: 0x" . dechex($marker) . ". Skipping segment.</p>";
                if (fseek($reader->file, $segmentEndPos, SEEK_SET) === -1) {
                     throw new Exception("Failed to seek past unsupported SOF data");
                 }
                break;

            // --- Define Huffman Tables ---
            case 0xFFC4: // DHT
                 echo "<p>  Reading DHT...</p>";
                 $bytesRead = 0;
                 while ($bytesRead < $length - 2) {
                     $htInfo = $reader->readByte();
                     if ($htInfo === null) throw new Exception("EOF in DHT segment info byte");
                     $bytesRead++;

                     $tableId = $htInfo & 0x0F; // Lower 4 bits: Destination ID (0-3)
                     $tableClass = $htInfo >> 4; // Upper 4 bits: Class (0=DC, 1=AC)

                     if ($tableId > 3) throw new Exception("Invalid Huffman table ID: $tableId");
                     if ($tableClass > 1) throw new Exception("Invalid Huffman table class: $tableClass");

                     echo "<p>    Table Class: " . ($tableClass == 0 ? 'DC' : 'AC') . ", ID: $tableId</p>";

                     // Read BITS list (Li: Number of codes of length i+1)
                     $codeLengths = []; // Array to hold L1..L16
                     $totalCodes = 0;
                     for ($i = 0; $i < 16; $i++) {
                         $count = $reader->readByte();
                          if ($count === null) throw new Exception("EOF reading Huffman BITS (L".($i+1).")");
                         $codeLengths[$i] = $count;
                         $totalCodes += $count;
                     }
                     $bytesRead += 16;
                     echo "<p>      Code Counts (L1-L16): [" . implode(', ', $codeLengths) . "] (Total: $totalCodes)</p>";

                     // Read HUFFVAL list (Vi: Values for codes)
                     $huffmanValues = [];
                     if ($bytesRead + $totalCodes > $length - 2) {
                         throw new Exception("DHT segment length mismatch. Declared: " . ($length - 2) . ", Needed for values: " . ($bytesRead + $totalCodes));
                     }
                     for ($i = 0; $i < $totalCodes; $i++) {
                         $val = $reader->readByte();
                         if ($val === null) throw new Exception("EOF reading Huffman values (V".($i+1).")");
                         $huffmanValues[] = $val;
                     }
                     $bytesRead += $totalCodes;
                    // echo "<p>      Values: [" . implode(',', array_slice($huffmanValues, 0, 20)) . ($totalCodes > 20 ? '...' : '') . "]</p>";

                    // Build and store the table
                    $huffmanTree = buildHuffmanTable($codeLengths, $huffmanValues);

                    if ($tableClass == 0) { // DC Table
                        $huffmanTablesDC[$tableId] = $huffmanTree;
                    } else { // AC Table
                        $huffmanTablesAC[$tableId] = $huffmanTree;
                    }
                 }
                  if ($bytesRead !== $length - 2) {
                     echo "<p>    Warning: DHT segment length mismatch. Declared: ".($length-2).", Read: $bytesRead</p>";
                     // Try to seek to the expected end
                      if (fseek($reader->file, $segmentEndPos, SEEK_SET) === -1) {
                          throw new Exception("Failed to seek past DHT segment");
                      }
                 }
                break;

            // --- Define Restart Interval ---
            case 0xFFDD: // DRI
                 echo "<p>  Reading DRI...</p>";
                 if ($length !== 4) throw new Exception("Invalid DRI segment length: $length (should be 4)");
                 $resetInterval = $reader->readUInt16BE();
                 if ($resetInterval === null) throw new Exception("EOF reading restart interval");
                 echo "<p>    Restart Interval: $resetInterval MCUs</p>";
                break;

            // --- Start of Scan ---
            case 0xFFDA: // SOS
                echo "<p>  Reading SOS...</p>";
                if ($frame === null) throw new Exception("SOS found before SOF");
                $sosSeen = true;

                $componentSelectorCount = $reader->readByte(); // Ns: Number of components in scan
                 if ($componentSelectorCount === null) throw new Exception("EOF reading SOS component count");
                 $expectedLength = 6 + (2 * $componentSelectorCount);
                 if ($length !== $expectedLength) {
                      echo "<p>    Warning: SOS header length mismatch. Expected: $expectedLength, Found: $length. Might be progressive scan parameters.</p>";
                      // For baseline, length should be fixed. Progressive adds Ss, Se, Ah/Al.
                 }
                 if ($componentSelectorCount == 0 || $componentSelectorCount > 4) {
                      throw new Exception("Invalid number of components in scan: $componentSelectorCount");
                 }
                echo "<p>    Components in scan: $componentSelectorCount</p>";

                $scanComponents = []; // Store component info specific to this scan
                $bytesRead = 1;
                for ($i = 0; $i < $componentSelectorCount; $i++) {
                    $componentId = $reader->readByte(); // Csj: Component selector
                    $tableSpec = $reader->readByte();   // Tdj, Taj: DC/AC Huffman table selectors
                     if ($componentId === null || $tableSpec === null) {
                         throw new Exception("EOF reading SOS component selector/table spec");
                     }
                     $bytesRead += 2;

                    $dcTableId = $tableSpec >> 4;
                    $acTableId = $tableSpec & 0x0F;

                    echo "<p>    Scan Comp ID: $componentId, DC Table: $dcTableId, AC Table: $acTableId</p>";

                    // Find the component details from the frame using the ID
                    if (!isset($frame['components'][$componentId])) {
                        throw new Exception("SOS references component ID $componentId which was not defined in SOF");
                    }

                    // Check if required Huffman tables were defined
                    if ($huffmanTablesDC[$dcTableId] === null) {
                         throw new Exception("DC Huffman table ID $dcTableId required by SOS component $componentId was not defined in DHT");
                    }
                     if ($huffmanTablesAC[$acTableId] === null) {
                         throw new Exception("AC Huffman table ID $acTableId required by SOS component $componentId was not defined in DHT");
                    }

                    // Link the component from the frame with the tables for this scan
                    $componentRef = &$frame['components'][$componentId]; // Get reference
                    $componentRef['huffmanTableDC'] = $huffmanTablesDC[$dcTableId];
                    $componentRef['huffmanTableAC'] = $huffmanTablesAC[$acTableId];
                    $scanComponents[] = &$componentRef; // Add reference to list for this scan
                    unset($componentRef); // Break reference link for safety
                }

                // Read spectral selection and successive approximation params
                $spectralStart = $reader->readByte(); // Ss: Start of spectral selection (usually 0 for baseline)
                $spectralEnd = $reader->readByte();   // Se: End of spectral selection (usually 63 for baseline)
                $approx = $reader->readByte();      // Ah, Al: Successive approximation bit position high/low
                if ($spectralStart === null || $spectralEnd === null || $approx === null) {
                     throw new Exception("EOF reading SOS spectral/approximation parameters");
                 }
                 $bytesRead += 3;
                 $successiveHigh = $approx >> 4;
                 $successiveLow = $approx & 0x0F;

                 echo "<p>    Spectral Select: $spectralStart to $spectralEnd</p>";
                 echo "<p>    Successive Approx: High=$successiveHigh, Low=$successiveLow</p>";

                  // Skip any remaining bytes in the SOS header (if length was unusual)
                 if (ftell($reader->file) < $segmentEndPos) {
                     echo "<p>    Skipping " . ($segmentEndPos - ftell($reader->file)) . " extra bytes in SOS header.</p>";
                     if (fseek($reader->file, $segmentEndPos, SEEK_SET) === -1) {
                        throw new Exception("Failed to seek past extra SOS header data");
                     }
                 } else if (ftell($reader->file) > $segmentEndPos) {
                     throw new Exception("Read past SOS header end. Indicated: $segmentEndPos, Current: " . ftell($reader->file));
                 }

                // --- Decode the actual image data ---
                echo "<p>--- Starting Entropy-Coded Data Decode ---</p>";
                decodeScan(
                    $reader,
                    $frame,
                    $scanComponents, // Pass the components specific to *this* scan
                    $resetInterval,
                    $spectralStart,
                    $spectralEnd,
                    $successiveHigh, // Prev=High for progressive AC
                    $successiveLow   // Current=Low for progressive DC/AC
                );
                 echo "<p>--- Finished Entropy-Coded Data Decode ---</p>";
                 // After decodeScan, the reader should be positioned right after the scan data,
                 // ready for the next marker (often EOI or another SOS in multi-scan images).
                break;


            // --- Other known markers (skip) ---
            case 0xFFC8: // JPG (JPEG Extensions)
            case 0xFFCC: // DAC (Define Arithmetic Coding) - Error if baseline/sequential
                 if ($marker === 0xFFCC) throw new Exception("Arithmetic Coding (DAC marker) is not supported by this decoder.");
                 echo "<p>  Skipping known marker 0x" . dechex($marker) . "</p>";
                 if (fseek($reader->file, $segmentEndPos, SEEK_SET) === -1) {
                     throw new Exception("Failed to seek past marker 0x" . dechex($marker));
                 }
                break;


            default:
                 // Handle unknown markers - best effort is to skip them
                 echo "<p>  Skipping unknown or unsupported marker: 0x" . dechex($marker) . "</p>";
                 if (fseek($reader->file, $segmentEndPos, SEEK_SET) === -1) {
                     // If seek fails, maybe try reading byte-by-byte? Less robust.
                     throw new Exception("Failed to seek past unknown marker 0x" . dechex($marker));
                 }
                 break;

        } // End switch($marker)

        // Check if we read exactly the segment length (except for SOS)
        // if ($marker !== 0xFFDA && ftell($reader->file) !== $segmentEndPos) {
        //      echo "<p>Warning: Did not read expected number of bytes for marker 0x" . dechex($marker) . ". Expected end: $segmentEndPos, Current: " . ftell($reader->file) . ". Attempting to seek.</p>";
        //      if (fseek($reader->file, $segmentEndPos, SEEK_SET) === -1) {
        //          throw new Exception("Failed to resync after marker 0x" . dechex($marker));
        //      }
        // }

    } // End while(true) marker loop

    // --- Post-parsing validation and preparation ---
    if ($frame === null) {
        throw new Exception("JPEG parsing finished but no SOF marker was found.");
    }
     if (!$sosSeen) {
        throw new Exception("JPEG parsing finished but no SOS marker was found.");
    }

    // Assign the correct quantization table (loaded from DQT) to each component
    foreach ($frame['components'] as &$component) {
        $qTableId = $component['quantizationTableId'];
        if ($quantizationTables[$qTableId] === null) {
            throw new Exception("Quantization table ID $qTableId required by component {$component['id']} was not defined in DQT");
        }
        // De-zigzag the Q table for use in quantizeAndInverse (which expects natural order)
        $qTableNatural = array_fill(0, 64, 0);
        for ($i = 0; $i < 64; $i++) {
            $qTableNatural[$i] = $quantizationTables[$qTableId][$i]; // Already in zigzag order from DQT read
        }
        $component['quantizationTable'] = $qTableNatural; // Assign the raw (zigzag) Q table
        // quantizeAndInverse will access it via zigzag indices: $zz[$i] * $qt[$i];

        // We might not need the ID anymore after linking
        // unset($component['quantizationTableId']);
    }
    unset($component);


    // Prepare component data for final output (apply IDCT, level shift)
    // The buildComponentData function handles this
    $outputComponents = [];
    foreach ($frame['componentsOrder'] as $componentId) {
        $component = $frame['components'][$componentId];
        echo "<p>Building final data for component ID: $componentId...</p>";
        $outputComponents[] = [
            'lines' => buildComponentData($component, $frame), // Pass frame for scaling info
            'scaleX' => $component['h'] / $frame['maxH'],
            'scaleY' => $component['v'] / $frame['maxV'],
            'width' => $component['blocksPerLine'] * 8, // Store component dims
            'height' => $component['blocksPerColumn'] * 8
        ];
         echo "<p>  Component $componentId done. Dimensions: " . ($component['blocksPerLine'] * 8) . "x" . ($component['blocksPerColumn'] * 8) . "</p>";
    }

    // Return all parsed data
    // Note: Returning DC/AC coefficients separately might be redundant now
    // as they are processed within decodeScan and buildComponentData.
    // We return the final pixel data in $outputComponents.
    return [
        //$dc, $ac, $tables, // Removed these intermediate structures
        $frame,
        $jfif,
        $adobe,
        $outputComponents, // This now contains the processed pixel data per component
        $comments
    ];
}

/**
 * Builds the final pixel data for a component by applying IDCT and level shifting.
 */
function buildComponentData(&$component, &$frame) { // Pass component by ref if pred needs update? No, pred is for decode.
    $lines = [];
    $blocksPerLine = $component['blocksPerLine'];
    $blocksPerColumn = $component['blocksPerColumn'];
    $samplesPerLine = $blocksPerLine << 3; // width = blocksPerLine * 8
    $totalLines = $blocksPerColumn << 3;   // height = blocksPerColumn * 8

    echo "<p>  buildComponentData: {$blocksPerLine}x{$blocksPerColumn} blocks ({$samplesPerLine}x{$totalLines} pixels)</p>";


    // Pre-allocate lines array
    for ($y = 0; $y < $totalLines; $y++) {
        $lines[$y] = array_fill(0, $samplesPerLine, 0);
    }

    // Buffers for IDCT (reused per block)
    $dctOutput = array_fill(0, 64, 0); // Stores dequantized DCT coeffs (input to IDCT)
    $pixelOutput = array_fill(0, 64, 0); // Stores IDCT output pixels (0-255)

    $blockCount = 0;
    for ($blockRow = 0; $blockRow < $blocksPerColumn; $blockRow++) {
        $baseScanLine = $blockRow << 3; // y * 8

        if (!isset($component['blocks'][$blockRow])) {
             echo "<p>Warning: Missing block row $blockRow in component {$component['id']}</p>";
             continue;
        }

        for ($blockCol = 0; $blockCol < $blocksPerLine; $blockCol++) {
             $blockCount++;
             if (!isset($component['blocks'][$blockRow][$blockCol])) {
                 echo "<p>Warning: Missing block at [$blockRow, $blockCol] in component {$component['id']}</p>";
                 continue;
             }

            // Get the decoded (but still quantized) DCT coefficients (zigzag order)
            $zz = $component['blocks'][$blockRow][$blockCol];

            // Apply dequantization and Inverse DCT using the fast integer method
            // quantizeAndInverse takes zigzag coeffs, outputs 8-bit pixels (natural order)
            // It needs the component's quantization table.
            quantizeAndInverse($zz, $pixelOutput, $dctOutput, $component);
            // $zz: Input (Quantized DCT coeffs, zigzag) - Read by func
            // $pixelOutput: Output (Pixel values 0-255, natural order) - Written by func
            // $dctOutput: Intermediate (Dequantized DCT coeffs) - Written & read by func
            // $component: Contains ['quantizationTable'] (zigzag order) - Read by func

            // Copy the 8x8 pixel block into the component's lines array
            $baseSample = $blockCol << 3; // x * 8
            $pixelIdx = 0;
            for ($j = 0; $j < 8; $j++) { // Row within block
                $currentLine = $baseScanLine + $j;
                 if (!isset($lines[$currentLine])) {
                    echo "<p>Warning: Target line $currentLine out of bounds (max $totalLines).</p>";
                    continue; // Should not happen if allocation is correct
                 }
                for ($i = 0; $i < 8; $i++) { // Col within block
                    $currentSample = $baseSample + $i;
                    if (!isset($lines[$currentLine][$currentSample])) {
                         echo "<p>Warning: Target sample $currentSample out of bounds (max $samplesPerLine).</p>";
                         continue; // Should not happen
                    }
                    $lines[$currentLine][$currentSample] = $pixelOutput[$pixelIdx++];
                }
            }
        }
    }
     echo "<p>  Processed $blockCount blocks for component {$component['id']}.</p>";
    return $lines;
}


/**
 * Fast Integer Inverse DCT (AAN Algorithm) and Dequantization.
 * Based on libjpeg's implementation.
 *
 * @param array $zz Input: 64 quantized DCT coefficients in zigzag order.
 * @param array &$dataOut Output: 64 pixel values (0-255) in natural order (passed by reference).
 * @param array &$dataIn Intermediate/Workspace: 64 dequantized DCT coefficients (passed by reference).
 * @param array $component The component definition containing ['quantizationTable'].
 */
function quantizeAndInverse($zz, &$dataOut, &$dataIn, $component) {
    $qt = $component['quantizationTable']; // Quantization table (zigzag order)
    $p = &$dataIn; // Use $dataIn as workspace for dequantized coefficients

    // Dequantize (Results stored in $p in zigzag order)
    for ($i = 0; $i < 64; $i++) {
        // Access Q table in zigzag order, multiply with zigzag input coeff
        $p[$i] = $zz[$i] * $qt[$i];
    }

    // Inverse DCT on rows (operates on $p, results overwrite $p)
    // The IDCT input needs to be in natural row order, but $p is currently zigzag.
    // However, the AAN IDCT algorithm below is cleverly designed to work
    // directly on the intermediate values derived from the zigzag input if accessed correctly.
    // Let's adapt the provided AAN code which seems to expect natural order input.
    // We need to un-zigzag $p first.

    $p_natural = array_fill(0, 64, 0);
    for($i=0; $i<64; $i++) {
        $p_natural[$GLOBALS['dctZigZag'][$i]] = $p[$i];
    }
    // Now $p_natural contains the dequantized coefficients in natural 8x8 order.
    // We'll use $p_natural as input and $p as output buffer for row IDCT.

    $p_row_out = &$p; // Reuse $p for output of row IDCT

    for ($i = 0; $i < 8; ++$i) { // Loop over rows
        $row_offset = 8 * $i;
        $in_ptr = $row_offset; // Start index for this row in $p_natural

        // Check for all-zero AC coefficients in this row
        $ac_all_zero = true;
        for ($k = 1; $k < 8; $k++) {
            if ($p_natural[$in_ptr + $k] != 0) {
                $ac_all_zero = false;
                break;
            }
        }

        if ($ac_all_zero) {
            // Simplified IDCT for DC-only row
            $dcval = $p_natural[$in_ptr + 0];
            // The formula uses DCT scaling constants. For AAN, this becomes:
            // t = (dctSqrt2 * dcval + SROUND) >> SBITS;
            // Where SROUND and SBITS depend on implementation details (e.g., 512, 10 or 128, 8)
            // Let's use the constants from the original code (dctSqrt2=5793, shift 10)
            $t = ($GLOBALS['dctSqrt2'] * $dcval + 512) >> 10;
            for ($k = 0; $k < 8; $k++) {
                $p_row_out[$row_offset + $k] = $t;
            }
            continue; // Next row
        }

        // Full AAN IDCT Row Pass (Derived from the provided code)
        // Stage 4
        $v0 = ($GLOBALS['dctSqrt2'] * $p_natural[$in_ptr + 0] + 128) >> 8;
        $v1 = ($GLOBALS['dctSqrt2'] * $p_natural[$in_ptr + 4] + 128) >> 8;
        $v2 = $p_natural[$in_ptr + 2];
        $v3 = $p_natural[$in_ptr + 6];
        $v4 = ($GLOBALS['dctSqrt1d2'] * ($p_natural[$in_ptr + 1] - $p_natural[$in_ptr + 7]) + 128) >> 8;
        $v7 = ($GLOBALS['dctSqrt1d2'] * ($p_natural[$in_ptr + 1] + $p_natural[$in_ptr + 7]) + 128) >> 8;
        $v5 = $p_natural[$in_ptr + 3] << 4; // Needs shift adjustment if constants change
        $v6 = $p_natural[$in_ptr + 5] << 4; // Needs shift adjustment

        // Stage 3
        $t = ($v0 - $v1 + 1) >> 1; // +1 for rounding
        $v0 = ($v0 + $v1 + 1) >> 1;
        $v1 = $t;
        $t = ($v2 * $GLOBALS['dctSin6'] + $v3 * $GLOBALS['dctCos6'] + 128) >> 8;
        $v2 = ($v2 * $GLOBALS['dctCos6'] - $v3 * $GLOBALS['dctSin6'] + 128) >> 8;
        $v3 = $t;
        $t = ($v4 - $v6 + 1) >> 1;
        $v4 = ($v4 + $v6 + 1) >> 1;
        $v6 = $t;
        $t = ($v7 + $v5 + 1) >> 1;
        $v5 = ($v7 - $v5 + 1) >> 1;
        $v7 = $t;

        // Stage 2
        $t = ($v0 - $v3 + 1) >> 1;
        $v0 = ($v0 + $v3 + 1) >> 1;
        $v3 = $t;
        $t = ($v1 - $v2 + 1) >> 1;
        $v1 = ($v1 + $v2 + 1) >> 1;
        $v2 = $t;
        // These shifts (>>12) and constants (2048) might need tuning
        $t = ($v4 * $GLOBALS['dctSin3'] + $v7 * $GLOBALS['dctCos3'] + 2048) >> 12;
        $v4 = ($v4 * $GLOBALS['dctCos3'] - $v7 * $GLOBALS['dctSin3'] + 2048) >> 12;
        $v7 = $t;
        $t = ($v5 * $GLOBALS['dctSin1'] + $v6 * $GLOBALS['dctCos1'] + 2048) >> 12;
        $v5 = ($v5 * $GLOBALS['dctCos1'] - $v6 * $GLOBALS['dctSin1'] + 2048) >> 12;
        $v6 = $t;

        // Stage 1 - Output to $p_row_out
        $p_row_out[$row_offset + 0] = $v0 + $v7;
        $p_row_out[$row_offset + 7] = $v0 - $v7;
        $p_row_out[$row_offset + 1] = $v1 + $v6;
        $p_row_out[$row_offset + 6] = $v1 - $v6;
        $p_row_out[$row_offset + 2] = $v2 + $v5;
        $p_row_out[$row_offset + 5] = $v2 - $v5;
        $p_row_out[$row_offset + 3] = $v3 + $v4;
        $p_row_out[$row_offset + 4] = $v3 - $v4;
    }

    // Inverse DCT on columns (operates on $p_row_out, results overwrite $p_row_out)
    // Input is now $p_row_out (intermediate results in natural order)
    // Output will overwrite $p_row_out
    for ($i = 0; $i < 8; ++$i) { // Loop over columns
        $col_ptr = $i; // Start index for this column

         // Check for all-zero AC coefficients in this column
         $ac_all_zero = true;
         for ($k = 1; $k < 8; $k++) {
             if ($p_row_out[$k * 8 + $col_ptr] != 0) {
                 $ac_all_zero = false;
                 break;
             }
         }

        if ($ac_all_zero) {
            // Simplified IDCT for DC-only column
            $dcval = $p_row_out[0 * 8 + $col_ptr];
            // The original code uses different scaling here (dctSqrt2, shift 14, add 8192)
            // Let's try consistency first (shift 10, add 512), adjust if needed
             // $t = ($GLOBALS['dctSqrt2'] * $dcval + 512) >> 10;
             // Using the original code's scaling for columns:
             $t = ($GLOBALS['dctSqrt2'] * $dcval + 8192) >> 14;

            for ($k = 0; $k < 8; $k++) {
                 $p_row_out[$k * 8 + $col_ptr] = $t;
            }
            continue; // Next column
        }

        // Full AAN IDCT Column Pass (using values from $p_row_out)
        // Stage 4
        $v0 = ($GLOBALS['dctSqrt2'] * $p_row_out[0*8 + $col_ptr] + 2048) >> 12; // Different scaling from original? Yes.
        $v1 = ($GLOBALS['dctSqrt2'] * $p_row_out[4*8 + $col_ptr] + 2048) >> 12;
        $v2 = $p_row_out[2*8 + $col_ptr];
        $v3 = $p_row_out[6*8 + $col_ptr];
        $v4 = ($GLOBALS['dctSqrt1d2'] * ($p_row_out[1*8 + $col_ptr] - $p_row_out[7*8 + $col_ptr]) + 2048) >> 12;
        $v7 = ($GLOBALS['dctSqrt1d2'] * ($p_row_out[1*8 + $col_ptr] + $p_row_out[7*8 + $col_ptr]) + 2048) >> 12;
        $v5 = $p_row_out[3*8 + $col_ptr]; // No shift? Original col code didn't shift v5/v6 here.
        $v6 = $p_row_out[5*8 + $col_ptr];

        // Stage 3
        $t = ($v0 - $v1 + 1) >> 1;
        $v0 = ($v0 + $v1 + 1) >> 1;
        $v1 = $t;
        $t = ($v2 * $GLOBALS['dctSin6'] + $v3 * $GLOBALS['dctCos6'] + 2048) >> 12; // Shift 12? Original used 8.
        $v2 = ($v2 * $GLOBALS['dctCos6'] - $v3 * $GLOBALS['dctSin6'] + 2048) >> 12;
        $v3 = $t;
        $t = ($v4 - $v6 + 1) >> 1;
        $v4 = ($v4 + $v6 + 1) >> 1;
        $v6 = $t;
        $t = ($v7 + $v5 + 1) >> 1;
        $v5 = ($v7 - $v5 + 1) >> 1;
        $v7 = $t;

        // Stage 2
        $t = ($v0 - $v3 + 1) >> 1;
        $v0 = ($v0 + $v3 + 1) >> 1;
        $v3 = $t;
        $t = ($v1 - $v2 + 1) >> 1;
        $v1 = ($v1 + $v2 + 1) >> 1;
        $v2 = $t;
        $t = ($v4 * $GLOBALS['dctSin3'] + $v7 * $GLOBALS['dctCos3'] + 2048) >> 12;
        $v4 = ($v4 * $GLOBALS['dctCos3'] - $v7 * $GLOBALS['dctSin3'] + 2048) >> 12;
        $v7 = $t;
        $t = ($v5 * $GLOBALS['dctSin1'] + $v6 * $GLOBALS['dctCos1'] + 2048) >> 12;
        $v5 = ($v5 * $GLOBALS['dctCos1'] - $v6 * $GLOBALS['dctSin1'] + 2048) >> 12;
        $v6 = $t;

        // Stage 1 - Output to $p_row_out (overwriting intermediate column data)
        $p_row_out[0*8 + $col_ptr] = $v0 + $v7;
        $p_row_out[7*8 + $col_ptr] = $v0 - $v7;
        $p_row_out[1*8 + $col_ptr] = $v1 + $v6;
        $p_row_out[6*8 + $col_ptr] = $v1 - $v6;
        $p_row_out[2*8 + $col_ptr] = $v2 + $v5;
        $p_row_out[5*8 + $col_ptr] = $v2 - $v5;
        $p_row_out[3*8 + $col_ptr] = $v3 + $v4;
        $p_row_out[4*8 + $col_ptr] = $v3 - $v4;
    }

    // Level shift and clamp to 8-bit range -> Output to $dataOut
    // Input is the final result in $p_row_out (natural order)
    for ($i = 0; $i < 64; ++$i) {
      // Add 128, then apply rounding and shift. Original used (val + 8) >> 4.
      // Let's use the standard +128 for level shift. The scaling factors should handle the rest.
      // A right shift might be needed if the results aren't centered around 0.
      // The original formula `128 + (($p[$i] + 8) >> 4)` suggests the IDCT output
      // has a certain scale. Let's try that specific formula.
      $sample = 128 + (($p_row_out[$i] + 8) >> 4); // Apply level shift and scaling/clamping
      $dataOut[$i] = $sample < 0 ? 0 : ($sample > 255 ? 255 : $sample);
    }
}


/**
 * Builds a Huffman decoding tree from the BITS (L) and HUFFVAL (V) lists.
 * Returns a nested array structure where keys are 0 or 1 (bits) and
 * leaves are the decoded symbol values (integers).
 *
 * @param array $codeLengths The L1..L16 counts from the DHT marker.
 * @param array $values The HUFFVAL list (V) from the DHT marker.
 * @return array The Huffman decoding tree.
 */
function buildHuffmanTable($codeLengths, $values) {
    $root = []; // Start with an empty root array (represents the tree)
    $valueIndex = 0; // Index into the $values array
    $huffmanCode = 0; // The current Huffman code being assigned (integer value)

    //echo "<p>Building Huffman Table...</p>"; // Debug

    // Iterate through code lengths (1 to 16 bits)
    for ($i = 0; $i < 16; $i++) {
        $numCodesOfThisLength = $codeLengths[$i]; // L(i+1)
        $codeLength = $i + 1; // Actual bit length

        //echo "<p>-- Length: $codeLength, Count: $numCodesOfThisLength</p>"; // Debug

        // Assign codes for all symbols of this length
        for ($j = 0; $j < $numCodesOfThisLength; $j++) {
            if ($valueIndex >= count($values)) {
                // This indicates an error in the JPEG DHT data (more codes than values)
                echo "<p><b>Warning:</b> More Huffman codes specified in BITS than values provided in HUFFVAL. Value index: $valueIndex, Total values: " . count($values) . "</p>";
                 // Returning a partially built tree might lead to errors later.
                 // It's safer to throw or return an indicator of failure.
                 throw new Exception("Invalid DHT data: Mismatch between code counts and values.");
                 // break 2; // Stop processing this table
            }
            $value = $values[$valueIndex++]; // Get the next symbol value (V)
            $node = &$root; // Start traversal from the root (use reference!)

            // Traverse or build the tree according to the bits of the current huffmanCode
            for ($bitPos = $codeLength - 1; $bitPos >= 0; $bitPos--) {
                $bit = ($huffmanCode >> $bitPos) & 1; // Get bit (MSB first)

                if ($bitPos == 0) {
                    // This is the last bit - assign the value to this leaf node
                    if (isset($node[$bit]) && is_array($node[$bit])) {
                        // Error: Trying to assign a value where a branch already exists
                        // This means a code is a prefix of another - invalid Huffman table
                         echo "<p><b>Warning:</b> Huffman table conflict. Code " . str_pad(decbin($huffmanCode), $codeLength, '0', STR_PAD_LEFT) . " is a prefix of an existing code. Assigning value $value anyway.</p>";
                         // Allow overwriting for potential robustness, but it's likely an error.
                         // throw new Exception("Invalid Huffman table: Code is prefix of another.");
                    }
                    $node[$bit] = $value;
                    //echo "<p>---- Assigned Value: $value to Code: " . str_pad(decbin($huffmanCode), $codeLength, '0', STR_PAD_LEFT) . "</p>"; // Debug
                } else {
                    // This is an intermediate bit - ensure a branch exists and move down
                    if (!isset($node[$bit])) {
                        // Create a new branch (empty array) if it doesn't exist
                        $node[$bit] = [];
                        //echo "<p>------ Created Branch for bit $bit at level " . ($codeLength - $bitPos) . "</p>"; // Debug
                    } else if (!is_array($node[$bit])) {
                         // Error: Trying to create a branch where a value already exists
                         // This means an existing code is a prefix of this one - invalid Huffman table
                         echo "<p><b>Warning:</b> Huffman table conflict. Trying to create branch over existing value " . $node[$bit] . " for code " . str_pad(decbin($huffmanCode), $codeLength, '0', STR_PAD_LEFT) . ".</p>";
                         // Replace the value with a branch, potentially losing the previous code.
                         $node[$bit] = []; // Overwrite value with branch.
                          // throw new Exception("Invalid Huffman table: Code prefix conflict.");
                    }
                    // Move down the tree (update reference)
                    $node = &$node[$bit];
                }
            }

            // Increment the code for the next symbol of the *same* length
            $huffmanCode++;
        } // End loop for codes of current length

        // Shift the code left for the next length (standard canonical Huffman procedure)
        // e.g., if code was 101 (len 3), next code for len 4 starts at (101 + 1) << 1 = 110 << 1 = 1100
        $huffmanCode <<= 1;
    } // End loop for code lengths

    //echo "<p>Finished Huffman Table. Root structure:</p><pre>" . print_r($root, true) . "</pre>"; // Debug
    return $root; // Return the completed tree
}


/**
 * Decodes the entropy-coded data for a scan.
 * Modifies the component blocks directly.
 *
 * @param JPEGFileReader $reader The file reader, positioned at the start of scan data.
 * @param array &$frame The main frame data structure.
 * @param array &$scanComponents Array of references to the components included in this scan.
 * @param int $resetInterval Restart interval in MCUs (0 if none).
 * @param int $spectralStart Start of spectral band for progressive scan (usually 0 for baseline).
 * @param int $spectralEnd End of spectral band for progressive scan (usually 63 for baseline).
 * @param int $successivePrev Successive approximation bit position high (for AC).
 * @param int $successive Successive approximation bit position low (for DC/AC).
 */
function decodeScan(
    JPEGFileReader $reader,
    array &$frame,
    array &$scanComponents, // Passed by reference, contains refs to frame[components][id]
    int $resetInterval,
    int $spectralStart,
    int $spectralEnd,
    int $successivePrev, // Ah
    int $successive      // Al
) {
    $mcusPerLine = $frame['mcusPerLine'];
    $mcusPerColumn = $frame['mcusPerColumn'];
    $progressive = $frame['progressive'];
    $baseline = $frame['baseline']; // Use this for clarity

    // --- Huffman Decoding Helper ---
    $decodeHuffman = function ($tree) use ($reader) : ?int {
        $node = $tree; // Start at the root of the specific AC/DC tree
        while (is_array($node)) { // While we are at a branch node
            $bit = $reader->readBit();
            if ($bit === null) {
                 // echo "<p>DEBUG: decodeHuffman hit EOF or marker</p>";
                return null; // EOF or marker encountered during bit read
            }
            if (!isset($node[$bit])) {
                 // This indicates an invalid Huffman code sequence in the data stream
                 // Or a corrupted Huffman table definition.
                 echo "<p><b>Error:</b> Invalid Huffman code sequence encountered. Bit: $bit. Current Node: " . print_r($node, true) . "</p>";
                 // Attempt to find the next marker to resync? Difficult. Throwing is safer.
                 throw new Exception("Invalid Huffman code sequence in scan data.");
            }
            $node = $node[$bit]; // Move down the tree
        }
        // If loop finishes, $node should hold the decoded integer value
        if (!is_int($node)) {
             // Should not happen if tree is built correctly and data is valid
             throw new Exception("Huffman decoding ended on a non-integer node.");
        }
        return $node; // Return the decoded symbol (e.g., category for DC, run/size for AC)
    };

    // --- Bit Reading Helpers ---
    $receive = function ($length) use ($reader) : ?int {
        if ($length == 0) return 0;
        $n = 0;
        for ($i = 0; $i < $length; $i++) {
            $bit = $reader->readBit();
            if ($bit === null) return null; // EOF or marker
            $n = ($n << 1) | $bit;
        }
        return $n;
    };

    // Decode the value bits and extend to signed representation (JPEG Annex F.2.2.1)
    $receiveAndExtend = function ($length) use ($receive) : ?int {
        if ($length == 0) return 0; // Category 0 has 0 bits, value is 0
        $n = $receive($length);
        if ($n === null) return null; // EOF or marker

        // Check the most significant bit (sign bit)
        // If it's 0, the value is negative in JPEG's representation
        if ($n < (1 << ($length - 1))) {
            // Negative number: Value = n - (2^length - 1)
            // Example: length=3 (values 000..111). If n=011 (3), sign=0. Value = 3 - (8-1) = -4.
            // Simplified: n + (-1 << length) + 1
             $n = $n + (-1 << $length) + 1;
        }
        // Positive number: Value = n
        // Example: length=3. If n=100 (4), sign=1. Value = 4.
        return $n;
    };

    // --- Decoding Functions for Different Modes ---

    // Baseline DCT decode for one 8x8 block
    $decodeBaseline = function (&$component, &$zz) use ($decodeHuffman, $receiveAndExtend, $reader) {
        // Decode DC coefficient
        $dcCategory = $decodeHuffman($component['huffmanTableDC']); // Get category (length of value bits)
        if ($dcCategory === null) return false; // EOF or marker
        if ($dcCategory > 11) throw new Exception("Invalid DC category: $dcCategory"); // Max category for DC is 11
        $dcDiff = $receiveAndExtend($dcCategory); // Get the signed difference value
        if ($dcDiff === null) return false;
        $component['pred'] += $dcDiff; // Update DC predictor (cumulative)
        $zz[0] = $component['pred'];   // Store the absolute DC value

        // Decode AC coefficients
        $k = 1; // Index in zigzag sequence (starts at AC coeff 1)
        while ($k < 64) {
            $acSymbol = $decodeHuffman($component['huffmanTableAC']); // Get Run/Size symbol
            if ($acSymbol === null) return false; // EOF or marker

            $size = $acSymbol & 0x0F; // Lower 4 bits: size (category) of AC coefficient value
            $run = $acSymbol >> 4;   // Upper 4 bits: run length of zeros before this coeff

            if ($size === 0) { // Special AC symbols
                if ($run === 15) { // ZRL (Zero Run Length) - Skip 16 zeros
                    $k += 16;
                } else { // EOB (End of Block) - Remaining coeffs are zero
                    // No need to fill $zz with zeros, it was initialized that way.
                    break; // Stop decoding AC for this block
                }
            } else { // Regular AC coefficient
                $k += $run; // Skip 'run' zeros
                if ($k >= 64) {
                     // echo "<p>Warning: Run length ($run) exceeds block size (k=$k) for AC symbol 0x".dechex($acSymbol)."</p>";
                     break; // Data corruption likely
                 }
                $acValue = $receiveAndExtend($size); // Get the signed AC coefficient value
                if ($acValue === null) return false;
                $zz[$GLOBALS['dctZigZag'][$k]] = $acValue; // Store AC value at correct zigzag position
                $k++; // Move to next zigzag position
            }
        }
         return true; // Successfully decoded block
    };


    // Progressive DC Decode - First Scan (AC Scan)
    // Decodes only the DC coefficient's most significant bits.
    $decodeDCFirst = function (&$component, &$zz) use ($decodeHuffman, $receiveAndExtend, $successive) { // $successive = Al
        $dcCategory = $decodeHuffman($component['huffmanTableDC']);
        if ($dcCategory === null) return false;
        if ($dcCategory > 11) throw new Exception("Invalid DC category: $dcCategory");
        $dcDiff = $receiveAndExtend($dcCategory);
        if ($dcDiff === null) return false;
        $component['pred'] += $dcDiff; // Update predictor
        // Store the value shifted left by Al (successive approximation bit position low)
        // This effectively stores only the MSBs.
        $zz[0] = $component['pred'] << $successive;
        return true;
    };

    // Progressive DC Decode - Refinement Scan (DC Scan)
    // Decodes the next single bit (at position Al) for the DC coefficient.
    $decodeDCSuccessive = function (&$component, &$zz) use ($reader, $successive) { // $successive = Al
        $bit = $reader->readBit(); // Read the single refinement bit
        if ($bit === null) return false;
        // OR the bit into the existing DC value at the correct position (Al)
        $zz[0] |= ($bit << $successive);
        return true;
    };

    // Progressive AC Decode - First Scan (AC Scan)
    // Decodes AC coefficients' MSBs (spectral selection Ss to Se).
    $eobrun = 0; // End-of-Block Run counter for AC successive refinement
    $decodeACFirst = function (&$component, &$zz) use ($decodeHuffman, $receive, $receiveAndExtend, $successive, &$eobrun, $spectralStart, $spectralEnd) { // $successive = Al
        // If we are in an EOB run from a previous block in this MCU refinement scan, just decrement count.
        if ($eobrun > 0) {
            $eobrun--;
            return true;
        }

        $k = $spectralStart; // Start spectral selection index
        while ($k <= $spectralEnd) { // Loop through selected AC coefficients
            $acSymbol = $decodeHuffman($component['huffmanTableAC']);
            if ($acSymbol === null) return false;

            $size = $acSymbol & 0x0F;
            $run = $acSymbol >> 4;

            if ($size === 0) {
                if ($run === 15) { // ZRL
                    $k += 16;
                } else { // EOB Run
                    // Receive the length of the EOB run (number of blocks to skip AC decode)
                    $eobrun = $receive($run);
                    if ($eobrun === null) return false;
                    $eobrun += (1 << $run) - 1; // Calculate total run length (Annex G.1.2.2)
                    // Decrement now for the current block ending
                    $eobrun--;
                    break; // End AC decode for this block
                }
            } else {
                $k += $run; // Skip zeros
                 if ($k > $spectralEnd) { // Check if run exceeded spectral band
                     // echo "<p>Warning: AC run length ($run) exceeded spectral end ($spectralEnd) at k=$k.</p>";
                     break;
                 }
                $acValue = $receiveAndExtend($size);
                if ($acValue === null) return false;
                // Store the AC value shifted left by Al (store MSBs)
                $zz[$GLOBALS['dctZigZag'][$k]] = $acValue << $successive;
                $k++;
            }
        }
        return true;
    };


    // Progressive AC Decode - Refinement Scan (AC Scan)
    // Decodes the next single bit (at position Al) for existing non-zero AC coefficients,
    // or inserts new coefficients that become non-zero after this refinement.
    // This is complex logic from JPEG Annex G.1.2.3.
    $successiveACState = 0; // State machine: 0=Initial, 1/2=Skipping zeros, 3=Got new value, 4=In EOB run
    $successiveACNextValue = 0; // Stores newly determined AC value magnitude (1 or -1)
    $decodeACSuccessive = function (&$component, &$zz) use (
        $reader, $decodeHuffman, $receive, $receiveAndExtend,
        $successive, &$eobrun, $spectralStart, $spectralEnd,
        &$successiveACState, &$successiveACNextValue
    ) { // $successive = Al

        $k = $spectralStart;
        $e = $spectralEnd;

        // Handle EOB run continuation
        if ($successiveACState === 4) {
            // Refine non-zero coefficients within the EOB run
            while ($k <= $e) {
                $zig_k = $GLOBALS['dctZigZag'][$k];
                if ($zz[$zig_k] !== 0) { // If coefficient is already non-zero
                    $bit = $reader->readBit();
                    if ($bit === null) return false;
                    if ($bit === 1) { // Add refinement bit
                        $zz[$zig_k] += ($zz[$zig_k] > 0 ? 1 : -1) << $successive;
                    }
                }
                $k++;
            }
            $eobrun--;
            if ($eobrun === 0) {
                $successiveACState = 0; // EOB run finished
            }
            return true;
        }

        // Main state machine for refining/finding coefficients
        while ($k <= $e) {
            $zig_k = $GLOBALS['dctZigZag'][$k]; // Zigzag index for current spectral position k

            switch ($successiveACState) {
                case 0: // Initial state: Decode next symbol (could be ZRL, EOB, or Run/Value=1)
                    $rs = $decodeHuffman($component['huffmanTableAC']);
                    if ($rs === null) return false;
                    $s = $rs & 0x0F; // Size (should be 0 or 1 for successive AC)
                    $r = $rs >> 4;   // Run

                    if ($s === 0) { // ZRL or EOB
                        if ($r === 15) { // ZRL
                            $k += 16; // Skip 16 zeros (no refinement needed for zeros)
                             // Stay in state 0
                        } else { // EOB Run
                            $eobrun = $receive($r);
                            if ($eobrun === null) return false;
                            $eobrun += (1 << $r) -1;
                            // We are now in an EOB run. Refine any existing non-zero coeffs.
                             $successiveACState = 4;
                             // Refine from current k to end of spectral band
                             while ($k <= $e) {
                                 if ($zz[$GLOBALS['dctZigZag'][$k]] !== 0) {
                                     $bit = $reader->readBit();
                                     if ($bit === null) return false;
                                     if ($bit === 1) {
                                         $zz[$GLOBALS['dctZigZag'][$k]] += ($zz[$GLOBALS['dctZigZag'][$k]] > 0 ? 1 : -1) << $successive;
                                     }
                                 }
                                 $k++;
                             }
                             $eobrun--; // Decrement for current block
                             if ($eobrun === 0) $successiveACState = 0; // Run might end immediately
                             return true; // Finished block due to EOB
                        }
                    } else { // s != 0. For successive AC, s must be 1.
                        if ($s !== 1) {
                            throw new Exception("Invalid AC successive symbol Size ($s) != 1. Symbol=0x".dechex($rs));
                        }
                        // Received (Run, Size=1). Read the sign bit.
                        $bit = $reader->readBit();
                        if ($bit === null) return false;
                        $successiveACNextValue = ($bit === 1) ? (1 << $successive) : (-1 << $successive);
                        // Now skip R zeros, then assign the new value.
                        $successiveACState = $r > 0 ? 2 : 3; // Go to state 2 if run > 0, else state 3
                        // Need to continue the loop (use `continue 2` if inside switch inside loop)
                        continue 2; // Restart switch for current k in new state
                    }
                    break; // Break switch for case 0 (ZRL handled by incrementing k)

                case 1: // Not used? State 2 handles skipping.
                case 2: // Skipping R zeros. Refine non-zeros, decrement run count R.
                    $zig_k = $GLOBALS['dctZigZag'][$k];
                    if ($zz[$zig_k] !== 0) { // Refine existing non-zero coefficient
                        $bit = $reader->readBit();
                        if ($bit === null) return false;
                        if ($bit === 1) {
                            $zz[$zig_k] += ($zz[$zig_k] > 0 ? 1 : -1) << $successive;
                        }
                    } else {
                        $r--; // Decrement run count
                        if ($r === 0) {
                            $successiveACState = 3; // Run finished, next k gets the new value
                        }
                    }
                    break; // Break switch, increment k happens below

                case 3: // Assign the newly determined value
                     $zig_k = $GLOBALS['dctZigZag'][$k];
                     if ($zz[$zig_k] !== 0) { // Refine existing non-zero coefficient
                         $bit = $reader->readBit();
                         if ($bit === null) return false;
                         if ($bit === 1) {
                             $zz[$zig_k] += ($zz[$zig_k] > 0 ? 1 : -1) << $successive;
                         }
                     } else { // Coefficient was zero, assign the new value
                         $zz[$zig_k] = $successiveACNextValue;
                         $successiveACState = 0; // Go back to initial state for next symbol
                     }
                     break; // Break switch, increment k happens below

                // Case 4 (EOB run) is handled at the start of the function now.

            } // End switch($successiveACState)

            $k++; // Move to next spectral coefficient
        } // End while (k <= e)

        return true; // Finished block processing
    };


    // --- Block/MCU Decoding Logic ---
    $decodeMCU = function(
        $mcuIndex,
        &$component,
        $decodeFn // The specific function (baseline, DC first, AC successive etc.)
    ) use ($mcusPerLine, &$frame) {
        $mcuRow = floor($mcuIndex / $mcusPerLine);
        $mcuCol = $mcuIndex % $mcusPerLine;
        $compH = $component['h'];
        $compV = $component['v'];
        $blocksPerLine = $component['blocksPerLine']; // Total blocks per line for this component

        // Iterate through the blocks within this MCU for this component
        for ($v = 0; $v < $compV; $v++) {
            $blockRow = $mcuRow * $compV + $v;
             if ($blockRow >= $component['blocksPerColumn']) continue; // Handle padding on bottom edge

            for ($h = 0; $h < $compH; $h++) {
                $blockCol = $mcuCol * $compH + $h;
                if ($blockCol >= $blocksPerLine) continue; // Handle padding on right edge

                if (!isset($component['blocks'][$blockRow][$blockCol])) {
                     // This might happen if prepareComponents allocated less than needed, or indexing is wrong.
                     echo "<p><b>Error:</b> Attempting to access non-existent block [$blockRow, $blockCol] for component {$component['id']} in MCU $mcuIndex.</p>";
                     return false; // Signal error
                }

                // Get reference to the block's zigzag coefficients array
                $zz = &$component['blocks'][$blockRow][$blockCol];

                // Call the appropriate decoding function (e.g., decodeBaseline)
                if (!$decodeFn($component, $zz)) {
                    // Decoding function returned false (EOF or marker)
                    return false; // Propagate failure
                }
            }
        }
        return true; // Successfully decoded all blocks for this component in this MCU
    };

    // --- Select Decoding Function based on Scan Parameters ---
    $decodeFn = null;
    if ($baseline) {
        $decodeFn = $decodeBaseline;
    } elseif ($progressive) {
        if ($spectralStart === 0 && $spectralEnd === 0) { // DC Scan
            if ($successivePrev === 0) { // DC First Scan (MSBs)
                $decodeFn = $decodeDCFirst;
            } else { // DC Refinement Scan (LSBs)
                $decodeFn = $decodeDCSuccessive;
            }
        } elseif ($spectralStart > 0 && $spectralEnd > 0) { // AC Scan
             if ($successivePrev === 0) { // AC First Scan (MSBs)
                 $decodeFn = $decodeACFirst;
                 $eobrun = 0; // Reset EOB run counter for AC first scan
             } else { // AC Refinement Scan (LSBs)
                 $decodeFn = $decodeACSuccessive;
                 $successiveACState = 0; // Reset state machine for AC successive
             }
        } else {
            // Invalid combination for progressive (e.g., spectralStart=0, spectralEnd > 0)
            throw new Exception("Invalid spectral selection (Ss=$spectralStart, Se=$spectralEnd) for progressive scan.");
        }
    } else {
        // Only Baseline and Progressive DCT modes are supported here
        throw new Exception("Unsupported JPEG SOF type: 0x" . dechex($frame['type']));
    }

    // --- Main MCU Loop ---
    $totalMCUs = $mcusPerLine * $mcusPerColumn;
    $mcuCounter = 0;
    $restartMarkerFound = false;

    while ($mcuCounter < $totalMCUs) {
        // --- Handle Restart Interval ---
        if ($resetInterval > 0 && ($mcuCounter % $resetInterval === 0)) {
            echo "<p>-- MCU $mcuCounter: Expecting Restart Marker RST" . ($mcuCounter / $resetInterval) % 8 . " --</p>";

            // Reset DC predictors for all components in the scan
            foreach ($scanComponents as &$compRef) {
                $compRef['pred'] = 0;
            }
            unset($compRef);

            // Reset progressive AC state if applicable
             $eobrun = 0;
             $successiveACState = 0;

            // The bit buffer needs to be discarded before reading the marker byte-aligned.
            // The readBit function handles marker detection and buffer invalidation.
            // If a marker was just detected by readBit returning null, the main loop should find it.
             if ($restartMarkerFound) {
                // We already consumed the marker in the previous loop iteration's end check.
                $restartMarkerFound = false; // Reset flag
             } else {
                 // If readBit didn't just signal a marker, we need to explicitly look for one.
                 // This might involve flushing the bit buffer and reading bytes.
                 // Let's rely on decodeHuffman/receive to return null if a marker is hit.
                 // If we are exactly at a restart interval, we *must* find RSTm or EOI.
                 // Force bit buffer reset and read marker.
                 $reader->bitBuffer = 0; // TODO: Add method to JPEGFileReader to reset bits
                 $reader->bitBufferLength = 0;
                 $expectedRST = 0xFFD0 | (($mcuCounter / $resetInterval) % 8);
                 $marker = $reader->readMarker(); // Reads byte-aligned marker

                 if ($marker === null) {
                     throw new Exception("EOF encountered while expecting restart marker RST" . ($expectedRST & 7) . " at MCU $mcuCounter");
                 }
                 if ($marker !== $expectedRST) {
                      // Allow EOI immediately after last MCU? Some encoders might do this.
                      if ($marker === 0xFFD9 && $mcuCounter === $totalMCUs) {
                          echo "<p>Found EOI instead of final RST marker. Ending scan.</p>";
                           $restartMarkerFound = true; // Signal marker found to break outer loop
                           break;
                      }
                     throw new Exception("Expected restart marker RST" . ($expectedRST & 7) . " but found 0x" . dechex($marker) . " at MCU $mcuCounter");
                 }
                 echo "<p>-- Found Restart Marker 0x" . dechex($marker) . " --</p>";
             }
        } // End restart interval handling

        // --- Decode one MCU ---
        // An MCU consists of blocks from all components in the scan,
        // interleaved according to their sampling factors.
        foreach ($scanComponents as &$compRef) {
             // Pass MCU counter, component ref, and the decode function
            if (! $decodeMCU($mcuCounter, $compRef, $decodeFn)) {
                 // Decoding failed (EOF or marker encountered)
                 // The marker should have been detected by readBit and pushed back.
                 echo "<p>-- Decode failed/stopped at MCU $mcuCounter (likely marker/EOF) --</p>";
                 $restartMarkerFound = true; // Assume marker was found
                 break 2; // Break component loop and MCU loop
            }
        }
        unset($compRef); // Break reference

        $mcuCounter++;

        // Check for marker immediately after finishing MCU data bits (optional, robustness)
        // readBit should handle this, but we can peek
        // $nextMarker = $reader->peekMarker();
        // if ($nextMarker !== null && $nextMarker !== 0xFF00) { // Check if it's not stuffed 00
        //     echo "<p>-- Early marker detected after MCU $mcuCounter: 0x" . dechex($nextMarker) . " --</p>";
        //     $restartMarkerFound = true;
        //     break; // Exit MCU loop, let outer loop handle the marker
        // }

    } // End while (mcuCounter < totalMCUs)

    echo "<p>Finished decoding $mcuCounter MCUs.</p>";

    // The reader should now be positioned right after the scan data.
    // The outer loop in read_image_file will read the next marker (e.g., EOI).
} //function decodeScan()


// Clamp value to 0-255
function clampTo8bit($a) {
    // Use max/min for potential float inputs before casting
    return (int) max(0, min(255, round($a)));
}

/**
 * Combines component data (Y, Cb, Cr or C, M, Y, K) into RGB or CMYK output pixels.
 * Handles upsampling based on component scales.
 *
 * @param array $components Array of component data, each with 'lines', 'scaleX', 'scaleY', 'width', 'height'.
 * @param int $targetWidth Final image width (from frame['samplesPerLine']).
 * @param int $targetHeight Final image height (from frame['scanLines']).
 * @param ?array $adobe Adobe marker data (for color transform info).
 * @param array $opts Options (e.g., 'forceRGB' => true).
 * @return array Pixel data array (e.g., [R, G, B, R, G, B, ...]).
 * @throws Exception If unsupported number of components.
 */
function getData(array $components, int $targetWidth, int $targetHeight, ?array $adobe, array $opts = []): array {

    $numComponents = count($components);
    $dataLength = $targetWidth * $targetHeight * $numComponents; // Initial estimate
    $outputData = []; // Use dynamic array

    echo "<p>Combining components into final image ({$targetWidth}x{$targetHeight}). Components: $numComponents</p>";
    if ($numComponents == 0) return [];


    // Determine color transform (primarily for 3/4 components)
    $colorTransform = false; // Default for grayscale/CMYK
    if ($numComponents === 3) {
        $colorTransform = true; // Default for 3 components is YCbCr -> RGB
        if ($adobe && isset($adobe['transformCode']) && $adobe['transformCode'] === 0) { // Adobe 0 = Unknown (assume RGB)
             echo "<p>Adobe marker transform=0 (RGB), overriding default YCbCr->RGB.</p>";
            $colorTransform = false;
        } elseif ($adobe && isset($adobe['transformCode']) && $adobe['transformCode'] === 1) { // Adobe 1 = YCbCr
            $colorTransform = true; // Explicit YCbCr
        }
         // Allow override via options? opts['colorTransform'] = false;
    } elseif ($numComponents === 4) {
        $colorTransform = false; // Default for 4 components is CMYK
        if ($adobe && isset($adobe['transformCode']) && $adobe['transformCode'] === 2) { // Adobe 2 = YCCK
            $colorTransform = true;
             echo "<p>Adobe marker transform=2 (YCCK), overriding default CMYK.</p>";
        }
         // Allow override via options? opts['colorTransform'] = true;
    }

    // Pre-calculate scaling factors if needed (though component data should already match target size if prepare/buildComponentData handled padding correctly)
    // Let's assume component lines directly map for now, scaling was handled during block processing.
    // We might need nearest neighbor or bilinear interpolation if component dimensions don't perfectly match after sampling.

    $comp1 = $components[0] ?? null;
    $comp2 = $components[1] ?? null;
    $comp3 = $components[2] ?? null;
    $comp4 = $components[3] ?? null;

    // Check if component dimensions match target dimensions after scaling
    // This requires the component width/height calculated in buildComponentData or passed back.
    // Let's use targetWidth/Height directly and calculate source coords with scaling factors.

    // Optimization: Pre-fetch lines for the current target Y
    $comp1Lines = $comp1['lines'] ?? null;
    $comp2Lines = $comp2['lines'] ?? null;
    $comp3Lines = $comp3['lines'] ?? null;
    $comp4Lines = $comp4['lines'] ?? null;


    for ($y = 0; $y < $targetHeight; $y++) {
        // Calculate corresponding source Y for each component based on scaling
        // Use floor to get the source line index.
        $y1 = floor($y * $comp1['scaleY']);
        $y2 = $comp2 ? floor($y * $comp2['scaleY']) : 0;
        $y3 = $comp3 ? floor($y * $comp3['scaleY']) : 0;
        $y4 = $comp4 ? floor($y * $comp4['scaleY']) : 0;

        // Get the relevant line arrays (handle boundary conditions)
        $line1 = $comp1Lines[min($y1, count($comp1Lines)-1)] ?? [];
        $line2 = $comp2 ? ($comp2Lines[min($y2, count($comp2Lines)-1)] ?? []) : [];
        $line3 = $comp3 ? ($comp3Lines[min($y3, count($comp3Lines)-1)] ?? []) : [];
        $line4 = $comp4 ? ($comp4Lines[min($y4, count($comp4Lines)-1)] ?? []) : [];


        for ($x = 0; $x < $targetWidth; $x++) {
            // Calculate corresponding source X for each component
            $x1 = floor($x * $comp1['scaleX']);
            $x2 = $comp2 ? floor($x * $comp2['scaleX']) : 0;
            $x3 = $comp3 ? floor($x * $comp3['scaleX']) : 0;
            $x4 = $comp4 ? floor($x * $comp4['scaleX']) : 0;

            // Get pixel values (handle boundary conditions)
            $p1 = $line1[min($x1, count($line1)-1)] ?? 0;
            $p2 = $comp2 ? ($line2[min($x2, count($line2)-1)] ?? 128) : 0; // Default Cb/Cr to 128 if missing
            $p3 = $comp3 ? ($line3[min($x3, count($line3)-1)] ?? 128) : 0;
            $p4 = $comp4 ? ($line4[min($x4, count($line4)-1)] ?? 0) : 0; // Default K to 0 if missing

            // --- Perform Color Conversion ---
            if ($numComponents === 1) { // Grayscale
                $outputData[] = $p1; // R
                $outputData[] = $p1; // G
                $outputData[] = $p1; // B
            } elseif ($numComponents === 3) {
                if ($colorTransform) { // YCbCr -> RGB
                    $Y = $p1;
                    $Cb = $p2;
                    $Cr = $p3;
                    // Standard ITU-R BT.601 conversion formula
                    $R = $Y + 1.402 * ($Cr - 128);
                    $G = $Y - 0.344136 * ($Cb - 128) - 0.714136 * ($Cr - 128);
                    $B = $Y + 1.772 * ($Cb - 128);
                    $outputData[] = clampTo8bit($R);
                    $outputData[] = clampTo8bit($G);
                    $outputData[] = clampTo8bit($B);
                } else { // Assume RGB directly
                    $outputData[] = $p1; // R
                    $outputData[] = $p2; // G
                    $outputData[] = $p3; // B
                }
            } elseif ($numComponents === 4) {
                if ($colorTransform) { // YCCK -> CMYK -> RGB (for display)
                    $Y = $p1;
                    $Cb = $p2;
                    $Cr = $p3;
                    $K = $p4;
                    // YCCK to CMYK is roughly:
                    // C = 255 - (Y + 1.402 * (Cr - 128))
                    // M = 255 - (Y - 0.344136 * (Cb - 128) - 0.714136 * (Cr - 128))
                    // Y_cmyk = 255 - (Y + 1.772 * (Cb - 128))
                    // K_cmyk = K
                    // This intermediate CMYK value is then inverted for typical CMYK definition.
                    // For direct display, convert YCCK -> RGB (ignoring K temporarily?) or YCCK->CMYK->RGB
                    // Let's do YCCK -> RGB (similar to YCbCr->RGB) and maybe use K later if needed.
                    $R = $Y + 1.402 * ($Cr - 128);
                    $G = $Y - 0.344136 * ($Cb - 128) - 0.714136 * ($Cr - 128);
                    $B = $Y + 1.772 * ($Cb - 128);
                    // Apply K (simple multiplicative model - not accurate but common)
                    $R = $R * (1 - $K / 255);
                    $G = $G * (1 - $K / 255);
                    $B = $B * (1 - $K / 255);
                    $outputData[] = clampTo8bit($R);
                    $outputData[] = clampTo8bit($G);
                    $outputData[] = clampTo8bit($B);
                } else { // Assume CMYK directly -> Convert to RGB for display
                    $C = $p1;
                    $M = $p2;
                    $Y_cmyk = $p3;
                    $K = $p4;
                    // Simple conversion: R = (1 - C/255) * (1 - K/255) * 255
                    $R = (1.0 - $C / 255.0) * (1.0 - $K / 255.0) * 255.0;
                    $G = (1.0 - $M / 255.0) * (1.0 - $K / 255.0) * 255.0;
                    $B = (1.0 - $Y_cmyk / 255.0) * (1.0 - $K / 255.0) * 255.0;
                    $outputData[] = clampTo8bit($R);
                    $outputData[] = clampTo8bit($G);
                    $outputData[] = clampTo8bit($B);
                }
            } elseif ($numComponents === 2) {
                // Typically not a standard color space. Output as two grayscale channels?
                // Or maybe specific PDF usage? Let's output R=G=p1, B=p2 for visualization.
                 $outputData[] = $p1;
                 $outputData[] = $p1;
                 $outputData[] = $p2;
            } else {
                throw new Exception("Unsupported number of components for color conversion: $numComponents");
            }

             // Add Alpha channel (always fully opaque)
             $outputData[] = 255; // A

        } // End loop x
    } // End loop y

    // Recalculate expected length based on RGBA output
    $expectedLength = $targetWidth * $targetHeight * 4;
    if (count($outputData) !== $expectedLength) {
         echo "<p><b>Warning:</b> Final image data length mismatch. Expected: $expectedLength, Got: " . count($outputData) . "</p>";
    }

    return $outputData;
}


/**
 * Copies the processed pixel data into a structure suitable for GD image creation.
 * Assumes $data is already in RGBA format from getData().
 *
 * @param array $imageData Structure like ['width'=>W, 'height'=>H, 'data'=>[]] (modified by reference).
 * @param array $data The RGBA pixel data array from getData().
 */
function copyToImageData(&$imageData, $data) {
    $width = $imageData['width'];
    $height = $imageData['height'];
    $expectedLength = $width * $height * 4; // RGBA

    if (count($data) !== $expectedLength) {
        // Pad or truncate if necessary? Or just assign? Assigning is cleaner if lengths match.
        echo "<p>Warning in copyToImageData: Data length mismatch. Expected $expectedLength, got " . count($data) . ". Assigning directly.</p>";
    }

    // Directly assign the data array
    $imageData['data'] = $data;
}


