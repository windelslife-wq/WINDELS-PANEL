<?php defined('BASEPATH') OR exit('No direct script access allowed'); ?>
<?php // Dependent dropdown: the Bundle / Package / PIN-type list follows the chosen network. ?>
<script <?=csp_nonce_attr()?>>
(function () {
  'use strict';
  var network = document.getElementById('network');
  var product = document.getElementById('product');
  if (!network || !product) return;
  var serviceType = <?=json_encode($service_type)?>;
  var tab = <?=json_encode($tab)?>;
  // Root-relative, derived from the page's own URL: an absolute site_url()
  // can name a different origin than the one serving the page (misconfigured
  // APP_URL, preview hosts), and CSP connect-src 'self' would then block
  // the fetch. This way the request is same-origin by construction and works
  // in subdirectory installs too.
  var root = location.pathname.split('/dashboard/vtu/')[0];
  var baseUrl = root + '/dashboard/vtu/products';
  var pageUrl = root + '/dashboard/vtu/' + tab;

  function optionEl(value, text) {
    var opt = document.createElement('option');
    opt.value = value;
    opt.textContent = text;
    return opt;
  }

  function load() {
    product.innerHTML = '';
    product.appendChild(optionEl('', 'Loading…'));
    product.disabled = true;
    fetch(baseUrl + '/' + encodeURIComponent(network.value) + '/' + encodeURIComponent(serviceType), {
      headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
      credentials: 'same-origin'
    }).then(function (res) {
      if (!res.ok) throw new Error('HTTP ' + res.status);
      return res.json();
    }).then(function (rows) {
      product.innerHTML = '';
      if (!rows || !rows.length) {
        product.appendChild(optionEl('', 'No options available for this network'));
      } else {
        rows.forEach(function (p) {
          product.appendChild(optionEl(p.code,
            p.price_text ? p.name + ' — ' + p.price_text : p.name));
        });
      }
      product.disabled = false;
    }).catch(function () {
      // No-JS-grade fallback: reload with ?network=CODE, which the server
      // honours when rendering the product list, so the dropdown still
      // follows the network even when fetch is unavailable or blocked.
      window.location.href = pageUrl + '?network=' + encodeURIComponent(network.value);
    });
  }

  network.addEventListener('change', load);
})();
</script>
