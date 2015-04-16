<?php
/*
Plugin Name: PMPro Renewals
Plugin URI: http://www.bscmanage.com/my-plugin/pmpro-renewals
Description: Sends multiple expiration reminders and processes membership renewals
Version: 0.1.0
License: MPL
Author: Val Catalasan
*/
// Exit if accessed directly
if (!defined('ABSPATH')) exit;

class PMPro_Renewals
{
	var $notification_days = array(7, 14, 28);

	private static $instance = null;

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
		// requires pmpro plugin
		if (!function_exists('pmpro_init'))
			return;

		// remove existing expiration warning
		remove_all_actions('pmpro_cron_expiration_warnings');

		add_action('pmpro_cron_expiration_warnings', array($this, 'pmpro_cron_expiration_warnings'));
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

}

//load pmpro renewals plugin
add_action('init', array('PMPro_Renewals', 'get_instance'), PHP_INT_MAX);
