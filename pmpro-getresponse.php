<?php
/*
Plugin Name: PMPro GetResponse
Plugin URI: http://www.paidmembershipspro.com/pmpro-getresponse/
Description: Sync your WordPress users and members with GetResponse campaigns.
Version: .1
Author: Stranger Studios
Author URI: http://www.strangerstudios.com
*/
/*
	Copyright 2011	Stranger Studios	(email : jason@strangerstudios.com)
	GPLv2 Full license details in license.txt
*/

/*	
	If PMPro is not installed:
	- New users should be subscribed to these campaigns: [ ]
	- Remove members from campaign when they unsubscribe/delete their account? [ ]
	
	If PMPro is installed:
	* All new users should be subscribed to these campaigns:
	* New users with no membership should be subscribed to these campaigns:
	* New users with membership # should be subscribed to these campaigns: 
	* (Show each level)		
	
	* Provide export for initial import?
*/

//init
function pmprogr_init()
{
	//include GetResponse Class if we don't have it already
	if(!class_exists("GetResponse"))
	{
		require_once(dirname(__FILE__) . "/includes/GetResponseAPI.class.php");
	}
	
	//get options for below
	$options = get_option("pmprogr_options");
	
	//setup hooks for new users	
	if(!empty($options['users_campaigns']))
		add_action("user_register", "pmprogr_user_register");
	
	//setup hooks for PMPro levels
	pmprogr_getPMProLevels();
	global $pmprogr_levels;
	
	if(!empty($pmprogr_levels))
	{		
		add_action("pmpro_after_change_membership_level", "pmprogr_pmpro_after_change_membership_level", 15, 2);
	}
}
add_action("init", "pmprogr_init", 0);

//use a different action if we are on the checkout page
function pmprogr_wp()
{
	if(is_admin())
		return;
		
	global $post;
	if(!empty($post->post_content) && strpos($post->post_content, "[pmpro_checkout]") !== false)
	{
		remove_action("pmpro_after_change_membership_level", "pmprogr_pmpro_after_change_membership_level");
		add_action("pmpro_after_checkout", "pmprogr_pmpro_after_checkout", 15);
		
	}
}
add_action("wp", "pmprogr_wp", 0);

//for when checking out
function pmprogr_pmpro_after_checkout($user_id)
{
	pmprogr_pmpro_after_change_membership_level(intval($_REQUEST['level']), $user_id);
}

//subscribe users when they register
function pmprogr_user_register($user_id)
{
	clean_user_cache($user_id);
	
	$options = get_option("pmprogr_options");
	
	//should we add them to any campaigns?
	if(!empty($options['users_campaigns']) && !empty($options['api_key']))
	{
		//get user info
		$campaign_user = get_userdata($user_id);
		
		//subscribe to each campaign
		$api = new GetResponse($options['api_key']);
		foreach($options['users_campaigns'] as $campaign)
		{					
			//subscribe them
			$api->addContact($campaign, trim($campaign_user->first_name . " " . $campaign_user->last_name), $campaign_user->user_email, 'standard', 0, apply_filters("pmpro_getresponse_custom_fields", array()));			
		}
	}
}

