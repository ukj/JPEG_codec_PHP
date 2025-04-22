<?php


include('class.jpeg_encoder.php');

/*
A helper function that creates a JPEGEncoder object and calls its encode method.

$imgData = [
    'data' => [],     // array of pixel values: R, G, B, A, R, G, B, A, ... 
    'width' => 100,   // image width 
    'height' =>100,   // image height 
    'comments' => [], // optional array of comment strings
    'exifBuffer' => '' // optional EXIF data as a string
];

Initialization: The JPEGEncoder initializes various lookup tables (Huffman, quantization, RGB-YUV).

Quality Setting: The desired quality level (1-100) is used to adjust the quantization tables, influencing the compression level and image quality.

Header Creation: The encoder writes the necessary JPEG header segments (APP0, APP1, DQT, SOF0, DHT, SOS) to the output buffer.

Data Unit Processing:

The image is divided into 8x8 blocks (Data Units).

Each block is converted from RGB to YUV color space.

Discrete Cosine Transform (DCT) is applied to each block.

The DCT coefficients are quantized (divided by the quantization table values and rounded).

The quantized coefficients are encoded using Huffman coding.

Output: The encoded data is written to the $this->byteout array.

EOI (End of Image): The EOI marker (0xFFD9) is written.

Return Value: The function returns an array containing the encoded JPEG data, width, and height.
*/
function encode($imgData, $quality = 50)
{
    $encoder = new JPEGEncoder($quality);
    return $encoder->encode($imgData, $quality);
}
            




$inputFile='input.png';
$outputFile='output.jpg';
$gdim = imagecreatefrompng($inputFile);
$gdim_w=imagesx($gdim);
$gdim_h=imagesy($gdim);
$gdim_rgba=[];

//$r = $g = $b = $a = 0;
for($y = 0; $y < $gdim_h; $y++) {
	for($x = 0; $x < $gdim_w; $x++) {		
		$rgba = imagecolorat( $gdim, $x, $y);
		$gdim_rgba[] = ($rgba >> 16) & 0xFF;//$r
		$gdim_rgba[] = ($rgba >> 8) & 0xFF;//$g
		$gdim_rgba[] = $rgba & 0xFF;//$b
		$gdim_rgba[] = ($rgba & 0x7F000000) >> 24;//$a
	}
}

imagedestroy($gdim);

$imgData = [
    'data' => $gdim_rgba,
    'width' => $gdim_w,
    'height' => $gdim_h
    ,'comments' => ['K'], 'exifBuffer' => ''
];
$jpegResult = encode($imgData, 90);
file_put_contents($outputFile, implode(array_map("chr", $jpegResult['data'])));

echo "<html><head><title>PHP JPEG Decoder Test</title></head><body>";


echo "<p>This is an experimental JPEG encoder and decoder ported to PHP from the JavaScript JPEG codec.</p><hr><h2>Comparison:</h2>";
    echo "<h3>Original Input Image:</h3>";
    echo "<img src='" . htmlspecialchars($inputFile) . "' alt='Original JPEG' title='Original JPEG' style='max-width: 400px; border: 1px solid blue;'>";
    
    echo "<h3>Decoded Output Image (PNG):</h3>";
      echo "<img src='" . htmlspecialchars($outputFile) . "?t=" . time() . "' alt='Decoded PNG' title='Decoded PNG' style='max-width: 400px; border: 1px solid green;'>"; 
   echo "</body></html>";
