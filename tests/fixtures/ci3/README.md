# CI3 framework fixture (test-only)

`libraries/Email.php` is a byte-for-byte copy of
`system/libraries/Email.php` from the tracked `application-deployment.zip`
(identical to `vendor/codeigniter/framework/system/libraries/Email.php`,
CodeIgniter 3.1.13, MIT licensed — the license header is preserved in the
file itself).

## Why it is here

`application/libraries/MY_Email.php` extends `CI_Email`, and the mail regression
tests (`MailDeliveryTransportTest`, `MailGreetingTest`) deliberately exercise
the *real* CI3 SMTP handshake rather than a stub. In a normal checkout the
framework comes from `composer install` plus `tools/link_system.php`, which
creates the gitignored `system/` symlink. A fresh clone / offline audit run has
neither `vendor/` nor `system/`, so `require_once BASEPATH.'libraries/Email.php'`
fataled in `setUpBeforeClass()` and both classes were reported as failures
before a single assertion ran.

`tests/bootstrap.php` therefore points `BASEPATH` at this fixture tree only
when the real framework file is absent. Existing installs keep using their
own `system/` (or vendor copy); nothing in the application or the deployment
package changes.

## Keeping it in sync

If the bundled CodeIgniter version is upgraded, refresh this file from the
package so the offline tests still exercise the framework the panel ships:

```bash
python3 - <<'PY'
import zipfile
z = zipfile.ZipFile('application-deployment.zip')
open('tests/fixtures/ci3/libraries/Email.php', 'wb').write(
    z.read('system/libraries/Email.php'))
PY
```

Only the files the tests actually load belong here — this is not a vendored
copy of the framework and is never shipped in the deployment zip (`tests/` is
excluded by `tools/build_deployment_package.sh`).
