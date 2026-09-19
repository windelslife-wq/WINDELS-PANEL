# Footer social channels — the seven buttons

*Branch `arena/01a0baf2-windels-panel`.*

The operator asked for Facebook, Instagram, WhatsApp, X, LinkedIn, Telegram and
YouTube buttons in the site footer. The work is not the markup — it is the four
decisions underneath it, because a white-label panel cannot ship seven
hard-coded links to somebody else's profiles.

---

## 1. The buttons render from settings, not from the view

Each channel is one row in `settings` (`social_facebook_url`, …), edited under
**Admin → Settings → Social channels** as `url`-type fields. A channel can be
in one of three states, and the footer does a different thing with each:

| State | What the footer does |
| --- | --- |
| A stored URL | The button opens it in a new tab (`target="_blank" rel="noopener"`). |
| No row yet (fresh install, skipped migration) | The button falls back to the platform's home page. |
| An empty row (field cleared in admin) | The button is hidden — the url type's usual "leave blank to disable" contract. |

`marvy_social_links()` (`application/helpers/marvy_helper.php`) owns that table.
It reads through `Setting_model::all()`, which is memoised per request, and
guards the database the same way `marvy_brand_setting()` does — a database that
is not reachable gets the shipped defaults rather than a broken page.

**Why the home-page fallback rather than hiding unconfigured channels?** Two
reasons. A row of five buttons with two missing looks broken, and an operator
who has not configured their profiles yet still asked for the row. And a button
that opens `facebook.com` is honest; a button that opens
`facebook.com/MarvySocials` — a handle nobody registered — is a dead link
wearing a working one's clothes.

## 2. One default list, three places it must agree

The same seven key/default pairs are shipped by:

- `SettingsService::schema()` — the admin form and its validation;
- `Core_seeder::default_settings()` — fresh installs and the generated
  production SQL;
- `Migration_Footer_social_channels` (043) — panels already deployed, whose
  settings table has no `social_*` rows. `up()` inserts-or-leaves, so a value
  an operator already set by hand is never reclaimed; `down()` removes the
  seven rows it added.

Three copies of one list is exactly how one of them drifts, so
`FooterSocialChannelsTest` asserts the seeder and the migration agree, and the
migration was actually run against the dev database before it shipped.
`database/marvysocials.sql` is regenerated (never hand-edited) via
`tools/build_production_sql.php`, and `application-deployment.zip` rebuilt —
a stale package is a panel that boots without the new fields.

## 3. Icons: the original brand marks, at a size that cannot drift

The first cut used the generic stroke glyphs from `partials/icon` — the set's
house style, and deliberately not brand logos. The operator asked for the
**original icons**, so that is what ships now, with the licence question
answered rather than ducked:

- the marks are the official brand glyphs from **Simple Icons**
  (simpleicons.org), published under **CC0 1.0** — public domain, explicitly
  free to redistribute. The "no licence to redistribute traced logos" rule
  that keeps the category grid generic does not apply to CC0 artwork;
- they live in their own partial, `partials/social_icon.php`, so the generic
  stroke set stays generic (`partials/icon` is back to exactly what it was);
- each button is the channel's own colour — Facebook `#0866FF`, Instagram its
  published gradient, WhatsApp `#25D366`, X `#000000` (with a hairline so the
  black circle reads against the navy footer), LinkedIn `#0A66C2`, Telegram
  `#26A5E4`, YouTube `#FF0000` — with the mark in white. That is what makes
  the row read as "the real icons" and what keeps every one of them visible on
  the navy footer.

**Sizing is written on the `<svg>` element, not left to CSS.** The buttons are
36px circles with 18px marks, and the 18 is a `width="18" height="18"`
attribute rendered by the partial. An icon whose size lives only in a
stylesheet class renders at the 24×24 viewBox default the moment that CSS
fails to load — which is exactly the "icon too big" defect the operator
reported on the first cut's preview image. With the attributes, the icon size
holds regardless of what loads, in what order.

The shipped paths were byte-verified against the Simple Icons package, and the
rendered buttons colour-verified against the official brand values. A typo in
a mark key renders nothing at all, silently — which is why the test pins every
mark the footer references. True-scale render in
[footer-social-icons-preview.png](footer-social-icons-preview.png).

## 4. Where the row lives, and what it looks like

The buttons sit in the footer's brand column, under the strapline, on every
shell that renders `partials/footer` — the public site, the auth pages and the
API reference. The button rules live once in `design-system.css`; the only
footer-specific override is a specificity shim (`.ws-footer .ws-social-btn`)
that stops the footer's text-link rules from dimming or underlining the white
marks.

Icon-only links have no accessible name unless they carry one, so each button
has `aria-label="MarvySocials on Facebook"` (built from the configured site
name), and the marks themselves stay `aria-hidden`. The row is a
`<nav aria-label="Social channels">`.

The static preview (`tools/public_preview_server.js`) mirrors the row — same
marks, same classes, same 18px attributes — so the design can be looked at
without a PHP runtime. The mirror is pinned by a test too, because a preview
that stops mirroring the views previews a site that no longer exists.

---

## Proofs

`tests/unit/FooterSocialChannelsTest.php` — 9 tests, 100 assertions: the footer
markup and its aria/noopener contract, the original marks and their
element-level sizing, the stylesheet's brand colours, the helper's three
states, the schema's seven keys, seeder/migration agreement, and the
migration's insert-or-leave behaviour against the real `Setting_model`.
The full suite (1,808 tests) passes except the six payments/Fundsvera failures
that predate this branch.

## Still open

- **The shipped defaults are platform home pages, not profiles.** An operator
  with real channels pastes their URLs in Admin → Settings → Social channels;
  the per-field help text shows the exact shape (`https://wa.me/…`,
  `https://t.me/…`, `https://www.youtube.com/@…`).
- **Trademarks belong to their owners.** CC0 covers the artwork's copyright;
  using a platform's logo to link to that platform's own site is the use the
  brands' guidelines contemplate, but a deployment that renames the site
  should re-read those guidelines with its own counsel.
