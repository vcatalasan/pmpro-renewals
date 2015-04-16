<?php
/**
 * Created by PhpStorm.
 * User: gusgus
 * Date: 4/15/15
 * Time: 10:17 PM
 */

class PMPro_Renewals
{
    /*
        Activation/Deactivation
    */
    function activation()
    {
        //schedule crons
        wp_schedule_event(current_time('timestamp'), 'daily', 'pmpro_cron_expiration_warnings');
        //wp_schedule_event(current_time('timestamp')(), 'daily', 'pmpro_cron_trial_ending_warnings');		//this warning has been deprecated since 1.7.2
        wp_schedule_event(current_time('timestamp'), 'daily', 'pmpro_cron_expire_memberships');
        wp_schedule_event(current_time('timestamp'), 'monthly', 'pmpro_cron_credit_card_expiring_warnings');

        do_action('pmpro_renewals_activation');
    }

    function deactivation()
    {
        //remove crons
        wp_clear_scheduled_hook('pmpro_cron_expiration_warnings');
        wp_clear_scheduled_hook('pmpro_cron_trial_ending_warnings');
        wp_clear_scheduled_hook('pmpro_cron_expire_memberships');
        wp_clear_scheduled_hook('pmpro_cron_credit_card_expiring_warnings');

        do_action('pmpro_renewals_deactivation');
    }
}

register_activation_hook(__FILE__, array('PMPro_Renewals', 'activation'));
register_deactivation_hook(__FILE__, array('PMPro_Renewals', 'deactivation'));
