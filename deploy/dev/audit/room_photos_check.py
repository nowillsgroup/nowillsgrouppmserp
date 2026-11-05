#!/usr/bin/env python3
"""Room photos: the picture a room carries from the moment it is created.

Creating a room used to be text only — the room number, its type and a floor. A
photo could only be added afterwards, from the reservation desk, which is not where
a property sets a room up. This guard keeps the pieces of that feature wired
together, because none of them is useful on its own:

  · the rooms master declares that its records keep photos, and the form posts files;
  · the controller saves them inside the same save as the room, on create and edit;
  · the photo store keeps one primary per room and hides rather than deletes;
  · the desk, the room list and the register report all read the same rows;
  · the whole thing hangs off the existing room_images table, no new storage.

Run with: python3 deploy/dev/audit/room_photos_check.py
"""
from pathlib import Path
import re
import unittest

ROOT = Path(__file__).resolve().parents[3]


def read(path):
    return (ROOT / path).read_text()


CONFIG = read("config/masters.php")
ROUTES = read("routes/web.php")
CONTROLLER = read("app/Http/Controllers/MasterController.php")
SERVICE = read("app/Services/RoomPhotoService.php")
MODEL = read("app/Models/RoomImage.php")
ROOM = read("app/Models/Room.php")
FORM = read("resources/views/masters/form.blade.php")
GALLERY = read("resources/views/masters/room-photos.blade.php")
INDEX = read("resources/views/masters/index.blade.php")
DESK = read("app/Http/Controllers/ReservationDeskController.php")
REPORT = read("app/Http/Controllers/ReportController.php")
CSS = read("public/css/app.css")
SAMPLE = read("deploy/dev/preview/rooms-sample.mjs")

ROOMS_DEF = CONFIG[CONFIG.index("'rooms' =>"):CONFIG.index("'rate-plans' =>")]


def method(source, signature):
    """Body of a PHP method, from its signature to the next top-level closing brace."""
    start = source.index(signature)
    depth = 0
    for offset, char in enumerate(source[start:], start):
        if char == "{":
            depth += 1
        elif char == "}":
            depth -= 1
            if depth == 0:
                return source[start:offset + 1]
    raise AssertionError(f"unbalanced body for {signature}")


