<?php
/**
 * Countdown Pre-Orders
 *
 * @package     WC_Pre_Orders/Shortcodes
 * @author      WooThemes
 * @copyright   Copyright (c) 2013, WooThemes
 * @license     http://www.gnu.org/licenses/gpl-3.0.html GNU General Public License v3.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly
}

/**
 * Countdown Shortcode
 *
 * Displays a JavaScript-enabled countdown timer
 *
 * @since 1.0
 */
class WC_Pre_Orders_Shortcode_Countdown {

	/**
	 * Get the shortcode content.
	 *
	 * @param array $atts associative array of shortcode parameters
	 * @return string shortcode content
	 */
	public static function get( $atts ) {
		global $woocommerce;
		return $woocommerce->shortcode_wrapper( array( __CLASS__, 'output' ), $atts, array( 'class' => 'woocommerce-pre-orders' ) );
	}

	/**
	 * Sanitize the layout content.
	 *
	 * @param string $content Layout content
	 * @return string
	 */
	private static function sanitize_layout( $content ) {
		$content = wp_kses_no_null( $content, array( 'slash_zero' => 'keep' ) );
		$content = wp_kses_normalize_entities( $content );
		$content = preg_replace_callback( '%(<!--.*?(-->|$))|(<(?!})[^>]*(>|$))%', array( __CLASS__, 'sanitize_layout_callback' ), $content );

		// This sanitization comes from the `esc_js` function in WordPress.
		// The same sanitization is used except for `_wp_specialchars` which removes characters needed for HTML.
		// https://core.trac.wordpress.org/browser/tags/6.2/src/wp-includes/formatting.php#L4548
		$content = wp_check_invalid_utf8( $content );
		$content = preg_replace( '/&#(x)?0*(?(1)27|39);?/i', "'", stripslashes( $content ) );
		$content = str_replace( "\r", '', $content );
		return str_replace( "\n", '\\n', addslashes( $content ) );
	}

	/**
	 * Callback for `sanitize_layout()`.
	 *
	 * @param array $matches preg_replace regexp matches
	 * @return string
	 */
	public static function sanitize_layout_callback( $matches ) {
		$allowed_html      = wp_kses_allowed_html( 'post' );
		$allowed_protocols = wp_allowed_protocols();

		return wp_kses_split2( $matches[0], $allowed_html, $allowed_protocols );
	}

