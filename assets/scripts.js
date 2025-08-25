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
});