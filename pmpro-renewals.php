<?php
/*
Plugin Name: PMPro Renewals
Plugin URI: http://www.bscmanage.com/my-plugin/pmpro-renewals
Description: Sends multiple expiration reminders and processes membership renewals using calendar year
Version: 2.1.1
License: MPL
Author: Val Catalasan
*/
// Exit if accessed directly
if (!defined('ABSPATH')) exit;

class PMPro_Renewals
{
	static $lapsed_member_role = array( 'id' => 'lapsed_member', 'name' => 'Lapsed Member');

	var $notification_days = array(7, 14, 28);
	var $membership_renewal = array();

	private static $instance = null;

	// required plugins to used in this application
	static $required_plugins = array(
		'Paid Membership Pro' => 'paid-memberships-pro/paid-memberships-pro.php'
	);

	/**
	 * Return an instance of this class.
	 *
	 * @since     1.0.0
	 *
	 * @return    object    A single instance of this class.
	 */
	public static function get_instance()
	{
		// If the single instance hasn't been set, set it now.
		if (null == self::$instance) {
			self::$instance = new self;
		}

		return self::$instance;
	}

	function __construct()
	{
		if (!$this->required_plugins_active()) return;

		// set default values
		$this->notification_days = explode(',', get_option('notification_days', implode(',', $this->notification_days)));

		// remove existing expiration warning
		remove_all_actions('pmpro_cron_expiration_warnings');

		add_action('pmpro_cron_expiration_warnings', array($this, 'pmpro_cron_expiration_warnings'));
        add_filter('pmpro_send_expiration_warning_email', array($this, 'pmpro_send_expiration_warning_email'), 10, 2);
		add_action('pmpro_membership_post_membership_expiry', array($this, 'pmpro_membership_post_membership_expiry'), 10, 2);
        add_filter('pmpro_checkout_level', array($this, 'pmpro_checkout_level_extend_memberships'), PHP_INT_MAX);
        add_filter('pmpro_ipnhandler_level', array($this, 'pmpro_checkout_level_extend_memberships'), PHP_INT_MAX);
        add_filter('pmpro_level_expiration_text', array($this, 'pmpro_calendar_year_expiration_text'), PHP_INT_MAX, 2);
        add_filter('pmpro_level_cost_text', array($this, 'pmpro_calendar_year_cost_text'), PHP_INT_MAX, 4);
		add_filter('pmpro_checkout_end_date', array($this, 'pmpro_checkout_end_date'), PHP_INT_MAX, 4);

		add_action('admin_menu', array($this, 'renewals_menu'));
	}

	public static function required_plugins_active()
	{
		$status = true;
		include_once( ABSPATH . 'wp-admin/includes/plugin.php' );
		foreach (self::$required_plugins as $name => $plugin) {
			if (is_plugin_active($plugin)) continue;
			?>
			<div class="error">
				<p>PMPro Renewals plugin requires <strong><?php echo $name ?></strong> plugin to be installed and activated</p>
			</div>
			<?php
			$status = false;
		}
		return $status;
	}

	function create_lapsed_member_roles()
	{
		global $wp_roles, $membership_levels;

		foreach ($membership_levels as $level) {
			$lapsed_member_role_level = self::$lapsed_member_role['id'] . '_' . $level->id;
			$lapsed_member_role_level_name = $level->name . ' ' . self::$lapsed_member_role['name'];
			if ( ! in_array($lapsed_member_role_level, array_keys( $wp_roles->roles ) ) ) {
				add_role( $lapsed_member_role_level, $lapsed_member_role_level_name );
			}
		}
	}

	public static function plugin_activation()
	{
		self::required_plugins_active() and self::create_lapsed_member_roles();
	}

