#!/usr/bin/env node
/** Exercise menu-item form photo checks and inclusive/exclusive price preview without a browser. */
import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';
import vm from 'node:vm';
import {fileURLToPath} from 'node:url';

const __dirname = path.dirname(fileURLToPath(import.meta.url));
const root = path.resolve(__dirname, '../../..');
const view = fs.readFileSync(path.join(root, 'resources/views/masters/menu-item-form.blade.php'), 'utf8');
const script = view.match(/<script>\s*([\s\S]*?)\s*<\/script>/)?.[1];
assert.ok(script, 'Expected the menu-item form client script');

function mount({inclusive = false, price = '100', rate = '20', originalPhoto = ''} = {}) {
  const events = {};
  const classes = new Set();
  const removePhoto = {checked: true};
  const variantHandlers = {};
  const variantRowsList = [];
  const variantRows = {dataset: {nextIndex: '0'}, querySelectorAll: () => variantRowsList, appendChild: row => variantRowsList.push(row), addEventListener: (name, handler) => { variantHandlers[name] = handler; }};
  const variantButton = {disabled: false, addEventListener: (name, handler) => { events[`variant:${name}`] = handler; }, setAttribute() {}, removeAttribute() {}};
  const variantTemplate = {innerHTML: '<div data-variant-row aria-label="Side dish variant __INDEX_LABEL__"><label for="variant-__INDEX__-name">Side dish</label><input id="variant-__INDEX__-name" name="variants[__INDEX__][name]" data-variant-name><input name="variants[__INDEX__][rate]"><input name="variants[__INDEX__][cost]"><button data-remove-variant></button></div>'};
  const variantEmpty = {hidden: false};
  const variantCount = {textContent: '0'};
  const image = {src: originalPhoto, dataset: {originalSrc: originalPhoto}, hidden: !originalPhoto};
  const empty = {hidden: !!originalPhoto};
  const input = {value: '', files: [], addEventListener: (name, handler) => { events[`photo:${name}`] = handler; }};
  const priceInput = {value: price, addEventListener: (name, handler) => { events[`price:${name}`] = handler; }};
  const rateInput = {value: rate, addEventListener: (name, handler) => { events[`rate:${name}`] = handler; }};
  const status = {textContent: '', classList: {add: name => classes.add(name), remove: name => classes.delete(name)}};
  const elements = {
    menuItemVariantRows: variantRows,
    menuItemVariantTemplate: variantTemplate,
    addMenuItemVariant: variantButton,
    menuItemVariantsEmpty: variantEmpty,
    menuItemVariantCount: variantCount,
    f_image: input,
    menuItemPhotoPreview: image,
    menuItemPhotoEmpty: empty,
    menuItemPhotoStatus: status,
    menuItemTaxPreview: {dataset: {inclusive: inclusive ? '1' : '0', currency: 'GH₵'}},
    f_price: priceInput,
    f_tax_rate: rateInput,
    menuItemEnteredPrice: {textContent: ''},
    menuItemTaxAmount: {textContent: ''},
    menuItemGuestPrice: {textContent: ''},
  };
  let objectUrlId = 0;
  const revoked = [];
  const createElement = () => {
    const wrapper = {firstElementChild: null};
    Object.defineProperty(wrapper, 'innerHTML', {set(html) {
      this.firstElementChild = {html, querySelector: () => ({focus() {}}), remove() { const i = variantRowsList.indexOf(this); if (i >= 0) variantRowsList.splice(i, 1); }};
    }});
    return wrapper;
  };
  vm.runInNewContext(script, {
    document: {getElementById: id => elements[id] ?? null, querySelector: () => removePhoto, createElement},
    window: {addEventListener() {}},
    URL: {createObjectURL: () => `blob:menu-photo-${++objectUrlId}`, revokeObjectURL: url => revoked.push(url)},
    Number, Math, console,
  });
  return {events, classes, removePhoto, image, empty, input, status, elements, revoked, variantRowsList, variantHandlers, variantButton};
}

