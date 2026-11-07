/**
 * Sample data for the HR employee-file preview.
 *
 * The screen is resources/views/hr/employee.blade.php, fed by HrController::employee():
 * the employee, the documents on file, the document kinds, the photo URL and this month's
 * attendance counts. The sample mirrors exactly that list of view data and nothing else.
 *
 * The photograph is an inline SVG silhouette rather than a photograph of a person: the
 * frame, the crop and the replace-panel are what the screen has to show, and dressing a
 * stranger up as a member of staff would be a lie in a document people are meant to trust.
 */
import { Coll, SampleDate } from './blade-render.mjs';

const day = iso => new SampleDate(`${iso}T09:00:00Z`);

export const DOC_KINDS = { id: 'ID / passport', certificate: 'Certificate', contract: 'Contract', other: 'Other' };

/** A silhouette in a soft frame — stands in for the photograph HR would load. */
const PHOTO = 'data:image/svg+xml;charset=utf-8,' + encodeURIComponent(
  `<svg xmlns="http://www.w3.org/2000/svg" width="336" height="336" viewBox="0 0 336 336">
    <defs><linearGradient id="g" x1="0" y1="0" x2="1" y2="1">
      <stop offset="0" stop-color="#17553a"/><stop offset="1" stop-color="#2f8a5d"/>
    </linearGradient></defs>
    <rect width="336" height="336" fill="url(#g)"/>
    <circle cx="168" cy="132" r="52" fill="#d8efe1" opacity=".92"/>
    <path d="M60 300c0-58 48-92 108-92s108 34 108 92z" fill="#d8efe1" opacity=".92"/>
  </svg>`,
);

const DOCS = [
  { id: 551, title: 'Signed contract 2026', kind: 'contract', source: 'hr', status: 'verified',
    bytes: 421888, created_at: day('2026-11-02'), review_note: 'Loaded by HR — no review needed.' },
  { id: 548, title: 'Food hygiene certificate', kind: 'certificate', source: 'ess', status: 'new',
    bytes: 655360, created_at: day('2026-11-03'), review_note: null },
  { id: 522, title: 'Bank details letter', kind: 'other', source: 'hr', status: 'verified',
    bytes: 90112, created_at: day('2026-10-30'), review_note: 'Loaded by HR — no review needed.' },
  { id: 507, title: 'Ghana Card — front and back', kind: 'id', source: 'ess', status: 'verified',
    bytes: 1153433, created_at: day('2026-10-21'), review_note: 'Name and number match the file.' },
];

const STATUS_META = { new: ['New', 'blue'], verified: ['Verified', 'green'], rejected: ['Rejected', 'red'] };

const doc = d => ({
  ...d,
  statusLabel: () => STATUS_META[d.status]?.[0] ?? d.status,
  statusTone: () => STATUS_META[d.status]?.[1] ?? 'slate',
  sourceLabel: () => (d.source === 'hr' ? 'HR' : 'Staff'),
  kindLabel: () => DOC_KINDS[d.kind] ?? 'Document',
  sizeLabel: () => (d.bytes >= 1048576
    ? `${(d.bytes / 1048576).toFixed(1).replace(/\.0$/, '')} MB`
    : `${Math.max(1, Math.round(d.bytes / 1024))} KB`),
});

export const EMPLOYEE = {
  id: 142, hotel_id: 1, emp_code: 'EMP-0142', name: 'Kwabena Owusu-Ansah',
  designation: 'Front Office Supervisor', department_id: 4,
  department: { id: 4, name: 'Front Office' },
  joining_date: day('2022-03-14'), leaving_date: null,
  employment_type: 'permanent', phone: '+233 24 551 0932', email: 'k.owusu-ansah@grove.example',
  tax_id: 'C0012345678', social_security_no: 'D1234567890', user_id: 34, is_active: true,
  photo_path: 'employee-photos/1/142-a1b2c3d4.jpg',
  salary: 4850,
  photoUrl: () => PHOTO,
  initials: () => 'KO',
};

export function hrEmployeeContext() {
  const helperSet = {
    dmy: value => (value instanceof SampleDate
      ? value.format('j M Y')
      : String(value ?? '')),
    money: (v, symbol = true) => `${symbol ? 'GH₵ ' : ''}${Number(v ?? 0).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`,
    number_format: (v, places = 0) => Number(v ?? 0).toLocaleString('en-US', { minimumFractionDigits: places, maximumFractionDigits: places }),
    collect: value => (value instanceof Coll ? value : Coll.of(Array.isArray(value) ? value : Object.values(value ?? {}))),
    can_do: () => true,
    bdate: () => day('2026-11-05'),
    now: () => new SampleDate('2026-11-05T09:14:22Z'),
    route: (name, params) => {
      const id = params && typeof params === 'object' && !Array.isArray(params) ? params.id : params;
      const first = Array.isArray(params) ? params[0] : null;
      switch (name) {
        case 'hr.register': return '/hr/register';
        case 'hr.attendance': return '/hr/attendance';
        case 'hr.ess': return '/hr/ess';
        case 'hr.employee.file': return `/hr/employees/${id ?? 0}`;
        case 'hr.employee.photo': return `/hr/employees/${id ?? 0}/photo`;
        case 'hr.employee.photo.save': return `/hr/employees/${id ?? 0}/photo`;
        case 'hr.employee.photo.remove': return `/hr/employees/${id ?? 0}/photo/remove`;
        case 'hr.employee.documents.save': return `/hr/employees/${id ?? 0}/documents`;
        case 'hr.document': return `/hr/documents/${id ?? 0}`;
        case 'hr.document.delete': return `/hr/documents/${id ?? 0}/delete`;
        case 'hr.ess.doc': return `/hr/ess/documents/${id ?? 0}`;
        case 'masters.index': return `/masters/${first ?? 'employees'}`;
        case 'masters.edit': return `/masters/${first ?? 'employees'}/${id ?? 0}/edit`;
        case 'payroll.employee': return `/payroll/employees/${id ?? 0}`;
        default: return '#';
      }
    },
    __static: (cls, method, ...args) => args[0] ?? '',
    __const: () => [],
    Math, Number, String, Boolean, Array, Object, Date, JSON, RegExp,
    parseFloat, parseInt, isNaN, isFinite, undefined: undefined, NaN: NaN, Infinity: Infinity,
  };

  return {
    helpers: helperSet,
    fallbacks: {},
    scope: {
      e: EMPLOYEE,
      docs: Coll.of(DOCS.map(doc)),
      kinds: DOC_KINDS,
      photoUrl: EMPLOYEE.photoUrl(),
      month: day('2026-11-01'),
      monthStatus: { P: 14, A: 1, L: 2, H: 1, WO: 4 },
      title: `Employee file · ${EMPLOYEE.name}`,
    },
  };
}
