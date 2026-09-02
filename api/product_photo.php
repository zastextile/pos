<?php
declare(strict_types=1);

require dirname(__DIR__) . '/app/bootstrap.php';

api_user('admin');

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    json_response(['ok' => false, 'message' => 'Method not allowed.'], 405);
}

$csrf = (string) ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');

if ($csrf === '' || !hash_equals(csrf_token(), $csrf)) {
    json_response(['ok' => false, 'message' => 'Your session expired. Refresh and try again.'], 419);
}

const PHOTO_EDGE = 600;          // every stored photo is exactly this square
const PHOTO_MAX_BYTES = 102400;  // 100 KB ceiling the shop asked for
const UPLOAD_MAX_BYTES = 3145728;

// The browser already crops and shrinks before sending. This is the backstop
// for anything that reaches the endpoint another way.
if ((int) ($_SERVER['CONTENT_LENGTH'] ?? 0) > UPLOAD_MAX_BYTES) {
    json_response(['ok' => false, 'message' => 'That image is too large. Choose a smaller photo.'], 413);
}

if (!isset($_FILES['photo']) || !is_array($_FILES['photo'])) {
    json_response(['ok' => false, 'message' => 'No photo was received.'], 422);
}

$file = $_FILES['photo'];

if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
    json_response(['ok' => false, 'message' => 'The photo did not upload. Please try again.'], 422);
}

if (!is_uploaded_file($file['tmp_name']) || (int) $file['size'] > UPLOAD_MAX_BYTES) {
    json_response(['ok' => false, 'message' => 'That image is too large. Choose a smaller photo.'], 413);
}

$raw = (string) file_get_contents($file['tmp_name']);
$info = @getimagesizefromstring($raw);

// Trusting the sent MIME type would let a script through with an image header,
// so the file is decoded and re-encoded rather than stored as received.
if ($info === false || !in_array($info[2], [IMAGETYPE_JPEG, IMAGETYPE_PNG, IMAGETYPE_WEBP, IMAGETYPE_GIF], true)) {
    json_response(['ok' => false, 'message' => 'Upload a JPG, PNG or WebP image.'], 422);
}

if ($info[0] > 6000 || $info[1] > 6000) {
    json_response(['ok' => false, 'message' => 'That image is too large. Choose a smaller photo.'], 413);
}

$source = @imagecreatefromstring($raw);

if ($source === false) {
    json_response(['ok' => false, 'message' => 'That image could not be read.'], 422);
}

// Centre square crop, so every product tile lines up whatever was uploaded.
$width = imagesx($source);
$height = imagesy($source);
$edge = min($width, $height);
$srcX = (int) (($width - $edge) / 2);
$srcY = (int) (($height - $edge) / 2);

$canvas = imagecreatetruecolor(PHOTO_EDGE, PHOTO_EDGE);
$white = imagecolorallocate($canvas, 255, 255, 255);
imagefilledrectangle($canvas, 0, 0, PHOTO_EDGE, PHOTO_EDGE, $white);
imagecopyresampled($canvas, $source, 0, 0, $srcX, $srcY, PHOTO_EDGE, PHOTO_EDGE, $edge, $edge);
imagedestroy($source);

// Step the quality down until it fits the 100 KB budget.
$encoded = '';

foreach ([82, 74, 66, 58, 50, 42, 34] as $quality) {
    ob_start();
    imagejpeg($canvas, null, $quality);
    $encoded = (string) ob_get_clean();

    if (strlen($encoded) <= PHOTO_MAX_BYTES) {
        break;
    }
}

imagedestroy($canvas);

if ($encoded === '' || strlen($encoded) > PHOTO_MAX_BYTES) {
    json_response(['ok' => false, 'message' => 'This photo could not be compressed under 100 KB. Try a simpler image.'], 422);
}

$directory = APP_ROOT . '/uploads/products';

if (!is_dir($directory) && !mkdir($directory, 0755, true) && !is_dir($directory)) {
    json_response(['ok' => false, 'message' => 'Photo storage is unavailable.'], 500);
}

$name = bin2hex(random_bytes(12)) . '.jpg';

if (file_put_contents($directory . '/' . $name, $encoded) === false) {
    json_response(['ok' => false, 'message' => 'The photo could not be saved.'], 500);
}

@chmod($directory . '/' . $name, 0644);

// Replacing a photo removes the old file rather than orphaning it on disk.
$previous = basename(trim((string) ($_POST['previous'] ?? '')));

if ($previous !== '' && preg_match('/^[a-f0-9]{24}\.jpg$/', $previous) === 1) {
    @unlink($directory . '/' . $previous);
}

json_response([
    'ok' => true,
    'photo' => $name,
    'url' => product_photo_url($name),
    'bytes' => strlen($encoded),
    'message' => 'Photo saved at ' . ceil(strlen($encoded) / 1024) . ' KB.',
], 201);
