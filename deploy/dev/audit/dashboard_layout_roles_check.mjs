#!/usr/bin/env node
// Isolated preview-renderer checks: these do not replace Laravel permission tests.
import assert from 'node:assert/strict';
import { JSDOM } from 'jsdom';
import { BladePreview } from '../preview/blade-render.mjs';
import { sampleContext } from '../preview/dashboard-sample.mjs';

function render(permissions) {
  const ctx = sampleContext({});
  ctx.helpers.can_do = permission => permissions.includes(permission);
  ctx.scope.managementModules = [];
  ctx.scope.executiveKpis = [];
  ctx.scope.canSeeFinance = false;
  const log = { expressions: 0, fallbacks: 0, failed: [], conditionsDefaulted: [], phpSkipped: [], unknownComponents: [], unknownDirectives: [], errors: [] };
  const renderer = new BladePreview({ root: process.cwd(), ctx, log });
  return new JSDOM(renderer.section('dashboard', 'content', ctx.scope)).window.document;
}
const none = render([]);
assert.equal(none.querySelector('a[href="#dashDepartments"]'), null);
assert.equal(none.querySelector('a[href="#dashSnapshots"]'), null);
assert.equal(none.querySelector('#dashSnapshots'), null);
assert.equal(none.querySelector('.dash-stay-grid'), null);
assert.ok(none.querySelector('#dashOperations'));
const fleet = render(['transport.fleet']);
assert.ok(fleet.querySelector('a[href="#dashSnapshots"]'));
assert.ok(fleet.querySelector('#dashSnapshots #dashFleetCost'));
assert.equal(fleet.querySelector('#dashBudget'), null);
for (const link of fleet.querySelectorAll('.dash-section-nav a')) assert.ok(fleet.querySelector(link.getAttribute('href')));
console.log('dashboard role layout: 8 checks and all section destinations passed (isolated renderer)');