//subscribe new members (PMPro) when they register
function pmprogr_pmpro_after_change_membership_level($level_id, $user_id)
{
	clean_user_cache($user_id);
	
	global $pmprogr_levels;
	$options = get_option("pmprogr_options");
	$all_campaigns = get_option("pmprogr_all_campaigns");	
	
	$api = new GetResponse($options['api_key']);
		
	//should we add them to any campaigns?
	if(!empty($options['level_' . $level_id . '_campaigns']) && !empty($options['api_key']))
	{
		//get user info
		$campaign_user = get_userdata($user_id);		
		
		//delete contact to unsubscribe them from all campaigns
		$campaign_contacts = $api->getContactsByEmail($campaign_user->user_email);
		if(!empty($campaign_contacts))
		{
			foreach($campaign_contacts as $contact_id => $campaign_contact)
			$api->deleteContact($contact_id);
		}
		
		//subscribe to each campaign
		foreach($options['level_' . $level_id . '_campaigns'] as $campaign)
		{					
			//echo "<hr />Trying to subscribe to " . $campaign . "...";
			
			//subscribe them
			$api->addContact($campaign, trim($campaign_user->first_name . " " . $campaign_user->last_name), $campaign_user->user_email, 'standard', 0, apply_filters("pmpro_getresponse_custom_fields", array()));	
		}
	}
	elseif(!empty($options['api_key']) && count($options) > 3)
	{
		//now they are a normal user should we add them to any campaigns?
		if(!empty($options['users_campaigns']) && !empty($options['api_key']))
		{
			//get user info
			$campaign_user = get_userdata($user_id);
			
			//delete contact to unsubscribe them from all campaigns
			$campaign_contacts = $api->getContactsByEmail($campaign_user->user_email);
			if(!empty($campaign_contacts))
			{
				foreach($campaign_contacts as $contact_id => $campaign_contact)
				$api->deleteContact($contact_id);
			}
			
			//subscribe to each campaign
			foreach($options['users_campaigns'] as $campaign)
			{					
				//echo "<hr />Trying to subscribe to " . $campaign . "...";
				
				//subscribe them
				$api->addContact($campaign, trim($campaign_user->first_name . " " . $campaign_user->last_name), $campaign_user->user_email, 'standard', 0, apply_filters("pmpro_getresponse_custom_fields", array()));	
			}
		}
		else
		{
			//some memberships are on campaigns. assuming the admin intends this level to be unsubscribed from everything
			if(is_array($all_campaigns))
			{
				//get user info
				$campaign_user = get_userdata($user_id);
				
				//delete contact to unsubscribe them from all campaigns
				$campaign_contacts = $api->getContactsByEmail($campaign_user->user_email);
				if(!empty($campaign_contacts))
				{
					foreach($campaign_contacts as $contact_id => $campaign_contact)
					$api->deleteContact($contact_id);
				}
			}
		}
	}
}

//admin init. registers settings
function pmprogr_admin_init()
{
	//setup settings
	register_setting('pmprogr_options', 'pmprogr_options', 'pmprogr_options_validate');	
	add_settings_section('pmprogr_section_general', 'General Settings', 'pmprogr_section_general', 'pmprogr_options');	
	add_settings_field('pmprogr_option_api_key', 'GetResponse API Key', 'pmprogr_option_api_key', 'pmprogr_options', 'pmprogr_section_general');		
	add_settings_field('pmprogr_option_users_campaigns', 'All Users Campaign', 'pmprogr_option_users_campaigns', 'pmprogr_options', 'pmprogr_section_general');	
	//add_settings_field('pmprogr_option_double_opt_in', 'Require Double Opt-in?', 'pmprogr_option_double_opt_in', 'pmprogr_options', 'pmprogr_section_general');	
	
	//pmpro-related options	
	add_settings_section('pmprogr_section_levels', 'Membership Levels and Campaigns', 'pmprogr_section_levels', 'pmprogr_options');		
	
	//add options for levels
	pmprogr_getPMProLevels();
	global $pmprogr_levels;
	if(!empty($pmprogr_levels))
	{						
		foreach($pmprogr_levels as $level)
		{
			add_settings_field('pmprogr_option_memberships_campaigns_' . $level->id, $level->name, 'pmprogr_option_memberships_campaigns', 'pmprogr_options', 'pmprogr_section_levels', array($level));
		}
	}		
}
add_action("admin_init", "pmprogr_admin_init");

//set the pmprogr_levels array if PMPro is installed
function pmprogr_getPMProLevels()
{	
	global $pmprogr_levels, $wpdb;
	$pmprogr_levels = $wpdb->get_results("SELECT * FROM $wpdb->pmpro_membership_levels ORDER BY id");			
}

//options sections
function pmprogr_section_general()
{	
?>
<p></p>	
<?php
}