	/**
	 * Output the countdown timer.  This defaults to the following format, where
	 * elments in [ ] are not shown if zero:
	 *
	 * [y Years] [o Months] [d Days] h Hours m Minutes s Seconds
	 *
	 * The following shortcode arguments are optional:
	 *
	 * * product_id/product_sku - id or sku of pre-order product to countdown to.
	 *     Defaults to current product, if any
	 * * until - date/time to count down to, overrides product release date
	 *     if set.  Example values: "15 March 2015", "+1 month".
	 *     More examples: http://php.net/manual/en/function.strtotime.php
	 * * before - text to show before the countdown.  Only available if 'layout' is not ''
	 * * after - text to show after the countdown.  Only available if 'layout' is not ''
	 * * layout - The countdown layout, defaults to y Years o Months d Days h Hours m Minutes s Seconds
	 *     See http://keith-wood.name/countdownRef.html#layout for all possible options
	 * * format - The format for the countdown display.  Example: 'yodhms'
	 *     to display the year, month, day and time.  See http://keith-wood.name/countdownRef.html#format for all options
	 * * compact - If 'true' displays the date/time labels in compact form, ie
	 *     'd' rather than 'days'.  Defaults to 'false'
	 *
	 * When the countdown date/time is reached the page will refresh.
	 *
	 * To test different time periods you can create shortcodes like the following samples:
	 *
	 * [woocommerce_pre_order_countdown until="+10 year"]
	 * [woocommerce_pre_order_countdown until="+10 month"]
	 * [woocommerce_pre_order_countdown until="+10 day"]
	 * [woocommerce_pre_order_countdown until="+10 second"]
	 *
	 * @param array $atts associative array of shortcode parameters
	 */
	public static function get_pre_order_countdown_shortcode_content( $atts ) {
		global $woocommerce, $product, $wpdb;

		$shortcode_atts = shortcode_atts(
			array(
				'product_id'  => '',
				'product_sku' => '',
				'until'       => '',
				'before'      => '',
				'after'       => '',
				'layout'      => '{y<}{yn} {yl}{y>} {o<}{on} {ol}{o>} {d<}{dn} {dl}{d>} {h<}{hn} {hl}{h>} {m<}{mn} {ml}{m>} {s<}{sn} {sl}{s>}',
				'format'      => 'yodHMS',
				'compact'     => 'false',
			),
			$atts
		);

		$product_id = $shortcode_atts['product_id'];

		// product by sku?
		if ( $shortcode_atts['product_sku'] ) {
			$product_id = wc_get_product_id_by_sku( $shortcode_atts['product_sku'] );
		}

		// product by id?
		if ( $product_id ) {
			$product = wc_get_product( $product_id );
		}

		// no product, or product is in the trash? Bail.
		if ( ! $product instanceof WC_Product || 'trash' === $product->get_status() ) {
			return;
		}

		// date override (convert from string unless someone was savvy enough to provide a timestamp)
		$until = $shortcode_atts['until'];
		if ( $until && ! is_numeric( $until ) ) {
			$until = strtotime( $until );
		}

		// no date override, get the datetime from the product.
		if ( ! $until ) {
			$until = get_post_meta( $product->get_id(), '_wc_pre_orders_availability_datetime', true );
		}

		// can't do anything without an 'until' date
		if ( ! $until ) {
			return;
		}

		// if a layout is being used, prepend/append the before/after text
		$layout = $shortcode_atts['layout'];
		if ( $layout ) {
			$layout  = esc_js( $shortcode_atts['before'] );
			$layout .= self::sanitize_layout( $shortcode_atts['layout'] );
			$layout .= esc_js( $shortcode_atts['after'] );
		}

		// enqueue the required javascripts
		self::enqueue_scripts();

		// countdown javascript
		ob_start();
		?>
		$('#woocommerce-pre-orders-countdown-<?php echo esc_attr( $until ); ?>').countdown({
			until: new Date(<?php echo (int) $until * 1000; ?>),
			layout: '<?php echo $layout; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>',
			format: '<?php echo esc_js( $shortcode_atts['format'] ); ?>',
			compact: <?php echo filter_var( $shortcode_atts['compact'], FILTER_VALIDATE_BOOL ) ? 'true' : 'false'; ?>,
			expiryUrl: location.href,
		});
		<?php
		$javascript = ob_get_clean();
		if ( function_exists( 'wc_enqueue_js' ) ) {
			wc_enqueue_js( $javascript );
		} else {
			$woocommerce->add_inline_js( $javascript );
		}

		ob_start();
		?>
		<div class="woocommerce-pre-orders">
			<?php // the countdown element with a unique identifier to allow multiple countdowns on the same page, and common class for ease of styling ?>
			<div class="woocommerce-pre-orders-countdown" id="woocommerce-pre-orders-countdown-<?php echo esc_attr( $until ); ?>"></div>
		</div>
		<?php
		return ob_get_clean();
	}


	/**
	 * Enqueue required JavaScripts:
	 * * jquery.countdown.js - Main countdown script
	 * * jquery.countdown-{language}.js - Localized countdown script based on WPLANG, and if available
	 */
	private static function enqueue_scripts() {
		global $wc_pre_orders;

		// required library files
		$suffix = defined( 'SCRIPT_DEBUG' ) && SCRIPT_DEBUG ? '' : '.min';

		// enqueue the main countdown script
		wp_enqueue_script( 'jquery-countdown', $wc_pre_orders->get_plugin_url() . '/assets/js/jquery.countdown/jquery.countdown' . $suffix . '.js', array( 'jquery' ), '1.6.1' );

		if ( defined( 'WPLANG' ) && WPLANG ) {
			// countdown includes some localization files, in the form: jquery.countdown-es.js and jquery.countdown-pt-BR.js
			//  convert our WPLANG constant to that format and see whether we have a localization file to include
			@list( $lang, $dialect ) = explode( '_', WPLANG );
			if ( 0 === strcasecmp( $lang, $dialect ) ) {
				$dialect = null;
			}
			$localization = $lang;
			if ( $dialect ) {
				$localization .= '-' . $dialect;
			}

			if ( ! is_readable( $wc_pre_orders->get_plugin_path() . '/assets/js/jquery.countdown/jquery.countdown-' . $localization . '.js' ) ) {
				$localization = $lang;
				if ( ! is_readable( $wc_pre_orders->get_plugin_path() . '/assets/js/jquery.countdown/jquery.countdown-' . $localization . '.js' ) ) {  // try falling back to base language if dialect is not found
					$localization = null;
				}
			}

			if ( $localization ) {
				wp_enqueue_script( 'jquery-countdown-' . $localization, $wc_pre_orders->get_plugin_url() . '/assets/js/jquery.countdown/jquery.countdown-' . $localization . $suffix . '.js', array( 'jquery-countdown' ), '1.6.1' );
			}
		}
	}
}
