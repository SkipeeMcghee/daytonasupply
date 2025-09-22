/* Site interactions: carousel, messaging slider, mega menu, back-to-top, and small helpers */
document.addEventListener('DOMContentLoaded', function () {
	// --- Add-to-cart AJAX handler (keeps original behavior) ---
	document.body.addEventListener('submit', function (e) {
		var form = e.target;
		if (form.classList && form.classList.contains('cart-add')) {
			e.preventDefault();
			var data = new FormData(form);
			var xhr = new XMLHttpRequest();
			xhr.open('POST', form.action || window.location.href);
			xhr.setRequestHeader('X-Requested-With', 'XMLHttpRequest');
			xhr.onload = function () {
				try {
					var res = JSON.parse(xhr.responseText);
					if (res && res.success) {
						var countEl = document.getElementById('cart-count');
						if (countEl) {
							// Format client-side to match server: "empty" when 0, "999+" when >=1000
							var cc = parseInt(res.cartCount || 0, 10) || 0;
							var display = (cc === 0) ? 'empty' : (cc >= 1000 ? '999+' : String(cc));
							countEl.textContent = display;
						}
						var row = document.getElementById('product-' + res.productId);
						if (row) {
							var msg = document.createElement('div');
							msg.className = 'added-msg';
							msg.textContent = 'Added';
							msg.style.position = 'absolute';
							msg.style.background = '#198754';
							msg.style.color = '#fff';
							msg.style.padding = '6px 10px';
							msg.style.borderRadius = '6px';
							msg.style.zIndex = 9999;
							var btn = row.querySelector('button[type="submit"]');
							if (btn) {
								var rect = btn.getBoundingClientRect();
								msg.style.top = (window.scrollY + rect.top - 10) + 'px';
								msg.style.left = (window.scrollX + rect.left + rect.width + 8) + 'px';
								document.body.appendChild(msg);
								setTimeout(function () { msg.parentNode && msg.parentNode.removeChild(msg); }, 1400);
							}
						}
					} else {
						alert('Unable to add item to cart');
					}
				} catch (err) { console.error('Add-to-cart error', err); }
			};
			xhr.send(data);
		}
	}, true);

	// --- Manager portal approve/reject prompt ---
	document.body.addEventListener('click', function (e) {
		var target = e.target;
		if (target.matches && target.matches('a.action-approve, a.action-reject')) {
			e.preventDefault();
			var url = target.getAttribute('href');
			var isApprove = target.classList.contains('action-approve');
			var promptLabel = isApprove ? 'Additional Details (optional):' : 'Reason Why (optional):';
			var note = prompt(promptLabel);
			if (note !== null) {
				var sep = url.indexOf('?') === -1 ? '?' : '&';
				window.location = url + sep + 'manager_note=' + encodeURIComponent(note);
			} else {
				window.location = url;
			}
		}
	});

	// --- Sort buttons for manager lists (client-side) ---
	document.body.addEventListener('click', function (e) {
		var el = e.target;
		while (el && el !== document.body && !el.classList) el = el.parentNode;
		if (!el || !el.classList) return;
		if (el.classList.contains('sort-btn')) {
			e.preventDefault();
			var target = el.dataset.target;
			var mode = el.dataset.sort;
			if (!target || !mode) return;
			var t = document.getElementById(target);
			if (!t) return;
			var tbody = t.tBodies && t.tBodies[0] ? t.tBodies[0] : t;
			var rows = Array.prototype.slice.call(tbody.querySelectorAll('tr'));
			rows.forEach(function (r, i) { if (r.querySelector && r.querySelector('th')) return; if (!r.dataset || typeof r.dataset.origIndex === 'undefined') r.dataset = r.dataset || {}, r.dataset.origIndex = r.dataset.origIndex || i; });
			rows = rows.filter(function (r) { if (r.querySelector && r.querySelector('th')) return false; return r.querySelector('td') !== null; });
			function getCellText(row, col) { var cells = row.children; return (cells && cells.length > col) ? cells[col].innerText.trim().toLowerCase() : ''; }
			if (mode === 'original') rows.sort(function (a, b) { return (a.dataset.origIndex || 0) - (b.dataset.origIndex || 0); });
			else if (mode === 'name') rows.sort(function (a, b) { return getCellText(a, 1).localeCompare(getCellText(b, 1)); });
			else if (mode === 'business') rows.sort(function (a, b) { return getCellText(a, 2).localeCompare(getCellText(b, 2)); });
			rows.forEach(function (r) { tbody.appendChild(r); });
			document.querySelectorAll('.sort-btn[data-target="' + target + '"]').forEach(function (b) {
				if (b.dataset.sort === mode) { b.style.transform = 'translateY(1px)'; b.style.boxShadow = ''; } else { b.style.transform = ''; b.style.boxShadow = '0 6px 18px rgba(0,0,0,0.12)'; }
			});
		}
	}, false);

	// --- Simple accessible hero carousel ---
	(function initCarousel() {
		var carousel = document.querySelector('.hero-carousel');
		if (!carousel) return;
		var track = carousel.querySelector('.carousel-track');
		var slides = Array.prototype.slice.call(carousel.querySelectorAll('.carousel-slide'));
		var prev = carousel.querySelector('.carousel-control.prev');
		var next = carousel.querySelector('.carousel-control.next');
		var dotsWrap = carousel.querySelector('.carousel-dots');
		var index = 0; var interval = 5000; var timer = null; var playing = true;
		function go(i) {
			index = (i + slides.length) % slides.length;
			var x = -index * 100;
			track.style.transform = 'translateX(' + x + '%)';
			if (dotsWrap) Array.prototype.slice.call(dotsWrap.children).forEach(function (b, idx) { b.classList.toggle('active', idx === index); });
		}
		function start() { if (timer) clearInterval(timer); timer = setInterval(function () { go(index + 1); }, interval); playing = true; }
		function stop() { if (timer) clearInterval(timer); timer = null; playing = false; }
		if (prev) prev.addEventListener('click', function () { go(index - 1); stop(); });
		if (next) next.addEventListener('click', function () { go(index + 1); stop(); });
		if (dotsWrap) Array.prototype.slice.call(dotsWrap.children).forEach(function (b, idx) { b.addEventListener('click', function () { go(idx); stop(); }); });
		carousel.addEventListener('mouseenter', stop); carousel.addEventListener('mouseleave', start);
		// start only if more than one slide
		if (slides.length > 1) start(); else go(0);
	})();

	// --- Favorite toggle handler for catalogue ---
	(function favoriteToggles() {
		var favButtons = document.querySelectorAll('button.fav-toggle');
		if (!favButtons || favButtons.length === 0) return;
		favButtons.forEach(function (btn) {
			btn.addEventListener('click', function (e) {
				e.preventDefault();
				var pid = btn.getAttribute('data-product-id');
				if (!pid) return;
				var fd = new FormData();
				fd.append('favorite_product_id', pid);
				var xhr = new XMLHttpRequest();
				xhr.open('POST', window.location.pathname + window.location.search);
				xhr.setRequestHeader('X-Requested-With', 'XMLHttpRequest');
				xhr.onload = function () {
					try {
						var json = JSON.parse(xhr.responseText || '{}');
						if (json && json.success) {
							if (json.favorited) btn.classList.add('fav-on'); else btn.classList.remove('fav-on');
							btn.setAttribute('aria-pressed', json.favorited ? 'true' : 'false');
						} else {
							// fallback to a page reload if AJAX failed
							window.location.reload();
						}
					} catch (err) { window.location.reload(); }
				};
				xhr.onerror = function () { window.location.reload(); };
				xhr.send(fd);
			});
		});
	})();

	// Account menu hover/focus tolerant toggling to avoid flicker when moving pointer
	(function accountMenuTolerant() {
		var acct = document.querySelector('.has-account');
		if (!acct) return;
		var timer = null;
		function open() { clearTimeout(timer); acct.classList.add('open'); acct.setAttribute('aria-expanded','true'); }
		function close() { clearTimeout(timer); timer = setTimeout(function () { acct.classList.remove('open'); acct.setAttribute('aria-expanded','false'); }, 180); }
		acct.addEventListener('mouseenter', open);
		acct.addEventListener('mouseleave', close);
		acct.addEventListener('focusin', open);
		acct.addEventListener('focusout', function (e) { setTimeout(function () { if (!acct.contains(document.activeElement)) close(); }, 10); });
	})();

	// --- Messaging slider (rotating short messages) ---
	(function initMessages() {
		var list = document.querySelector('.message-list'); if (!list) return;
		var items = Array.prototype.slice.call(list.children);
		var i = 0; var t = 3500; if (items.length < 2) return;
		items.forEach(function (it, idx) { it.style.transition = 'opacity 360ms'; it.style.opacity = idx === 0 ? '1' : '0'; });
		setInterval(function () { items[i].style.opacity = '0'; i = (i + 1) % items.length; items[i].style.opacity = '1'; }, t);
	})();

	// --- Mega menu toggle for small screens ---
	document.body.addEventListener('click', function (e) {
		var btn = e.target.closest && e.target.closest('.nav-toggle');
		if (!btn) return;
		var nav = document.querySelector('.nav-menu');
		if (!nav) return;
		var expanded = nav.getAttribute('data-open') === 'true';
		nav.setAttribute('data-open', (!expanded).toString());
		btn.setAttribute('aria-expanded', (!expanded).toString());
		nav.style.display = (!expanded) ? 'block' : 'none';
	});

	// Accessible Products dropdown behavior (keyboard & touch)
	(function productsDropdown() {
		var prodBtn = document.querySelector('.products-item > .cat-btn');
		if (!prodBtn) return;
		var prodLi = prodBtn.closest && prodBtn.closest('.products-item');
		var mega = prodLi ? prodLi.querySelector('.mega') : null;
		// If the control is NOT an anchor, intercept clicks to toggle the mega menu (useful on small screens)
		var isAnchor = prodBtn.tagName && prodBtn.tagName.toLowerCase() === 'a';
		if (!isAnchor) {
			prodBtn.addEventListener('click', function (e) {
				e.preventDefault();
				var expanded = prodBtn.getAttribute('aria-expanded') === 'true';
				prodBtn.setAttribute('aria-expanded', (!expanded).toString());
				if (mega) mega.style.display = (!expanded) ? 'block' : 'none';
			});
		}
		// If the control IS an anchor, ensure clicks do NOT toggle the mega menu — allow navigation
		if (isAnchor) {
			prodBtn.addEventListener('click', function (e) {
				// Do not preventDefault; just make sure any visible mega is closed before navigation
				try {
					if (mega) mega.style.display = 'none';
					prodBtn.setAttribute('aria-expanded','false');
				} catch (err) { /* noop */ }
			});
		}
		// Hide/show mega on focus/hover — keeps hover behaviour for pointer devices
		if (mega) {
			prodLi.addEventListener('focusout', function (e) { setTimeout(function () { if (!prodLi.contains(document.activeElement)) { prodBtn.setAttribute('aria-expanded','false'); mega.style.display='none'; } }, 10); });
			prodLi.addEventListener('mouseenter', function () { if (mega) mega.style.display = 'block'; });
			prodLi.addEventListener('mouseleave', function () { if (mega) mega.style.display = 'none'; });
		}
	})();

	// --- Back to top button ---
	var back = document.querySelector('.back-to-top');
	if (back) {
		window.addEventListener('scroll', function () { back.style.display = (window.scrollY > 360) ? 'block' : 'none'; });
		back.addEventListener('click', function () { window.scrollTo({ top: 0, behavior: 'smooth' }); });
	}

	// --- Order collapse/expand toggles on account page ---
	(function orderToggles() {
		var toggles = document.querySelectorAll('.order-toggle');
		if (!toggles || toggles.length === 0) return;
		toggles.forEach(function (t) {
			var id = t.getAttribute('data-order');
			var table = document.querySelector('.order-items[data-order="' + id + '"]');
			if (!table) return;
			t.setAttribute('role','button');
			t.setAttribute('tabindex','0');
			t.setAttribute('aria-expanded','true');
			function toggle() {
				var expanded = t.getAttribute('aria-expanded') === 'true';
				if (expanded) {
					table.style.display = 'none';
					t.setAttribute('aria-expanded','false');
				} else {
					table.style.display = '';
					t.setAttribute('aria-expanded','true');
				}
			}
			t.addEventListener('click', toggle);
			t.addEventListener('keydown', function (e) { if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); toggle(); } });
		});
	})();

});

