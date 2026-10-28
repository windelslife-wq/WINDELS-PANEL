import assert from 'node:assert/strict';
import { translate } from '../../tools/devdb/translate.js';

const update = `UPDATE payment_transactions
  SET base_currency = COALESCE(
    NULLIF(JSON_UNQUOTE(JSON_EXTRACT(metadata, '$.settlement.base_currency')), 'null'),
    base_currency, currency
  )`;
const translated = translate(update, {});
assert.equal(translated.length, 1);
assert.match(translated[0], /json_extract\(metadata, '\$\.settlement\.base_currency'\)/i);
assert.doesNotMatch(translated[0], /JSON_UNQUOTE/i);

// The rewrite must not touch ordinary string values that merely mention the
// MySQL function names.
const literal = translate("SELECT 'JSON_UNQUOTE(JSON_EXTRACT(x, y))' AS note", {});
assert.equal(literal[0], "SELECT 'JSON_UNQUOTE(JSON_EXTRACT(x, y))' AS note");

// SQLite has no FULLTEXT index; keep the service-name LIKE fallback active so
// the app's MATCH/AGAINST + LIKE predicate remains searchable in dev.
const search = translate("SELECT id FROM services WHERE MATCH(services.name, services.description) AGAINST ('instagram' IN NATURAL LANGUAGE MODE) OR services.name LIKE '%instagram%'", {});
assert.match(search[0], /WHERE\s+0\s+OR\s+services\.name\s+LIKE/i);
assert.doesNotMatch(search[0], /AGAINST|MATCH\s*\(/i);
const fulltextLiteral = translate("SELECT 'MATCH(name) AGAINST (value)' AS note", {});
assert.equal(fulltextLiteral[0], "SELECT 'MATCH(name) AGAINST (value)' AS note");

console.log('DEVDB TRANSLATION TEST PASSED (5 assertions)');
