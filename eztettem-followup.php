<?php
/**
 * Plugin Name:  Eztettem Followup Emails
 * Plugin URI:   http://www.eztettem.hu
 * Description:  Order followup email addon for Jigoshop.
 * Version:      1.0.0
 * Tested up to: 4.5.2
 * Author:       Enterprise Software Innovation Kft.
 * Author URI:   http://google.com/+EnterpriseSoftwareInnovationKftBudapest
 * Text Domain:  eztettem
 * License:      GPL2
 */
class Eztettem_Followup_Email {
	const OPTION_EXPIRY = 'eztettem_followup_expiry';
	const OPTION_TRESHOLD = 'eztettem_followup_treshold';
	const OPTION_PERCENT = 'eztettem_followup_percent';
	const OPTION_AFTER_ORDER = 'eztettem_followup_after';
	const OPTION_BEFORE_EXPIRY = 'eztettem_followup_before';
	const EMAIL_VARIABLE_CODE = 'followup_coupon';
	const EMAIL_VARIABLE_VALUE = 'followup_value';
	const EMAIL_VARIABLE_EXPIRY = 'followup_expiry';
	const ACTION_AFTER_ORDER_EMAIL = 'eztettem_followup_after_order';
	const ACTION_BEFORE_EXPIRY_EMAIL = 'eztettem_followup_before_expiry';
	const ORDER_ATTRIBUTE = '_followup_data';
	const CRON_EVENT = 'eztettem_folloup_event';

	public function __construct() {
		$this->options = Jigoshop_Base::get_options();
		foreach( $this->get_default_options() as $key => $value )
			$this->options->install_external_options_after_id( $key, $value );

		if( !wp_next_scheduled( self::CRON_EVENT ) )
			wp_schedule_event( time(), 'daily', self::CRON_EVENT );

		add_action( 'order_status_completed',                     array( &$this, 'create_followup_attribute' ),  1    );
		add_filter( 'jigoshop_order_email_variables',             array( &$this, 'add_email_variable'        ), 10, 2 );
		add_filter( 'jigoshop_order_email_variables_description', array( &$this, 'add_email_variable'        )        );
		add_action( 'admin_init',                                 array( &$this, 'register_email_actions'    )        );
		add_action( self::CRON_EVENT,                             array( &$this, 'send_emails'               )        );
	}

	/**
	 * Add the followup attribute to the completing order
	 *
	 * The attribute is an array and has two variables: the emailing state and the coupon code.
	 * If the coupon expiry parameter is 0 or the order is below the treshold, no coupon is created.
	 *
	 * The emaling state is an integer with 3 possible attributes:
	 * 0. initial value, no emails has been sent yet
	 * 1. the first, the followup email has already been sent
	 * 2. also the coupon expiry notification has been sent
	 */
	public function create_followup_attribute( $order_id ) {
		if( get_post_meta( $order_id, self::ORDER_ATTRIBUTE, true ) ) return;
		$order_attribute = array( 'email_state' => 0 );

		$order = new jigoshop_order( $order_id );
		if( $this->options->get( self::OPTION_EXPIRY ) > 0 &&
				$order->order_discount_subtotal >= $this->options->get( self::OPTION_TRESHOLD ) ) {
			unset( $_POST['jigoshop_meta_nonce'] ); // turn off coupon save hooks
			$coupon_title = sprintf( __( '%s %s followup coupon', 'eztettem' ), $order->billing_first_name, $order->billing_last_name );
			$coupon_amount = ceil( $order->order_discount_subtotal * $this->options->get( self::OPTION_PERCENT ) / 100 );
			$coupon_expiry = (int) $this->options->get( self::OPTION_EXPIRY );
			$coupon_id = wp_insert_post( array(
					'post_content' => '',
					'post_title' => $coupon_title,
					'post_status' => 'publish',
					'post_type' => 'shop_coupon',
					'post_author' => 1,
					'meta_input' => array(
							'type' => 'fixed_cart',
							'amount' => $coupon_amount,
							'date_from' => strtotime( 'today' ),
							'date_to' => strtotime( "+$coupon_expiry months" ),
							'usage_limit' => 1,
							'individual_use' => true
					)
			) );
			$coupon_code = sanitize_title( sprintf( '%s%1.1s-%d', $order->billing_first_name, $order->billing_last_name, $coupon_id ) );
			wp_update_post( array(
					'ID' => $coupon_id,
					'post_name' => $coupon_code
			) );
			$order_attribute['coupon_code'] = $coupon_code;
		}

		update_post_meta( $order_id, self::ORDER_ATTRIBUTE, $order_attribute );
	}

