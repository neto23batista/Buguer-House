<?php

declare(strict_types=1);

/** Validate and re-encode the photograph before storing it outside the web root. */
function uploadedProductPhoto(array $file): ?string
{
    $error = $file['error'] ?? UPLOAD_ERR_NO_FILE;
    if ($error === UPLOAD_ERR_NO_FILE) {
        return null;
    }
    if ($error !== UPLOAD_ERR_OK || !is_string($file['tmp_name'] ?? null) || !is_uploaded_file($file['tmp_name']) || filesize($file['tmp_name']) > 2097152) {
        throw new DomainException('Envie uma foto de até 2 MB.');
    }
    $info = @getimagesize($file['tmp_name']);
    if (!$info || !in_array($info[2], [IMAGETYPE_JPEG, IMAGETYPE_PNG, IMAGETYPE_WEBP], true)
        || $info[0] * $info[1] > 12000000) {
        throw new DomainException('Escolha uma imagem JPEG, PNG ou WebP de até 12 megapixels.');
    }
    if (!function_exists('imagecreatefromstring')) {
        throw new DomainException('O envio de fotos requer a extensão GD no servidor.');
    }
    $image = @imagecreatefromstring(file_get_contents($file['tmp_name']));
    if (!$image) {
        throw new DomainException('Não foi possível ler essa foto.');
    }
    $scale = min(1, 960 / max($info[0], $info[1]));
    $width = max(1, (int) round($info[0] * $scale));
    $height = max(1, (int) round($info[1] * $scale));
    $photo = imagecreatetruecolor($width, $height);
    imagefill($photo, 0, 0, imagecolorallocate($photo, 255, 255, 255));
    imagecopyresampled($photo, $image, 0, 0, 0, 0, $width, $height, $info[0], $info[1]);
    ob_start();
    $ok = imagejpeg($photo, null, 85);
    $jpeg = ob_get_clean();
    if (!$ok || !$jpeg) {
        throw new DomainException('Não foi possível processar a foto.');
    }
    return $jpeg;
}
