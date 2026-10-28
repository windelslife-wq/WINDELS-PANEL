# Active Digital Hub brand

The default identity uses Facebook blue (`#1877F2`), a white A icon and the Active Digital Hub wordmark. The matching brand and accent scales are defined in `assets/css/design-system.css` and `tailwind.config.js`; the public marketing layer is in `assets/css/marketing.css`.

The bundled wordmarks and icons live in `assets/brand/`. Wordmarks use a 972×192 canvas; the ratio is reserved in `application/views/partials/brand_logo.php`. The manifest and favicon variants use the same identity. An administrator may override the wordmark through Admin → Appearance. The public name is configured in `application/config/marvy.php`.

Existing installations can retain their own database settings, uploaded logos and content. Update those through the admin interface if they still reference an earlier brand. Rebuild Tailwind with `npm run build:css` when deploying changes to its configuration.