/* Auto-resize textareas with class .autosize (used by signup form for long addresses) */
(function () {
	function autosizeOne(el) {
		if (!el) return;
		el.style.height = 'auto';
		// Add a couple pixels to avoid a 1px scrollbar appearing
		el.style.height = Math.min(el.scrollHeight + 2, 1000) + 'px';
	}

	function initAutosize() {
		document.querySelectorAll('textarea.autosize').forEach(function (ta) {
			autosizeOne(ta);
			// remove existing to avoid duplicates
			ta.removeEventListener('input', ta._autosizeHandler);
			ta._autosizeHandler = function () { autosizeOne(ta); };
			ta.addEventListener('input', ta._autosizeHandler, { passive: true });
		});
	}

	if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', initAutosize);
	else initAutosize();
})();

/* Desktop-only subtle parallax for category tiles: translate image slightly on pointermove/hover.
	 Respects reduced-motion and avoids running on touch devices. */
(function categoryParallax() {
	function isTouch() { return ('ontouchstart' in window) || navigator.maxTouchPoints > 0; }
	if (isTouch()) return;
	if (window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches) return;

	document.addEventListener('DOMContentLoaded', function () {
		var cards = document.querySelectorAll('.category-card');
		if (!cards || cards.length === 0) return;

		cards.forEach(function (card) {
			var img = card.querySelector('img');
			if (!img) return;
			// Use pointermove for smooth tracking on desktop; fallback to mousemove
			function move(e) {
				var rect = card.getBoundingClientRect();
				var cx = rect.left + rect.width / 2;
				var cy = rect.top + rect.height / 2;
				var dx = (e.clientX - cx) / rect.width;
				var dy = (e.clientY - cy) / rect.height;
				var tx = dx * 6; // small translate range px
				var ty = dy * 6;
				img.style.transform = 'translate(' + tx + 'px,' + ty + 'px) scale(1.03)';
			}
			function reset() { img.style.transform = ''; }
			card.addEventListener('pointermove', move, { passive: true });
			card.addEventListener('pointerleave', reset);
			card.addEventListener('pointerup', reset);
		});
	});
})();