//options sections
function pmprogr_section_levels()
{	
	global $wpdb, $pmprogr_levels;
	
	//do we have PMPro installed?
	if(class_exists("MemberOrder"))
	{
	?>
		<p>PMPro is installed.</p>
	<?php
		//do we have levels?
		if(empty($pmprogr_levels))
		{
		?>
		<p>Once you've <a href="admin.php?page=pmpro-membershiplevels">created some levels in Paid Memberships Pro</a>, you will be able to assign GetResponse campaigns to them here.</p>
		<?php
		}
		else
		{
		?>
		<p>For each level below, choose the campaigns which should be subscribed to when a new user registers.</p>
		<?php
		}
	}
	else
	{
		//just deactivated or needs to be installed?
		if(file_exists(dirname(__FILE__) . "/../paid-memberships-pro/paid-memberships-pro.php"))
		{
			//just deactivated
			?>
			<p><a href="plugins.php?plugin_status=inactive">Activate Paid Memberships Pro</a> to add membership functionality to your site and finer control over your GetResponse campaigns.</p>
			<?php
		}
		else
		{
			//needs to be installed
			?>
			<p><a href="plugin-install.php?tab=search&type=term&s=paid+memberships+pro&plugin-search-input=Search+Plugins">Install Paid Memberships Pro</a> to add membership functionality to your site and finer control over your GetResponse campaigns.</p>
			<?php
		}
	}
}


//options code
function pmprogr_option_api_key()
{
	$options = get_option('pmprogr_options');		
	if(isset($options['api_key']))
		$api_key = $options['api_key'];
	else
		$api_key = "";
	echo "<input id='pmprogr_api_key' name='pmprogr_options[api_key]' size='80' type='text' value='" . esc_attr($api_key) . "' />";
}

function pmprogr_option_users_campaigns()
{	
	global $pmprogr_campaigns;
	$options = get_option('pmprogr_options');
		
	if(isset($options['users_campaigns']) && is_array($options['users_campaigns']))
		$selected_campaigns = $options['users_campaigns'];
	else
		$selected_campaigns = array();
	
	if(!empty($pmprogr_campaigns))
	{
		echo "<select multiple='yes' name=\"pmprogr_options[users_campaigns][]\">";
		foreach($pmprogr_campaigns as $key => $campaign)
		{
			echo "<option value='" . $key . "' ";
			if(in_array($key, $selected_campaigns))
				echo "selected='selected'";
			echo ">" . $campaign->name . "</option>";
		}
		echo "</select>";
	}
	else
	{
		echo "No campaigns found.";
	}	
}

function pmprogr_option_double_opt_in()
{
	$options = get_option('pmprogr_options');	
	?>
	<select name="pmprogr_options[double_opt_in]">
		<option value="0" <?php selected($options['double_opt_in'], 0);?>>No</option>
		<option value="1" <?php selected($options['double_opt_in'], 1);?>>Yes</option>		
	</select>
	<?php
}

function pmprogr_option_memberships_campaigns($level)
{	
	global $pmprogr_campaigns;
	$options = get_option('pmprogr_options');
	
	$level = $level[0];	//WP stores this in the first element of an array
		
	if(isset($options['level_' . $level->id . '_campaigns']) && is_array($options['level_' . $level->id . '_campaigns']))
		$selected_campaigns = $options['level_' . $level->id . '_campaigns'];
	else
		$selected_campaigns = array();
	
	if(!empty($pmprogr_campaigns))
	{
		echo "<select multiple='yes' name=\"pmprogr_options[level_" . $level->id . "_campaigns][]\">";
		foreach($pmprogr_campaigns as $key => $campaign)
		{
			echo "<option value='" . $key . "' ";
			if(in_array($key, $selected_campaigns))
				echo "selected='selected'";
			echo ">" . $campaign->name . "</option>";
		}
		echo "</select>";
	}
	else
	{
		echo "No campaigns found.";
	}	
}