	/**
	 * Add email variables (title and value) for the coupon
	 *
	 * Usage example:
	 * [followup_coupon]
	 *   <p>Also we created a discount coupon for you with value €[followup_value]
	 *   that you can use with your next purchase up until [followup_expiry].
	 *   The coupon code is: <strong>[value]</strong>.</p>
	 * [/followup_coupon]
	 */
	public function add_email_variable( $variables, $order_id = NULL ) {
		if( isset( $order_id ) ) {
			$order_attribute = (array) maybe_unserialize( get_post_meta( $order_id, self::ORDER_ATTRIBUTE, true ) );
			$coupon = JS_Coupons::get_coupon( @$order_attribute['coupon_code'] );
			$values = $coupon ? array(
					$coupon['code'],
					$coupon['amount'],
					mysql2date( get_option( 'date_format' ), $coupon['date_to'] )
			) : array( '', '', '' );
		} else
			$values = array(
					__( 'Folloup Coupon Code', 'eztettem' ),
					__( 'Folloup Coupon Value', 'eztettem' ),
					__( 'Folloup Coupon Expiry Date', 'eztettem' )
			);
		return array_merge( array_combine( array(
				self::EMAIL_VARIABLE_CODE,
				self::EMAIL_VARIABLE_VALUE,
				self::EMAIL_VARIABLE_EXPIRY
		), $values ), $variables );
	}

	/**
	 * Register our email actions for the email editor
	 *
	 * These are just defining the email types, the corresponding email templates
	 * have to be created and written in admin page ''Jigoshop >> Emails''
	 */
	public function register_email_actions() {
		jigoshop_emails::register_mail( self::ACTION_AFTER_ORDER_EMAIL, __( 'Order followup notification' ), get_order_email_arguments_description() );
		jigoshop_emails::register_mail( self::ACTION_BEFORE_EXPIRY_EMAIL, __( 'Coupon expiry notification' ), get_order_email_arguments_description() );
	}

	/**
	 * Send emails in cron job
	 *
	 * There are two kinds of emails sent:
	 * 1. Followup emails reminding the customer of the shop and the unused coupon (if any was created).
	 *    It is sent if the following is true: order was completed AFTER_ORDER days ago,
	 *    there is an unused coupon or no coupon at all, and no followup emails have been sent yet.
	 * 2. Reminder emails about unused coupons expiring soon.
	 *    It is sent if the following is true: there is an unused coupon (this is a coupon-only email),
	 *    the coupon will expire in BEFORE_EXPIRY days, and the first followup email has already been sent.
	 *
	 * These emails are sent maximum once for an order an only if the order is not older than the expiry time.
	 *
	 * It is also completely valid to turn both kinds of emails off, but have the coupons created.
	 * In this case you might want to add the coupon code to the completed order notification email.
	 */
	public function send_emails() {
		$option_expiry        = (int) $this->options->get( self::OPTION_EXPIRY );
		$option_after_order   = (int) $this->options->get( self::OPTION_AFTER_ORDER );
		$option_before_expiry = (int) $this->options->get( self::OPTION_BEFORE_EXPIRY );

		// if both emails are switched off, there's nothing to do
		if( !$option_after_order && !$option_before_expiry ) return;

		$after_order   = date( 'Y-m-d', strtotime( sprintf( '-%d days', $option_after_order ) ) );
		$before_expiry = date( 'Y-m-d', strtotime( sprintf( '-%d months +%d days', $option_expiry, $option_before_expiry ) ) );
		$expiry        = date( 'Y-m-d', strtotime( sprintf( '-%d months', $option_expiry ) ) );

		// get all orders before the coupon expiry date
		$orders = get_posts( array(
				'post_status'		=> 'publish',
				'post_type'			=> 'shop_order',
				'shop_order_status'	=> 'completed',
				'fields'			=> 'ids',
				'meta_query'		=> array( array(
						'key'		=> self::ORDER_ATTRIBUTE,
				), array(
						'key'		=> '_js_completed_date',
						'compare'	=> '>',
						'value'		=> $expiry
				) )
		) );

		foreach( $orders as $order_id ) {
			$order_date = get_post_meta( $order_id, '_js_completed_date', true );
			$order_attribute = (array) maybe_unserialize( get_post_meta( $order_id, self::ORDER_ATTRIBUTE, true ) );

			// skip emailing if there is a coupon but it's already used
			if( array_key_exists( 'coupon_code', $order_attribute ) ) {
				$coupon = JS_Coupons::get_coupon( $order_attribute['coupon_code'] );
				if( !$coupon || $coupon['usage'] > 0 )
					continue;
			}

			// determine the required action
			$is_after_order = ( $order_attribute['email_state'] === 0 && $option_after_order && $order_date < $after_order );
			$is_before_expiry = ( $order_attribute['email_state'] === 1 && $option_before_expiry && array_key_exists( 'coupon_code', $order_attribute ) && $order_date < $before_expiry );
			$email_action = $is_after_order ? self::ACTION_AFTER_ORDER_EMAIL : ( $is_before_expiry ? self::ACTION_BEFORE_EXPIRY_EMAIL : false );

			// send email and adjust emailing state
			if( $email_action ) {
				$order = new jigoshop_order( $order_id );
				jigoshop_emails::send_mail( $email_action, get_order_email_arguments( $order_id ), $order->billing_email );
				$order_attribute['email_state'] += 1;
				update_post_meta( $order_id, self::ORDER_ATTRIBUTE, $order_attribute );
			}
		}
	}