const variants = mount();
variants.events['variant:click']();
assert.equal(variants.variantRowsList.length, 1);
assert.match(variants.variantRowsList[0].html, /variants\[0\]\[name\]/);
assert.match(variants.variantRowsList[0].html, /variants\[0\]\[rate\]/);
assert.match(variants.variantRowsList[0].html, /variants\[0\]\[cost\]/);
assert.equal(variants.elements.menuItemVariantCount.textContent, '1');
assert.equal(variants.elements.menuItemVariantsEmpty.hidden, true);
for (let i = 1; i < 30; i++) variants.events['variant:click']();
assert.equal(variants.variantRowsList.length, 30);
assert.equal(variants.variantButton.disabled, true, 'The form caps variants at 30');
const firstVariant = variants.variantRowsList[0];
const removeButton = {closest: selector => selector === '[data-variant-row]' ? firstVariant : null};
variants.variantHandlers.click({target: {closest: selector => selector === '[data-remove-variant]' ? removeButton : null}});
assert.equal(variants.variantRowsList.length, 29);
assert.equal(variants.variantButton.disabled, false);

const exclusive = mount();
assert.equal(exclusive.elements.menuItemEnteredPrice.textContent, 'GH₵ 100.00');
assert.equal(exclusive.elements.menuItemTaxAmount.textContent, 'GH₵ 20.00');
assert.equal(exclusive.elements.menuItemGuestPrice.textContent, 'GH₵ 120.00');
exclusive.elements.f_price.value = '50';
exclusive.events['price:input']();
assert.equal(exclusive.elements.menuItemGuestPrice.textContent, 'GH₵ 60.00', 'Price edits recalculate the preview');

const inclusive = mount({inclusive: true});
assert.equal(inclusive.elements.menuItemEnteredPrice.textContent, 'GH₵ 100.00');
assert.equal(inclusive.elements.menuItemTaxAmount.textContent, 'GH₵ 16.67');
assert.equal(inclusive.elements.menuItemGuestPrice.textContent, 'GH₵ 100.00');

const photo = mount({originalPhoto: '/uploads/menu/old.jpg'});
photo.input.files = [{name: 'dish.webp', type: 'image/webp', size: 512_000}];
photo.input.value = 'dish.webp';
photo.events['photo:change']();
assert.equal(photo.image.src, 'blob:menu-photo-1');
assert.equal(photo.empty.hidden, true);
assert.equal(photo.removePhoto.checked, false, 'Selecting a replacement cancels the remove-photo flag');

photo.input.files = [{name: 'too-large.jpg', type: 'image/jpeg', size: 2 * 1024 * 1024 + 1}];
photo.input.value = 'too-large.jpg';
photo.events['photo:change']();
assert.equal(photo.input.value, '');
assert.equal(photo.status.textContent, 'The image must be 2 MB or smaller.');
assert.equal(photo.image.src, '/uploads/menu/old.jpg', 'Invalid replacement keeps the saved photo preview');
assert.deepEqual(photo.revoked, ['blob:menu-photo-1']);

photo.input.files = [{name: 'dish.gif', type: 'image/gif', size: 1000}];
photo.input.value = 'dish.gif';
photo.events['photo:change']();
assert.equal(photo.input.value, '');
assert.equal(photo.status.textContent, 'Choose a JPG, PNG or WebP image.');

photo.input.files = [{name: 'empty.png', type: 'image/png', size: 0}];
photo.input.value = 'empty.png';
photo.events['photo:change']();
assert.equal(photo.status.textContent, 'This image file is empty. Choose another file.');

console.log('Menu-item form behavior passed: repeatable side-dish rows and 30-row cap, tax-inclusive/exclusive math, live recalculation, valid photo preview, type/size/empty-file rejection, and preservation of an existing photo.');
