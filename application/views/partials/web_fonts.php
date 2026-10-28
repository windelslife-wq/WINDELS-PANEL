<?php defined('BASEPATH') OR exit('No direct script access allowed');
/**
 * Optional Google Fonts upgrade, fetched without blocking first paint.
 *
 * Pages render immediately with the system fallback declared in the local
 * stylesheets. A nonce-authorized loader inserts the remote stylesheet with
 * a non-render-blocking media value; if JavaScript or the font host is
 * unavailable, the fallback remains usable. `font_variant=sans` loads Inter
 * only; the full theme additionally loads Fraunces for display headings.
 */
$__font_variant = isset($font_variant) && $font_variant === 'sans' ? 'sans' : 'full';
$__font_href = $__font_variant === 'sans'
    ? 'https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap'
    : 'https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&family=Fraunces:opsz,wght@9..144,500;9..144,600;9..144,700&display=swap';
?>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<script <?=csp_nonce_attr()?>>
(function(){
  var href = <?=json_encode($__font_href, JSON_UNESCAPED_SLASHES)?>;
  var link = document.createElement('link');
  link.rel = 'stylesheet';
  link.href = href;
  link.media = 'print';
  link.onload = function(){ this.media = 'all'; };
  document.head.appendChild(link);
})();
</script>
<noscript><link rel="stylesheet" href="<?=htmlspecialchars($__font_href, ENT_QUOTES, 'UTF-8')?>"></noscript>
<?php unset($__font_variant, $__font_href); ?>
