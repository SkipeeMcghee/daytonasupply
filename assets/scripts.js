// Placeholder for future JavaScript enhancements.
// You can add client‑side interactivity here if needed.
// Global JS for enhanced interactivity: AJAX add-to-cart, update cart count,
// and manager prompts for notes.
document.addEventListener('DOMContentLoaded', function() {
	// Handle AJAX add-to-cart forms (forms with class 'cart-add')
	document.body.addEventListener('submit', function(e) {
		var form = e.target;
		if (form.classList && form.classList.contains('cart-add')) {
			e.preventDefault();
			var data = new FormData(form);
			var xhr = new XMLHttpRequest();
			xhr.open('POST', form.action || window.location.href);
			xhr.setRequestHeader('X-Requested-With', 'XMLHttpRequest');
			xhr.onload = function() {
				try {
					var res = JSON.parse(xhr.responseText);
					if (res && res.success) {
						// update the cart count in header
						var countEl = document.getElementById('cart-count');
						if (countEl) countEl.textContent = res.cartCount;
						// show a small non-intrusive added message next to the product row
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
							// Position it near the Add button
							var btn = row.querySelector('button[type="submit"]');
							if (btn) {
								var rect = btn.getBoundingClientRect();
								msg.style.top = (window.scrollY + rect.top - 10) + 'px';
								msg.style.left = (window.scrollX + rect.left + rect.width + 8) + 'px';
								document.body.appendChild(msg);
								setTimeout(function() { msg.parentNode && msg.parentNode.removeChild(msg); }, 1400);
							}
						}
					} else {
						alert('Unable to add item to cart');
					}
				} catch (err) {
					console.error('Add to cart error', err, xhr.responseText);
				}
			};
			xhr.send(new URLSearchParams(data).toString());
		}
	}, true);

	// Manager portal: prompt for reason when approving/rejecting a single order
	document.body.addEventListener('click', function(e) {
		var target = e.target;
		if (target.matches && target.matches('a.action-approve, a.action-reject')) {
			e.preventDefault();
			var url = target.getAttribute('href');
			var isApprove = target.classList.contains('action-approve');
			var promptLabel = isApprove ? 'Additional Details (optional):' : 'Reason Why (optional):';
			var note = prompt(promptLabel);
			// Build a URL that submits the manager note via GET (managerportal will read it)
			if (note !== null) {
				// Append manager_note param
				var sep = url.indexOf('?') === -1 ? '?' : '&';
				window.location = url + sep + 'manager_note=' + encodeURIComponent(note);
			} else {
				// User cancelled prompt -> proceed without note
				window.location = url;
			}
		}
	});

	// Global delegated handler for manager-portal sort buttons.
	// This ensures clicks on .sort-btn will not navigate/submit and will
	// sort the target table client-side even if inline scripts or markup differ.
	document.body.addEventListener('click', function(e) {
		var el = e.target;
		// allow click on inner text nodes or children by walking up
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
			// annotate original index if missing (skip header rows containing <th>)
			rows.forEach(function(r, i){ if (r.querySelector && r.querySelector('th')) return; if (!r.dataset || typeof r.dataset.origIndex === 'undefined') r.dataset = r.dataset || {}, r.dataset.origIndex = r.dataset.origIndex || i; });
			// filter to data rows (skip header rows that contain <th>)
			rows = rows.filter(function(r){ if (r.querySelector && r.querySelector('th')) return false; return r.querySelector('td') !== null; });
			function getCellText(row, col) { var cells = row.children; return (cells && cells.length > col) ? cells[col].innerText.trim().toLowerCase() : ''; }
			if (mode === 'original') {
				rows.sort(function(a,b){ return (a.dataset.origIndex||0) - (b.dataset.origIndex||0); });
			} else if (mode === 'name') {
				rows.sort(function(a,b){ return getCellText(a,1).localeCompare(getCellText(b,1)); });
			} else if (mode === 'business') {
				rows.sort(function(a,b){ return getCellText(a,2).localeCompare(getCellText(b,2)); });
			}
			rows.forEach(function(r){ tbody.appendChild(r); });
			// set button active visual
			document.querySelectorAll('.sort-btn[data-target="' + target + '"]').forEach(function(b){
				if (b.dataset.sort === mode) { b.style.transform = 'translateY(1px)'; b.style.boxShadow = ''; } else { b.style.transform = ''; b.style.boxShadow = '0 6px 18px rgba(0,0,0,0.12)'; }
			});
		}
	}, false);
});