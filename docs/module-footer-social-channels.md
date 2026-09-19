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

## 3. Icons: generic line forms, not traced logos

The buttons use the same 24×24 stroke glyphs `partials/icon` already ships for
the service-category grid — deliberately generic shapes, for the same licence
reason the category glyphs are. Four existed (`facebook`, `instagram`,
`youtube`, and `twitter`, which is the X mark); three were added:

- **`whatsapp`** — the house `message-circle` bubble carrying a phone handset.
  The handset is the standard Lucide `phone` outline scaled to 42% around the
  centre and re-weighted (`stroke-width="1.25"`) so the stroke keeps its
  proportion at that size.
- **`linkedin`** — the classic rounded-square shape reduced to strokes.
- **`telegram`** — the house `send` paper plane scaled to 45% and ringed, so it
  reads as a channel badge rather than the generic send action.

The two composites were generated mechanically from the existing glyphs and
verified by rasterising them next to the reference transform (99.7% and 100%
pixel overlap; the glyphs as shipped are in
[footer-social-icons-preview.png](footer-social-icons-preview.png)). A
typo in a glyph key renders nothing at all, silently — which is why the test
pins every glyph name the footer references.

## 4. Where the row lives, and what it looks like

The buttons sit in the footer's brand column, under the strapline, on every
shell that renders `partials/footer` — the public site, the auth pages and the
API reference. They are 40px circles with an 18px glyph, defined once in
`design-system.css` on the light tokens and restated on the navy palette
(the footer is navy on every shell, and a `.ws-footer a` text-link rule would
otherwise win the specificity fight and underline them).

Icon-only links have no accessible name unless they carry one, so each button
has `aria-label="MarvySocials on Facebook"` (built from the configured site
name), and the icons themselves stay `aria-hidden`. The row is a
`<nav aria-label="Social channels">`.

The static preview (`tools/public_preview_server.js`) mirrors the row so the
design can be looked at without a PHP runtime — the mirror is pinned by a test
too, because a preview that stops mirroring the views previews a site that no
longer exists.

---

## Proofs

`tests/unit/FooterSocialChannelsTest.php` — 9 tests, 87 assertions: the footer
markup and its aria/noopener contract, the icon set, the stylesheet, the
helper's three states, the schema's seven keys, seeder/migration agreement, and
the migration's insert-or-leave behaviour against the real `Setting_model`.
The full suite (1,808 tests) passes except the six payments/Fundsvera failures
that predate this branch.

## Still open

- **The shipped defaults are platform home pages, not profiles.** An operator
  with real channels pastes their URLs in Admin → Settings → Social channels;
  the per-field help text shows the exact shape (`https://wa.me/…`,
  `https://t.me/…`, `https://www.youtube.com/@…`).
- **No brand colours on hover.** The buttons go navy-on-navy like the rest of
  the footer. Per-channel brand colours would need the logo-adjacent artwork
  the licence stance already rules out.
