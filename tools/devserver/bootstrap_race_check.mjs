/** Two independent PHP workers + a real MySQL server, never a live database.
 * Both POSTs wait on a lock held here before being released together. */
import assert from 'node:assert/strict';
import { randomBytes } from 'node:crypto';
import mysql from 'mysql2/promise';
import { Client } from './client.mjs';

const { AUTH_RACE_DB: database, AUTH_RACE_USER: user, AUTH_RACE_PASSWORD: password,
  VP_SETUP_TOKEN: secret, AUTH_RACE_WORKER_A: baseA, AUTH_RACE_WORKER_B: baseB } = process.env;
const port = Number(process.env.AUTH_RACE_DB_PORT || 3306);
if (process.env.AUTH_RACE_DISPOSABLE !== '1' || !/^auth_race_[a-z0-9_]+$/.test(database || '') ||
    !user || !secret || !baseA || !baseB || baseA === baseB) {
  throw new Error('Disposable test only: set AUTH_RACE_DISPOSABLE=1, AUTH_RACE_DB (auth_race_*), AUTH_RACE_USER, VP_SETUP_TOKEN, and two distinct local AUTH_RACE_WORKER_A/B URLs.');
}
for (const base of [baseA, baseB]) {
  const u = new URL(base);
  if (u.protocol !== 'http:' || !['127.0.0.1', 'localhost', '[::1]'].includes(u.hostname)) {
    throw new Error('Workers must be local HTTP endpoints.');
  }
}
const db = await mysql.createConnection({host:'127.0.0.1', port, user, password:password || '', database});
let held = false;
try {
  const [before] = await db.query('SELECT COUNT(*) AS n FROM users');
  assert.equal(before[0].n, 0, 'requires an empty disposable users table');
  const clients = [new Client(baseA), new Client(baseB)];
  for (const c of clients) {
    const r = await c.get('/setup');
    assert.equal(r.status, 200);
    assert.match(r.text, /Create first administrator/);
  }
  const [lock] = await db.query("SELECT GET_LOCK('marvy_bootstrap_admin', 1) AS got");
  assert.equal(lock[0].got, 1, 'test lock must be available'); held = true;
  const suffix = randomBytes(6).toString('hex'), pwd = `Race-${randomBytes(16).toString('hex')}!`;
  const requests = Promise.all(clients.map((c, i) => c.postForm('/setup/admin', {
    username:`race${i}${suffix}`, email:`race${i}${suffix}@example.test`,
    password:pwd, password_confirm:pwd, setup_token:secret,
  }, {follow:false})));
  let waiting = 0;
  for (let i = 0; i < 60; i++) {
    await new Promise(r => setTimeout(r, 100));
    const [rows] = await db.query('SHOW FULL PROCESSLIST');
    waiting = rows.filter(row => row.Info && /GET_LOCK\s*\(/i.test(row.Info)).length;
    if (waiting >= 2) break;
  }
  assert.equal(waiting, 2, 'both requests must concurrently wait on the database lock');
  await db.query("SELECT RELEASE_LOCK('marvy_bootstrap_admin')"); held = false;
  const responses = await requests;
  assert.equal(responses.filter(r => r.status === 302 && /admin\/login/.test(r.headers.get('location') || '')).length, 1);
  assert.equal(responses.filter(r => r.status === 200 && /Setup is closed/i.test(r.text)).length, 1);
  const [after] = await db.query('SELECT role, COUNT(*) AS n FROM users GROUP BY role');
  assert.equal(after.reduce((sum, row) => sum + row.n, 0), 1);
  assert.equal(after.filter(row => row.role === 'SUPER_ADMIN').reduce((sum, row) => sum + row.n, 0), 1);
  console.log('PASS: two concurrent first-admin requests; exactly one SUPER_ADMIN committed.');
} finally {
  if (held) await db.query("SELECT RELEASE_LOCK('marvy_bootstrap_admin')");
  await db.end();
}
