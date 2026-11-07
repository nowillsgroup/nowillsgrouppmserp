/**
 * Fixed sample for the Rooms master screen (create and edit).
 *
 * The screen itself is the real view — resources/views/masters/form.blade.php
 * plus the gallery include — rendered against this sample. The definition below
 * mirrors the 'rooms' entry of config/masters.php; deploy/dev/audit/room_photos_check.py
 * fails if the two drift apart.
 */
import { Coll, phpEmpty } from './blade-render.mjs';

/** The 'rooms' entry of config/masters.php (fields and columns, in order). */
export const roomsDef = {
  slug: 'rooms',
  title: 'Rooms',
  order: 'room_no',
  with: ['roomType.images', 'floor', 'images'],
  photos: true,
  fields: {
    room_no: ['Room no.', 'text', 'required|string|max:20'],
    room_type_id: ['Room type', 'select', 'required|integer|owned:room_types', 'model:RoomType,name'],
    floor_id: ['Floor', 'select', 'nullable|integer|owned:floors', 'model:Floor,name'],
    extension: ['Phone extension', 'text', 'nullable|string|max:10'],
    is_smoking: ['Smoking room', 'bool', 'boolean'],
    notes: ['Notes', 'text', 'nullable|string|max:255'],
    is_active: ['Active', 'bool', 'boolean'],
  },
  columns: {
    photo: 'Photo',
    room_no: 'Room no.',
    'roomType.name': 'Type',
    'floor.name': 'Floor',
    status: 'Status',
    hk_status: 'Housekeeping',
    is_smoking: 'Smoking',
    is_active: 'Active',
  },
};

const ROOM_TYPES = { 3: 'Deluxe Sea View', 4: 'Standard Garden', 7: 'Executive Suite' };
const FLOORS = { 1: 'Ground floor', 2: 'First floor', 3: 'Second floor' };

/** A room record as the controller hands it to the view. */
export function roomsRow() {
  return {
    id: 204,
    exists: true,
    room_no: '204',
    room_type_id: 3,
    floor_id: 2,
    extension: '204',
    is_smoking: false,
    notes: 'Sea-facing balcony, king bed. Extra bed allowed.',
    is_active: true,
    status: 'inspected',
    hk_status: 'clean',
    room_type: { id: 3, name: 'Deluxe Sea View' },
    floor: { id: 2, name: 'First floor' },
  };
}

/** A room that has not been saved yet — the create screen. */
export function newRoomsRow() {
  return {
    id: null, exists: false, room_no: '', room_type_id: null, floor_id: null,
    extension: '', is_smoking: false, notes: '', is_active: true,
  };
}

/** The photos already on room 204 (room_images rows). */
export function roomPhotos() {
  return Coll.of([
    {
      id: 41, room_id: 204, is_primary: true, is_active: true,
      caption: 'King bed and work desk',
      path: 'uploads/room-images/room-204-20261105091500-4f2a1c.jpg',
      url: () => '/preview-assets/room-204-bed.jpg',
    },
    {
      id: 42, room_id: 204, is_primary: false, is_active: true,
      caption: 'Bathroom with walk-in shower',
      path: 'uploads/room-images/room-204-20261105091502-9b7d3e.jpg',
      url: () => '/preview-assets/room-204-bath.jpg',
    },
    {
      id: 43, room_id: 204, is_primary: false, is_active: true,
      caption: 'Sea-facing balcony',
      path: 'uploads/room-images/room-204-20261105091504-2c8e51.jpg',
      url: () => '/preview-assets/room-204-balcony.jpg',
    },
  ]);
}

/** Laravel's $errors bag, empty: the sample shows a form that has just been opened. */
const errors = {
  has: () => false,
  any: () => false,
  first: () => '',
  all: () => [],
  get: () => [],
};

/** The helpers the view calls. */
const helpers = {
  collect: value => (value instanceof Coll ? value : Coll.of(Array.isArray(value) ? value : Object.values(value ?? {}))),
  empty: phpEmpty,
  str_contains: (haystack, needle) => String(haystack ?? '').includes(String(needle ?? '')),
  old: (name, fallback = null) => fallback,
  route: (name, params) => routePath(name, params),
};

/** The URLs the form posts to, as the controller's routes define them. */
function routePath(name, params) {
  const one = Array.isArray(params) ? params[0] : params;
  const extra = Array.isArray(params) ? params.slice(1) : [];
  switch (name) {
    case 'masters.index': return `/masters/${one}`;
    case 'masters.create': return `/masters/${one}/create`;
    case 'masters.store': return `/masters/${one}`;
    case 'masters.edit': return `/masters/${one}/${extra[0] ?? ''}/edit`;
    case 'masters.update': return `/masters/${one}/${extra[0] ?? ''}`;
    case 'masters.photos.store': return `/masters/${one}/${extra[0] ?? ''}/photos`;
    case 'masters.photos.primary': return `/masters/${one}/${extra[0] ?? ''}/photos/${extra[1] ?? ''}/primary`;
    case 'masters.photos.hide': return `/masters/${one}/${extra[0] ?? ''}/photos/${extra[1] ?? ''}/hide`;
    default: return '#';
  }
}

/** Context for the Blade renderer. */
export function roomsContext() {
  return { helpers, fallbacks: {}, errors };
}

/**
 * Scope for resources/views/masters/form.blade.php.
 * @param {'create'|'edit'} mode
 */
export function roomsScope(mode = 'edit') {
  const scope = {
    def: roomsDef,
    row: mode === 'edit' ? roomsRow() : newRoomsRow(),
    options: { room_type_id: ROOM_TYPES, floor_id: FLOORS },
    photos: mode === 'edit' ? roomPhotos() : Coll.of([]),
    errors,
  };
  return scope;
}
