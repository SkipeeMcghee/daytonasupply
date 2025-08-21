<?php
// Shared footer for all pages.  Closes the <main> element and adds
// a simple footer section.
?>
</main>
<footer>
    <p>&copy; <?php echo date('Y'); ?> Daytona Supply. All rights reserved.</p>
</footer>
<script>
// Collapse/expand order groups when toggle clicked
document.addEventListener('DOMContentLoaded', function() {
    var toggles = document.querySelectorAll('.order-toggle');
    toggles.forEach(function(t) {
        t.addEventListener('click', function() {
            var id = t.getAttribute('data-order');
            var tbl = document.querySelector('.order-items[data-order="' + id + '"]');
            if (!tbl) return;
            if (tbl.style.display === 'none') {
                tbl.style.display = '';
                t.textContent = 'Collapse';
            } else {
                tbl.style.display = 'none';
                t.textContent = 'Expand';
            }
        });
    });
    // Smoothly center any element referenced by a fragment after navigation to reduce jarring jumps.
    function smoothScrollToHash() {
        if (!location.hash) return;
        // decode and remove leading '#'
        var id = decodeURIComponent(location.hash.substring(1));
        if (!id) return;
        var el = document.getElementById(id);
        if (!el) return;
        // Give the browser a brief moment to render before animating
        setTimeout(function() {
            try {
                // Ensure element can receive focus without scrolling
                var hadTabIndex = el.hasAttribute('tabindex');
                var prevTab = el.getAttribute('tabindex');
                if (!hadTabIndex) el.setAttribute('tabindex', '-1');
                el.focus({preventScroll: true});
                el.scrollIntoView({behavior: 'smooth', block: 'center'});
                // Remove the fragment from the URL so further navigation isn't affected
                history.replaceState(null, '', location.pathname + location.search);
                if (!hadTabIndex) el.removeAttribute('tabindex');
                else el.setAttribute('tabindex', prevTab);
            } catch (e) {
                // Fallback if browser doesn't support focus options
                el.scrollIntoView({behavior: 'smooth', block: 'center'});
                history.replaceState(null, '', location.pathname + location.search);
            }
        }, 60);
    }

    // Intercept same-page hash links and smooth-scroll to target instead of jumping
    document.querySelectorAll('a[href^="#"]').forEach(function(a) {
        a.addEventListener('click', function(ev) {
            var href = a.getAttribute('href');
            if (!href || href === '#') return;
            var id = decodeURIComponent(href.substring(1));
            var el = document.getElementById(id);
            if (!el) return; // let the browser handle
            ev.preventDefault();
            try {
                var hadTabIndex = el.hasAttribute('tabindex');
                var prevTab = el.getAttribute('tabindex');
                if (!hadTabIndex) el.setAttribute('tabindex', '-1');
                el.focus({preventScroll: true});
                el.scrollIntoView({behavior: 'smooth', block: 'center'});
                if (!hadTabIndex) el.removeAttribute('tabindex');
                else el.setAttribute('tabindex', prevTab);
            } catch (e) {
                el.scrollIntoView({behavior: 'smooth', block: 'center'});
            }
        });
    });

    // Run the smooth-scroll handler if the page was loaded with a hash
    smoothScrollToHash();
});

// Intercept add-to-cart forms to prevent full page reloads and avoid scroll jumps
document.addEventListener('DOMContentLoaded', function() {
    document.querySelectorAll('form.cart-add').forEach(function(form) {
        form.addEventListener('submit', function(ev) {
            ev.preventDefault();
            var data = new FormData(form);
            var url = form.getAttribute('action') || window.location.href;
            fetch(url, {
                method: 'POST',
                headers: {
                    'X-Requested-With': 'XMLHttpRequest'
                },
                body: data
            }).then(function(resp) {
                return resp.json();
            }).then(function(json) {
                if (json && json.success) {
                    // update any cart count badge if present
                    var badge = document.querySelector('.cart-count');
                    if (badge) badge.textContent = json.cartCount;
                    // show a brief inline confirmation without changing scroll
                    var btn = form.querySelector('button[type="submit"]');
                    var msg = document.createElement('span');
                    msg.textContent = ' Added';
                    msg.style.marginLeft = '8px';
                    msg.style.color = '#198754';
                    btn.parentNode.insertBefore(msg, btn.nextSibling);
                    setTimeout(function() { msg.remove(); }, 1400);
                }
            }).catch(function() {
                // fall back to normal submit if AJAX fails
                form.submit();
            });
        });
    });
});

// Favorite toggle handler for catalogue
document.addEventListener('DOMContentLoaded', function() {
    document.querySelectorAll('button.fav-toggle').forEach(function(btn) {
        btn.addEventListener('click', function(ev) {
            ev.preventDefault();
            var pid = btn.getAttribute('data-product-id');
            if (!pid) return;
            var fd = new FormData();
            fd.append('favorite_product_id', pid);
            fetch('catalogue.php', { method: 'POST', body: fd, headers: { 'X-Requested-With': 'XMLHttpRequest' } })
                .then(function(r){ return r.json(); })
                .then(function(json){
                    if (json && json.success) {
                        if (json.favorited) btn.classList.add('fav-on'); else btn.classList.remove('fav-on');
                        btn.setAttribute('aria-pressed', json.favorited ? 'true' : 'false');
                    }
                }).catch(function(){
                    // ignore errors
                });
        });
    });
});
</script>
</body>
</html>