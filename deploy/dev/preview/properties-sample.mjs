/**
 * Sample data for the v10.0 multi-tenant / multi-property previews.
 *
 * No PHP and no database in the sandbox, so the real
 * resources/views/properties/index.blade.php and resources/views/properties/show.blade.php are
 * rendered against this fixed sample, shaped the way PropertyController::index()/show() hand
 * their variables to the view.
 *
 * The point of the screen: one group account, five properties in five different states, and the
 * things a multi-property operator actually needs to see at a glance — which property is behind on
 * its night audit, which is suspended and why, who may enter what, and what has to happen before a
 * property can be retired.
 *
 * "Today" is 7 November 2026. One live property is still on 6 November, so the register shows a
 * one-day lag: that is a night audit that has not been closed, not a rounding error.
 */
import { Coll, SampleDate } from './blade-render.mjs';

const TODAY = new SampleDate('2026-11-07T06:00:00Z');
const day = (iso) => new SampleDate(`${iso}T00:00:00Z`);

export const HOTEL = { name: 'The Grove Essipong Beach Resort', code: 'GROVE', currency: 'GHS', currency_symbol: 'GH₵', timezone: 'Africa/Accra' };

const GROUP = {
  id: 1, code: 'NWGROUP', name: 'Nowills Group', legal_name: 'Nowills Group Holdings Ltd',
  brand: 'Nowills Collection', management_company: 'Nowills Hospitality Ltd',
  country: 'Ghana', currency: 'GHS', timezone: 'Africa/Accra', default_property_type: 'hotel',
  max_properties: 8, status: 'active', is_active: true, suspended_at: null,
  contact_name: 'Ama Serwaa', contact_email: 'portfolio@nowillsgroup.com', contact_phone: '+233 24 000 0000',
  description: 'Owner-operator of beach resorts, city hotels and a lodge across Ghana.',
  notes: null,
  statusLabel: () => 'Active',
  isSuspended: () => false,
  remainingProperties: () => 3,
};

const property = (o) => ({
  id: o.id, code: o.code, name: o.name, legal_name: o.legal_name ?? null,
  property_type: o.type, brand: o.brand ?? 'Nowills Collection', star_rating: o.stars ?? null,
  address: o.address ?? null, city: o.city, state: o.state ?? null, country: 'Ghana', pincode: o.pincode ?? null,
  phone: o.phone ?? null, email: o.email ?? null, website: o.website ?? null, tax_number: o.tax ?? null,
  currency: 'GHS', currency_symbol: 'GH₵', timezone: 'Africa/Accra',
  checkin_time: '14:00', checkout_time: '11:00', invoice_footer: o.footer ?? null,
  business_date: o.business_date, status: o.status, status_reason: o.reason ?? null,
  is_active: o.status === 'active', suspended_at: o.suspended_at ?? null, archived_at: o.archived_at ?? null,
  opened_on: o.opened_on ?? null, closed_on: o.closed_on ?? null,
  is_head_office: !!o.hq, sort_order: o.sort ?? 0, hotel_group_id: 1, vat_registered: o.vat ?? true, supports_e_invoicing: true,
  group: GROUP,
  isDraft: () => o.status === 'draft',
  isLive: () => o.status === 'active',
  isSuspended: () => o.status === 'suspended',
  isArchived: () => o.status === 'archived',
  isOperable: () => o.status === 'active',
  isHeadOffice: () => !!o.hq,
  statusLabel: () => ({ draft: 'Draft', active: 'Live', suspended: 'Suspended', archived: 'Archived' })[o.status],
  statusTone: () => ({ draft: 'slate', active: 'green', suspended: 'amber', archived: 'red' })[o.status],
  businessDateLag: () => o.lag ?? 0,
});

