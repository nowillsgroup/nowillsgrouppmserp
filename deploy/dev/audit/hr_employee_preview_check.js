#!/usr/bin/env node
/*
 * The employee file is where HR loads a photograph and the papers that go with it, so this
 * check reads it the way HR works: does the photograph land in the right form, does every
 * document open, can a paper the member of staff sent be verified from here, and is the
 * file the thing that gets deleted and not just the row.
 *
 * Four things it is deliberately careful about:
 *
 *   1. a document is only "New" when the member of staff sent it — what HR loads is filed
 *      verified, and no verify button appears next to it;
 *   2. the open link points at the authorised route, never at a storage path;
 *   3. the upload forms post files, so they must carry enctype="multipart/form-data" —
 *      a form without it silently sends the filename and nothing else;
 *   4. a member of staff opening their own paper is checked against their own employee row,
 *      so one person's document id cannot open another person's file.
 *
 * Run: node deploy/dev/audit/hr_employee_preview_check.js
 */
import { readFileSync, existsSync } from 'node:fs';
import { resolve, dirname } from 'node:path';
import { fileURLToPath } from 'node:url';

const ROOT = resolve(dirname(fileURLToPath(import.meta.url)), '../../..');
const PREVIEW = resolve(ROOT, 'deploy/dev/previews/hr-employee.html');
const VIEW = resolve(ROOT, 'resources/views/hr/employee.blade.php');
const CONTROLLER = resolve(ROOT, 'app/Http/Controllers/HrController.php');
const ESS = resolve(ROOT, 'app/Http/Controllers/EssController.php');
const SERVICE = resolve(ROOT, 'app/Services/EmployeeSelfService.php');
const ROUTES = resolve(ROOT, 'routes/web.php');
const MIGRATION = resolve(ROOT, 'database/migrations/2026_11_08_000440_hr_employee_file.php');

let JSDOM;
try {
  ({ JSDOM } = await import('jsdom'));
} catch (_) {
  console.log('hr employee preview check: SKIP (jsdom is not installed)');
  process.exit(0);
}

let passed = 0;
const failures = [];
const ok = (label, condition, detail = '') => {
  if (condition) { passed += 1; return; }
  failures.push(detail ? `${label} — ${detail}` : label);
};

ok('the employee file preview exists', existsSync(PREVIEW));
if (!existsSync(PREVIEW)) {
  console.log(`hr employee preview check: 1 FAILED\n  ✗ ${failures[0]}`);
  process.exit(1);
}

const doc = new JSDOM(readFileSync(PREVIEW, 'utf8')).window.document;
const $$ = sel => [...doc.querySelectorAll(sel)];
const text = (sel, root = doc) => (root.querySelector(sel)?.textContent ?? '').replace(/\s+/g, ' ').trim();
const rows = () => $$('#hrDocs tbody tr');
const rowCells = row => [...row.cells].map(td => td.textContent.replace(/\s+/g, ' ').trim());

/* ── the file as a page ──────────────────────────────────────────────────── */
ok('the tab names the person it belongs to', /Employee file · Kwabena Owusu-Ansah/.test(doc.title), doc.title);
ok('the page is headed as the employee file', /Employee file · Kwabena Owusu-Ansah/.test(text('h1')), text('h1'));
ok('the crumbs lead back through HR and Employees', /HR/.test(text('.crumbs')) && /Employees/.test(text('.crumbs')));
ok('the actions offer the employee record and the pay setup',
  /Edit employee/.test(text('.actions')) && /Pay setup/.test(text('.actions')), text('.actions'));

/* ── the photograph ──────────────────────────────────────────────────────── */
const photo = doc.querySelector('img.hr-photo');
ok('a photograph is shown in its own frame', !!photo, 'no img.hr-photo');
ok('the photograph has a real source, not a storage path', /^(data:image|\.\/|https?:)/.test(photo?.getAttribute('src') ?? ''),
  (photo?.getAttribute('src') ?? '').slice(0, 40));
ok('the photograph is described for a screen reader',
  /Photograph of Kwabena Owusu-Ansah/.test(photo?.getAttribute('alt') ?? ''), photo?.getAttribute('alt'));
