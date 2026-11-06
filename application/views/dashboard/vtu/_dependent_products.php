<?php defined('BASEPATH') OR exit('No direct script access allowed'); ?>
<?php // Dependent dropdown: the Bundle / Package / PIN-type list follows the chosen network. ?>
<script <?=csp_nonce_attr()?>>
(function () {
  'use strict';
  var network = document.getElementById('network');
  var product = document.getElementById('product');
  if (!network || !product) return;
  var serviceType = <?=json_encode($service_type)?>;
  var baseUrl = <?=json_encode(site_url('dashboard/vtu/products'))?>;

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
      product.innerHTML = '';
      product.appendChild(optionEl('', 'Could not load options — please retry'));
      product.disabled = false;
    });
  }

  network.addEventListener('change', load);
})();
</script>