export const PROPERTIES = [
  property({ id: 1, code: 'GROVE', name: 'The Grove Essipong Beach Resort', type: 'resort', city: 'Takoradi', state: 'Western', stars: 4, hq: true, status: 'active', business_date: day('2026-11-07'), lag: 0, sort: 1, tax: 'C0012345678', phone: '+233 31 200 0001', email: 'grove@nowillsgroup.com', address: 'Essipong Beach Road', footer: 'Thank you for staying with us.' }),
  property({ id: 2, code: 'ACC', name: 'Nowills Airport City Hotel', type: 'hotel', city: 'Accra', state: 'Greater Accra', stars: 4, status: 'active', business_date: day('2026-11-06'), lag: 1, sort: 2, tax: 'C0098765432' }),
  property({ id: 3, code: 'KSI', name: 'Nowills Kumasi City', type: 'hotel', city: 'Kumasi', state: 'Ashanti', stars: 3, status: 'suspended', business_date: day('2026-10-12'), lag: 26, sort: 3, reason: 'Refurbishment — wing B, reopens March 2027', suspended_at: day('2026-10-13') }),
  property({ id: 4, code: 'CCP', name: 'The Grove Cape Coast', type: 'guest_house', city: 'Cape Coast', state: 'Central', stars: 3, status: 'draft', business_date: day('2026-11-07'), lag: 0, sort: 4, opened_on: null }),
  property({ id: 5, code: 'TML', name: 'Nowills Tamale Lodge', type: 'hotel', city: 'Tamale', state: 'Northern', stars: 2, status: 'archived', business_date: day('2026-09-30'), lag: 38, sort: 5, reason: 'Lease ended 30 September 2026 — handed back to the owner', archived_at: day('2026-10-01'), closed_on: day('2026-09-30'), opened_on: day('2019-04-01') }),
];

/** PropertyHealthService::row() per property, summarised the way the register does it. */
const HEALTH = [
  { hotel: PROPERTIES[0], rooms: 96, occupied: 78, occ: 81.3, in_house: 61, open_pos: 3, staff: 214, business_date: day('2026-11-07'), lag: 0, needs_night_audit: false },
  { hotel: PROPERTIES[1], rooms: 120, occupied: 103, occ: 85.8, in_house: 88, open_pos: 5, staff: 260, business_date: day('2026-11-06'), lag: 1, needs_night_audit: true },
  { hotel: PROPERTIES[2], rooms: 74, occupied: 0, occ: 0, in_house: 0, open_pos: 0, staff: 96, business_date: day('2026-10-12'), lag: 26, needs_night_audit: false },
  { hotel: PROPERTIES[3], rooms: 24, occupied: 0, occ: 0, in_house: 0, open_pos: 0, staff: 0, business_date: day('2026-11-07'), lag: 0, needs_night_audit: false },
  { hotel: PROPERTIES[4], rooms: 40, occupied: 0, occ: 0, in_house: 0, open_pos: 0, staff: 12, business_date: day('2026-09-30'), lag: 38, needs_night_audit: false },
];

export const SUMMARY = {
  properties: 5, live: 2, draft: 1, suspended: 1, archived: 1,
  rooms: 354, occupied: 181, occ: 51.1, in_house: 149, staff: 582, behind: 1,
};

const STAFF = (name, username) => ({ name, username, hotel_id: 1 });
const MEMBER = (name, username, role, isDefault, lastUsed) => ({ user: STAFF(name, username), role, is_default: isDefault, last_used: lastUsed });

export function propertiesContext() {
  return {
    helpers: {
      number_format: (value, places = 0) => Number(value ?? 0).toLocaleString('en-US', { minimumFractionDigits: places, maximumFractionDigits: places }),
      money: (value, symbol = true) => `${symbol ? 'GH₵ ' : ''}${Number(value ?? 0).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`,
      // PHP builtins the register prints with: a property type is stored as `guest_house`
      str_replace: (search, replace, subject) => {
        const list = Array.isArray(search) ? search : [search];
        return list.reduce((text, needle, i) => String(text).split(needle).join(Array.isArray(replace) ? replace[i] : replace), String(subject ?? ''));
      },
      ucwords: (value) => String(value ?? '').replace(/\b\w/g, (c) => c.toUpperCase()),
      ucfirst: (value) => { const t = String(value ?? ''); return t.charAt(0).toUpperCase() + t.slice(1); },
      mb_substr: (value, start, length) => String(value ?? '').slice(start, start + length),
      mb_strlen: (value) => String(value ?? '').length,
      collect: (values) => new Coll(Array.isArray(values) ? values : Object.values(values ?? {})),
      route: (name, params) => {
        const part = (v) => (v && typeof v === 'object' ? String(v.id ?? v.code ?? '') : String(v));
        const path = [];
        const query = [];
        const take = (key, value) => {
          if (typeof key === 'number' || /^\d+$/.test(String(key))) path.push(part(value));
          else if (value !== null && value !== undefined && value !== '') query.push(`${key}=${part(value)}`);
        };
        if (params && typeof params === 'object' && !Array.isArray(params) && params.id !== undefined) path.push(params.id);
        else if (Array.isArray(params)) params.forEach((value, index) => take(index, value));
        else if (params && typeof params === 'object') Object.entries(params).forEach(([key, value]) => take(key, value));
        else if (params !== undefined && params !== null) path.push(part(params));
        return `${String(name).replace(/\./g, '-')}${path.length ? '-' + path.join('-') : ''}${query.length ? '?' + query.join('&') : ''}`;
      },
      can_do: (key) => key === 'properties.manage' || key === 'properties.view',
      auth: () => ({ user: () => ({ hasPermission: () => true, name: 'Ama Serwaa' }) }),
      now: () => TODAY,
      old: (key, fallback = '') => fallback,          // Laravel's old() — the preview has no previous request
      hotel: () => PROPERTIES[0],   // the property the operator is standing in (helpers.php `hotel()`)
      number_format_plain: (value) => String(value),
      Math, Number, String, Boolean, Array, Object, Date, JSON, RegExp,
      parseFloat, parseInt, isNaN, isFinite, undefined: undefined, NaN: NaN, Infinity: Infinity,
    },
    fallbacks: {},
    scope: {
      rows: HEALTH,
      summary: SUMMARY,
      statuses: { draft: 'Draft', active: 'Live', suspended: 'Suspended', archived: 'Archived' },
      filters: { q: '', status: '', group: null },
      groups: new Coll([GROUP]),
      canAdd: new Coll([GROUP]),
      currentHotel: PROPERTIES[0],
    },
  };
}