	function pmpro_cron_expiration_warnings()
	{
		global $wpdb;

		//make sure we only run once a day
		$today = date("Y-m-d H:i:s", current_time("timestamp"));

		$notification_days = implode(',', $this->notification_days);

		//look for memberships that are going to expire within one week (but we haven't emailed them within a week)
		$sqlQuery = "SELECT mu.user_id, mu.membership_id, mu.startdate, mu.enddate
FROM $wpdb->pmpro_memberships_users mu
WHERE mu.status = 'active'
AND mu.enddate IS NOT NULL
AND mu.enddate <> ''
AND mu.enddate <> '0000-00-00 00:00:00'
AND DATEDIFF(mu.enddate, '$today') IN ($notification_days)
ORDER BY mu.enddate";

		if ( defined( 'PMPRO_CRON_LIMIT' ) ) {
			$sqlQuery .= " LIMIT " . PMPRO_CRON_LIMIT;
		}

		$expiring_soon = $wpdb->get_results( $sqlQuery );

		foreach ( $expiring_soon as $e ) {
			$send_email = apply_filters( "pmpro_send_expiration_warning_email", true, $e->user_id );
			if ( $send_email ) {
				//send an email
				$pmproemail = new PMProEmail();
				$euser      = get_userdata( $e->user_id );
				$pmproemail->sendMembershipExpiringEmail( $euser );

				printf( __( "Membership expiring email sent to %s. ", "pmpro" ), $euser->user_email );
			}

			//update user meta so we don't email them again
			update_user_meta( $e->user_id, "pmpro_expiration_notice", $today );
		}
	}

    function pmpro_send_expiration_warning_email($send_email, $user_id)
    {
        // do not send email if notification has already been sent today
        $expiration_notice = date('Y-m-d', strtotime(get_user_meta($user_id, 'pmpro_expiration_notice', true)));
        $today = date('Y-m-d', current_time('timestamp'));
        return $send_email and $expiration_notice !== $today;
    }

	function pmpro_membership_post_membership_expiry($user_id, $membership_id)
	{
		// do nothing if user is an administrator
		if (user_can($user_id,'administrator'))
			return;

		$lapsed_member_role_level = self::$lapsed_member_role['id'] . '_' . $membership_id;

		// change user role to lapsed_members
		$user = new WP_User($user_id);
		$user->set_role($lapsed_member_role_level);
	}


	function renewals_menu()
	{
		add_submenu_page('pmpro-membershiplevels',
			__('Membership Renewal Settings', 'pmpro-renewal'),
			__('Renewal Settings', 'pmpro-renewal'),
			'manage_options',
			'pmpro_renewal_settings',
			array($this, 'renewals_page')
			);
	}

	// show form
	function renewals_page()
	{
		$_POST['update'] == 'Update' and $this->notification_days = $_POST['notification_days'] and update_option('notification_days', $this->notification_days);
		?>
		<div class="wrap">
			<h2>Membership Renewal Settings</h2>
			<form method="post">
				<p>
					<label>Notification days:</label>
					<input type="text" name="notification_days" value="<?php echo get_option('notification_days', implode(',', $this->notification_days)) ?>" />
					<input type="submit" name="update" value="Update" />
				</p>
			</form>
		</div>
	<?php
	}

    /*
     *  For a level to expire on a certain date.
     *  (Note, this will need to be tweaked to work with PayPal Standard.)
     */
    function pmpro_checkout_level_extend_memberships( $level )
    {
        $renewal = $this->membership_renewal( $level );
        return $renewal['level'];
    }

    function pmpro_calendar_year_expiration_text( $expiration_text, $level ) {
        $renewal = $this->membership_renewal( $level );
        $expiration_date = date( 'm-d-Y', $renewal['new_expiration_date'] );
        $expiration_text = preg_replace( '/after [^.]+/i', "on $expiration_date", $expiration_text );
        return $expiration_text;
    }

