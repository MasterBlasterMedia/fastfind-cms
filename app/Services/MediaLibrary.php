<?php

namespace App\Services;

use App\Models\AuditEvent;
use App\Models\MediaAsset;
use App\Models\Page;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class MediaLibrary
{
    public function upload(UploadedFile $file, string $alt, int $userId): MediaAsset
    {
        Gate::forUser(User::findOrFail($userId))->authorize('edit-content');
        if (!function_exists('imagecreatefromstring')) $this->fail('Image processing is unavailable. Enable the PHP GD extension.');
        $dimensions = @getimagesize($file->getRealPath());
        if (!$dimensions || !in_array($dimensions['mime'], ['image/jpeg', 'image/png', 'image/gif', 'image/webp'], true)
            || $dimensions[0] > 6000 || $dimensions[1] > 6000 || $dimensions[0] * $dimensions[1] > 12000000) {
            $this->fail('Upload a valid raster image of at most 12 megapixels and 6,000 pixels per side.');
        }
        $image = @imagecreatefromstring(file_get_contents($file->getRealPath()));
        if (!$image) $this->fail('The image is damaged and could not be decoded.');
        $paths = [];
        try {
            if ($dimensions['mime'] === 'image/jpeg' && function_exists('exif_read_data')) {
                $exif = @exif_read_data($file->getRealPath());
                $orientation = (int) ($exif['Orientation'] ?? 1);
                if (in_array($orientation, [2, 4, 5, 7], true)) imageflip($image, IMG_FLIP_HORIZONTAL);
                $angle = match ($orientation) { 3, 4 => 180, 5, 6 => -90, 7, 8 => 90, default => 0 };
                if ($angle) $image = imagerotate($image, $angle, 0);
            }
            $image = $this->resize($image, 2400);
            $thumbnail = $this->resize($image, 320);
            $extension = function_exists('imagewebp') ? 'webp' : 'png';
            $path = 'images/'.now()->format('Y/m').'/'.Str::uuid().'.'.$extension;
            $thumbnailPath = preg_replace('/\.'.$extension.'$/', '-thumb.'.$extension, $path);
            $content = $this->encode($image, $extension);
            $thumbContent = $this->encode($thumbnail, $extension);
            foreach ([$path => $content, $thumbnailPath => $thumbContent] as $key => $bytes) {
                if (!Storage::disk('public')->put($key, $bytes)) $this->fail('The image could not be saved. Check storage permissions.');
                $paths[] = $key;
            }
            return DB::transaction(function () use ($file, $path, $thumbnailPath, $extension, $content, $image, $alt, $userId) {
                $asset = MediaAsset::create([
                    'filename' => Str::limit($file->getClientOriginalName(), 240, ''), 'path' => $path, 'thumbnail_path' => $thumbnailPath,
                    'mime' => 'image/'.$extension, 'size' => strlen($content), 'width' => imagesx($image), 'height' => imagesy($image),
                    'alt' => $alt, 'uploaded_by' => $userId,
                ]);
                AuditEvent::create(['user_id' => $userId, 'action' => 'media.uploaded', 'subject' => 'media', 'subject_id' => $asset->id]);
                return $asset;
            });
        } catch (\Throwable $error) {
            Storage::disk('public')->delete($paths);
            throw $error;
        }
    }

    public function delete(MediaAsset $asset, int $userId): void
    {
        Gate::forUser(User::findOrFail($userId))->authorize('manage-site');
        DB::transaction(function () use ($asset, $userId) {
            $locked = MediaAsset::whereKey($asset->id)->lockForUpdate()->firstOrFail();
            foreach (Page::select(['id', 'document', 'published_snapshot', 'extensions'])->lazyById(100) as $page) {
                $content = json_encode([$page->document, $page->published_snapshot, $page->extensions], JSON_UNESCAPED_SLASHES);
                if (str_contains($content, $locked->path) || ($locked->thumbnail_path && str_contains($content, $locked->thumbnail_path))) {
                    throw ValidationException::withMessages(['media' => 'This image is referenced by draft or published page '.$page->id.'. Remove those references first.']);
                }
            }
            $paths = array_values(array_filter([$locked->path, $locked->thumbnail_path]));
            if (!Storage::disk('public')->delete($paths)) $this->fail('The image could not be deleted from storage.');
            $locked->delete();
            AuditEvent::create(['user_id' => $userId, 'action' => 'media.deleted', 'subject' => 'media', 'subject_id' => $locked->id]);
        });
    }

    private function resize(\GdImage $image, int $max): \GdImage
    {
        $scale = min(1, $max / max(imagesx($image), imagesy($image)));
        $width = max(1, (int) round(imagesx($image) * $scale));
        $height = max(1, (int) round(imagesy($image) * $scale));
        $resized = imagecreatetruecolor($width, $height);
        imagealphablending($resized, false);
        imagesavealpha($resized, true);
        imagecopyresampled($resized, $image, 0, 0, 0, 0, $width, $height, imagesx($image), imagesy($image));
        return $resized;
    }

    private function encode(\GdImage $image, string $extension): string
    {
        ob_start();
        try {
            $success = $extension === 'webp' ? imagewebp($image, null, 86) : imagepng($image);
            $bytes = ob_get_contents();
            if (!$success || $bytes === '') $this->fail('The image could not be encoded.');
            return $bytes;
        } finally {
            ob_end_clean();
        }
    }

    private function fail(string $message): never
    {
        throw ValidationException::withMessages(['file' => $message]);
    }
}