/* Observe hero-joe and remove blur when it scrolls into view */
(function heroJoeDynamicBlur(){
	if (typeof window === 'undefined') return;
	if (window.matchMedia && (window.matchMedia('(prefers-reduced-motion: reduce)').matches)) return;
	var container = document.querySelector('.hero-joe');
	if (!container) return;

	// mapping params
	var maxBlur = 12; // px when mostly out of view
	var centerDeadZonePx = 18; // small dead zone in px around center where blur is zero

	function setBlur(val) {
		container.style.setProperty('--joe-blur', val + 'px');
	}

	function computeAndSet(entry) {
		var rect = entry.boundingClientRect;
		var viewportH = window.innerHeight || document.documentElement.clientHeight;
		// compute distance from vertical center normalized to -1..1
		var elementCenter = rect.top + rect.height / 2;
		var norm = (elementCenter - viewportH / 2) / (viewportH / 2);
		norm = Math.max(-1, Math.min(1, norm));

		// When the element center is near 0 (centered), we want blur=0 within dead zone.
		// When the element is moved so that half of it is out of frame, produce max blur.
		// Measure half-out condition: if rect.top >= viewportH/2 or rect.bottom <= viewportH/2 then it's half-out roughly.

		// Compute distance of center from center in pixels
		var centerPx = Math.abs(elementCenter - (viewportH/2));

		// If within dead zone, zero blur
		if (centerPx <= centerDeadZonePx) { setBlur(0); return; }

		// Map centerPx (0..viewportH/2) to blur (0..maxBlur) with clamp.
		var maxDistance = viewportH / 2; // when center at top or bottom
		var t = Math.min(1, centerPx / maxDistance);

		// soften curve a bit for nicer falloff
		var eased = Math.pow(t, 0.9);
		var blur = Math.round(eased * maxBlur * 100) / 100;
		setBlur(blur);
	}

	try {
		var obs = new IntersectionObserver(function(entries){
			entries.forEach(function(en){
				computeAndSet(en);
			});
		}, { threshold: [0, 0.1, 0.25, 0.5, 0.75, 1] });
		obs.observe(container);
		// also update on resize/scroll to keep value in sync
		window.addEventListener('scroll', function(){ /* no-op; IntersectionObserver triggers on scroll */ }, { passive: true });
		window.addEventListener('resize', function(){ /* recompute via intersection */ }, { passive: true });
	} catch (err) {
		// IntersectionObserver not supported: just remove blur
		setBlur(0);
	}
})();