    function pmpro_calendar_year_cost_text( $r, $level, $tags, $short ) {
        if (is_admin()) return $r;

        $renewal = $this->membership_renewal( $level );
        $dues = $r;
        if ( !pmpro_hasMembershipLevel( $level->id ) || 'checkout' == $_REQUEST['form'] ) {
            $dues = preg_replace( '/&#36;[^<]+/i', $renewal['prorated'], $r );
        }
        return $dues;
    }

    function pmpro_checkout_end_date( $enddate, $user_id, $pmpro_level, $startdate ) {
        return empty($this->membership_renewal[ $pmpro_level->id ]) ? $enddate : date('Y-m-d', $this->membership_renewal[ $pmpro_level->id ]['new_expiration_date'] );
    }

    function membership_renewal( $level ) {
        global $current_user;

        $level_id = intval( $level->id );

        if ( isset( $this->membership_renewal[ $level_id ] ) )
            return $this->membership_renewal[ $level_id ];

        // set membership calendar date
        $current_calendar_date = date( 'Y' ) . '-01-01';
        $next_calendar_date = date( 'Y-m-d', strtotime( $current_calendar_date . '+1 Year' ) );

        // set new expiration date
        $hasMembershipLevel = pmpro_hasMembershipLevel( $level_id );
        $expiration_date = date('Y-m-d', $hasMembershipLevel ? $current_user->membership_level->enddate : time() );
        $new_expiration_date = $expiration_date < $next_calendar_date ? $next_calendar_date : date( 'Y-m-d', strtotime( $next_calendar_date . '+1 Year' ));

        // calculate membership dues
        $prorated_date = date( 'Y' ) . '-10-01';
        $prorated = 0;
        if ( $hasMembershipLevel ) {
            // renew membership and prorate fees
            if ( $expiration_date < $next_calendar_date ) {
                $prorated = $this->membership_prorate( $expiration_date, $level->initial_payment );
            }
        } else {
            // new membership and prorate fees up-to-certain date
            if ( $expiration_date < $prorated_date ) {
                $prorated = $this->membership_prorate( $expiration_date, $level->initial_payment );
            } elseif ( $expiration_date < $next_calendar_date ) {
                // update new expiration date to following year
                $new_expiration_date = date( 'Y-m-d', strtotime( $new_expiration_date . '+1 Year' ) );
            }
        }

        // re-calculate dues with prorate only for calendar year 2017
        $dues = null;
        if ( date('Y') == 2017 && $prorated ) {
            $current_year = date( 'Y', strtotime( $current_calendar_date ) );
            $next_year = date( 'Y', strtotime( $next_calendar_date ));
            $annual = $level->initial_payment;
            $total = $annual + $prorated;
            $dues = "&#36;{$prorated} Prorated ({$current_year}) + &#36;{$annual} Annual ({$next_year}) = &#36;{$total}";
	        $new_expiration_date = date( 'Y-m-d', strtotime( $new_expiration_date . '+1 Year' ) );
	        $level->initial_payment = $total;
        }

        // set new level expiration
        $level->expiration_number = $this->days_left( $expiration_date, $new_expiration_date );
        $level->expiration_period = 'Day';

        $this->membership_renewal[ $level_id ] = array(
            'new_expiration_date' => strtotime( $new_expiration_date ),
            'level' => $level,
            'prorated' => $dues,
        );
        return $this->membership_renewal[ $level_id ];
    }

    function membership_prorate( $expiration_date, $fees ) {
        $expiration_month = date('m', strtotime( $expiration_date ));
        $rate = $fees / 12;
        $diff = 13 - $expiration_month;
        return $diff ? 5 * round( $diff * $rate / 5 ) : 0;
    }

    function days_left( $start_date, $end_date ) {
	    $date1 = new DateTime( $start_date );
	    $date2 = new DateTime( $end_date );
	    return $date1->diff( $date2 )->format( '%a' );
    }
}

//load pmpro renewals plugin
add_action('init', array('PMPro_Renewals', 'get_instance'), PHP_INT_MAX);

register_activation_hook(__FILE__, array('PMPro_Renewals', 'plugin_activation'));
