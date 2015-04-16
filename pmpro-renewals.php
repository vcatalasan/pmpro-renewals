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
	var $days = array(7, 14, 28);

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
		add_filter('pmpro_email_days_before_expiration', array($this, 'get_days_before_expiration'));
	}

	function get_days_before_expiration()
	{
		$expiration = get_option('pmpro_days_before_expiration', 0);
		$next = $expiration + 1;
		$next >= count($this->days) and $next = 0;
		update_option('pmpro_days_before_expiration', $next);
		return $this->days[$expiration];
	}

	function reset_cron_expiration_warnings()
	{
		// clear existing cron job
		wp_clear_scheduled_hook('pmpro_cron_expiration_warnings');

		// set new cron job for each days
		foreach ($this->days as $key => $val) {
			wp_schedule_event(strtotime("+$key hours", current_time("timestamp")), 'daily', 'pmpro_cron_expiration_warnings');
		}
	}

    /*
        Activation/Deactivation
    */
    function activation()
    {
        //schedule crons
        $this->reset_cron_expiration_warnings();
    }

    function deactivation()
    {
        //remove crons
        wp_clear_scheduled_hook('pmpro_cron_expiration_warnings');
    }
}

//load pmpro renewals plugin
$pmpro_renewals = PMPro_Renewals::get_instance();

register_activation_hook(__FILE__, array($pmpro_renewals, 'activation'));
register_deactivation_hook(__FILE__, array($pmpro_renewals, 'deactivation'));
