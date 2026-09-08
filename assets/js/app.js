(function () {
	"use strict";

	const root = document.getElementById("mpo-app");
	if (!root || typeof MPO === "undefined") {
		return;
	}

	const SEARCH_DEBOUNCE_MS = 250;

	const state = {
		cart: null,
		busy: false,
		busyCount: 0,
		qtyDirty: {},
		productAbort: null,
		customerAbort: null,
		geo: null,
		lastOrder: null,
		paymentChosen: "",
	};

	const els = {
		flash: root.querySelector(".mpo-flash"),
		customerQ: document.getElementById("mpo-customer-q"),
		customerSuggest: document.getElementById("mpo-customer-suggest"),
		customerCard: document.getElementById("mpo-customer-card"),
		productQ: document.getElementById("mpo-product-q"),
		productResults: document.getElementById("mpo-product-results"),
		cartItems: document.getElementById("mpo-cart-items"),
		gifts: document.getElementById("mpo-gifts"),
		qtyActions: document.getElementById("mpo-qty-actions"),
		coupons: document.getElementById("mpo-coupons"),
		fees: document.getElementById("mpo-fees"),
		shipping: document.getElementById("mpo-shipping-methods"),
		payment: document.getElementById("mpo-payment-methods"),
		totals: document.getElementById("mpo-totals"),
		notes: document.getElementById("mpo-notes"),
		inlineError: document.getElementById("mpo-inline-error"),
		modal: document.getElementById("mpo-modal"),
		modalBody: document.getElementById("mpo-modal-body"),
	};

	function debounce(fn, wait) {
		let t;
		return function () {
			const args = arguments;
			clearTimeout(t);
			t = setTimeout(function () {
				fn.apply(null, args);
			}, wait);
		};
	}

	function api(path, options) {
		options = options || {};
		const headers = Object.assign(
			{
				"X-WP-Nonce": MPO.nonce,
				Accept: "application/json",
			},
			options.headers || {}
		);
		if (options.body && typeof options.body === "object" && !(options.body instanceof FormData)) {
			headers["Content-Type"] = "application/json";
			options.body = JSON.stringify(options.body);
		}
		return fetch(MPO.root + path.replace(/^\//, ""), Object.assign({ credentials: "same-origin" }, options, { headers })).then(
			function (res) {
				return res.json().then(function (data) {
					if (!res.ok) {
						const msg =
							(data && (data.message || (data.data && data.data.message))) ||
							MPO.i18n.error;
						const err = new Error(msg);
						err.status = res.status;
						err.payload = data;
						throw err;
					}
					return data;
				});
			}
		);
	}

	function flash(message, type) {
		if (!message) {
			els.flash.hidden = true;
			els.flash.textContent = "";
			return;
		}
		els.flash.hidden = false;
		els.flash.className = "mpo-flash is-" + (type || "error");
		els.flash.textContent = message;
	}

	function setBusy(busy) {
		state.busyCount += busy ? 1 : -1;
		if (state.busyCount < 0) {
			state.busyCount = 0;
		}
		const on = state.busyCount > 0;
		state.busy = on;
		root.classList.toggle("is-busy", on);
		const loader = document.getElementById("mpo-loader");
		if (loader) {
			loader.hidden = !on;
			loader.setAttribute("aria-busy", on ? "true" : "false");
		}
	}

	function applyCart(cart) {
		if (cart && state.cart && cart.uuid !== state.cart.uuid) {
			state.qtyDirty = {};
		}
		state.cart = cart;
		if (cart && cart.notices && cart.notices.length) {
			const first = cart.notices[0];
			flash(first.message, first.type === "error" ? "error" : "success");
		}
		render();
	}

	function uuid() {
		return state.cart && state.cart.uuid;
	}

	function mutate(path, body, options) {
		if (!uuid()) {
			return Promise.resolve();
		}
		if (path !== "cart/update" && path !== "cart/empty" && Object.keys(state.qtyDirty).length) {
			return flushQty().then(function () {
				return mutate(path, body, options);
			});
		}
		options = options || {};
		if (!options.silent) {
			setBusy(true);
		}
		return api("session/" + uuid() + "/" + path, { method: "POST", body: body || {} })
			.then(applyCart)
			.catch(function (err) {
				flash(err.message, "error");
				throw err;
			})
			.finally(function () {
				if (!options.silent) {
					setBusy(false);
				}
			});
	}

	function esc(str) {
		return String(str == null ? "" : str)
			.replace(/&/g, "&amp;")
			.replace(/</g, "&lt;")
			.replace(/>/g, "&gt;")
			.replace(/"/g, "&quot;");
	}

	function render() {
		const cart = state.cart;
		if (!cart) {
			return;
		}
		renderCustomer(cart.customer);
		renderItems(cart.items || []);
		renderGifts(cart.gifts || {});
		renderCoupons(cart.coupons || []);
		renderFees(cart.fees || []);
		renderShipping(cart.shipping || {});
		renderPayment(cart.payment || {});
		renderTotals(cart.totals || {});
		if (els.notes && document.activeElement !== els.notes) {
			els.notes.value = cart.notes || "";
		}
		syncQtyActions();
	}

	function renderCustomer(customer) {
		if (!customer) {
			els.customerCard.innerHTML = "<em>" + esc(MPO.i18n.guest) + "</em>";
			return;
		}
		const balances = customer.balances || [];
		let extra = "";
		if (balances.length) {
			extra =
				'<div class="mpo-balances">' +
				balances
					.map(function (row) {
						let html =
							'<div class="mpo-balance-wrap"><div class="mpo-balance">' +
							esc(row.label) +
							": " +
							(row.html || "") +
							"</div>";
						if (row.id === "points" && row.redeemable) {
							html +=
								'<div class="mpo-points-worth">' +
								(row.worth_html
									? "Worth " + row.worth_html + " off"
									: "") +
								"</div></div>" +
								'<form class="mpo-redeem" data-form="rewards">' +
								'<input class="mpo-input" name="points" type="number" min="1" step="1" placeholder="0">' +
								'<button class="mpo-btn" type="submit">Redeem</button></form>';
						}
						return html;
					})
					.join("") +
				"</div>";
		} else if (customer.funds_html) {
			extra = '<div class="mpo-funds">Account funds: ' + customer.funds_html + "</div>";
		}
		const billLine = formatAddress(customer.billing);
		const shipLine = formatAddress(customer.shipping);
		const different = shippingDiffers(customer.billing, customer.shipping);
		let addrHtml = "";
		if (billLine || shipLine) {
			addrHtml = '<div class="mpo-addr">';
			if (billLine) {
				addrHtml += '<div><span class="mpo-addr-k">Billing</span> ' + esc(billLine) + "</div>";
			}
			if (different && shipLine) {
				addrHtml += '<div><span class="mpo-addr-k">Shipping</span> ' + esc(shipLine) + "</div>";
			} else if (billLine) {
				addrHtml += '<div><span class="mpo-addr-k">Shipping</span> Same as billing</div>';
			} else if (shipLine) {
				addrHtml += '<div><span class="mpo-addr-k">Shipping</span> ' + esc(shipLine) + "</div>";
			}
			addrHtml += "</div>";
		}
		els.customerCard.innerHTML =
			"<strong>" +
			esc(customer.name) +
			(customer.role_label && !customer.is_guest ? ' <span class="mpo-role">' + esc(customer.role_label) + "</span>" : "") +
			"</strong><span>" +
			esc(customer.email || "") +
			(customer.billing && customer.billing.phone ? " · " + esc(customer.billing.phone) : "") +
			"</span>" +
			addrHtml +
			'<div style="margin-top:8px"><button type="button" class="mpo-link" data-action="edit-address">Edit addresses</button></div>' +
			extra;
	}

	function renderItems(items) {
		if (!items.length) {
			els.cartItems.innerHTML = '<p class="mpo-empty">' + esc(MPO.i18n.emptyCart) + "</p>";
			return;
		}
		els.cartItems.innerHTML = items
			.map(function (item) {
				const qtyVal =
					state.qtyDirty[item.key] != null ? state.qtyDirty[item.key] : item.qty;
				const priceVal =
					item.custom_price != null ? item.custom_price : item.unit_price;
				const qtyLocked = item.sold_individually || item.is_gift;
				const qtyCtrl = qtyLocked
					? '<input class="mpo-input mpo-qty" type="number" min="0" step="1" value="' +
					  esc(qtyVal) +
					  '" disabled>'
					: '<div class="mpo-qty-wrap">' +
					  '<button type="button" class="mpo-qty-btn" data-action="qty-dec" data-key="' +
					  esc(item.key) +
					  '" aria-label="Decrease">−</button>' +
					  '<input class="mpo-input mpo-qty" type="number" min="0" step="1" value="' +
					  esc(qtyVal) +
					  '">' +
					  '<button type="button" class="mpo-qty-btn" data-action="qty-inc" data-key="' +
					  esc(item.key) +
					  '" aria-label="Increase">+</button></div>';
				return (
					'<div class="mpo-line' +
					(item.is_gift ? " is-gift" : "") +
					'" data-key="' +
					esc(item.key) +
					'">' +
					'<img src="' +
					esc(item.image) +
					'" alt="">' +
					"<div><div class=\"mpo-line-name\">" +
					esc(item.name) +
					(item.is_gift ? ' <span class="mpo-gift-tag">Free gift</span>' : "") +
					"</div><div class=\"mpo-line-sku\">" +
					esc(item.sku || "") +
					"</div></div>" +
					qtyCtrl +
					'<input class="mpo-input mpo-price-edit" type="number" min="0" step="0.01" value="' +
					esc(priceVal) +
					'" ' +
					(item.is_gift ? "disabled" : "") +
					">" +
					'<button type="button" class="mpo-x" data-action="remove" aria-label="Remove">×</button>' +
					"</div>"
				);
			})
			.join("");
	}

	function renderGifts(gifts) {
		if (!els.gifts) {
			return;
		}
		const qty = gifts && gifts.qty ? Number(gifts.qty) : 0;
		const items = (gifts && gifts.items) || [];
		if (!gifts || !gifts.enabled || qty < 1) {
			els.gifts.hidden = true;
			els.gifts.innerHTML = "";
			return;
		}
		els.gifts.hidden = false;
		els.gifts.innerHTML =
			'<div class="mpo-gift-banner">' +
			'<div><strong>Free gift available</strong><span>' +
			esc(gifts.notice || "This cart qualifies for a free gift.") +
			"</span></div>" +
			(items.length
				? '<button type="button" class="mpo-btn mpo-btn-primary" data-action="choose-gift">Choose gift</button>'
				: '<span class="mpo-gift-hint">Search the gift in Products and add it.</span>') +
			"</div>";
	}

	function showGiftModal() {
		const gifts = (state.cart && state.cart.gifts) || {};
		const items = gifts.items || [];
		if (!items.length) {
			flash("Search the gift in Products and add it.", "success");
			return;
		}
		openModal(
			"<h3>Choose a free gift</h3>" +
				'<p class="mpo-gift-modal-note">' +
				esc(gifts.notice || "Select a gift for this order.") +
				"</p>" +
				'<div class="mpo-gift-grid">' +
				items
					.map(function (p) {
						return (
							'<button type="button" class="mpo-gift-pick" data-action="add-gift" data-id="' +
							p.id +
							'" data-type="' +
							esc(p.type) +
							'">' +
							'<img src="' +
							esc(p.image) +
							'" alt="">' +
							"<span>" +
							esc(p.name) +
							"</span>" +
							"<em>" +
							(p.gift_price_html || p.price_html || "") +
							"</em></button>"
						);
					})
					.join("") +
				"</div>"
		);
	}

	function renderCoupons(coupons) {
		els.coupons.innerHTML = coupons
			.map(function (code) {
				return (
					'<span class="mpo-chip">' +
					esc(code) +
					' <button type="button" class="mpo-x" data-action="remove-coupon" data-code="' +
					esc(code) +
					'">×</button></span>'
				);
			})
			.join("");
	}

	function renderFees(fees) {
		els.fees.innerHTML = fees
			.map(function (fee) {
				return (
					'<span class="mpo-chip">' +
					esc(fee.name) +
					" " +
					fee.html +
					(fee.readonly
						? ""
						: ' <button type="button" class="mpo-x" data-action="remove-fee" data-id="' +
						  esc(fee.id) +
						  '">×</button>') +
					"</span>"
				);
			})
			.join("");
	}

	function renderShipping(shipping) {
		const methods = shipping.methods || [];
		if (!methods.length) {
			els.shipping.innerHTML =
				'<p class="mpo-empty" style="padding:8px">Add a shippable product and customer address to load rates, or enter a custom amount below.</p>';
		} else {
			els.shipping.innerHTML =
				'<div class="mpo-methods">' +
				methods
					.map(function (m) {
						const checked = shipping.chosen === m.id ? " checked" : "";
						return (
							'<label class="mpo-method"><input type="radio" name="mpo-shipping" value="' +
							esc(m.id) +
							'"' +
							checked +
							"> <span>" +
							esc(m.label) +
							" — " +
							m.html +
							"</span></label>"
						);
					})
					.join("") +
				"</div>";
		}
		const customInput = document.querySelector('[data-form="custom-shipping"] input[name="custom"]');
		if (customInput && document.activeElement !== customInput) {
			customInput.value = shipping.custom != null && shipping.custom !== "" ? shipping.custom : "";
		}
	}

	function collectPaymentData() {
		const wrap = document.getElementById("mpo-payment-fields");
		const data = {};
		if (!wrap) {
			return data;
		}
		Array.prototype.forEach.call(wrap.querySelectorAll("input, select, textarea"), function (el) {
			const key = el.name || el.id;
			if (!key) {
				return;
			}
			if ((el.type === "radio" || el.type === "checkbox") && !el.checked) {
				return;
			}
			data[key] = el.value;
		});
		return data;
	}

	function restorePaymentData(saved) {
		const wrap = document.getElementById("mpo-payment-fields");
		if (!wrap || !saved) {
			return;
		}
		Array.prototype.forEach.call(wrap.querySelectorAll("input, select, textarea"), function (el) {
			const key = el.name || el.id;
			if (!key || typeof saved[key] === "undefined") {
				return;
			}
			if (el.type === "checkbox" || el.type === "radio") {
				el.checked = el.value === saved[key];
			} else {
				el.value = saved[key];
			}
		});
	}

	function renderPayment(payment) {
		clearPlaceValidation();
		const saved = collectPaymentData();
		const prevChosen = state.paymentChosen;
		const methods = payment.methods || [];
		if (!methods.length) {
			els.payment.innerHTML = '<p class="mpo-empty" style="padding:8px">No payment methods available for this customer.</p>';
			return;
		}
		els.payment.innerHTML =
			'<div class="mpo-methods">' +
			methods
				.map(function (m) {
					const checked = payment.chosen === m.id ? " checked" : "";
					let extra = "";
					if (payment.chosen === m.id && m.fields_html) {
						extra = '<div class="mpo-payment-box" id="mpo-payment-fields">' + m.fields_html + "</div>";
					}
					return (
						'<div class="mpo-payment-method">' +
						'<label class="mpo-method"><input type="radio" name="mpo-payment" value="' +
						esc(m.id) +
						'"' +
						checked +
						"> <span>" +
						esc(m.title) +
						"</span></label>" +
						extra +
						"</div>"
					);
				})
				.join("") +
			"</div>";
		if (payment.chosen && payment.chosen === prevChosen) {
			restorePaymentData(saved);
		}
		state.paymentChosen = payment.chosen || "";
		if (window.jQuery) {
			window.jQuery(document.body).trigger("updated_checkout");
			window.jQuery(document.body).trigger("payment_method_selected");
		}
	}

	function renderTotals(totals) {
		const items = (state.cart && state.cart.items) || [];
		let lines = "";
		if (items.length) {
			lines =
				'<div class="mpo-totals-items">' +
				items
					.map(function (item) {
						const unit = item.regular_html
							? '<s>' + item.regular_html + "</s> " + (item.unit_html || "")
							: item.unit_html || "";
						return (
							'<div class="mpo-totals-item">' +
							'<div class="mpo-totals-item-row"><span>' +
							esc(item.name) +
							"</span><span>" +
							(item.line_total_html || item.line_html || "") +
							"</span></div>" +
							'<div class="mpo-totals-item-meta">' +
							esc(item.qty) +
							" × " +
							unit +
							"</div></div>"
						);
					})
					.join("") +
				"</div>";
		}
		els.totals.innerHTML =
			lines +
			row("Subtotal", totals.subtotal_html) +
			row("Discount", totals.discount_html) +
			row("Shipping", totals.shipping_html) +
			row("Fees", totals.fees_html) +
			row("Tax", totals.tax_html) +
			(totals.points_to_earn
				? row("Points to earn", String(totals.points_to_earn))
				: "") +
			(totals.points_redeemed
				? row("Points redeemed", String(totals.points_redeemed))
				: "") +
			'<div class="mpo-totals-row is-total"><span>Total</span><span>' +
			(totals.total_html || "") +
			"</span></div>";
	}

	function row(label, html) {
		return (
			'<div class="mpo-totals-row"><span>' +
			label +
			"</span><span>" +
			(html || "") +
			"</span></div>"
		);
	}

	function syncQtyActions() {
		if (!els.qtyActions) {
			return;
		}
		els.qtyActions.hidden = Object.keys(state.qtyDirty).length === 0;
	}

	function flushQty() {
		const keys = Object.keys(state.qtyDirty);
		if (!keys.length) {
			syncQtyActions();
			return Promise.resolve();
		}
		const items = keys.map(function (key) {
			return { key: key, qty: state.qtyDirty[key] };
		});
		state.qtyDirty = {};
		syncQtyActions();
		return mutate("cart/update", { items: items });
	}

	function onQtyInput(key, value) {
		const qty = Number(value);
		state.qtyDirty[key] = isNaN(qty) ? 0 : Math.max(0, qty);
		syncQtyActions();
	}

	function bumpQty(key, delta) {
		const line = els.cartItems.querySelector('.mpo-line[data-key="' + key + '"]');
		const input = line && line.querySelector(".mpo-qty");
		if (!input || input.disabled) {
			return;
		}
		const current = state.qtyDirty[key] != null ? Number(state.qtyDirty[key]) : Number(input.value);
		const next = Math.max(0, (isNaN(current) ? 0 : current) + delta);
		input.value = next;
		onQtyInput(key, next);
	}

	function openModal(html) {
		els.modalBody.innerHTML = html;
		els.modal.hidden = false;
	}

	function closeModal() {
		els.modal.hidden = true;
		els.modalBody.innerHTML = "";
	}

	function searchProducts(q) {
		if (state.productAbort) {
			state.productAbort.abort();
		}
		if (!q || q.length < 2) {
			els.productResults.innerHTML = "";
			return;
		}
		els.productResults.innerHTML = '<p class="mpo-empty mpo-loading">Loading…</p>';
		const ctrl = new AbortController();
		state.productAbort = ctrl;
		api("products?q=" + encodeURIComponent(q), { signal: ctrl.signal })
			.then(function (items) {
				if (!items.length) {
					els.productResults.innerHTML = '<p class="mpo-empty">No products found.</p>';
					return;
				}
				els.productResults.innerHTML = items
					.map(function (p) {
						return (
							'<button type="button" class="mpo-hit" data-action="add-product" data-id="' +
							p.id +
							'" data-type="' +
							esc(p.type) +
							'">' +
							'<img src="' +
							esc(p.image) +
							'" alt="">' +
							"<div class=\"mpo-hit-content\"><div><span class=\"mpo-hit-name\">" +
							esc(p.name) +
							'</span><span class="mpo-hit-meta">' +
							esc(p.sku || p.type) +
							(p.in_stock ? "" : " · out of stock") +
							(p.points ? " · " + p.points + " pts" : "") +
							"</span></div>" +
							'<span class="mpo-hit-price">' +
							(p.price_html || "") +
							"</span></div></button>"
						);
					})
					.join("");
			})
			.catch(function (err) {
				if (err.name !== "AbortError") {
					flash(err.message, "error");
					els.productResults.innerHTML = '<p class="mpo-empty">Search failed. Try again.</p>';
				}
			});
	}

	function searchCustomers(q) {
		if (state.customerAbort) {
			state.customerAbort.abort();
		}
		if (!q || q.length < 2) {
			els.customerSuggest.hidden = true;
			els.customerSuggest.innerHTML = "";
			return;
		}
		els.customerSuggest.hidden = false;
		els.customerSuggest.innerHTML = '<div class="mpo-hit mpo-loading">Loading…</div>';
		const ctrl = new AbortController();
		state.customerAbort = ctrl;
		api("customers?q=" + encodeURIComponent(q), { signal: ctrl.signal })
			.then(function (items) {
				if (!items.length) {
					els.customerSuggest.innerHTML = '<div class="mpo-hit">No customers found.</div>';
					els.customerSuggest.hidden = false;
					return;
				}
				els.customerSuggest.hidden = false;
				els.customerSuggest.innerHTML = items
					.map(function (c) {
						return (
							'<button type="button" class="mpo-hit" data-action="pick-customer" data-id="' +
							c.id +
							'"><divc class="mpo-hit-content"><div><span class="mpo-hit-name">' +
							esc(c.name) +
							'</span><span class="mpo-hit-meta">' +
							esc(c.email) +
							(c.phone ? " · " + esc(c.phone) : "") +
							" · " +
							c.order_count +
							" orders" +
							(c.points != null ? " · " + c.points + " pts" : "") +
							"</span></div></div></button>"
						);
					})
					.join("");
			})
			.catch(function (err) {
				if (err.name !== "AbortError") {
					flash(err.message, "error");
					els.customerSuggest.innerHTML = '<div class="mpo-hit">Search failed. Try again.</div>';
					els.customerSuggest.hidden = false;
				}
			});
	}

	function addProduct(id, extra) {
		extra = extra || {};
		return flushQty().then(function () {
			setBusy(true);
			return api("session/" + uuid() + "/cart/add", {
				method: "POST",
				body: Object.assign({ product_id: id, qty: 1 }, extra),
			})
				.then(function (cart) {
					applyCart(cart);
					closeModal();
				})
				.catch(function (err) {
					if (err.status === 409 && err.payload && err.payload.code === "needs_variation") {
						showVariationModal(err.payload.product, extra.gift ? { gift: true } : {});
						return;
					}
					flash(err.message, "error");
				})
				.finally(function () {
					setBusy(false);
				});
		});
	}

	function showVariationModal(product, extra) {
		extra = extra || {};
		const attrs = (product.attributes || [])
			.map(function (attr) {
				const opts = ['<option value="">' + esc(attr.label) + "</option>"]
					.concat(
						attr.options.map(function (o) {
							return '<option value="' + esc(o.slug) + '">' + esc(o.label) + "</option>";
						})
					)
					.join("");
				return (
					'<label class="full">' +
					esc(attr.label) +
					'<select class="mpo-input" name="' +
					esc(attr.key) +
					'">' +
					opts +
					"</select></label>"
				);
			})
			.join("");
		openModal(
			"<h3>" +
				esc(product.name) +
				"</h3><form data-form='variation' data-id='" +
				product.id +
				(extra.gift ? "' data-gift='1" : "") +
				"' class='mpo-form-grid'>" +
				attrs +
				'<div class="full"><button class="mpo-btn mpo-btn-primary" type="submit">Add to cart</button></div></form>'
		);
	}

	function showNewCustomerModal() {
		openModal(
			"<h3>New customer</h3>" +
				"<form data-form='new-customer' class='mpo-form-grid'>" +
				'<input class="mpo-input" name="first_name" placeholder="First name">' +
				'<input class="mpo-input" name="last_name" placeholder="Last name">' +
				'<input class="mpo-input full" name="email" type="email" required placeholder="Email">' +
				'<input class="mpo-input full" name="phone" placeholder="Phone">' +
				'<div class="full"><button class="mpo-btn mpo-btn-primary" type="submit">Create & select</button></div></form>'
		);
	}

	function addrInput(name, placeholder, value, full) {
		return (
			'<input class="mpo-input' +
			(full ? " full" : "") +
			'" name="' +
			name +
			'" placeholder="' +
			placeholder +
			'" value="' +
			esc(value) +
			'">'
		);
	}

	function addressFields(prefix, addr, withEmail) {
		addr = addr || {};
		let html =
			addrInput(prefix + "_first_name", "First name", addr.first_name) +
			addrInput(prefix + "_last_name", "Last name", addr.last_name);
		if (withEmail) {
			html += addrInput(prefix + "_email", "Email", addr.email, true);
		}
		html +=
			addrInput(prefix + "_phone", "Phone", addr.phone, true) +
			addrInput(prefix + "_company", "Company", addr.company, true) +
			addrInput(prefix + "_address_1", "Address", addr.address_1, true) +
			addrInput(prefix + "_address_2", "Apartment, suite, etc.", addr.address_2, true) +
			addrInput(prefix + "_city", "City", addr.city) +
			addrInput(prefix + "_postcode", "Postcode", addr.postcode) +
			addrInput(prefix + "_state", "State", addr.state) +
			addrInput(prefix + "_country", "Country", addr.country);
		return html;
	}

	function pickPrefixed(data, prefix) {
		const out = {};
		Object.keys(data).forEach(function (key) {
			if (key.indexOf(prefix) === 0) {
				out[key.slice(prefix.length)] = data[key];
			}
		});
		return out;
	}

	function copyBillingToShipping(billing) {
		const shipping = {};
		["first_name", "last_name", "company", "address_1", "address_2", "city", "state", "postcode", "country", "phone"].forEach(
			function (key) {
				shipping[key] = billing[key] || "";
			}
		);
		return shipping;
	}

	function formatAddress(addr) {
		if (!addr) {
			return "";
		}
		const name = [addr.first_name, addr.last_name].filter(Boolean).join(" ").trim();
		const line = [addr.address_1, addr.address_2, addr.city, addr.state, addr.postcode, addr.country]
			.map(function (part) {
				return String(part || "").trim();
			})
			.filter(Boolean)
			.join(", ");
		return [name, line].filter(Boolean).join(" — ");
	}

	function shippingDiffers(billing, shipping) {
		billing = billing || {};
		shipping = shipping || {};
		return ["first_name", "last_name", "company", "address_1", "address_2", "city", "state", "postcode", "country"].some(
			function (key) {
				const ship = String(shipping[key] || "").trim();
				if (!ship) {
					return false;
				}
				return ship !== String(billing[key] || "").trim();
			}
		);
	}

	function toggleShippingFields(on) {
		const wrap = document.getElementById("mpo-shipping-fields");
		if (wrap) {
			wrap.hidden = !on;
		}
	}

	function showAddressModal() {
		const c = (state.cart && state.cart.customer) || {};
		const b = c.billing || {};
		const s = c.shipping || {};
		const different = shippingDiffers(b, s);
		openModal(
			"<h3>Addresses</h3>" +
				"<form data-form='address' class='mpo-form-grid'>" +
				'<p class="mpo-fieldset-h full">Billing</p>' +
				addressFields("billing", b, true) +
				'<label class="mpo-check full"><input type="checkbox" name="ship_to_different" value="1"' +
				(different ? " checked" : "") +
				"> Ship to a different address</label>" +
				'<div id="mpo-shipping-fields" class="mpo-ship-fields full"' +
				(different ? "" : " hidden") +
				">" +
				'<p class="mpo-fieldset-h">Shipping</p>' +
				'<div class="mpo-form-grid">' +
				addressFields("shipping", s, false) +
				"</div></div>" +
				'<div class="full"><button class="mpo-btn mpo-btn-primary" type="submit">Update addresses</button></div></form>'
		);
	}

	function showLoadModal() {
		openModal(
			"<h3>Load previous order</h3>" +
				'<input class="mpo-input" id="mpo-order-q" placeholder="Search order number, email…">' +
				'<div id="mpo-order-results" class="mpo-results"></div>'
		);
		const input = document.getElementById("mpo-order-q");
		const results = document.getElementById("mpo-order-results");
		const run = debounce(function () {
			const q = input.value.trim();
			if (q.length < 1) {
				results.innerHTML = "";
				return;
			}
			api("orders?q=" + encodeURIComponent(q)).then(function (orders) {
				results.innerHTML = (orders || [])
					.map(function (o) {
						return (
							'<button type="button" class="mpo-hit" data-action="load-order" data-id="' +
							o.id +
							'"><div><span class="mpo-hit-name">#' +
							esc(o.number) +
							" — " +
							esc(o.customer) +
							'</span><span class="mpo-hit-meta">' +
							esc(o.date) +
							" · " +
							o.item_count +
							" items · " +
							esc(o.status) +
							"</span></div><span class=\"mpo-hit-price\">" +
							o.total_html +
							"</span></button>"
						);
					})
					.join("") || '<p class="mpo-empty">No orders.</p>';
			});
		}, SEARCH_DEBOUNCE_MS);
		input.addEventListener("input", run);
		input.focus();
	}

	function showHeldModal() {
		const held = (state.cart && state.cart.held) || [];
		if (!held.length) {
			openModal("<h3>Held orders</h3><p class='mpo-empty'>No held orders.</p>");
			return;
		}
		openModal(
			"<h3>Held orders</h3>" +
				held
					.map(function (h) {
						return (
							'<button type="button" class="mpo-hit" data-action="resume" data-uuid="' +
							esc(h.uuid) +
							'"><div><span class="mpo-hit-name">' +
							esc(h.customer) +
							'</span><span class="mpo-hit-meta">' +
							esc(h.email) +
							" · " +
							esc(h.updated) +
							" · " +
							h.item_hint +
							" items</span></div></button>"
						);
					})
					.join("")
		);
	}

	function isRedirectPayment() {
		const p = state.cart && state.cart.payment;
		if (!p || !p.chosen) {
			return false;
		}
		const methods = p.methods || [];
		for (let i = 0; i < methods.length; i++) {
			if (methods[i].id === p.chosen) {
				return !!methods[i].redirect;
			}
		}
		return /paypal|ppcp|ppec/i.test(p.chosen);
	}

	function clearPlaceValidation() {
		if (els.inlineError) {
			els.inlineError.hidden = true;
			els.inlineError.textContent = "";
		}
		if (els.payment) {
			const card = els.payment.closest(".mpo-card");
			if (card) {
				card.classList.remove("is-invalid");
			}
		}
		const box = document.getElementById("mpo-payment-fields");
		if (box) {
			box.classList.remove("is-invalid");
			Array.prototype.forEach.call(box.querySelectorAll(".is-invalid, .woocommerce-invalid"), function (el) {
				el.classList.remove("is-invalid", "woocommerce-invalid");
			});
		}
	}

	function showPlaceValidation(message, elements) {
		flash(message, "error");
		if (els.inlineError) {
			els.inlineError.hidden = false;
			els.inlineError.textContent = message;
		}
		const box = document.getElementById("mpo-payment-fields");
		if (box) {
			box.classList.add("is-invalid");
		}
		if (els.payment) {
			const card = els.payment.closest(".mpo-card");
			if (card) {
				card.classList.add("is-invalid");
			}
		}
		(elements || []).forEach(function (el) {
			if (!el || !el.classList) {
				return;
			}
			el.classList.add("is-invalid");
			const row = el.closest(".form-row");
			if (row) {
				row.classList.add("woocommerce-invalid", "is-invalid");
			}
		});
		const scrollTarget = (elements && elements[0]) || box || els.payment || els.inlineError;
		if (scrollTarget && scrollTarget.scrollIntoView) {
			scrollTarget.scrollIntoView({ behavior: "smooth", block: "center" });
		}
		const first = elements && elements[0];
		if (first && typeof first.focus === "function") {
			try {
				first.focus();
			} catch (err) {
				/* hosted fields (Stripe) cannot take focus this way */
			}
		}
	}

	function paymentFieldLabel(el) {
		const key = ((el && (el.name || el.id || el.className)) || "").toString().toLowerCase();
		if (/cvc|cvv|cid|security|card.?code/.test(key)) {
			return "CVC";
		}
		if (/expir/.test(key)) {
			return "expiry date";
		}
		if (/card.?number|cc.?num|account.?number/.test(key)) {
			return "card number";
		}
		if (/postcode|zip|postal/.test(key)) {
			return "card ZIP";
		}
		const row = el && el.closest && el.closest(".form-row, p, label");
		const label = row && row.querySelector("label");
		if (label) {
			return label.textContent.replace(/\*/g, "").replace(/\s+/g, " ").trim();
		}
		return (el && el.placeholder) || "";
	}

	function isEmptyPaymentValue(el) {
		if (!el) {
			return true;
		}
		const raw = String(el.value || "").replace(/\s+/g, "");
		if (!raw) {
			return true;
		}
		if (/^[•·*.]+$/.test(raw)) {
			return true;
		}
		return false;
	}

	function isCardishField(el) {
		const key = ((el.name || el.id || "") + " " + (el.className || "") + " " + (el.getAttribute("autocomplete") || "")).toLowerCase();
		return /card.?number|cc.?num|account.?number|card.?expir|cc-exp|cvc|cvv|cc-csc|wc-credit-card-form/.test(key);
	}

	function findIncompletePaymentFields() {
		const wrap = document.getElementById("mpo-payment-fields");
		const missing = [];
		if (!wrap) {
			return missing;
		}

		Array.prototype.forEach.call(wrap.querySelectorAll("input, select, textarea"), function (el) {
			if (el.disabled) {
				return;
			}
			if (el.type === "hidden" || el.type === "checkbox" || el.type === "radio" || el.type === "submit" || el.type === "button") {
				return;
			}
			const required = el.required || el.getAttribute("aria-required") === "true" || isCardishField(el);
			if (!required) {
				return;
			}
			if (isEmptyPaymentValue(el)) {
				missing.push(el);
			}
		});

		const hosted = wrap.querySelector(
			".StripeElement, .sq-card, #stripe-card-element, .wc-stripe-elements-field, iframe[name^='__privateStripe'], iframe[src*='js.stripe.com']"
		);
		if (hosted) {
			const tokenFilled = Array.prototype.some.call(wrap.querySelectorAll("input[type='hidden']"), function (el) {
				const key = (el.name || el.id || "").toLowerCase();
				return /stripe|payment.?method|token|source|nonce|payment_intent/.test(key) && String(el.value || "").trim();
			});
			if (!tokenFilled) {
				missing.push(hosted);
			}
		}

		return missing;
	}

	function formatFieldList(labels) {
		if (labels.length === 1) {
			return labels[0];
		}
		if (labels.length === 2) {
			return labels[0] + " and " + labels[1];
		}
		return labels.slice(0, -1).join(", ") + ", and " + labels[labels.length - 1];
	}

	function friendlyPlaceError(msg) {
		const original = String(msg || "");
		if (!original) {
			return MPO.i18n.error;
		}
		const without = original
			.replace(/an error occurred[,.]?\s*please try again or try an alternate form of payment\.?/gi, "")
			.replace(/please complete the payment form\.?/gi, "")
			.replace(/payment could not be processed\.?/gi, "")
			.replace(/please enter your payment details\.?/gi, "")
			.replace(/\s+/g, " ")
			.trim();
		if (!without) {
			return MPO.i18n.enterCardDetails;
		}
		return without;
	}

	function isPaymentError(original, friendly) {
		if (friendly && original && friendly !== original) {
			return true;
		}
		return /payment|card|cvc|cvv|expiry|gateway|billing/i.test(String(original || ""));
	}

	function validatePlace(markPaid) {
		clearPlaceValidation();
		const cart = state.cart;
		if (!cart || !(cart.items && cart.items.length)) {
			flash(MPO.i18n.emptyCartPlace || MPO.i18n.emptyCart, "error");
			if (els.cartItems && els.cartItems.scrollIntoView) {
				els.cartItems.scrollIntoView({ behavior: "smooth", block: "center" });
			}
			return false;
		}
		if (markPaid) {
			return true;
		}
		const needsPayment = cart.totals && cart.totals.needs_payment;
		if (needsPayment && !(cart.payment && cart.payment.chosen)) {
			showPlaceValidation(MPO.i18n.selectPayment, []);
			return false;
		}
		if (!needsPayment) {
			return true;
		}

		const missing = findIncompletePaymentFields();
		if (!missing.length) {
			return true;
		}

		const labels = [];
		missing.forEach(function (el) {
			const label = paymentFieldLabel(el);
			if (label && labels.indexOf(label) === -1) {
				labels.push(label);
			}
		});
		const message = labels.length
			? "Enter " + formatFieldList(labels) + " in Payment, then place the order."
			: MPO.i18n.enterCardDetails;
		showPlaceValidation(message, missing);
		return false;
	}

	function place(markPaid) {
		return flushQty().then(function () {
			if (!validatePlace(markPaid)) {
				return;
			}
			setBusy(true);
			return api("session/" + uuid() + "/place", {
				method: "POST",
				body: {
					payment_method: state.cart.payment && state.cart.payment.chosen,
					mark_paid: !!markPaid,
					note: els.notes.value,
					payment_data: collectPaymentData(),
				},
			})
				.then(function (data) {
					if (data.placed && data.placed.payment_redirect) {
						flash("Redirecting to PayPal…", "success");
						window.location.href = data.placed.payment_redirect;
						return;
					}
					if (data.placed) {
						els.flash.innerHTML =
							esc(MPO.i18n.orderPlaced) +
							' <a href="' +
							esc(data.placed.edit_url) +
							'">#' +
							esc(data.placed.number) +
							"</a>";
						els.flash.className = "mpo-flash is-success";
						els.flash.hidden = false;
					}
					return api("session", { method: "POST" }).then(applyCart);
				})
				.catch(function (err) {
					const original = err && err.message ? err.message : "";
					const message = friendlyPlaceError(original);
					if (isPaymentError(original, message)) {
						showPlaceValidation(message, findIncompletePaymentFields());
					} else {
						flash(original || MPO.i18n.error, "error");
					}
				})
				.finally(function () {
					setBusy(false);
				});
		});
	}

	function formToObject(form) {
		const data = {};
		new FormData(form).forEach(function (value, key) {
			data[key] = value;
		});
		return data;
	}

	function onActionClick(e) {
		const btn = e.target.closest("[data-action]");
		if (!btn) {
			return;
		}
		const action = btn.getAttribute("data-action");
		if (state.busy && action !== "close-modal") {
			e.preventDefault();
			return;
		}

		if (action === "close-modal") {
			e.preventDefault();
			closeModal();
		} else if (action === "guest") {
			mutate("customer", { customer_id: 0 });
		} else if (action === "open-new-customer") {
			showNewCustomerModal();
		} else if (action === "edit-address") {
			showAddressModal();
		} else if (action === "open-load") {
			showLoadModal();
		} else if (action === "open-held") {
			showHeldModal();
		} else if (action === "new-order") {
			flushQty().then(function () {
				setBusy(true);
				api("session", { method: "POST" })
					.then(applyCart)
					.finally(function () {
						setBusy(false);
					});
			});
		} else if (action === "hold") {
			flushQty().then(function () {
				mutate("hold");
			});
		} else if (action === "empty-cart") {
			state.qtyDirty = {};
			syncQtyActions();
			mutate("cart/empty");
		} else if (action === "update-cart") {
			flushQty();
		} else if (action === "qty-inc") {
			e.preventDefault();
			bumpQty(btn.getAttribute("data-key"), 1);
		} else if (action === "qty-dec") {
			e.preventDefault();
			bumpQty(btn.getAttribute("data-key"), -1);
		} else if (action === "place") {
			place(false);
		} else if (action === "place-paid") {
			place(true);
		} else if (action === "add-product") {
			addProduct(btn.getAttribute("data-id"));
		} else if (action === "choose-gift") {
			showGiftModal();
		} else if (action === "add-gift") {
			addProduct(btn.getAttribute("data-id"), { gift: true });
		} else if (action === "pick-customer") {
			mutate("customer", { customer_id: Number(btn.getAttribute("data-id")) });
			els.customerSuggest.hidden = true;
			els.customerQ.value = "";
		} else if (action === "remove") {
			const line = btn.closest(".mpo-line");
			const key = line.getAttribute("data-key");
			delete state.qtyDirty[key];
			syncQtyActions();
			mutate("cart/remove", { key: key });
		} else if (action === "remove-coupon") {
			flushQty().then(function () {
				setBusy(true);
				api("session/" + uuid() + "/coupon?code=" + encodeURIComponent(btn.getAttribute("data-code")), {
					method: "DELETE",
				})
					.then(applyCart)
					.catch(function (err) {
						flash(err.message, "error");
					})
					.finally(function () {
						setBusy(false);
					});
			});
		} else if (action === "remove-fee") {
			flushQty().then(function () {
				setBusy(true);
				api("session/" + uuid() + "/fee?id=" + encodeURIComponent(btn.getAttribute("data-id")), {
					method: "DELETE",
				})
					.then(applyCart)
					.catch(function (err) {
						flash(err.message, "error");
					})
					.finally(function () {
						setBusy(false);
					});
			});
		} else if (action === "load-order") {
			mutate("load-order", { order_id: Number(btn.getAttribute("data-id")) }).then(closeModal);
		} else if (action === "resume") {
			const id = btn.getAttribute("data-uuid");
			setBusy(true);
			api("session/" + id + "/resume", { method: "POST" })
				.then(function (cart) {
					applyCart(cart);
					closeModal();
				})
				.finally(function () {
					setBusy(false);
				});
		}
	}

	function onFormSubmit(e) {
		const form = e.target.closest("form");
		if (!form) {
			return;
		}
		e.preventDefault();
		if (state.busy) {
			return;
		}
		const kind = form.getAttribute("data-form");
		const data = formToObject(form);

		if (kind === "coupon") {
			mutate("coupon", { code: data.code });
			form.reset();
		} else if (kind === "fee") {
			mutate("fee", { name: data.name, amount: data.amount, taxable: false });
			form.reset();
		} else if (kind === "custom-shipping") {
			mutate("shipping", { custom: data.custom });
		} else if (kind === "rewards") {
			mutate("rewards", { points: Number(data.points) });
			form.reset();
		} else if (kind === "variation") {
			const attributes = {};
			Array.prototype.forEach.call(form.querySelectorAll("select"), function (sel) {
				attributes[sel.name] = sel.value;
			});
			const extra = { attributes: attributes };
			if (form.getAttribute("data-gift")) {
				extra.gift = true;
			}
			addProduct(form.getAttribute("data-id"), extra);
		} else if (kind === "new-customer") {
			setBusy(true);
			api("customers", { method: "POST", body: data })
				.then(function (customer) {
					return mutate("customer", { customer_id: customer.id });
				})
				.then(closeModal)
				.catch(function (err) {
					flash(err.message, "error");
				})
				.finally(function () {
					setBusy(false);
				});
		} else if (kind === "address") {
			const billing = pickPrefixed(data, "billing_");
			const shipping = data.ship_to_different
				? pickPrefixed(data, "shipping_")
				: copyBillingToShipping(billing);
			mutate("address", { billing: billing, shipping: shipping }).then(closeModal);
		}
	}

	root.addEventListener("click", onActionClick);
	root.addEventListener("submit", onFormSubmit);
	if (els.modal) {
		els.modal.addEventListener("click", onActionClick);
		els.modal.addEventListener("submit", onFormSubmit);
		els.modal.addEventListener("change", function (e) {
			if (e.target && e.target.name === "ship_to_different") {
				toggleShippingFields(e.target.checked);
			}
		});
	}

	if (els.payment) {
		els.payment.addEventListener("input", function (e) {
			const el = e.target;
			if (!el) {
				return;
			}
			el.classList.remove("is-invalid");
			const row = el.closest(".form-row");
			if (row) {
				row.classList.remove("is-invalid", "woocommerce-invalid");
			}
			if (!findIncompletePaymentFields().length) {
				clearPlaceValidation();
			}
		});
	}

	els.cartItems.addEventListener("input", function (e) {
		const line = e.target.closest(".mpo-line");
		if (!line) {
			return;
		}
		if (e.target.classList.contains("mpo-qty")) {
			onQtyInput(line.getAttribute("data-key"), e.target.value);
		}
	});

	els.cartItems.addEventListener("change", function (e) {
		const line = e.target.closest(".mpo-line");
		if (!line) {
			return;
		}
		if (e.target.classList.contains("mpo-price-edit")) {
			mutate("cart/price", {
				key: line.getAttribute("data-key"),
				price: Number(e.target.value),
			});
		}
	});

	root.addEventListener("change", function (e) {
		if (e.target.name === "mpo-shipping") {
			mutate("shipping", { method: e.target.value });
		}
		if (e.target.name === "mpo-payment") {
			const method = e.target.value;
			if (state.cart && state.cart.payment) {
				state.cart.payment.chosen = method;
				renderPayment(state.cart.payment);
			}
			mutate("payment", { method: method });
		}
	});

	const debouncedProducts = debounce(function () {
		searchProducts(els.productQ.value.trim());
	}, SEARCH_DEBOUNCE_MS);

	const debouncedCustomers = debounce(function () {
		searchCustomers(els.customerQ.value.trim());
	}, SEARCH_DEBOUNCE_MS);

	els.productQ.addEventListener("input", debouncedProducts);
	els.customerQ.addEventListener("input", debouncedCustomers);

	document.addEventListener("keydown", function (e) {
		if (e.key === "/" && document.activeElement.tagName !== "INPUT" && document.activeElement.tagName !== "TEXTAREA") {
			e.preventDefault();
			els.productQ.focus();
		}
		if (e.key === "Escape") {
			closeModal();
		}
	});

	let notesTimer;
	els.notes.addEventListener("input", function () {
		clearTimeout(notesTimer);
		notesTimer = setTimeout(function () {
			mutate("notes", { notes: els.notes.value }, { silent: true });
		}, 600);
	});

	setBusy(true);
	api("session")
		.then(applyCart)
		.catch(function (err) {
			flash(err.message, "error");
		})
		.finally(function () {
			setBusy(false);
		});
})();