export function propertyShowContext() {
  const grove = PROPERTIES[0];
  return {
    helpers: propertiesContext().helpers,
    fallbacks: {},
    scope: {
      property: grove,
      health: HEALTH[0],
      access: { staff: 214, granted: 2, group_admins: 3, total: 216 },
      members: new Coll([
        MEMBER('Kwesi Boateng', 'kboateng', 'Group Financial Controller', true, new SampleDate('2026-11-04T07:40:00Z')),
        MEMBER('Efua Mensah', 'emensah', 'Rooms Division Manager', false, new SampleDate('2026-10-29T16:05:00Z')),
      ]),
      ownStaff: new Coll([STAFF('Ama Serwaa', 'aserwaa'), STAFF('Yaw Osei', 'yosei')]),
      events: new Coll([
        {
          action: 'suspended', actionLabel: () => 'Suspended', tone: () => 'amber',
          summary: 'Suspended Nowills Kumasi City: Refurbishment — wing B, reopens March 2027',
          created_at: new SampleDate('2026-10-13T09:12:00Z'), user: { name: 'Ama Serwaa' },
        },
        {
          action: 'access_granted', actionLabel: () => 'Access granted', tone: () => 'green',
          summary: 'Granted emensah access to The Grove Essipong Beach Resort as Rooms Division Manager',
          created_at: new SampleDate('2026-10-08T11:30:00Z'), user: { name: 'Ama Serwaa' },
        },
        {
          action: 'updated', actionLabel: () => 'Updated', tone: () => 'slate',
          summary: 'Updated The Grove Essipong Beach Resort: checkin_time, checkout_time',
          created_at: new SampleDate('2026-09-22T15:02:00Z'), user: { name: 'Kwesi Boateng' },
        },
        {
          action: 'activated', actionLabel: () => 'Activated', tone: () => 'green',
          summary: 'The Grove Essipong Beach Resort went live',
          created_at: new SampleDate('2026-08-01T08:00:00Z'), user: { name: 'Ama Serwaa' },
        },
      ]),
      blockers: [],
      candidates: new Coll([
        { id: 7, name: 'Kwesi Boateng', username: 'kboateng', hotel_id: 2 },
        { id: 8, name: 'Adjoa Nyarko', username: 'anyarko', hotel_id: 2 },
        { id: 9, name: 'Ibrahim Fuseini', username: 'ifuseini', hotel_id: null },
      ]),
      roles: new Coll([{ id: 1, name: 'Administrator' }, { id: 2, name: 'Front Office Manager' }, { id: 3, name: 'Accountant' }]),
      canManage: true,
      canTransfer: true,
      transferGroups: new Coll([
        { id: 2, name: 'Cape Coast Hospitality Ltd' },
        { id: 3, name: 'Volta Lake Resorts' },
      ]),
      addressLine: 'Essipong Beach Road, Takoradi, Western, Ghana',
      contactLine: '+233 31 200 0001 · grove@nowillsgroup.com',
      currentHotel: grove,
    },
  };
}

