<?php

/**
 * GD-based image edits offered as opt-in checkboxes when uploading a
 * product image: flatten transparency onto a white background, sharpen
 * ("increase quality"), and fit the image onto a 1080x1080 canvas.
 */
class ImageProcessor
{
    public const FRAME_SIZE = 1080;

    /**
     * Loads an image from raw file bytes, returning a GD image resource,
     * or null if the data isn't a supported image.
     */
    public static function load(string $data)
    {
        $image = @imagecreatefromstring($data);
        return $image ?: null;
    }

    /**
     * Flattens any transparency onto a white background.
     */
    public static function addWhiteBackground($image)
    {
        $width = imagesx($image);
        $height = imagesy($image);

        $canvas = imagecreatetruecolor($width, $height);
        $white = imagecolorallocate($canvas, 255, 255, 255);
        imagefill($canvas, 0, 0, $white);

        imagealphablending($canvas, true);
        imagecopy($canvas, $image, 0, 0, 0, 0, $width, $height);
        imagedestroy($image);

        return $canvas;
    }

    /**
     * Sharpens the image to improve perceived quality.
     */
    public static function enhanceQuality($image)
    {
        $sharpen = [
            [0, -1, 0],
            [-1, 5, -1],
            [0, -1, 0],
        ];
        imageconvolution($image, $sharpen, 1, 0);

        return $image;
    }

    /**
     * Resizes (preserving aspect ratio) and centers the image on a square
     * white canvas of FRAME_SIZE x FRAME_SIZE pixels.
     */
    public static function resizeToFrame($image, int $size = self::FRAME_SIZE)
    {
        $srcWidth = imagesx($image);
        $srcHeight = imagesy($image);

        $scale = min($size / $srcWidth, $size / $srcHeight);
        $newWidth = max(1, (int) round($srcWidth * $scale));
        $newHeight = max(1, (int) round($srcHeight * $scale));

        $resized = imagecreatetruecolor($newWidth, $newHeight);
        imagecopyresampled($resized, $image, 0, 0, 0, 0, $newWidth, $newHeight, $srcWidth, $srcHeight);
        imagedestroy($image);

        $canvas = imagecreatetruecolor($size, $size);
        $white = imagecolorallocate($canvas, 255, 255, 255);
        imagefill($canvas, 0, 0, $white);

        $dstX = (int) round(($size - $newWidth) / 2);
        $dstY = (int) round(($size - $newHeight) / 2);
        imagecopy($canvas, $resized, $dstX, $dstY, 0, 0, $newWidth, $newHeight);
        imagedestroy($resized);

        return $canvas;
    }

    /**
     * Encodes the image as JPEG and returns the raw bytes.
     */
    public static function toJpeg($image, int $quality = 90): string
    {
        ob_start();
        imagejpeg($image, null, $quality);
        $data = ob_get_clean();
        imagedestroy($image);

        return $data;
    }
}
