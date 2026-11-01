<?php
declare(strict_types=1);
require dirname(__DIR__).'/bootstrap.php';
function photoCheck(bool $ok): void { if (!$ok) throw new RuntimeException('Shopping photo assertion failed.'); }
$directory = sys_get_temp_dir().'/ctr-photo-'.bin2hex(random_bytes(8));
$photos = new ShoppingPhotoService($directory);
try {
    $image = imagecreatetruecolor(2000, 1000);
    imagefill($image, 0, 0, imagecolorallocate($image, 255, 255, 255));
    ob_start(); imagejpeg($image, null, 90); $jpeg = ob_get_clean(); imagedestroy($image);
    // Minimal EXIF orientation 6: portrait image stored sideways by a camera.
    $exif = "Exif\0\0II".pack('vVv', 42, 8, 1).pack('vvVv', 0x112, 3, 1, 6)."\0\0".pack('V', 0);
    $oriented = substr($jpeg, 0, 2)."\xff\xe1".pack('n', strlen($exif) + 2).$exif.substr($jpeg, 2);
    $uploaded = $photos->upload('1', ['image_base64'=>base64_encode($oriented)]);
    $bytes = base64_decode($photos->read('1', $uploaded['photo'])['image_base64']);
    $info = getimagesizefromstring($bytes);
    photoCheck($info[0] === 800 && $info[1] === 1600 && $info['mime'] === 'image/jpeg');
    photoCheck(!str_contains($bytes, 'Exif'));
    photoCheck($photos->upload('1', ['image_base64'=>base64_encode($oriented)]) === $uploaded);
    photoCheck(!$photos->exists('2', $uploaded['photo']));
    $transparent = imagecreatetruecolor(2, 2);
    imagealphablending($transparent, false); imagesavealpha($transparent, true);
    imagefill($transparent, 0, 0, imagecolorallocatealpha($transparent, 0, 0, 0, 127));
    ob_start(); imagepng($transparent); $png = ob_get_clean(); imagedestroy($transparent);
    $uploaded = $photos->upload('1', ['image_base64'=>base64_encode($png)]);
    $white = imagecreatefromstring(base64_decode($photos->read('1', $uploaded['photo'])['image_base64']));
    photoCheck((imagecolorat($white, 0, 0) & 0xffffff) > 0xf0f0f0); imagedestroy($white);
    $pattern = imagecreatetruecolor(200, 300);
    imagefilledrectangle($pattern, 0, 0, 99, 149, imagecolorallocate($pattern, 255, 0, 0));
    imagefilledrectangle($pattern, 100, 0, 199, 149, imagecolorallocate($pattern, 0, 255, 0));
    imagefilledrectangle($pattern, 0, 150, 99, 299, imagecolorallocate($pattern, 0, 0, 255));
    imagefilledrectangle($pattern, 100, 150, 199, 299, imagecolorallocate($pattern, 255, 255, 0));
    ob_start(); imagejpeg($pattern, null, 95); $patternBytes = ob_get_clean(); imagedestroy($pattern);
    foreach ([5, 7] as $orientation) {
        $exif = "Exif\0\0II".pack('vVv', 42, 8, 1).pack('vvVv', 0x112, 3, 1, $orientation)."\0\0".pack('V', 0);
        $input = substr($patternBytes, 0, 2)."\xff\xe1".pack('n', strlen($exif) + 2).$exif.substr($patternBytes, 2);
        $reference = $photos->upload('1', ['image_base64'=>base64_encode($input)])['photo'];
        $output = imagecreatefromstring(base64_decode($photos->read('1', $reference)['image_base64']));
        photoCheck(imagesx($output) === 300 && imagesy($output) === 200);
        $corner = imagecolorat($output, 20, 20);
        photoCheck((($corner >> 16) & 255) > 220 && ($corner & 255) < 30);
        photoCheck($orientation === 5 ? (($corner >> 8) & 255) < 30 : (($corner >> 8) & 255) > 220);
        imagedestroy($output);
    }
    // Reject a decompression bomb before allocating its declared pixels.
    $large = substr_replace($png, pack('NN', 6000, 5000), 16, 8);
    foreach (['', 'not-an-image', base64_encode($large), base64_encode('<svg/>')] as $invalid) {
        try { $photos->upload('1', ['image_base64'=>$invalid]); throw new RuntimeException('Expected rejection.'); }
        catch (HttpError $error) { photoCheck($error->status === 422); }
    }
    echo "Shopping photo orientation, resize, metadata stripping, transparency and decode limits passed\n";
} finally {
    foreach (glob($directory.'/1/*') ?: [] as $file) unlink($file);
    if (is_dir($directory.'/1')) rmdir($directory.'/1');
    if (is_dir($directory)) rmdir($directory);
}
