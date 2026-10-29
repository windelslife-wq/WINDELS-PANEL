/** Real Chromium + real PHP application; use ONLY against a disposable empty DB.
 * BASE_URL, VP_SETUP_TOKEN and CHROMIUM_PATH are required. See devdb/README.md. */
import { chromium } from 'playwright-core';
import { randomBytes } from 'node:crypto';
import assert from 'node:assert/strict';
const base = process.env.BASE_URL || 'http://127.0.0.1:8081';
const secret = process.env.VP_SETUP_TOKEN;
if (!secret || !process.env.CHROMIUM_PATH) throw new Error('Set VP_SETUP_TOKEN and CHROMIUM_PATH; use a disposable database.');
const suffix = randomBytes(4).toString('hex');
const password = `Test-${randomBytes(16).toString('hex')}!`;
const owner = `owner${suffix}`, customer = `user${suffix}`, mobileUser = `mobile${suffix}`;
const browser = await chromium.launch({executablePath:process.env.CHROMIUM_PATH, headless:true, args:['--no-sandbox', '--no-zygote', '--single-process']});
let page = await browser.newPage({viewport:{width:1280,height:800}});
const desktopPage = page;
let mobile = false;
const errors = [];
function monitor(p) {
  p.on('pageerror', e => errors.push(e.message));
  p.on('requestfailed', r => { if (r.url().startsWith(base)) errors.push(`request failed ${r.method()} ${new URL(r.url()).pathname}: ${r.failure()?.errorText}`); });
  p.on('response', r => { if (r.request().method() === 'POST' && r.url().startsWith(base)) console.log(`POST ${new URL(r.url()).pathname}: ${r.status()}`); });
}
monitor(page);
async function press(locator) { if (mobile) await locator.tap(); else await locator.click(); }
async function go(path) { return page.goto(base + path, {waitUntil:'domcontentloaded'}); }
async function toggles() {
  const buttons = page.locator('[data-password-toggle]');
  assert.ok(await buttons.count(), 'password toggle exists');
  for (let i = 0; i < await buttons.count(); i++) {
    const button = buttons.nth(i), id = await button.getAttribute('data-password-toggle');
    const input = page.locator(`[id="${id}"]`);
    assert.equal(await button.getAttribute('type'), 'button');
    await press(button); assert.equal(await input.getAttribute('type'), 'text', `${id} reveals`);
    await press(button); assert.equal(await input.getAttribute('type'), 'password', `${id} hides`);
  }
  console.log('✓ password toggles', new URL(page.url()).pathname);
}
async function signIn(path, username, landing) {
  await go(path); await toggles();
  await page.locator('[name=identifier]').fill(username); await page.locator('[name=password]').fill(password);
  await Promise.all([page.waitForURL(`**${landing}`), press(page.locator('form[data-auth-form] button[type=submit]'))]);
  console.log('✓ login to', landing);
}
async function logout() {
  const button = page.locator('form[action$="/logout"] button').first();
  await Promise.all([page.waitForURL(u => u.pathname === '/login' || u.pathname === '/'), button.click()]);
  console.log('✓ logout');
}
async function register(username) {
  await go('/register'); await toggles();
  await page.locator('#username').fill(username); await page.locator('#email').fill(`${username}@example.test`);
  await page.locator('#password').fill(password); await page.locator('#password_confirm').fill(password);
  await page.locator('[name=terms]').check();
  // Public form is never allowed to use the submitted role.
  await page.locator('form[data-auth-form]').evaluate(form => { const input=document.createElement('input'); input.type='hidden';input.name='role';input.value='SUPER_ADMIN';form.appendChild(input); });
  await Promise.all([page.waitForURL('**/dashboard'), press(page.locator('form[data-auth-form] button[type=submit]'))]);
  console.log('✓ customer registration');
}
try {
  let response = await go('/admin'); assert.ok(response.status() === 403 || page.url().includes('login')); console.log('✓ anonymous admin access refused');
  await go('/setup'); assert.ok(await page.getByRole('button', {name:'Create first administrator'}).count(), 'setup requires empty disposable DB');
  const mobilePage = await browser.newPage({viewport:{width:390,height:844}, isMobile:true, hasTouch:true});
  monitor(mobilePage); page = mobilePage; mobile = true;
  await go('/setup'); await toggles();
  page = desktopPage; mobile = false; await toggles();
  await page.locator('#username').fill(owner); await page.locator('#email').fill(`${owner}@example.test`);
  await page.locator('#password').fill(password); await page.locator('#password_confirm').fill(password); await page.locator('#setup_token').fill(secret);
  await Promise.all([page.waitForURL('**/admin/login'), press(page.locator('form[data-auth-form] button[type=submit]'))]); console.log('✓ first admin created');
  await signIn('/admin/login', owner, '/admin'); await logout();
  await go('/setup'); assert.match(await page.locator('body').innerText(), /Setup is closed/); console.log('✓ setup closed');
  await register(customer);
  response = await go('/admin'); assert.ok(response.status() === 403 || !new URL(page.url()).pathname.startsWith('/admin')); console.log('✓ customer cannot access admin or promote itself');
  await go('/dashboard'); await logout();
  await go('/admin/login'); await page.locator('[name=identifier]').fill(customer); await page.locator('[name=password]').fill(password);
  await Promise.all([page.waitForResponse(r => r.request().method() === 'POST' && new URL(r.url()).pathname === '/admin/login'), press(page.locator('form[data-auth-form] button[type=submit]'))]);
  await page.waitForLoadState('load'); assert.ok(!page.url().endsWith('/admin'), 'customer credentials refused at staff door');
  console.log('✓ customer credentials rejected by admin login');
  await signIn('/login', customer, '/dashboard'); await logout(); await signIn('/login', customer, '/dashboard'); await logout();
  await go('/reset-password/test-token'); await toggles();
  page = mobilePage; mobile = true;
  await register(mobileUser);
  // Clear the mobile session, then exercise sign-in using touch events.
  await page.context().clearCookies();
  await signIn('/login', mobileUser, '/dashboard');
  await page.context().clearCookies();
  await signIn('/admin/login', owner, '/admin');
  await page.context().clearCookies();
  await go('/reset-password/test-token'); await toggles();
  assert.deepEqual(errors, [], 'same-origin requests and browser runtime errors');
  console.log('✓ no same-origin request failures or JavaScript exceptions');
} finally { await browser.close(); }
