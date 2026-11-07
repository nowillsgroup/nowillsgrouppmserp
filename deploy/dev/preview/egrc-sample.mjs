/**
 * Fixed sample for the e-GRC signature screen (frontdesk/grc-sign).
 *
 * It sits on the same sample as the printed card — deploy/dev/preview/grc-sample.mjs —
 * because the two are the same registration record seen from the desk and from the
 * tablet. What is added here is only what this screen needs on top: the route table,
 * the permission answers, the signature state the controller passes down, and the
 * guest's document list for the upload card.
 *
 * egrcContext({ dayUse: true }) shows the screen for a day use, which is the branch that
 * must not tell a guest they signed for a night.
 */
import { Coll } from './blade-render.mjs';
import { grcContext, HOTEL } from './grc-sample.mjs';

const DOCUMENT_TYPES = {
  passport: 'Passport',
  ghana_card: 'Ghana Card',
  driving_licence: 'Driving licence',
  voter_id: 'Voter ID',
  national_id: 'National ID',
  other: 'Other document',
};

export { HOTEL };

export function egrcContext({ dayUse = false, signed = true } = {}) {
  const base = grcContext({ dayUse, signed });
  const guest = base.guest;

  const helpers = {
    ...base.helpers,
    can_do: () => true,
    route: () => '#',
    filled: (value) => value !== null && value !== undefined && value !== '',
    array_filter: (items) => (Array.isArray(items) ? items.filter(Boolean) : {}),
    str_starts_with: (haystack, needle) => String(haystack ?? '').startsWith(String(needle ?? '')),
    str_contains: (haystack, needle) => String(haystack ?? '').includes(String(needle ?? '')),
    // \App\Models\GuestDocument::TYPES is a model constant; the renderer turns a
    // constant read into __const(class, NAME) and a static call into __static(...), so
    // the map the model carries is handed over here and nowhere else.
    __const: (cls, name) => (/GuestDocument/.test(String(cls)) && name === 'TYPES' ? DOCUMENT_TYPES : []),
    __static: (cls, method) => {
      if (/GuestDocument/.test(String(cls)) && method === 'TYPES') return DOCUMENT_TYPES;

      return undefined;
    },
  };

  const scope = {
    ...base.scope,
    // the controller's two answers, not a storage call inside the template
    signatureAvailable: base.signed,
    canViewSignature: true,
    documents: guest.documents ?? new Coll([]),
    GuestDocument: { TYPES: DOCUMENT_TYPES },
  };

  return { ...base, scope, helpers, fallbacks: {} };
}
