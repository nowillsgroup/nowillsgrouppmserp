<?php

namespace App\Services;

use App\Models\Room;
use App\Models\RoomImage;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;

/**
 * Room photos: the real pictures of a room, kept on the room record and shown by
 * the reservation desk, the arrivals/departures lists and the room board.
 *
 * The desk already had a way to add a photo to an existing room. This is the same
 * store (room_images, uploads/room-images) behind one place, so a photo added while
 * a room is being created behaves exactly like one added later from the desk:
 *
 *   · the first photo on a room becomes its primary one;
 *   · one primary per room — setting another clears the old one;
 *   · removing a photo hides it (is_active = false) and promotes the next one,
 *     because a folio or an arrival may already point at it;
 *   · files are re-encoded to a sane size instead of being stored as uploaded.
 */
class RoomPhotoService
{
    /** Matches the desk upload limit: 4 MB per photo. */
    public const MAX_KB = 4096;

    /** Photos accepted in one save. */
    public const MAX_PER_SAVE = 8;

    /** Longest edge kept, in pixels. */
    public const MAX_EDGE = 1600;

    /** The card-sized copy: a board of eighty rooms must not fetch eighty 1600px files. */
    public const THUMB_EDGE = 640;

    public const DIRECTORY = 'uploads/room-images';

    /** Validation rules for a room photo upload (single or multiple). */
    public static function rules(string $field = 'photos'): array
    {
        return [
            $field => ['nullable', 'array', 'max:'.self::MAX_PER_SAVE],
            $field.'.*' => ['image', 'mimes:jpg,jpeg,png,webp', 'max:'.self::MAX_KB],
            'photo_caption' => ['nullable', 'string', 'max:160'],
        ];
    }

    public static function validationMessages(string $field = 'photos'): array
    {
        return [
            $field.'.*.image' => 'Each room photo must be an image file.',
            $field.'.*.mimes' => 'Room photos must be JPG, PNG or WebP.',
            $field.'.*.max' => 'Each room photo must be 4 MB or smaller.',
            $field.'.max' => 'Save up to '.self::MAX_PER_SAVE.' photos at a time.',
        ];
    }

    /** Active photos of a room, primary first. */
    public static function photos(Room $room): Collection
    {
        return $room->images()->get();
    }

    /**
     * Store one uploaded photo on the room. Returns the row that was created.
     */
    public static function store(Room $room, UploadedFile $file, ?string $caption = null): RoomImage
    {
        $dir = public_path(self::DIRECTORY);
        if (! is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        $stamp = now()->format('YmdHis').'-'.substr(bin2hex(random_bytes(4)), 0, 8);
        $base = 'room-'.self::slug($room->room_no).'-'.$stamp;

        // Re-encode when GD can read it, otherwise keep the file as uploaded so a
        // host without GD still accepts room photos.
        $relative = self::DIRECTORY.'/'.$base.'.jpg';
        if (! self::reencode($file->getRealPath(), public_path($relative))) {
            $extension = strtolower((string) ($file->getClientOriginalExtension() ?: 'jpg'));
            if (! in_array($extension, ['jpg', 'jpeg', 'png', 'webp'], true)) {
                $extension = 'jpg';
            }
            $relative = self::DIRECTORY.'/'.$base.'.'.$extension;
            $file->move(public_path(self::DIRECTORY), basename($relative));
        }

        // A card-sized copy beside it, best effort: the full picture stays the file
        // of record and everything falls back to it.
        $thumb = RoomImage::thumbPath($relative);
        if ($thumb) {
            self::reencode(public_path($relative), public_path($thumb), self::THUMB_EDGE);
        }

        $existing = RoomImage::where('room_id', $room->id)->where('is_active', true);
        $isPrimary = ! (clone $existing)->exists();
        $sort = (int) (clone $existing)->max('sort_order') + 1;

        return RoomImage::create([
            'room_id' => $room->id,
            // One owner per photo: a room picture is keyed to the room, a type
            // picture to the type (RoomType::images() reads those with room_id null).
            'room_type_id' => null,
            'path' => $relative,
            'caption' => $caption !== null && trim($caption) !== '' ? trim($caption) : null,
            'is_primary' => $isPrimary,
            'sort_order' => $sort,
            'is_active' => true,
        ]);
    }

    /** Make one photo the primary picture of its room (or of its room type). */
    public static function makePrimary(RoomImage $image): void
    {
        $siblings = RoomImage::query();
        if ($image->room_id) {
            $siblings->where('room_id', $image->room_id);
        } else {
            $siblings->whereNull('room_id')->where('room_type_id', $image->room_type_id);
        }
        $siblings->update(['is_primary' => false]);
        $image->update(['is_primary' => true, 'is_active' => true]);
    }

    /**
     * Take a photo off the room record. The row and file stay (a folio, an arrival
     * or a report may already reference it); it is only hidden, and the next photo
     * on the room becomes primary.
     */
    public static function hide(RoomImage $image): void
    {
        $wasPrimary = (bool) $image->is_primary;
        $image->update(['is_active' => false, 'is_primary' => false]);

        if ($wasPrimary) {
            $next = RoomImage::where('room_id', $image->room_id)
                ->where('is_active', true)->orderBy('sort_order')->orderBy('id')->first();
            $next?->update(['is_primary' => true]);
        }
    }

    /** Filename-safe piece of a room number (A-101, 12B, Suite 2 → A101, 12B, Suite2). */
    protected static function slug(?string $roomNo): string
    {
        $clean = preg_replace('/[^A-Za-z0-9]+/', '', (string) $roomNo);

        return $clean !== '' ? substr($clean, 0, 24) : 'x';
    }

    /**
     * Scale the photo down to MAX_EDGE and store it as a JPEG with a white
     * background (PNG transparency would otherwise turn black). Returns false when
     * the file cannot be read here, so the caller can keep the original.
     */
    protected static function reencode(string $source, string $destination, int $edge = self::MAX_EDGE): bool
    {
        if (! function_exists('imagecreatetruecolor')) {
            return false;
        }
        $info = @getimagesize($source);
        if (! $info) {
            return false;
        }
        $image = match ($info[2]) {
            IMAGETYPE_JPEG => @imagecreatefromjpeg($source),
            IMAGETYPE_PNG => @imagecreatefrompng($source),
            IMAGETYPE_WEBP => function_exists('imagecreatefromwebp') ? @imagecreatefromwebp($source) : false,
            default => false,
        };
        if (! $image) {
            return false;
        }

        $width = imagesx($image);
        $height = imagesy($image);
        $scale = min(1, $edge / max(1, max($width, $height)));
        $targetW = max(1, (int) round($width * $scale));
        $targetH = max(1, (int) round($height * $scale));

        $canvas = imagecreatetruecolor($targetW, $targetH);
        $white = imagecolorallocate($canvas, 255, 255, 255);
        imagefilledrectangle($canvas, 0, 0, $targetW, $targetH, $white);
        imagecopyresampled($canvas, $image, 0, 0, 0, 0, $targetW, $targetH, $width, $height);
        $saved = imagejpeg($canvas, $destination, 85);

        imagedestroy($image);
        imagedestroy($canvas);

        return (bool) $saved;
    }
}
