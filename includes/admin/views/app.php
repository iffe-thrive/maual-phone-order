<?php
/**
 * SPA shell.
 *
 * @package ManualPhoneOrders
 */

defined( 'ABSPATH' ) || exit;
?>
<div id="mpo-app" class="mpo-app">
	<header class="mpo-top">
		<div class="mpo-brand">
			<span class="mpo-mark" aria-hidden="true"></span>
			<div>
				<h1><?php esc_html_e( 'Phone Orders', 'manual-phone-orders' ); ?></h1>
				<p class="mpo-sub"><?php esc_html_e( 'Isolated customer carts · no page reloads', 'manual-phone-orders' ); ?></p>
			</div>
		</div>
		<div class="mpo-top-actions">
			<button type="button" class="mpo-btn mpo-btn-ghost" data-action="hold"><?php esc_html_e( 'Hold', 'manual-phone-orders' ); ?></button>
			<button type="button" class="mpo-btn mpo-btn-ghost" data-action="open-held"><?php esc_html_e( 'Held orders', 'manual-phone-orders' ); ?></button>
			<button type="button" class="mpo-btn mpo-btn-ghost" data-action="open-load"><?php esc_html_e( 'Load order', 'manual-phone-orders' ); ?></button>
			<button type="button" class="mpo-btn mpo-btn-primary" data-action="new-order"><?php esc_html_e( 'New order', 'manual-phone-orders' ); ?></button>
		</div>
	</header>

	<div class="mpo-flash" hidden></div>

	<div class="mpo-layout">
		<aside class="mpo-col mpo-col-search">
			<section class="mpo-card" data-panel="customer">
				<div class="mpo-card-h">
					<h2><?php esc_html_e( 'Customer', 'manual-phone-orders' ); ?></h2>
					<div class="mpo-inline">
						<button type="button" class="mpo-link" data-action="guest"><?php esc_html_e( 'Guest', 'manual-phone-orders' ); ?></button>
						<button type="button" class="mpo-link" data-action="open-new-customer"><?php esc_html_e( 'New', 'manual-phone-orders' ); ?></button>
					</div>
				</div>
				<div class="mpo-search-wrap">
					<input type="search" class="mpo-input" id="mpo-customer-q" autocomplete="off" placeholder="<?php esc_attr_e( 'Search name, email, or phone…', 'manual-phone-orders' ); ?>" />
					<div class="mpo-suggest" id="mpo-customer-suggest" hidden></div>
				</div>
				<div id="mpo-customer-card" class="mpo-customer-card"></div>
			</section>

			<section class="mpo-card" data-panel="products">
				<div class="mpo-card-h">
					<h2><?php esc_html_e( 'Products', 'manual-phone-orders' ); ?></h2>
				</div>
				<div class="mpo-search-wrap">
					<input type="search" class="mpo-input" id="mpo-product-q" autocomplete="off" placeholder="<?php esc_attr_e( 'Search name or SKU…', 'manual-phone-orders' ); ?>" />
					<kbd class="mpo-kbd">/</kbd>
				</div>
				<div id="mpo-product-results" class="mpo-results"></div>
			</section>
		</aside>

		<main class="mpo-col mpo-col-cart">
			<section class="mpo-card mpo-card-cart">
				<div class="mpo-card-h">
					<h2><?php esc_html_e( 'Cart', 'manual-phone-orders' ); ?></h2>
					<button type="button" class="mpo-link" data-action="empty-cart"><?php esc_html_e( 'Empty', 'manual-phone-orders' ); ?></button>
				</div>
				<div id="mpo-cart-items" class="mpo-cart-items"></div>
				<div id="mpo-gifts" class="mpo-gifts" hidden></div>
				<div class="mpo-qty-actions" id="mpo-qty-actions" hidden>
					<p class="mpo-qty-hint"><?php esc_html_e( 'Change quantities, then update the cart.', 'manual-phone-orders' ); ?></p>
					<button type="button" class="mpo-btn mpo-btn-primary" data-action="update-cart"><?php esc_html_e( 'Update cart', 'manual-phone-orders' ); ?></button>
				</div>
			</section>
		</main>

		<aside class="mpo-col mpo-col-checkout">
			<section class="mpo-card">
				<div class="mpo-card-h">
					<h2><?php esc_html_e( 'Coupon / fee', 'manual-phone-orders' ); ?></h2>
				</div>
				<form class="mpo-row" data-form="coupon">
					<input class="mpo-input" name="code" placeholder="<?php esc_attr_e( 'Coupon code', 'manual-phone-orders' ); ?>" />
					<button class="mpo-btn" type="submit"><?php esc_html_e( 'Apply', 'manual-phone-orders' ); ?></button>
				</form>
				<div id="mpo-coupons" class="mpo-chips"></div>
				<form class="mpo-row mpo-row-3" data-form="fee">
					<input class="mpo-input" name="name" placeholder="<?php esc_attr_e( 'Fee label', 'manual-phone-orders' ); ?>" />
					<input class="mpo-input" name="amount" type="number" step="0.01" placeholder="0.00" />
					<button class="mpo-btn" type="submit"><?php esc_html_e( 'Add fee', 'manual-phone-orders' ); ?></button>
				</form>
				<div id="mpo-fees" class="mpo-chips"></div>
			</section>

			<section class="mpo-card" id="mpo-shipping-card">
				<div class="mpo-card-h">
					<h2><?php esc_html_e( 'Shipping', 'manual-phone-orders' ); ?></h2>
				</div>
				<div id="mpo-shipping-methods"></div>
				<form class="mpo-row" data-form="custom-shipping">
					<input class="mpo-input" name="custom" type="number" step="0.01" placeholder="<?php esc_attr_e( 'Custom shipping amount', 'manual-phone-orders' ); ?>" />
					<button class="mpo-btn" type="submit"><?php esc_html_e( 'Set', 'manual-phone-orders' ); ?></button>
				</form>
			</section>

			<section class="mpo-card">
				<div class="mpo-card-h">
					<h2><?php esc_html_e( 'Payment', 'manual-phone-orders' ); ?></h2>
				</div>
				<div id="mpo-payment-methods"></div>
				<p class="mpo-inline-error" id="mpo-inline-error" hidden></p>
			</section>

			<section class="mpo-card mpo-totals-card">
				<div id="mpo-totals" class="mpo-totals"></div>
				<label class="mpo-note">
					<span><?php esc_html_e( 'Order note', 'manual-phone-orders' ); ?></span>
					<textarea id="mpo-notes" class="mpo-input" rows="2"></textarea>
				</label>
				<div class="mpo-place">
					<button type="button" class="mpo-btn mpo-btn-primary mpo-btn-block" data-action="place"><?php esc_html_e( 'Place order', 'manual-phone-orders' ); ?></button>
					<button type="button" class="mpo-btn mpo-btn-block" data-action="place-paid"><?php esc_html_e( 'Place order & mark paid', 'manual-phone-orders' ); ?></button>
				</div>
			</section>
		</aside>
	</div>
</div>

<div class="mpo-modal" id="mpo-modal" hidden>
	<div class="mpo-modal-backdrop" data-action="close-modal"></div>
	<div class="mpo-modal-panel" role="dialog" aria-modal="true">
		<button type="button" class="mpo-modal-x" data-action="close-modal" aria-label="<?php esc_attr_e( 'Close', 'manual-phone-orders' ); ?>">×</button>
		<div id="mpo-modal-body"></div>
	</div>
</div>

<div id="mpo-loader" class="mpo-loader" hidden aria-live="polite" aria-busy="false">
	<div class="mpo-loader-card">
		<span class="mpo-spinner" aria-hidden="true"></span>
		<span><?php esc_html_e( 'Working…', 'manual-phone-orders' ); ?></span>
	</div>
</div>