ok('a photograph means no initials tile is drawn', !doc.querySelector('.hr-photo-empty'));
ok('the photo form posts a file', (() => {
  const form = doc.querySelector('form.hr-photo-form');
  return !!form && form.method.toLowerCase() === 'post'
    && form.getAttribute('enctype') === 'multipart/form-data'
    && form.getAttribute('action') === '/hr/employees/142/photo';
})(), doc.querySelector('form.hr-photo-form')?.getAttribute('action'));
ok('the photo input only takes images', (() => {
  const input = doc.querySelector('#hrPhoto');
  return input?.getAttribute('type') === 'file' && input?.getAttribute('name') === 'photo'
    && /image\/jpeg/.test(input?.getAttribute('accept') ?? '') && /image\/webp/.test(input?.getAttribute('accept') ?? '')
    && input?.hasAttribute('required');
})(), doc.querySelector('#hrPhoto')?.getAttribute('accept'));
ok('a photograph already on file can be replaced or removed',
  /Replace photo/.test(text('.hr-photo-wrap')) && /Remove/.test(text('.hr-photo-wrap')), text('.hr-photo-wrap'));
ok('removing the photo posts to its own route',
  $$('.hr-photo-wrap form').some(f => f.getAttribute('action') === '/hr/employees/142/photo/remove'));
ok('the size and the types HR may load are stated', /up to 5 MB/.test(text('.hr-photo-wrap')), text('.hr-photo-wrap'));
ok('the empty state survives in the view for a person with no photograph',
  /hr-photo-empty/.test(readFileSync(VIEW, 'utf8')) && /initials\(\)/.test(readFileSync(VIEW, 'utf8')));

/* ── who this is ─────────────────────────────────────────────────────────── */
ok('the identity block carries the employee number and the join date',
  /EMP-0142/.test(text('.hr-facts')) && /14 Mar 2022/.test(text('.hr-facts')), text('.hr-facts'));
ok('it carries the employment type, the TIN and the SSNIT number',
  /Permanent/.test(text('.hr-facts')) && /C0012345678/.test(text('.hr-facts')) && /D1234567890/.test(text('.hr-facts')));
ok('the month-to-date attendance is summarised beside the name',
  /Present 14/.test(text('.hr-month')) && /Absent 1/.test(text('.hr-month')) && /November 2026/.test(text('.hr-month')),
  text('.hr-month'));

/* ── the documents on file ───────────────────────────────────────────────── */
ok('every document on file is listed', rows().length === 4, String(rows().length));
ok('each row names the paper, the kind, where it came from and its status',
  /Signed contract 2026/.test(rowCells(rows()[0])[0])
  && rowCells(rows()[0]).slice(1, 4).join(' · ') === 'Contract · HR · Verified',
  rowCells(rows()[0]).slice(0, 4).join(' · '));
ok('the size is printed in units a person reads', rowCells(rows()[0]).includes('412 KB') && rows().some(r => rowCells(r).includes('1.1 MB')),
  rowCells(rows()[0]).join(' | '));
ok('a paper HR loaded is filed verified with no review buttons',
  rowCells(rows()[0]).includes('HR') && rowCells(rows()[0]).includes('Verified')
  && !rows()[0].textContent.includes('Verify') && rows()[0].querySelectorAll('form').length === 1,
  `${rows()[0].querySelectorAll('form').length} forms on the HR-loaded row`);
const pending = rows().find(r => rowCells(r).includes('New'));
ok('a paper the member of staff sent is waiting, and says so',
  !!pending && rowCells(pending).includes('Staff') && rowCells(pending).includes('New'), rowCells(pending ?? rows()[0]).join(' · '));
ok('and can be verified or rejected from the file itself',
  pending?.querySelectorAll('form').length === 3
  && pending.textContent.includes('Food hygiene certificate'),
  `${pending?.querySelectorAll('form').length} forms on the pending row`);
ok('the verify and reject buttons post the decision', (() => {
  const actions = [...pending.querySelectorAll('form')].map(f => f.getAttribute('action'));
  return actions.filter(a => a === '/hr/ess/documents/548').length === 2;
})(), [...pending.querySelectorAll('form')].map(f => f.getAttribute('action')).join(' '));
ok('every document opens through the authorised route, never a storage path', (() => {
  const links = rows().map(r => r.querySelector('a')?.getAttribute('href'));
  return links.every(href => /^\/hr\/documents\/\d+$/.test(href ?? ''));
})(), rows().map(r => r.querySelector('a')?.getAttribute('href')).join(' '));
ok('the row links carry a description for a screen reader',
  rows().every(r => (r.querySelector('a')?.getAttribute('aria-label') ?? '').startsWith('Open ')),
  rows().map(r => r.querySelector('a')?.getAttribute('aria-label') ?? 'none').join(' | '));