/* Dark mode toggle + persistence
   - Applies saved preference from localStorage immediately
   - Fetches server preference (if logged-in) and applies (server overrides local)
   - Persists changes to server via POST when toggled
*/
(function darkMode(){
	var storageKey = 'dg_theme';
	function apply(isDark){
		try { if (isDark) document.documentElement.classList.add('theme-dark'), document.body.classList.add('theme-dark'); else document.documentElement.classList.remove('theme-dark'), document.body.classList.remove('theme-dark'); } catch(e) {}
		var cb = document.getElementById('darkmode_toggle'); if (cb) cb.checked = !!isDark;
	}

	// Apply from localStorage immediately for fast UX
	try {
		var local = localStorage.getItem(storageKey);
		if (local !== null) apply(local === 'dark');
	} catch (e) { /* storage blocked */ }

	// Query server for saved preference and apply if available
	try {
		fetch('/ajax/get_user_prefs.php', { credentials: 'same-origin' }).then(function(r){ if (!r.ok) return null; return r.json(); }).then(function(json){ if (!json) return; if (typeof json.darkmode !== 'undefined' && json.darkmode !== null) { apply(!!json.darkmode); try { localStorage.setItem(storageKey, json.darkmode ? 'dark' : 'light'); } catch(e){} } }).catch(function(){});
	} catch (e) {}

	// Wire toggle to persist and apply
	document.addEventListener('DOMContentLoaded', function(){
		var toggle = document.getElementById('darkmode_toggle'); if (!toggle) return;
		toggle.addEventListener('change', function(){
			var isDark = !!toggle.checked; apply(isDark);
			try { localStorage.setItem(storageKey, isDark ? 'dark' : 'light'); } catch(e){}
			try {
				var fd = new FormData(); fd.append('darkmode', isDark ? '1' : '0');
				fetch('/ajax/update_darkmode.php', { method: 'POST', credentials: 'same-origin', body: fd });
			} catch(e){ }
		}, { passive: true });
	});
})();