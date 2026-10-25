/* MVRK Woo Connector: live showroom availability (fetched after the page loads, so cached pages stay correct). */
(function () {
    'use strict';

    var cfg = window.mvrkWc || {};

    function el(tag, cls, text) {
        var node = document.createElement(tag);
        if (cls) node.className = cls;
        if (text !== undefined) node.textContent = text;
        return node;
    }

    function load(box, sku) {
        var list = box.querySelector('.mvrk-availability__list');
        if (!sku) {
            list.replaceChildren(el('span', 'mvrk-availability__muted', 'Choose an option to see where it is available.'));
            return;
        }
        list.replaceChildren(el('span', 'mvrk-availability__muted', 'Checking…'));

        fetch(cfg.ajax + '?action=mvrk_availability&sku=' + encodeURIComponent(sku), { credentials: 'same-origin' })
            .then(function (r) { return r.json(); })
            .then(function (res) {
                var locations = (res && res.success && res.data && res.data.locations) || [];
                if (!locations.length) {
                    list.replaceChildren(el('span', 'mvrk-availability__muted', 'Please call us for availability.'));
                    return;
                }
                var ul = el('ul', 'mvrk-availability__locations');
                locations.forEach(function (l) {
                    var li = el('li', 'mvrk-availability__location mvrk-availability--' + l.status);
                    li.appendChild(el('span', 'mvrk-availability__name', l.name));
                    li.appendChild(el('span', 'mvrk-availability__status', l.label));
                    ul.appendChild(li);
                });
                list.replaceChildren(ul);
                if (cfg.enquiry) list.appendChild(enquiryForm(sku, locations));
            })
            .catch(function () {
                list.replaceChildren(el('span', 'mvrk-availability__muted', 'Availability is not available right now.'));
            });
    }

    function enquiryForm(sku, locations) {
        var wrap = el('details', 'mvrk-enquiry');
        wrap.appendChild(el('summary', null, 'Reserve to view in showroom'));
        var form = el('form');
        form.innerHTML =
            '<label>Your name<input name="name" required maxlength="120" autocomplete="name"></label>' +
            '<label>Phone<input name="phone" required maxlength="30" inputmode="tel" autocomplete="tel"></label>' +
            '<label>Email (optional)<input name="email" type="email" maxlength="160" autocomplete="email"></label>' +
            '<label>Showroom<select name="location"></select></label>' +
            '<label>Preferred day<input name="preferred_date" type="date"></label>' +
            '<label class="mvrk-enquiry__hp" aria-hidden="true">Website<input name="website" tabindex="-1" autocomplete="off"></label>' +
            '<button type="submit">Send request</button><p class="mvrk-enquiry__msg" role="status"></p>';
        var select = form.querySelector('select');
        locations.forEach(function (l) { var o = el('option', null, l.name); o.value = l.code; select.appendChild(o); });
        form.addEventListener('submit', function (e) {
            e.preventDefault();
            var data = new FormData(form);
            data.append('action', 'mvrk_enquiry');
            data.append('nonce', cfg.nonce);
            data.append('sku', sku);
            var msg = form.querySelector('.mvrk-enquiry__msg');
            form.querySelector('button').disabled = true;
            fetch(cfg.ajax, { method: 'POST', body: data, credentials: 'same-origin' })
                .then(function (r) { return r.json(); })
                .then(function (res) {
                    msg.textContent = (res && res.data && res.data.message) || 'Something went wrong.';
                    if (res && res.success) form.reset(); else form.querySelector('button').disabled = false;
                })
                .catch(function () { msg.textContent = 'Something went wrong. Please call the showroom.'; form.querySelector('button').disabled = false; });
        });
        wrap.appendChild(form);
        return wrap;
    }

    function init() {
        document.querySelectorAll('.mvrk-availability').forEach(function (box) {
            var base = box.getAttribute('data-sku');
            load(box, base);

            // Variable products: follow the chosen variation (WooCommerce fires these via jQuery).
            if (window.jQuery) {
                window.jQuery('form.variations_form')
                    .on('found_variation', function (e, variation) { load(box, (variation && variation.sku) || base); })
                    .on('reset_data', function () { load(box, base); });
            }
        });
    }

    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init); else init();
})();
