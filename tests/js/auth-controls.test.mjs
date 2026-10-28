import { readFileSync } from 'node:fs';
import assert from 'node:assert/strict';
import { JSDOM } from 'jsdom';

const app = readFileSync('assets/js/app.js', 'utf8');
for (const name of ['login', 'register', 'admin_login', 'reset_password', 'first_admin']) {
  const source = readFileSync(`application/views/auth/${name}.php`, 'utf8');
  const inputs = [...source.matchAll(/<input\b[^>]*id="([^"]+)"[^>]*>/g)];
  const buttons = [...source.matchAll(/<button\b[^>]*data-password-toggle="([^"]+)"[^>]*>[\s\S]*?<\/button>/g)];
  assert.ok(buttons.length, `${name} has password toggles`);
  for (const button of buttons) {
    const input = inputs.find(x => x[1] === button[1]);
    assert.ok(input, `${name}: target ${button[1]}`);
    const dom = new JSDOM(`<form>${input[0]}${button[0]}<button type="submit">Submit</button></form>`, {url:'https://example.test/', runScripts:'outside-only'});
    const w = dom.window;
    w.fetch = async () => ({ok:true, json:async () => ({})});
    w.eval(app);
    w.document.dispatchEvent(new w.Event('DOMContentLoaded'));
    const b = w.document.querySelector('[data-password-toggle]');
    const field = w.document.getElementById(button[1]);
    let submitted = false;
    w.document.querySelector('form').addEventListener('submit', () => { submitted = true; });
    b.click();
    assert.equal(field.type, 'text', `${name}: reveals ${button[1]}`);
    assert.equal(b.getAttribute('aria-pressed'), 'true');
    b.click();
    assert.equal(field.type, 'password', `${name}: hides ${button[1]}`);
    assert.equal(b.getAttribute('aria-pressed'), 'false');
    assert.equal(submitted, false, 'toggle must not submit');
    dom.window.close();
  }
}
const dom = new JSDOM('<form><button type="submit" data-loading-text="Working">Log in</button></form>', {url:'https://example.test/', runScripts:'outside-only'});
const w = dom.window; w.fetch = async () => ({ok:true, json:async () => ({})}); w.eval(app); w.document.dispatchEvent(new w.Event('DOMContentLoaded'));
const form = w.document.querySelector('form');
form.dispatchEvent(new w.Event('submit', {bubbles:true, cancelable:true}));
assert.equal(form.querySelector('button').disabled, true);
const duplicate = new w.Event('submit', {bubbles:true, cancelable:true});
form.dispatchEvent(duplicate); assert.equal(duplicate.defaultPrevented, true);
w.dispatchEvent(new w.PageTransitionEvent('pageshow', {persisted:true}));
assert.equal(form.querySelector('button').disabled, false);
console.log('Auth controls passed: all auth password toggles, duplicate guard and back navigation');
dom.window.close();