// validate our options
function pmprogr_options_validate($input) 
{					
	//api key
	$newinput['api_key'] = trim(preg_replace("[^a-zA-Z0-9\-]", "", $input['api_key']));		
	$newinput['double_opt_in'] = intval($input['double_opt_in']);
	
	//user campaigns
	if(!empty($input['users_campaigns']) && is_array($input['users_campaigns']))
	{
		$count = count($input['users_campaigns']);
		for($i = 0; $i < $count; $i++)
			$newinput['users_campaigns'][] = trim(preg_replace("[^a-zA-Z0-9\-]", "", $input['users_campaigns'][$i]));	;
	}
	
	//membership campaigns
	global $pmprogr_levels;		
	if(!empty($pmprogr_levels))
	{
		foreach($pmprogr_levels as $level)
		{
			if(!empty($input['level_' . $level->id . '_campaigns']) && is_array($input['level_' . $level->id . '_campaigns']))
			{
				$count = count($input['level_' . $level->id . '_campaigns']);
				for($i = 0; $i < $count; $i++)
					$newinput['level_' . $level->id . '_campaigns'][] = trim(preg_replace("[^a-zA-Z0-9\-]", "", $input['level_' . $level->id . '_campaigns'][$i]));	;
			}
		}
	}
	
	return $newinput;
}		

// add the admin options page	
function pmprogr_admin_add_page() 
{
	add_options_page('PMPro GetResponse Options', 'PMPro GetResponse', 'manage_options', 'pmprogr_options', 'pmprogr_options_page');
}
add_action('admin_menu', 'pmprogr_admin_add_page');

//html for options page
function pmprogr_options_page()
{
	global $pmprogr_campaigns;
	
	//check for a valid API key and get campaigns
	$options = get_option("pmprogr_options");	
	$api_key = $options['api_key'];
	if(!empty($api_key))
	{
		/** Ping the GetResponse API to make sure this API Key is valid */
		$api = new GetResponse( $api_key );
		$ping = $api->ping();
		
		if($ping)		
		{
			/** Looks like there was an error */
			$msg = sprintf( __( 'Sorry, but GetResponse was unable to verify your API key.</p> Please try entering your API key again.', 'pmpro-GetResponse' ), $api->errorMessage );
			$msgt = "error";
			add_settings_error( 'pmpro-GetResponse', 'apikey-fail', $msg, 'error' );
		}
		else {						
			//get campaigns
			$pmprogr_campaigns = $api->getCampaigns();	
			$all_campaigns = array();
			
			//save all campaigns in an option
			$i = 0;			
			foreach ( $pmprogr_campaigns as $key => $campaign ) {
				$all_campaigns[$i]['id'] = $key;
				$all_campaigns[$i]['name'] = $campaign->name;
				$i++;
			}
			
			/** Save all of our new data */
			update_option( "pmprogr_all_campaigns", $all_campaigns);		
		}
	}
?>
<div class="wrap">
	<div id="icon-options-general" class="icon32"><br></div>
	<h2>PMPro GetResponse Integration Options</h2>		
	
	<?php if(!empty($msg)) { ?>
		<div class="message <?php echo $msgt; ?>"><p><?php echo $msg; ?></p></div>
	<?php } ?>
	
	<form action="options.php" method="post">
		
		<p>This plugin will integrate your site with GetResponse. You can choose one or more GetResponse campaigns to have users subscribed to when they signup for your site.</p>
		<p>If you have <a href="http://www.paidmembershipspro.com">Paid Memberships Pro</a> installed, you can also choose one or more GetResponse campaigns to have members subscribed to for each membership level.</p>
		<p>Don't have a GetResponse account? <a href="http://eepurl.com/k4aAH" target="_blank">Get one here</a>. It's free.</p>
		
		<?php settings_fields('pmprogr_options'); ?>
		<?php do_settings_sections('pmprogr_options'); ?>

		<p><br /></p>
						
		<div class="bottom-buttons">
			<input type="hidden" name="pmprot_options[set]" value="1" />
			<input type="submit" name="submit" class="button-primary" value="<?php esc_attr_e('Save Settings'); ?>">				
		</div>
		
	</form>
</div>
<?php
}
