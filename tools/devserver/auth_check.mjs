/** Isolated HTTP auth regression. Requires a disposable, empty imported database.
 * Set VP_SETUP_TOKEN in .env; never run this against a live database. */
import { Client, errorMarkersIn } from './client.mjs';
import assert from 'node:assert/strict';
import { randomBytes } from 'node:crypto';
const base = process.env.BASE_URL || 'http://127.0.0.1:8080';
const secret = process.env.VP_SETUP_TOKEN;
if (!secret) throw new Error('VP_SETUP_TOKEN is required');
const suffix = randomBytes(5).toString('hex');
const password = 'Auth-test-' + randomBytes(10).toString('hex');
const owner = `owner${suffix}`, customer = `user${suffix}`;
let n = 0;
function check(value, message) { assert.ok(value, message); console.log('✓', message); n++; }
async function get(client, path) { const r = await client.get(path); check(!errorMarkersIn(r.text).length, `${path} has no server error`); return r; }
const admin = new Client(base), user = new Client(base), anonymous = new Client(base);
let r = await get(anonymous, '/admin');
check(r.status === 200 && r.url.includes('login') || r.status === 401 || r.status === 403, 'anonymous cannot open admin');
r = await get(admin, '/setup');
check(r.text.includes('Create first administrator'), 'bootstrap available on empty database');
const setupForm = r.text;
r = await admin.postForm('/setup/admin', {username: owner, email: `${owner}@example.test`, password, password_confirm: password, setup_token: 'wrong'});
check(r.text.includes('invalid or not configured'), 'wrong bootstrap secret rejected');
r = await admin.postForm('/setup/admin', {username: owner, email: `${owner}@example.test`, password, password_confirm: password, setup_token: secret}, {follow:false});
check(r.status === 302 && r.headers.get('location').includes('admin/login'), 'bootstrap creates administrator');
r = await get(admin, '/setup'); check(r.text.includes('Setup is closed'), 'second bootstrap closed');
r = await admin.postForm('/setup/admin', {username:'intruder'+suffix,email:`intruder${suffix}@example.test`,password,password_confirm:password,setup_token:secret}, {fromHtml:setupForm});
check(r.text.includes('Setup is closed') || r.text.includes('setup is closed'), 'second bootstrap POST rejected');
await get(admin, '/admin/login');
r = await admin.postForm('/admin/login', {identifier: owner, password: 'wrong'}); check(r.text.includes('Invalid email/username or password'), 'incorrect admin password rejected');
await get(admin, '/admin/login');
r = await admin.postForm('/admin/login', {identifier: owner, password, remember:'1'}, {follow:false});
check(r.status === 302 && r.headers.get('location').endsWith('/admin'), 'administrator login redirects to admin');
r = await get(admin, '/admin'); check(r.status === 200 && !r.url.includes('login'), 'administrator session survives refresh');
r = await get(user, '/register');
r = await user.postForm('/register',{username: customer, email:`${customer}@example.test`, password, password_confirm:'mismatch', terms:'1'});
check(r.text.includes('Confirm password') && /match/i.test(r.text), 'invalid registration shows validation error');
r = await user.postForm('/register', {username: customer,email:`${customer}@example.test`,password,password_confirm:password,terms:'1',role:'SUPER_ADMIN'}, {follow:false});
check(r.status === 302 && r.headers.get('location').endsWith('/dashboard'), 'customer registration redirects to dashboard');
r = await get(user, '/dashboard'); check(r.status === 200 && !r.url.includes('login'), 'customer session survives refresh');
r = await get(user, '/admin'); check(r.status === 403 || r.url.includes('login') || r.url.includes('dashboard'), 'customer cannot access admin despite role payload');
r = await get(user, '/dashboard'); r = await user.postForm('/logout', {}, {follow:false}); check(r.status === 302, 'logout submits');
r = await get(user, '/dashboard'); check(r.url.includes('login') || r.status === 401 || r.status === 403, 'logout invalidates session');
await get(user, '/login'); r = await user.postForm('/login',{identifier:customer,password:'wrong'}); check(r.text.includes('Invalid email/username or password'), 'incorrect customer credentials rejected');
await get(user, '/login'); r = await user.postForm('/login',{identifier:customer,password}, {follow:false}); check(r.status === 302 && r.headers.get('location').endsWith('/dashboard'), 'customer login succeeds');
const duplicate = new Client(base); await get(duplicate,'/register'); r = await duplicate.postForm('/register',{username:'other'+suffix,email:`${customer}@example.test`,password,password_confirm:password,terms:'1'}); check(r.text.includes('already exists'), 'duplicate email rejected');
console.log(`Auth HTTP regression: ${n} checks passed`);