ok('a document is removed from the file, with a warning that the file goes too', (() => {
  const del = [...doc.querySelectorAll('#hrDocs form')].filter(f => /\/delete$/.test(f.getAttribute('action') ?? ''));
  return del.length === 4 && del.every(f => /stored file is deleted/.test(f.getAttribute('onsubmit') ?? ''));
})(), `${[...doc.querySelectorAll('#hrDocs form')].filter(f => /\/delete$/.test(f.getAttribute('action') ?? '')).length} delete forms`);

/* ── loading a document ──────────────────────────────────────────────────── */
ok('the upload panel takes a title, a kind and a file', (() => {
  const title = doc.querySelector('#hrDocTitle');
  const kind = doc.querySelector('#hrDocKind');
  const file = doc.querySelector('#hrDocFile');
  return title?.getAttribute('name') === 'title' && kind?.getAttribute('name') === 'kind'
    && file?.getAttribute('name') === 'file' && file?.hasAttribute('required');
})(), doc.querySelector('#hrDocFile')?.getAttribute('name'));
ok('the kind list is the four the file recognises',
  doc.querySelectorAll('#hrDocKind option').length === 5,
  String(doc.querySelectorAll('#hrDocKind option').length));
ok('the upload form posts a file', (() => {
  const form = doc.querySelector('form.hr-drop');
  return form?.method.toLowerCase() === 'post' && form?.getAttribute('enctype') === 'multipart/form-data'
    && form?.getAttribute('action') === '/hr/employees/142/documents';
})(), doc.querySelector('form.hr-drop')?.getAttribute('action'));
ok('the panel says what happens to a paper HR loads',
  /marked verified/.test(doc.body.textContent) && /self-service desk/.test(doc.body.textContent));

/* ── nothing here is a mock of the screen ────────────────────────────────── */
ok('the page never calls itself a preview', !/preview/i.test(doc.body.textContent));

/* ── the wiring behind the screen ────────────────────────────────────────── */
const controller = readFileSync(CONTROLLER, 'utf8');
ok('the controller loads a file, saves and removes a photograph, and takes a document',
  /public function employee\(Employee \$employee\)/.test(controller)
  && /public function savePhoto\(Request \$request, Employee \$employee\)/.test(controller)
  && /public function removePhoto\(Employee \$employee\)/.test(controller)
  && /public function uploadDocument\(Request \$request, Employee \$employee\)/.test(controller));
ok('documents are served from the private disk, not a public URL',
  /Storage::disk\('local'\)->response\(\$employee->photo_path/.test(controller)
  && /Storage::disk\('local'\)->exists\(\$doc->file_path\)/.test(controller));
const service = readFileSync(SERVICE, 'utf8');
ok('what HR loads is filed verified rather than queued for review',
  /function uploadOnBehalf/.test(service) && /'status' => 'verified'/.test(service));
ok('the photograph is replaced by file, old one deleted',
  /function savePhoto/.test(service) && /employee-photos\//.test(service)
  && /Storage::disk\('local'\)->delete\(\$old\)/.test(service));
ok('deleting a document deletes the stored file first',
  /function deleteDocument/.test(service)
  && /Storage::disk\('local'\)->delete\(\$d->file_path\);\s*\n\s*\}\s*\n\s*\$d->delete\(\);/.test(service));
ok('a photograph may not be a 20 MB scan', /5 \* 1024 \* 1024/.test(service));
ok('documents keep their 10 MB ceiling', /10 \* 1024 \* 1024/.test(service));
const ess = readFileSync(ESS, 'utf8');
ok('a member of staff can only open their own paper',
  /function documentFile\(HrDocument \$doc\)/.test(ess) && /abort_unless\(\(int\) \$doc->employee_id === \(int\) \$e->id, 403\)/.test(ess));
const routes = readFileSync(ROUTES, 'utf8');
ok('the file, the photo and the documents each have a route under the HR permissions',
  routes.includes("->prefix('hr/employees')->name('hr.employee.')")
  && routes.includes("'employee')->whereNumber('employee')->middleware('perm:hr.manage,hr.view,payroll.view')->name('file');")
  && routes.includes("'savePhoto')->whereNumber('employee')->middleware('perm:hr.manage')->name('photo.save');")
  && routes.includes("->name('hr.document');"), 'one of the employee-file routes is missing');
ok('the photograph lives on the employee and the source on the document',
  existsSync(MIGRATION) && /photo_path/.test(readFileSync(MIGRATION, 'utf8')) && /source/.test(readFileSync(MIGRATION, 'utf8')));

if (failures.length) {
  console.log(`hr employee preview check: ${failures.length} FAILED, ${passed} passed`);
  failures.forEach(failure => console.log(`  ✗ ${failure}`));
  process.exit(1);
}
console.log(`hr employee preview check: ${passed} assertions passed`);