	/**
	 * Add settings fields to Jigoshop settings pages
	 *
	 * - OPTION_EXPIRY can turn coupons off
	 * - OPTION_AFTER_ORDER can turn the first followup email off
	 * - OPTION_BEFORE_EXPIRY can turn the expiry reminder email off
	 */
	private function get_default_options() {
		return array(
				'jigoshop_force_ssl_checkout' => array( array(
						'name'		=> __( 'Followup Marketing', 'eztettem' ),
						'type'		=> 'title',
						'desc'		=> __( 'Have cart coupons automatically created after each order to encourage repeated purchasing.<br/>In addition, followup emails can be scheduled about them. Edit those emails in <em>Jigoshop &gt;&gt; Emails</em> screen.', 'eztettem' )
				), array(
						'name'		=> __( 'Coupon validity interval', 'eztettem' ),
						'desc'		=> __( 'For how many <strong>months</strong> should the coupon be valid. Set to 0 to turn coupons off.', 'eztettem' ),
						'id'		=> self::OPTION_EXPIRY,
						'type'		=> 'natural'
				), array(
						'name'		=> __( 'Cart value percentage', 'eztettem' ),
						'desc'		=> __( 'Automatically create coupons for orders in this percentage of their value.', 'eztettem' ),
						'tip'		=> '',
						'id'		=> self::OPTION_PERCENT,
						'type'		=> 'natural'
				), array(
						'name'		=> __( 'Cart value treshold', 'eztettem' ),
						'desc'		=> __( 'Create coupons only for orders exceeding this value (ex. tax).', 'eztettem' ),
						'tip'		=> '',
						'id'		=> self::OPTION_TRESHOLD,
						'type'		=> 'natural'
				), array(
						'name'		=> __( 'Email after order', 'eztettem' ),
						'desc'		=> __( 'How many <strong>days after</strong> making the order to send the folloup email. Set to 0 to turn it off.', 'eztettem' ),
						'id'		=> self::OPTION_AFTER_ORDER,
						'type'		=> 'natural'
				), array(
						'name'		=> __( 'Email before expiry', 'eztettem' ),
						'desc'		=> __( 'How many <strong>days before</strong> the coupon expires to send the reminder email. Set to 0 to turn it off.', 'eztettem' ),
						'id'		=> self::OPTION_BEFORE_EXPIRY,
						'type'		=> 'natural'
				) ),
		);
	}
}

// Initialize only if Jigoshop is active
add_action( 'jigoshop_initialize_plugins', function() { new Eztettem_Followup_Email(); }, 90 );
