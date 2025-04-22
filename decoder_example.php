<?php

include('class.jpeg_decoder.php');

$inputFile = "./input.jpg"; // Replace with your input file path
$jpeg_decoder_output_png = './output.png';

echo "<html><head><title>PHP JPEG Decoder Test</title></head><body>";
echo "<p>This is an experimental JPEG encoder and decoder ported to PHP from the JavaScript JPEG codec.</p>
<h1>Decoding: " . htmlspecialchars($inputFile) . "</h1>";
echo "<hr>";
echo "<h2>Decoder Output:</h2>";
echo "<pre style='background-color:#f0f0f0; border:1px solid #ccc; padding: 10px; max-height: 300px; overflow-y: scroll;'>";

try {
    // --- Run the Decoder ---
    list($frame, $jfif, $adobe, $components, $comments) = read_image_file($inputFile);

    echo "</pre>"; // End decoder output pre block
    echo "<hr>";
    echo "<h2>Image Details:</h2>";
    echo "<ul>";
    echo "<li>Dimensions: {$frame['samplesPerLine']} x {$frame['scanLines']}</li>";
    echo "<li>Precision: {$frame['precision']} bits</li>";
    echo "<li>Mode: " . ($frame['baseline'] ? 'Baseline' : ($frame['progressive'] ? 'Progressive' : ($frame['extended'] ? 'Extended Sequential' : 'Unknown'))) . " (SOF Marker: 0x" . dechex($frame['type']) . ")</li>";
    echo "<li>Components: " . count($frame['components']) . "</li>";
    if ($jfif) echo "<li>JFIF Version: {$jfif['version']['major']}.{$jfif['version']['minor']}</li>";
    if ($adobe) echo "<li>Adobe Marker Found: Yes (Transform: {$adobe['transformCode']})</li>";
    if ($comments) echo "<li>Comments: " . count($comments) . "</li>";
    echo "</ul>";


    // --- Prepare for Image Output ---
    $width = $frame['samplesPerLine'];
    $height = $frame['scanLines'];

    echo "<hr><h2>Processing Component Data for Output:</h2><pre>";
    // Combine components into RGBA pixel data using getData
    $pixelDataRGBA = getData($components, $width, $height, $adobe);
    echo "</pre>";


    // Prepare imageData structure (though copyToImageData doesn't strictly need it now)
    $imageData = [
        'width' => $width,
        'height' => $height,
        'data' => [] // Will be filled by copyToImageData (or directly assign $pixelDataRGBA)
    ];
    // Copy (or just assign) the RGBA data
    $imageData['data'] = $pixelDataRGBA;


    // --- Create image with GD library ---
     echo "<hr><h2>Creating PNG with GD:</h2>";
    $image = imagecreatetruecolor($width, $height);
    if (!$image) {
        throw new Exception("GD: imagecreatetruecolor failed.");
    }
    // Enable alpha blending for transparency handling if needed (usually not for JPEG)
    imagealphablending($image, true); // Blend alpha channel
    imagesavealpha($image, true); // Save full alpha channel

    $pixelIndex = 0;
    for ($y = 0; $y < $height; $y++) {
        for ($x = 0; $x < $width; $x++) {
            if (!isset($imageData['data'][$pixelIndex+3])) {
                 // Data array might be shorter than expected if getData had issues
                 echo "<p>Warning: Ran out of pixel data at ($x, $y)</p>";
                 // Set pixel to black or break? Let's set to magenta for visibility
                 $color = imagecolorallocatealpha($image, 255, 0, 255, 0); // Opaque magenta
                 imagesetpixel($image, $x, $y, $color);
                 // Ensure pixelIndex advances correctly even on error if we continue
                 $pixelIndex += 4; // Move past the expected RGBA group
                 continue; // Skip to next pixel
            }
            $r = $imageData['data'][$pixelIndex++];
            $g = $imageData['data'][$pixelIndex++];
            $b = $imageData['data'][$pixelIndex++];
            $a_byte = $imageData['data'][$pixelIndex++]; // Alpha 0-255

            // GD alpha is 0 (opaque) to 127 (transparent)
            $a_gd = floor((127 * (255 - $a_byte)) / 255); // Convert 0-255 alpha to 0-127 GD alpha
            $a_gd = max(0, min(127, $a_gd)); // Clamp just in case

            $color = imagecolorallocatealpha($image, $r, $g, $b, $a_gd);
            if ($color === false) {
                 echo "<p>Warning: GD: imagecolorallocatealpha failed for pixel ($x, $y) with RGBA($r, $g, $b, $a_byte). Setting black.</p>";
                 $color = imagecolorallocatealpha($image, 0, 0, 0, 0); // Fallback: opaque black
            }
            imagesetpixel($image, $x, $y, $color);
        }
    }
    echo "<p>GD Image object created.</p>";


    // --- Save and Display ---
    echo "<p>Saving PNG to: $jpeg_decoder_output_png</p>";
    $saveResult = imagepng($image, $jpeg_decoder_output_png);
    if (!$saveResult) {
         echo "<p><b>Error: Failed to save PNG image! Check permissions and GD installation.</b></p>";
    } else {
        echo "<p>PNG saved successfully.</p>";
    }
    imagedestroy($image);

    echo "<hr><h2>Comparison:</h2>";
    echo "<h3>Original Input Image:</h3>";
    echo "<img src='" . htmlspecialchars($inputFile) . "' alt='Original JPEG' title='Original JPEG' style='max-width: 400px; border: 1px solid blue;'>";
    echo "<h3>Decoded Output Image (PNG):</h3>";
    if ($saveResult) {
      echo "<img src='" . htmlspecialchars($jpeg_decoder_output_png) . "?t=" . time() . "' alt='Decoded PNG' title='Decoded PNG' style='max-width: 400px; border: 1px solid green;'>"; // Add timestamp to avoid caching
    } else {
      echo "<p>(Failed to save decoded image)</p>";
    }


} catch (Exception $e) {
    echo '</pre><hr><h2 style="color: red;">Caught exception:</h2><pre>';
    echo 'Message: ',  htmlspecialchars($e->getMessage()), "\n";
    echo 'File: ', $e->getFile(), "\n";
    echo 'Line: ', $e->getLine(), "\n";
    echo "\nStack trace:\n", htmlspecialchars($e->getTraceAsString());
    echo "</pre>";
}

echo "</body></html>";