class RoomPhotoCreationTest(unittest.TestCase):
    # ── the room form ────────────────────────────────────────────────────────

    def test_the_rooms_master_is_photo_enabled(self):
        self.assertIn("'photos' => true", ROOMS_DEF)
        self.assertIn("'images'", ROOMS_DEF, "the list must eager-load the photos it shows")
        self.assertIn("'photo' => 'Photo'", ROOMS_DEF, "the room list needs a photo column")

    def test_the_form_uploads_files_and_switches_to_multipart(self):
        self.assertIn("$hasPhotos = ! empty($def['photos'])", FORM)
        self.assertIn("$hasUpload = $hasFile || $hasPhotos", FORM)
        self.assertIn('enctype="multipart/form-data"', FORM)
        self.assertIn('name="photos[]"', FORM)
        self.assertIn('multiple', FORM)
        self.assertIn('accept="image/jpeg,image/png,image/webp"', FORM)
        self.assertIn("4 MB each", FORM)
        self.assertIn("@error('photos')", FORM)
        self.assertIn("@error('photos.*')", FORM)

    def test_the_photo_gallery_only_appears_on_a_saved_room(self):
        self.assertIn("@if($hasPhotos && $row->exists)", FORM)
        self.assertIn("@include('masters.room-photos'", FORM)
        self.assertIn("$photos ?? collect()", FORM, "a brand-new room has no photos yet")

    def test_the_gallery_can_promote_and_remove(self):
        self.assertIn("route('masters.photos.primary'", GALLERY)
        self.assertIn("route('masters.photos.hide'", GALLERY)
        self.assertIn("route('masters.photos.store'", GALLERY)
        # the tile loads the card-sized copy; the click opens the photo itself
        self.assertIn("$photo->url('thumb')", GALLERY)
        self.assertIn('<a class="room-photo-full" href="{{ $photo->url() }}"', GALLERY)
        self.assertIn("enctype=\"multipart/form-data\"", GALLERY)
        self.assertIn("No photo on file yet", GALLERY)

    # ── controller wiring ───────────────────────────────────────────────────

    def test_photos_save_with_the_room_on_create_and_edit(self):
        store = method(CONTROLLER, "public function store(Request $request, string $master)")
        update = method(CONTROLLER, "public function update(Request $request, string $master, int $id)")
        for body in (store, update):
            # validated before the row is written, saved after it exists
            self.assertIn("$this->validateRoomPhotos($request, $def)", body)
            self.assertIn("$this->storeRoomPhotos($request, $def, $row)", body)
            self.assertLess(body.index("validateRoomPhotos"), body.index("$def['model']::create")
                            if body is store else body.index("DB::transaction"))
        self.assertIn("$row->images()->get()", method(CONTROLLER, "public function edit(string $master, int $id)"))

    def test_a_bad_photo_cannot_leave_a_half_saved_room(self):
        validate = method(CONTROLLER, "protected function validateRoomPhotos(Request $request, array $def)")
        self.assertIn("hasFile('photos')", validate)
        self.assertIn("RoomPhotoService::rules()", validate)
        # create() must call it before the insert
        store = method(CONTROLLER, "public function store(Request $request, string $master)")
        self.assertLess(store.index("validateRoomPhotos"), store.index("DB::transaction"))

    def test_photo_actions_are_scoped_to_the_record(self):
        for signature in [
            "public function storePhoto(Request $request, string $master, int $id)",
            "public function photoPrimary(string $master, int $id, int $image)",
            "public function photoHide(string $master, int $id, int $image)",
        ]:
            body = method(CONTROLLER, signature)
            self.assertIn("$this->def($master)", body, f"{signature} must respect the master's permission")
            self.assertIn("abort_unless($this->photoMaster($def), 404)", body)
        self.assertIn("$row->images()->findOrFail($image)", CONTROLLER,
                      "a photo must belong to the room in the URL")

    def test_the_routes_exist_under_masters(self):
        for route in [
            "Route::post('/{id}/photos', 'storePhoto')",
            "Route::post('/{id}/photos/{image}/primary', 'photoPrimary')",
            "Route::post('/{id}/photos/{image}/hide', 'photoHide')",
        ]:
            self.assertIn(route, ROUTES)
        self.assertIn("->name('photos.store')", ROUTES)
        self.assertIn("->name('photos.primary')", ROUTES)
        self.assertIn("->name('photos.hide')", ROUTES)

    # ── the photo store ─────────────────────────────────────────────────────

    def test_the_store_limits_are_the_documented_ones(self):
        self.assertIn("public const MAX_KB = 4096;", SERVICE)
        self.assertIn("public const MAX_PER_SAVE = 8;", SERVICE)
        self.assertIn("public const MAX_EDGE = 1600;", SERVICE)
        self.assertIn("public const DIRECTORY = 'uploads/room-images';", SERVICE)
        self.assertIn("'image', 'mimes:jpg,jpeg,png,webp'", SERVICE)

    def test_the_first_photo_becomes_primary_and_only_one_is_primary(self):
        store = method(SERVICE, "public static function store(Room $room, UploadedFile $file, ?string $caption = null): RoomImage")
        self.assertIn("$isPrimary = ! (clone $existing)->exists();", store)
        self.assertIn("'is_primary' => $isPrimary", store)
        self.assertIn("$sort = (int) (clone $existing)->max('sort_order') + 1;", store)
        primary = method(SERVICE, "public static function makePrimary(RoomImage $image): void")
        self.assertIn("update(['is_primary' => false])", primary)
        self.assertIn("update(['is_primary' => true, 'is_active' => true])", primary)

    def test_removing_a_photo_hides_it_and_promotes_the_next(self):
        hide = method(SERVICE, "public static function hide(RoomImage $image): void")
        self.assertIn("'is_active' => false, 'is_primary' => false", hide)
        self.assertIn("$next?->update(['is_primary' => true]);", hide)
        self.assertNotIn("->delete()", hide, "photos are hidden, never deleted")

    def test_files_are_re_encoded_with_a_fallback_when_gd_is_missing(self):
        self.assertIn("if (! function_exists('imagecreatetruecolor'))", SERVICE)
        self.assertIn("$file->move(public_path(self::DIRECTORY), basename($relative));", SERVICE)
        self.assertIn("imagejpeg($canvas, $destination, 85)", SERVICE)

    def test_a_photo_has_exactly_one_owner(self):
        store = method(SERVICE, "public static function store(Room $room, UploadedFile $file, ?string $caption = null): RoomImage")
        self.assertIn("'room_id' => $room->id", store)
        self.assertIn("'room_type_id' => null", store,
                      "a room photo is keyed to the room; type photos are the ones keyed to a type")

    # ── what the rest of the screen reads ───────────────────────────────────

    def test_the_list_and_the_desk_show_the_primary_photo(self):
        self.assertIn("$path === 'photo'", INDEX)
        self.assertIn("\\App\\Services\\ReservationDeskService::photoPath($row)", INDEX)
        self.assertIn("class=\"menu-thumb\"", INDEX)
        # url() also serves the card-sized copy, and falls back to the photo itself
        self.assertIn("public function url(?string $variant = null): string", MODEL)
        self.assertIn("if ($thumb && is_file(public_path($thumb)))", MODEL)
        self.assertIn("public function filename(): string", MODEL)
        self.assertIn("$this->hasMany(RoomImage::class)->where('is_active', true)->orderByDesc('is_primary')", ROOM)

    def test_a_card_sized_copy_is_written_beside_the_photo(self):
        self.assertIn('public const THUMB_EDGE = 640;', SERVICE)
        self.assertIn("self::reencode(public_path($relative), public_path($thumb), self::THUMB_EDGE)", SERVICE)
        self.assertIn("int $edge = self::MAX_EDGE): bool", SERVICE)

    def test_the_desk_uploads_through_the_same_store(self):
        store_image = method(DESK, "public function storeImage(Request $request)")
        self.assertIn("\\App\\Services\\RoomPhotoService::store($room", store_image)
        self.assertIn("whereNull('room_id')", store_image, "type-level photos stay keyed to the type")

    def test_the_desk_hides_and_promotes_like_the_master(self):
        destroy = method(DESK, "public function destroyImage(RoomImage $image)")
        self.assertIn("RoomPhotoService::hide($image)", destroy)
        make_primary = method(DESK, "public function makePrimary(RoomImage $image)")
        self.assertIn("RoomPhotoService::makePrimary($image)", make_primary)

    def test_the_room_image_register_reads_real_columns(self):
        report = method(REPORT, "protected function r_room_images($from, $to): array")
        for phantom in ["uploaded_at", "'filename' => $r->filename", "$r->status", "$r->user_id"]:
            self.assertNotIn(phantom, report, f"room_images has no {phantom}")
        self.assertIn("whereBetween('created_at'", report)
        self.assertIn("basename((string) $r->path)", report)

    def test_the_preview_renders_this_screen_from_the_real_view(self):
        self.assertIn("resources/views/masters/form.blade.php", read("deploy/dev/build-rooms-preview.mjs"))
        self.assertIn("renderer.section('masters/form', 'content'", read("deploy/dev/build-rooms-preview.mjs"))
        self.assertIn("rooms-edit.html", read("deploy/dev/management-dashboard-preview-server.mjs"))

    def test_the_preview_sample_matches_the_rooms_master(self):
        fields = ROOMS_DEF.split("'fields' =>")[1].split("'columns' =>")[0]
        wanted = set(re.findall(r"'([a-z_][a-z0-9_]*)' => (?:\[|\$)", fields))
        fields_block = SAMPLE.split("fields: {")[1].split("},")[0]
        have = set(re.findall(r"^\s{4}([a-z_]+): \[", fields_block, re.M))
        self.assertEqual(wanted, have, "the rooms preview sample has drifted from config/masters.php")

    def test_the_gallery_has_styles(self):
        self.assertIn(".room-photo-grid", CSS)
        self.assertIn(".room-photo img", CSS)
        self.assertIn(".room-photo-actions", CSS)


if __name__ == "__main__":
    unittest.main(verbosity=2)
