<?php
/**
 * Pinterest for WooCommerce Tracking Data classes.
 *
 * @package     Pinterest_For_WooCommerce/Classes/
 * @version     x.x.x
 */

namespace Automattic\WooCommerce\Pinterest\Tracking\Data;

/**
 * Holds InitiateCheckout event data: the customer's cart when they open the checkout page.
 *
 * Shares the Checkout data shape, without an order ID since no order exists yet.
 *
 * @class   InitiateCheckout
 * @version x.x.x
 */
class InitiateCheckout extends Checkout {

	/**
	 * @param string    $event_id A unique event id as a requirement by Pinterest for deduplication purposes.
	 * @param string    $price    Cart value.
	 * @param int       $quantity Number of items in the cart.
	 * @param string    $currency Cart currency.
	 * @param Product[] $items    An array of cart items.
	 */
	public function __construct( $event_id, $price, $quantity, $currency, $items ) {
		parent::__construct( $event_id, '', $price, $quantity, $currency, $items );
	}
}