/** HotelGroupController::edit(): the account itself — identity, the defaults it hands down, its cap and its status. */
export function groupAccountContext() {
  return {
    helpers: propertiesContext().helpers,
    fallbacks: {},
    scope: {
      group: GROUP,
      rows: HEALTH,
      summary: SUMMARY,
      admins: new Coll([
        { id: 1, name: 'Ama Serwaa', username: 'aserwaa', role: { name: 'Group Administrator' } },
        { id: 2, name: 'Kwesi Boateng', username: 'kboateng', role: { name: 'Group Financial Controller' } },
        { id: 3, name: 'Efua Mensah', username: 'emensah', role: { name: 'Group Operations Manager' } },
      ]),
      events: new Coll([
        {
          action: 'group_updated', actionLabel: () => 'Group updated', tone: () => 'slate',
          summary: 'Group Nowills Group settings updated: max_properties, default_property_type',
          created_at: new SampleDate('2026-10-20T10:15:00Z'), user: { name: 'Ama Serwaa' }, hotel: null,
        },
        {
          action: 'created', actionLabel: () => 'Created', tone: () => 'green',
          summary: 'Opened property The Grove Cape Coast (CCP) as Draft',
          created_at: new SampleDate('2026-10-04T09:05:00Z'), user: { name: 'Ama Serwaa' }, hotel: PROPERTIES[3],
        },
        {
          action: 'suspended', actionLabel: () => 'Suspended', tone: () => 'amber',
          summary: 'Suspended Nowills Kumasi City: Refurbishment — wing B, reopens March 2027',
          created_at: new SampleDate('2026-10-13T09:12:00Z'), user: { name: 'Ama Serwaa' }, hotel: PROPERTIES[2],
        },
        {
          action: 'archived', actionLabel: () => 'Archived', tone: () => 'amber',
          summary: 'Retired Nowills Tamale Lodge: Lease ended 30 September 2026 — handed back to the owner',
          created_at: new SampleDate('2026-10-01T16:40:00Z'), user: { name: 'Ama Serwaa' }, hotel: PROPERTIES[4],
        },
      ]),
      canSuspendGroup: true,
      types: { hotel: 'Hotel', resort: 'Resort', guest_house: 'Guest house', service_apartment: 'Serviced apartment', restaurant: 'Restaurant', cafe: 'Café' },
      currentHotel: PROPERTIES[0],
    },
  };
}

/** GroupPeopleController::index(): one tenant's people, their home property, role and extra grants. */
export function groupPeopleContext() {
  return {
    helpers: propertiesContext().helpers,
    fallbacks: {},
    scope: {
      group: GROUP,
      people: new Coll([
        { id: 1, name: 'Ama Serwaa', username: 'aserwaa', email: 'ama@nowillsgroup.com', is_active: true, is_group_admin: true, hotel_id: 1, hotel: PROPERTIES[0], role: { name: 'Group Administrator' } },
        { id: 2, name: 'Kwesi Boateng', username: 'kboateng', email: 'kwesi@nowillsgroup.com', is_active: true, is_group_admin: false, hotel_id: 2, hotel: PROPERTIES[1], role: { name: 'Accountant' } },
        { id: 3, name: 'Efua Mensah', username: 'emensah', email: 'efua@nowillsgroup.com', is_active: true, is_group_admin: false, hotel_id: 1, hotel: PROPERTIES[0], role: { name: 'Front Office Manager' } },
        { id: 4, name: 'Yaw Osei', username: 'yosei', email: null, is_active: false, is_group_admin: false, hotel_id: 3, hotel: PROPERTIES[2], role: { name: 'Housekeeping Supervisor' } },
        { id: 5, name: 'Adjoa Nyarko', username: 'anyarko', email: 'adjoa@nowillsgroup.com', is_active: true, is_group_admin: false, hotel_id: 4, hotel: PROPERTIES[3], role: null },
      ]),
      hotels: new Coll(PROPERTIES),
      homeOptions: { 1: 'The Grove Essipong Beach Resort (GROVE)', 2: 'Nowills Airport City Hotel (ACC)', 3: 'Nowills Kumasi City (KSI)', 4: 'The Grove Cape Coast (CCP)' },
      grantsByUser: { 2: ['The Grove Essipong Beach Resort'], 3: ['Nowills Airport City Hotel', 'The Grove Essipong Beach Resort'] },
      rolesByHotel: {
        1: { 1: 'Administrator', 2: 'Front Office Manager', 3: 'Accountant' },
        2: { 4: 'Administrator', 5: 'Accountant' },
        3: { 6: 'Administrator' },
        4: { 7: 'Administrator' },
      },
      search: '',
      canStaff: true,
      canMintAdmins: true,
      groupAdmins: 1,
      currentHotel: PROPERTIES[0],
    },
  };
}
