<?php

namespace App\Services;

use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class PhotoStorageService
{
    private const MAX_SIZE_BYTES = 5 * 1024 * 1024; // 5MB
    private const MAX_WIDTH = 1280;
    private const JPEG_QUALITY = 70;

    public function storeFromBase64(string $base64Data, string $type): string
    {
        $imageData = $this->decodeBase64($base64Data);
        $this->validateSize($imageData);
        $imageData = $this->resizeIfNeeded($imageData);

        $now = now();
        $directory = sprintf('punch_photos/%s/%s/%s', $now->format('Y'), $now->format('m'), $now->format('d'));
        $filename = sprintf('%s_%s_%s.jpg', $type, $now->format('His'), Str::random(8));
        $path = $directory . '/' . $filename;

        Storage::disk('local')->put($path, $imageData);

        return $path;
    }

    public function delete(string $path): void
    {
        Storage::disk('local')->delete($path);
    }

    private function decodeBase64(string $base64Data): string
    {
        $data = preg_replace('#^data:image/\w+;base64,#i', '', $base64Data);
        $decoded = base64_decode($data, true);

        if ($decoded === false) {
            throw new \InvalidArgumentException('無効な画像データです。');
        }

        return $decoded;
    }

    private function validateSize(string $imageData): void
    {
        if (strlen($imageData) > self::MAX_SIZE_BYTES) {
            throw new \InvalidArgumentException('画像サイズが5MBを超えています。');
        }
    }

    private function resizeIfNeeded(string $imageData): string
    {
        $image = @imagecreatefromstring($imageData);
        if ($image === false) {
            throw new \InvalidArgumentException('画像の読み込みに失敗しました。');
        }

        $width = imagesx($image);
        $height = imagesy($image);

        if ($width > self::MAX_WIDTH) {
            $ratio = self::MAX_WIDTH / $width;
            $newWidth = self::MAX_WIDTH;
            $newHeight = (int) ($height * $ratio);

            $resized = imagecreatetruecolor($newWidth, $newHeight);
            imagecopyresampled($resized, $image, 0, 0, 0, 0, $newWidth, $newHeight, $width, $height);
            imagedestroy($image);
            $image = $resized;
        }

        ob_start();
        imagejpeg($image, null, self::JPEG_QUALITY);
        $output = ob_get_clean();
        imagedestroy($image);

        return $output;
    }
}
