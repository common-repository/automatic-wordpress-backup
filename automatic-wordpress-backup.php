<?php
/*
Plugin Name: Automatic WordPress Backup
Plugin URI: http://www.webdesigncompany.net/automatic-wordpress-backup/
Description: Automatically upload backups of important parts of your blog to Amazon S3
Version: 2.1-dev
Author: Dan Coulter
Author URI: http://dancoulter.com/
*/ 

/*
 * Copyright 2009, Dan Coulter - http://co.deme.me | dan@dancoulter.com
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.

 * You should have received a copy of the GNU General Public License
 * along with this program; if not, write to the Free Software
 * Foundation, Inc., 59 Temple Place, Suite 330, Boston, MA  02111-1307  USA
 */

/**
 * @package automatic-wordpress-backup
 */

if ( !defined('WP_CONTENT_URL') )
	define( 'WP_CONTENT_URL', get_option('siteurl') . '/wp-content');
if ( !defined('WP_CONTENT_DIR') )
	define( 'WP_CONTENT_DIR', ABSPATH . 'wp-content' );
	
	
require_once 'wdc/wdc.class.php';

class cmAWB {
	static $version = '2.1-dev';

	/**
	 * Uses the init action to catch changes in the schedule and pass those on to the scheduler.
	 *
	 */
	function init() {
		if ( isset($_POST['s3b-schedule']) ) {
			$schedules = array(
				'daily' => 86400,
				'weekly' => 604800,
				'monthly' => 2592000,
			);
			
			if ( $_POST['s3b-schedule'] != 'disabled' ) {
				if ( get_option('s3b-schedule') != $_POST['s3b-schedule'] ) {
					wp_clear_scheduled_hook('s3-backup');
					wp_schedule_event(time() + $schedules[$_POST['s3b-schedule']], $_POST['s3b-schedule'], 's3-backup', array(false));
				}
			} else {
				wp_clear_scheduled_hook('s3-backup', false);
			}
			
			if ( $_POST['Submit'] == 'Save Changes and Backup Now' ) {
				wp_schedule_single_event(time(), 's3-backup', array(true));
			}
		}
		
		$settings = self::get_settings();
		if ( isset($_POST['s3-new-bucket']) && !empty($_POST['s3-new-bucket']) ) {
			include_once 'S3.php';
			$_POST['s3-new-bucket'] = strtolower($_POST['s3-new-bucket']);
			$s3 = new S3($settings['access-key'], $settings['secret-key']); 
			$s3->putBucket($_POST['s3-new-bucket']);
			$buckets = $s3->listBuckets();
			if ( is_array($buckets) && in_array($_POST['s3-new-bucket'], $buckets) ) {
				update_option('s3b-bucket', $_POST['s3-new-bucket']);
				$_POST['s3b-bucket'] = $_POST['s3-new-bucket'];
			} else {
				update_option('s3b-bucket', null);
				$_POST['s3b-bucket'] = null;
			}
		}
		if ( !$settings['access-key'] ) add_action('admin_notices', array('cmAWB','accessKeyWarning'));
		elseif ( !get_option('s3b-bucket') ) add_action('admin_notices', array('cmAWB','newBucketWarning'));
	}
	
	function setup() {
		require_once ABSPATH . '/wp-admin/includes/class-wp-filesystem-base.php';
		require_once ABSPATH . '/wp-admin/includes/class-wp-filesystem-direct.php';
		
		$fsd = new WP_Filesystem_Direct(1);
		$awb_dir = trailingslashit($fsd->wp_content_dir()) . '/uploads/awb';
		
		if ( !$fsd->exists($fsd->wp_content_dir() . '/uploads') ) {
			if ( !$fsd->mkdir($fsd->wp_content_dir() . '/uploads') ) {
				return false;
			}
			$fsd->chmod($fsd->wp_content_dir() . '/uploads', 0755);
		}
		if ( !$fsd->exists($awb_dir) ) {
			if ( !$fsd->mkdir($awb_dir) ) {
				return false;
			}
			$fsd->chmod($awb_dir, 0755);
		}
		if ( !$fsd->exists($awb_dir . '/.htaccess') ) {
			if ( !$fsd->put_contents($awb_dir . '/.htaccess', 'deny from all') ) {
				return false;
			}
			$fsd->chmod($awb_dir . '/.htaccess', 0755);
		}
		
		if ( !$fsd->touch(trailingslashit($awb_dir) . 'awb_test.txt') ) {
			return false;
		} else {
			$fsd->delete(trailingslashit($awb_dir) . 'awb_test.txt');
		}
		
		$settings = self::get_settings();
		
		if ( !is_array($settings) || !isset($settings['zip']) || $settings['zip']) {
			define('AWB_ZIP', true);
		} else {
			define('AWB_ZIP', false);
		}


		return true;
	}
	
	function newBucketWarning() {
		echo "<div id='awb-warning' class='updated fade'><p><strong>".__('Automatic WordPress Backup: You need to select a valid S3 bucket.', 'automatic-wordpress-backup')."</strong> ".__('If you tried to create a new bucket, it may have been an invalid name.', 'automatic-wordpress-backup').' | <a href="' . self::get_plugin_page() . '">'.__('Plugin Settings', 'automatic-wordpress-backup').'</a></p></div>';
	}

	function accessKeyWarning() {
		echo "<div id='awb-warning' class='updated fade'><p>".__('Automatic WordPress Backup: You need to enter your Amazon AWS Access Key and Secret Key.', 'automatic-wordpress-backup')."</strong> ".' | <a href="' . self::get_plugin_page() . '">'.__('Plugin Settings', 'automatic-wordpress-backup').'</a></p></div>';

	}

	/**
	 * Return the filesystem path that the plugin lives in.
	 *
	 * @return string
	 */
	function getPath() {
		return dirname(__FILE__) . '/';
	}
	
	/**
	 * Returns the URL of the plugin's folder.
	 *
	 * @return string
	 */
	function getURL() {
		return WP_CONTENT_URL.'/plugins/'.basename(dirname(__FILE__)) . '/';
	}
	
	/**
	 * Returns the URL of the plugin's settings page.
	 *
	 * @return string
	 */
	function get_plugin_page($full = false) {
		global $wp_version;
		if ( !$full ) {
			return 'admin.php?page=' . plugin_basename(__FILE__);
		}
	}

	/**
     * Sets up the settings page
     *
     */
	function add_settings_page() {
		load_plugin_textdomain('automatic-wordpress-backup', cmAWB::getPath() . 'i18n');
		add_submenu_page(apply_filters('wdc-settings-url', 'volcanic-credits'), __('Automatic Backup', 'automatic-wordpress-backup'), __('Automatic Backup', 'automatic-wordpress-backup'), 8, __FILE__, array('cmAWB', 'settings_page'));	
	}
	
	/**
	 * Generates the settings page
	 *
	 */
	function settings_page() {
		include_once 'S3.php';
		$sections = get_option('s3b-section');
		if ( !$sections ) {
			$sections = null;
		}
		$settings = self::get_settings();
		
		$pass = true;
		$reqs = array(
			'os' => array(
				'name' => 'Server OS',
				'required' => 'Linux',
			),
			'php' => array(
				'name' => 'PHP Version',
				'required' => '5.1+',
			),
			'uploads' => array(
				'name' => 'Uploads Folder',
				'required' => '',
			),
			'curl' => array(
				'name' => 'curl',
				'required' => '',
			),
			'shell' => array(
				'name' => 'shell_exec',
				'required' => '',
			),
			'zip' => array(
				'name' => 'zip',
				'required' => '',
			),
		);
		
		if ( strpos($_SERVER['DOCUMENT_ROOT'], '/') === 0 ) {
			$reqs['os']['status'] = 'Linux (or compatible)';
			$reqs['os']['pass'] = true;
		} else {
			$reqs['os']['status'] = 'Windows';
			$reqs['os']['pass'] = false;
			$pass = false;
		}
		
		$reqs['php']['status'] = phpversion();
		if ( (float) phpversion() >= 5.1 ) {
			$reqs['php']['pass'] = true;
		} else {
			$reqs['php']['pass'] = false;
			$pass = false;
		}
		
		if ( !self::setup() ) {
			$fsd = new WP_Filesystem_Direct(1);
			if ( !$fsd->exists($fsd->wp_content_dir() . '/uploads') ) {
				$reqs['uploads']['status'] = 'Does not exist';
			} else {
				$reqs['uploads']['status'] = 'Not writeable';
			}
			$reqs['uploads']['pass'] = false;
			$pass = false;
		} else {
			$reqs['uploads']['status'] = 'Exists and writeable';		
			$reqs['uploads']['pass'] = true;
		}

		if ( is_null(@shell_exec('ls')) ) {
			$reqs['shell']['status'] = 'Disabled';		
			$reqs['shell']['pass'] = false;
			$reqs['zip']['status'] = 'Unknown';		
			$reqs['zip']['pass'] = false;
			$pass = false;
		} elseif ( is_null(shell_exec('which zip')) ) {
			$reqs['shell']['status'] = 'Enabled';		
			$reqs['shell']['pass'] = true;
			$reqs['zip']['status'] = 'Not found';		
			$reqs['zip']['pass'] = false;
			$pass = false;
		} else {
			$reqs['shell']['status'] = 'Enabled';		
			$reqs['shell']['pass'] = true;
			$reqs['zip']['status'] = 'Installed';		
			$reqs['zip']['pass'] = true;
		}
		
		if ( function_exists('curl_init') ) {
			$reqs['curl']['status'] = 'Installed';		
			$reqs['curl']['pass'] = true;
		} else {
			$reqs['curl']['status'] = 'Not found';		
			$reqs['curl']['pass'] = false;
			$pass = false;
		}

		if ( !$pass ) {
			echo "<div id='awb-warning' class='updated fade'><p>".__("It looks like all the requirements for this plugin are not being met so it will probably not work. <a href='#requirements'>Click here</a> for details.", 'automatic-wordpress-backup')."</p></div>";
		}
		
		?>
			<script type="text/javascript">
				var ajaxTarget = "<?php echo self::getURL() ?>backup.ajax.php";
				var nonce = "<?php echo wp_create_nonce('automatic-wordpress-backup'); ?>";
			</script>
			<style type="text/css">
				#panels .panel {
					display: none;
					border: 1px solid #666;
					width: 600px;
					padding: .5em;
					clear: both;
				}

				#panels .panel.selected {
					display: block;
				}
				
				#tabs {
					clear: both;
					border-left: 1px solid #666;
					height: 28px;
					margin-bottom: -1px;
				}
				
				#tabs li {
					float: left;
					line-height: 26px;
					border-top: 1px solid #666;
					border-right: 1px solid #666;
					padding: 0px .5em;
					margin-bottom: -1px;
					cursor: pointer;
				}
				
				#tabs .tab.selected {
					font-weight: bold;
					border-bottom: 1px solid #F9F9F9;
				}

				.requirement .status {
					font-weight: bold;
				}
				.requirement.pass .status {
					color: green;
				}
				.requirement.fail .status {
					color: red;
				}
			</style>
			<div class="wrap">
			<pre>
<?php
/*
		$s3 = new S3($settings['access-key'], $settings['secret-key']); 		
		$site = 'awb/' . next(explode('//', get_bloginfo('siteurl')));
		print_r($s3->getBucket('danco-wordpress-test', 'awb'));
//*/
?></pre>

				<h2><?php _e('Automatic WordPress Backup', 'automatic-wordpress-backup') ?></h2>
				<?php if ( isset($_GET['updated']) ) : ?>
					<div class='updated fade'><p><?php _e('Settings saved.', 'automatic-wordpress-backup') ?></p></div>
					<?php if ( is_null(get_option('s3b-bucket')) ) : ?>
						<div class='error fade'><p><?php _e('No bucket was created.  You may have entered a bucket name that already exists on another account.', 'automatic-wordpress-backup') ?></p></div>
					<?php endif; ?>

				<?php endif; ?>

				<form method="post" action="options.php" id="settings-form">
					<input type="hidden" name="action" value="update" />
					<?php wp_nonce_field('update-options'); ?>
					<input type="hidden" name="page_options" value="s3b-access-key,s3b-secret-key,s3b-bucket,s3b-section,s3b-schedule,awb-settings,wdc_credits" />
					<p>
						<?php _e('AWS Access Key:', 'automatic-wordpress-backup') ?>
						<input type="text" name="awb-settings[access-key]" value="<?php echo (defined('AWB_ACCESS_KEY') ? 'DEFINED IN CONFIG' : $settings['access-key']); ?>" <?php if ( defined('AWB_ACCESS_KEY') ) echo 'readonly="readonly"' ?> />
					</p>
					<p>
						<?php _e('AWS Secret Key:', 'automatic-wordpress-backup') ?>
						<input type="password" name="awb-settings[secret-key]" value="<?php echo (defined('AWB_SECRET_KEY') ? 'DEFINED IN CONFIG' : $settings['secret-key']); ?>" <?php if ( defined('AWB_SECRET_KEY') ) echo 'readonly="readonly"' ?> />
					</p>
					<?php if ( $settings['access-key'] && $settings['secret-key'] ) : ?>
						<?php 
							$s3 = new S3($settings['access-key'], $settings['secret-key']); 
							$buckets = $s3->listBuckets();
						?>
						<p <?php if ( get_option('s3b-bucket') === false || get_option('s3b-bucket') == '' ) echo 'class="error"' ?>>
							<span style="vertical-align: middle;"><?php _e('S3 Bucket Name:', 'automatic-wordpress-backup') ?></span>
							<select name="s3b-bucket">
								<?php foreach ( $buckets as $b ) : ?>
									<option <?php if ( $b == get_option('s3b-bucket') ) echo 'selected="selected"' ?>><?php echo $b ?></option>
								<?php endforeach; ?>
							</select>
							
							<br />
							<span style="vertical-align: middle;"><?php _e('Or create a bucket:', 'automatic-wordpress-backup') ?></span>
							<input type="text" name="s3-new-bucket" id="new-s3-bucket" value="" /> <?php _e('Only use letters, numbers and dashes. Do not use periods or spaces.', 'automatic-wordpress-backup') ?>
							
						</p>
						<p>
							<span style="vertical-align: middle;"><?php _e('Backup schedule:', 'automatic-wordpress-backup') ?></span>
							<select name="s3b-schedule">
								<?php foreach ( array('Disabled','Daily','Weekly','Monthly') as $s ) : ?>
									<option value="<?php echo strtolower($s) ?>" <?php if ( strtolower($s) == get_option('s3b-schedule') || ((get_option('s3b-schedule') === false || get_option('s3b-schedule') == '') && $s == 'Daily') ) echo 'selected="selected"' ?>><?php echo $s ?></option>
								<?php endforeach; ?>
							</select>
						</p>
						<p>
							<?php _e('Parts of your blog to back up', 'automatic-wordpress-backup') ?><br />
							<label for="s3b-section-config">
								<input <?php if ( is_null($sections) || in_array('config', (array) $sections) ) echo 'checked="checked"' ?> type="checkbox" name="s3b-section[]" value="config" id="s3b-section-config" />
								<?php _e('Config file and htaccess', 'automatic-wordpress-backup') ?>
							</label><br />
							<label for="s3b-section-database">
								<input <?php if ( is_null($sections) || in_array('database', (array) $sections) ) echo 'checked="checked"' ?> type="checkbox" name="s3b-section[]" value="database" id="s3b-section-database" />
								<?php _e('Database dump', 'automatic-wordpress-backup') ?>
							</label><br />
							<label for="s3b-section-themes">
								<input <?php if ( is_null($sections) || in_array('themes', (array) $sections) ) echo 'checked="checked"' ?> type="checkbox" name="s3b-section[]" value="themes" id="s3b-section-themes" />
								<?php _e('Themes folder', 'automatic-wordpress-backup') ?>
							</label><br />
							<label for="s3b-section-plugins">
								<input <?php if ( is_null($sections) || in_array('plugins', (array) $sections) ) echo 'checked="checked"' ?> type="checkbox" name="s3b-section[]" value="plugins" id="s3b-section-plugins" />
								<?php _e('Plugins folder', 'automatic-wordpress-backup') ?>
							</label><br />
							<?php do_action('s3b_sections') ?>
							<label for="s3b-section-uploads">
								<input <?php if ( is_null($sections) || in_array('uploads', (array) $sections) ) echo 'checked="checked"' ?> type="checkbox" name="s3b-section[]" value="uploads" id="s3b-section-uploads" />
								<?php _e('Uploaded content', 'automatic-wordpress-backup') ?>
							</label><br />
						</p>
						<p>
							<div>
								<div style="float: left; margin-top: 2px;"><input type="radio" id="awb-settings-zip-yes" name="awb-settings[zip]" value="1" <?php if ( AWB_ZIP ) echo 'checked="checked"' ?> /></div>
								<div style="margin-left: 1.5em"><label for="awb-settings-zip-yes"><?php _e('Run zip-based backups. These backups will be compressed and easier to download without a script or an installation of this plugin from Amazon S3, but will take up more space in the long run.', 'automatic-wordpress-backup') ?></label></div>
							</div>
							<div>
								<div style="float: left; margin-top: 2px;"><input type="radio" id="awb-settings-zip-no" name="awb-settings[zip]" value="0" <?php if ( !AWB_ZIP ) echo 'checked="checked"' ?> /></div>
								<div style="margin-left: 1.5em"><label for="awb-settings-zip-no"><?php _e('Upload individual files to Amazon S3. The initial upload will take more time and will use more space. Subsequent uploads will use less space and bandwidth, but will be more difficult to download as a whole without the use of this plugin.', 'automatic-wordpress-backup') ?></label></div>
							</div>
						</p>
						<div id="zip-options" <?php if ( !AWB_ZIP ) echo 'style="display: none"'?>>
							<div style="margin-top: 1em;">
								<label><input type="checkbox" id="awb-settings-cleanup" name="awb-settings[cleanup]" value="1" <?php if ( is_array($settings) && isset($settings['cleanup']) && $settings['cleanup']) echo 'checked="checked"' ?> /> <?php _e('Delete backups older than one month', 'automatic-wordpress-backup') ?></label>
								<div id="cleanup-settings" style="padding-left: 1em;">
									<label><input type="checkbox" name="awb-settings[cleanup-save-monthly]" value="1" <?php if ( is_array($settings) && isset($settings['cleanup-save-monthly']) && $settings['cleanup-save-monthly']) echo 'checked="checked"' ?> /> <?php _e('Keep a monthly backup for one year', 'automatic-wordpress-backup') ?></label><br />
									<label><input type="checkbox" name="awb-settings[cleanup-save-manual]" <?php if ( is_array($settings) && isset($settings['cleanup-save-manual']) && $settings['cleanup-save-manual']) echo 'checked="checked"' ?> value="1" /> <?php _e('Keep manual backups forever', 'automatic-wordpress-backup') ?></label>
								</div>
							</div>
						</div>
					<?php endif; ?>
					
					<?php if ( get_option('wdc_credits') === "none" || get_option('wdc_credits') === "0" || get_option('wdc_credits') === "none" || get_option('wdc_credits') === false ) : ?>
						<h4>How would you like to support us?</h4>
						<p>
							We've put some serious time, money and energy into making this plugin and are constantly improving it to ensure you have a safe website. In order to help keep the plugin free and constantly improving, we ask that you do your part by supporting us by allowing us to add a short credits link into the footer or by writing a review of plugin when you're ready.
						</p>
						<p>
							<label for="wdc_credits_all"><input type="radio" id="wdc_credits_all" name="wdc_credits" value="all" <?php if ( get_option('wdc_credits') === "all" || get_option('wdc_credits') === "1" ) echo 'checked="checked"' ?> /> Add a link short credits in footer of all pages on my site</label><br />
							<label for="wdc_credits_home"><input type="radio" id="wdc_credits_home" name="wdc_credits" value="home" <?php if ( get_option('wdc_credits') === "home" ) echo 'checked="checked"' ?> /> Add a link short credits in footer of just the home page</label><br />
							<label for="wdc_credits_none"><input type="radio" id="wdc_credits_none" name="wdc_credits" value="none" <?php if ( get_option('wdc_credits') === "none" || get_option('wdc_credits') === "0" ) echo 'checked="checked"' ?> /> I'll show my support by writing a review of the plugin when I'm ready. (Please email us at <a href="mailto:awb@webdesigncompany.net">awb@webdesigncompany.net</a> if you do write a review. We'd like to read what you write.)</label>
						</p>
						<p>
							PS: If the default credit message doesn't look right in your theme, <br />
							<a href="mailto:mr@webdesigncompany.net?subject=Customize Credits&body=Make the credits link look good.">email us</a> and we'll make it look great at no cost to you. <br />
						</p>
					<?php endif ?>
					
					<p class="submit">
						<input type="submit" name="Submit" value="<?php _e('Save Changes', 'automatic-wordpress-backup') ?>" />
						<input type="submit" id="backup-now" name="Submit" value="<?php _e('Save Changes and Backup Now', 'automatic-wordpress-backup') ?>" />
					</p>
					<p id="awb-running">
						<?php 
							$crons = _get_cron_array();
							foreach ( $crons as $cron ) {
								if ( isset($cron['s3-backup']) ) {
									$cron = current($cron['s3-backup']);
									if ( $cron['args'][0] ) {
										printf(__("Your manual backup has started. If it hasn't yet, it will show up below once it's completed <a href='%s'>the next time you view this page.</a>", 'automatic-wordpress-backup'), self::get_plugin_page() );
										break;
									}
								}
							}
						?>
					</p>
				</form>
				
				<ul id="tabs">
					<li id="download" <?php if ( !AWB_ZIP ) echo 'class="tab" style="display: none"'; else echo 'class="tab selected"';?>><?php _e('Download recent backups') ?></li>
					<li id="restore" class="tab" <?php if ( !AWB_ZIP ) echo 'style="display: none"'?>><?php _e('Restore from a backup') ?></li>
					<li id="restore2" <?php if ( AWB_ZIP ) echo 'class="tab" style="display: none"'; else echo 'class="tab selected"';?>><?php _e('Restore from a backup') ?></li>
				</ul>
				<div id="panels">
					<div class="panel download <?php if ( AWB_ZIP ) echo 'selected' ?>">
						<?php 
							if ( get_option('s3b-bucket') ) {
								$backups = $s3->getBucket(get_option('s3b-bucket'), 'awb/' . next(explode('//', get_bloginfo('siteurl'))));
								krsort($backups);
								$count = 0;
								foreach ( $backups as $key => $backup ) {
									$backup['label'] = sprintf(__('WordPress Backup from %s', 'automatic-wordpress-backup'), mysql2date(__('F j, Y h:i a'), date('Y-m-d H:i:s', $backup['time'] + (get_option('gmt_offset') * 3600))));
									
									if ( preg_match('|[0-9]{4}\.zip$|', $backup['name']) ) {
										$backup['label'] = sprintf(__('Manual WordPress Backup from %s', 'automatic-wordpress-backup'), mysql2date(__('F j, Y h:i a'), date('Y-m-d H:i:s', $backup['time'] + (get_option('gmt_offset') * 3600))));
									} elseif ( preg_match('|[0-9]{4}\.uploads\.zip$|', $backup['name']) ) {
										$backup['label'] = sprintf(__('Manual Uploads Backup from %s', 'automatic-wordpress-backup'), mysql2date(__('F j, Y h:i a'), date('Y-m-d H:i:s', $backup['time'] + (get_option('gmt_offset') * 3600))));
									} elseif ( preg_match('|\.uploads\.zip$|', $backup['name']) ) {
										$backup['label'] = sprintf(__('Uploads Backup from %s', 'automatic-wordpress-backup'), mysql2date(__('F j, Y h:i a'), date('Y-m-d H:i:s', $backup['time'] + (get_option('gmt_offset') * 3600))));
									}
									
									$backup = apply_filters('s3b-backup-item', $backup);
									
									if ( ++$count > 40 ) break;
									?>
										<div class="backup"><a href="<?php echo $s3->getObjectURL(get_option('s3b-bucket'), $backup['name']) ?>"><?php echo $backup['label'] ?></a></div>
									<?php
								}
							}
						?>
					</div>
					<div class="restore panel">
						<p><b><?php _e('Select a backup') ?></b></p>
						<p>
							<a href="#" id="view_all_trigger"><span><?php _e('View all backups in this bucket') ?></span><span class="hidden"><?php _e('View only backups from this site') ?></span></a>
						</p>
						<?php 
							if ( get_option('s3b-bucket') ) {
								$count = 0;
								$all_backups = $s3->getBucket(get_option('s3b-bucket'), 'awb/');
								krsort($all_backups);
								echo '<div id="backups_to_restore"><div>'; 
								foreach ( $backups as $key => $backup ) {
									$backup['label'] = sprintf(__('WordPress Backup from %s', 'automatic-wordpress-backup'), mysql2date(__('F j, Y h:i a'), date('Y-m-d H:i:s', $backup['time'] + (get_option('gmt_offset') * 3600))));
									
									if ( preg_match('|[0-9]{4}\.zip$|', $backup['name']) ) {
										$backup['label'] = sprintf(__('Manual WordPress Backup from %s', 'automatic-wordpress-backup'), mysql2date(__('F j, Y h:i a'), date('Y-m-d H:i:s', $backup['time'] + (get_option('gmt_offset') * 3600))));
									} elseif ( preg_match('|[0-9]{4}\.uploads\.zip$|', $backup['name']) ) {
										$backup['label'] = sprintf(__('Manual Uploads Backup from %s', 'automatic-wordpress-backup'), mysql2date(__('F j, Y h:i a'), date('Y-m-d H:i:s', $backup['time'] + (get_option('gmt_offset') * 3600))));
									} elseif ( preg_match('|\.uploads\.zip$|', $backup['name']) ) {
										$backup['label'] = sprintf(__('Uploads Backup from %s', 'automatic-wordpress-backup'), mysql2date(__('F j, Y h:i a'), date('Y-m-d H:i:s', $backup['time'] + (get_option('gmt_offset') * 3600))));
									}
									
									$backup = apply_filters('s3b-backup-item', $backup);
									
									if ( ++$count > 40 ) break;
									?>
										<div class="backup"><label for="<?php echo $key ?>"><input id="<?php echo $key ?>" type="radio" name="restore-backup" value="<?php echo $backup['name'] ?>" /> <?php echo $backup['label'] ?></label></div>
									<?php
								}
								echo "</div><div class='hidden'>";
								$current_site = '';
								foreach ( $all_backups as $key => $backup ) {
									$backup['site'] = substr($backup['name'], 4, strrpos($backup['name'], '/') - 4);
									if ( $backup['site'] != $current_site ) {
										$current_site = $backup['site'];
										echo '<div><strong>Backups from '. $backup['site'] . '</strong></div>';
									}
									$backup['label'] = sprintf(__('Backup on %s'), mysql2date(__('F j, Y h:i a'), date('Y-m-d H:i:s', $backup['time'] + (get_option('gmt_offset') * 3600))));
									
									$backup = apply_filters('s3b-backup-item', $backup);
									
									if ( ++$count > 40 ) break;
									?>
										<div class="backup" style="padding-left: 20px;"><label for="<?php echo $key ?>"><input id="<?php echo $key ?>" type="radio" name="restore-backup" value="<?php echo $backup['name'] ?>" /> <?php echo $backup['label'] ?></label></div>
									<?php
								}
								echo "</div></div>";
							}
						?>
						<p><?php _e('<b>Warning!</b> Doing this will overwrite files and your database (depending on what was backed up).  A manual backup will be run prior to the restore.') ?></p>
						<p>
							<div style="float: left; padding-top: 2px;">
								<input type="checkbox" checked="checked" id="use_existing_db" name="use_existing_db" value="1" />
							</div>
							<div style="margin-left: 1.5em;"><label for="use_existing_db">
								<?php _e('Keep your current database settings by overwriting the settings (except the database prefix) in the backed up config file (if applicable).') ?>
							</label></div>
						</p>
						<p class="submit"><button id="restore-trigger" class="button"><?php _e('Restore from backup') ?></button></p>
					</div>
					<div class="restore2 panel <?php if ( !AWB_ZIP ) echo 'selected' ?>">
						<p><b><?php _e('Select a backup') ?></b></p>
						<?php if ( get_option('s3b-bucket') ) : ?>
							<?php 
								$manifest = $s3->getBucketVersions(get_option('s3b-bucket'), 'awb/' . next(explode('//', get_bloginfo('siteurl'))) . '-fbf/manifest.txt');
								foreach ( $manifest['awb/' . next(explode('//', get_bloginfo('siteurl'))) . '-fbf/manifest.txt'] as $time => $version ) {
									if ( !$version['deleteMarker'] ) {
										?>
											<div class="backup" style="padding-left: 20px;"><label for="fbf-<?php echo $time ?>"><input id="fbf-<?php echo $time ?>" type="radio" name="restore-backup" value="<?php echo $time ?>" /> WordPress Backup from <?php echo mysql2date(__('F j, Y h:i a'), date('Y-m-d H:i:s', $time + (get_option('gmt_offset') * 3600))) ?></label></div>
										<?php
									}
								}

							?>
							<?php if ( count($manifest) ) : ?>
								<p><?php _e('<b>Warning!</b> Doing this will overwrite files and your database (depending on what was backed up).  A manual backup will be run prior to the restore.') ?></p>
								<p>
									<div style="float: left; padding-top: 2px;">
										<input type="checkbox" checked="checked" id="use_existing_db" name="use_existing_db" value="1" />
									</div>
									<div style="margin-left: 1.5em;"><label for="use_existing_db">
										<?php _e('Keep your current database settings by overwriting the settings (except the database prefix) in the backed up config file (if applicable).') ?>
									</label></div>
								</p>
								<p class="submit"><button id="restore_fbf-trigger" class="button"><?php _e('Restore from backup') ?></button></p>
							<?php endif ?>
						<?php endif; ?>
					</div>
				</div>
				<div>
					<a name="requirements"></a>
					<h3>Plugin Requirements</h3>
					<table>
						<thead>
							<tr>
								<th>Name</th>
								<th>Required</th>
								<th>Status</th>
							</tr>
						</thead>
						<tbody>
							<?php foreach ( $reqs as $slug => $r ) : ?>
								<tr class="requirement <?php echo $r['pass'] ? 'pass' : 'fail' ?>" id="req-<?php echo $slug ?>">
									<td class="name"><?php echo $r['name'] ?></td>
									<td class="required"><?php echo $r['required'] ?></td>
									<td class="status"><?php echo $r['status'] ?></td>
								</tr>
							<?php endforeach ?>

						</tbody>
					</table>
								
				</div>
			</div>
			<script type="text/javascript">				
				;(function($){
					$("#settings-form").submit(function(){
						if ( $("input[name=wdc_credits]").length && !$("input[name=wdc_credits]:checked").length ) {
							alert("Please select whether you'd like to insert a credit link");
							return false;
						}
					});
				
					toggle_zip = function() {
						if ( $("input[name=awb-settings\[zip\]]:checked").val() == "1" ) {
							$("#zip-options").show();
							$("#restore, #download").show();
							$("#download").click();
							$("#restore2").hide();
						} else {
							$("#zip-options").hide();
							$("#restore, #download").hide();
							$("#restore2").show().click();
						}
					}

					$("input[name=awb-settings\[zip\]]").click(toggle_zip);

					$().ready(function(){
					
						$("#view_all_trigger").click(function(){
							$("#view_all_trigger span, #backups_to_restore > div").toggleClass("hidden");
							return false;
						});
						$(".tab").click(function(){
							id = $(this).attr("id");
							$(".tab.selected").removeClass("selected");
							$(this).addClass("selected");
							$(".panel.selected").removeClass("selected");
							$(".panel." + id).addClass("selected");
						});
						
						$("#restore-trigger").click(function(){
							if ( confirm('<?php _e('Are you SURE that you want to restore from this backup?') ?>') ) {
								$(this).attr("disabled", true).after('<p class="updated">The restore is running.  Please be patient. This page will refresh once the restore has completed.</p>');
								var data = {
									action: 'awb_restore',
									backup: $('input[name=restore-backup]:checked').val(),
									use_existing_db: $("#use_existing_db:checked").length
								};
								if ( $("#ftp-hostname").length ) {
									data.hostname = $("#ftp-hostname").val();
									data.username = $("#ftp-username").val();
									data.password = $("#ftp-password").val();
									data.method = $("#ftp-method").val();
								}
								$.post(
									"<?php echo wp_nonce_url('admin-ajax.php', 'awb') ?>", 
									data, 
									function(rsp){
										if ( rsp.success == false && rsp.error == "Insufficient Permissions" ) {
											$(".restore.panel p.submit").before("<div class='error fade'><p><?php _e('To restore from this backup, you will need to enter your FTP login credentials.', 'automatic-wordpress-backup') ?></p></div>");
											$(".restore.panel p.submit").before("<div><p><?php _e('FTP host name:', 'automatic-wordpress-backup') ?> <input type='text' id='ftp-hostname' name='hostname' value='localhost' /></p></div>");
											$(".restore.panel p.submit").before("<div><p><?php _e('FTP user name:', 'automatic-wordpress-backup') ?> <input type='text' id='ftp-username' name='username' /></p></div>");
											$(".restore.panel p.submit").before("<div><p><?php _e('FTP password:', 'automatic-wordpress-backup') ?> <input type='password' id='ftp-password' name='password' /></p></div>");
											$(".restore.panel p.submit").before("<input type='hidden' id='ftp-method' name='method' value='" + rsp.method + "' />");
										} else {
											window.location.reload();
										}
									}, 
									'json'
								);
							}
						});
						
						$("#restore_fbf-trigger").click(function(){
							if ( confirm('<?php _e('Are you SURE that you want to restore from this backup?') ?>') ) {
								//$(this).attr("disabled", true).after('<p class="updated">The restore is running.  Please be patient. This page will refresh once the restore has completed.</p>');
								var data = {
									action: 'awb_restore',
									fbf: '1',
									backup: $('input[name=restore-backup]:checked').val(),
									use_existing_db: $("#use_existing_db:checked").length
								};
								if ( $("#ftp-hostname").length ) {
									data.hostname = $("#ftp-hostname").val();
									data.username = $("#ftp-username").val();
									data.password = $("#ftp-password").val();
									data.method = $("#ftp-method").val();
								}
								$.post(
									"<?php echo wp_nonce_url('admin-ajax.php', 'awb') ?>", 
									data, 
									function(rsp){
										if ( rsp.success == false && rsp.error == "Insufficient Permissions" ) {
											$(".restore2.panel p.submit").before("<div class='error fade'><p><?php _e('To restore from this backup, you will need to enter your FTP login credentials.', 'automatic-wordpress-backup') ?></p></div>");
											$(".restore2.panel p.submit").before("<div><p><?php _e('FTP host name:', 'automatic-wordpress-backup') ?> <input type='text' id='ftp-hostname' name='hostname' value='localhost' /></p></div>");
											$(".restore2.panel p.submit").before("<div><p><?php _e('FTP user name:', 'automatic-wordpress-backup') ?> <input type='text' id='ftp-username' name='username' /></p></div>");
											$(".restore2.panel p.submit").before("<div><p><?php _e('FTP password:', 'automatic-wordpress-backup') ?> <input type='password' id='ftp-password' name='password' /></p></div>");
											$(".restore2.panel p.submit").before("<input type='hidden' id='ftp-method' name='method' value='" + rsp.method + "' />");
										} else {
											window.location.reload();
										}
									}, 
									'json'
								);
							}
						});
						
						<?php if ( $cron['args'][0] ) : ?>
							var check_running = function(){
								$.post('admin-ajax.php', {action:"awb_running"}, function(rsp){
									if ( rsp.running ) {
										window.setTimeout(check_running, 1000);
									} else {
										window.setTimeout(function(){
											$("#awb-running").html("<?php printf(__("Your manual backup has completed. <a href='%s'>Refresh now</a> to see the backup.", 'automatic-wordpress-backup'), self::get_plugin_page() ); ?>").addClass("updated");
										}, 5000);
										
									}
								}, 'json');
							}
							
							window.setTimeout(check_running, 1000);
						<?php endif ?>

					});
				})(jQuery);
			</script>
		<?php
	}
	
	function get_settings() {
		$settings = get_option('awb-settings');
		if ( !$settings ) {
			register_setting('automatic-wordpress-backup', 'awb-settings');
			$settings = array();
		}
		$settings = array_merge(array(
			'cleanup' => 0,
			'cleanup-save-monthly' => 0,
			'cleanup-save-manual' => 0,
			'access-key' => null,
			'secret-key' => null,
		), $settings);
		if ( defined('AWB_ACCESS_KEY') ) $settings['access-key'] = AWB_ACCESS_KEY;
		if ( defined('AWB_SECRET_KEY') ) $settings['secret-key'] = AWB_SECRET_KEY;
		return $settings;
	}
	
	function log($message, $append = true) {
		file_put_contents(WP_CONTENT_DIR . '/uploads/awb/log.txt', $message . "\n", $append ? FILE_APPEND : 0);
	}
	
	function backup($manual = false) {
		global $wpdb, $wp_version;
		require_once('S3.php');
		
		self::setup();
		self::log('Initializing backup', false);
		$settings = self::get_settings();
		$s3 = new S3($settings['access-key'], $settings['secret-key']); 		
		if ( !(bool) @$s3->listBuckets() ) {
			self::log('S3 connection or authentication has failed');
			self::log('Quitting');
			return false;
		}
		
		$site = 'awb/' . next(explode('//', get_bloginfo('siteurl')));
		if ( isset($settings['zip']) && !$settings['zip'] ) {
			// file-by-file backups using S3 versioning.
			self::log('File-by-file backups using S3 versioning');
			
			$site = $site . '-fbf';
		
			$bucket = get_option('s3b-bucket');
			self::log('Using bucket "' . $bucket . '"');
			
			// Enable bucket versioning
			if ( !$s3->getBucketVersioning($bucket) ) {
				self::log('Activating bucket versioning');
				$s3->setBucketVersioning($bucket, true);
			}
			
			// Get all current objects
			self::log('Getting list of existing files from S3');
			$remote_files = $s3->getBucket($bucket, $site);
			
			// Get current file list
			$sections = get_option('s3b-section');
			if ( !$sections ) {
				$sections = array();
			}

			$cwd = getcwd();
			chdir(ABSPATH);
			
			self::log('Building local file list');
			$backups = array();
			if ( in_array('config', $sections) ) {
				$backups[] = ABSPATH . 'wp-config.php';
				if ( is_file(ABSPATH . '.htaccess') ) {
					$backups[] = ABSPATH . '.htaccess';
				}
			}
			if ( in_array('themes',  $sections) ) $backups = array_merge($backups, self::rscandir(WP_CONTENT_DIR . '/themes'));
			if ( in_array('plugins', $sections) ) $backups = array_merge($backups, self::rscandir(WP_CONTENT_DIR . '/plugins'));
			if ( in_array('uploads', $sections) && is_dir(WP_CONTENT_DIR . '/uploads') ) $backups = array_merge($backups, self::rscandir(WP_CONTENT_DIR . '/uploads'));
			
			
			chdir(WP_CONTENT_DIR . '/uploads/awb');
				
			// Add Database backup
			if ( in_array('database', $sections) ) {
				$tables = $wpdb->get_col("SHOW TABLES LIKE '" . $wpdb->prefix . "%'");
				$sql = '';
				self::log('Build database backup SQL');
				foreach ( $tables as $table ) {
					$sql .= self::backup_table($table);
				}

				self::log('Output SQL file');
				file_put_contents('awb-database-backup.sql', $sql);
				self::log('Zip SQL file');
				$result = shell_exec('zip awb-database-backup.zip awb-database-backup.sql');
				$backups[] = WP_CONTENT_DIR . '/uploads/awb/awb-database-backup.zip';
				self::log('Delete unzipped SQL file');
				@unlink(WP_CONTENT_DIR . '/uploads/awb/awb-database-backup.sql');
			}
			
			
			$same = 0;
			$deleted = 0;
			$changed = 0;
				
			// Compare files
			self::log('Compare local files to existing backups');
			foreach ( $remote_files as $key => $file ) {
				$path = str_replace(trailingslashit($site), trailingslashit(ABSPATH), $file['name']);

				$position = array_search($path, $backups);
				if ( $position !== false ) {
					// File exists, has it changed?
					if ( $file['hash'] == md5_file($path) ) {
						// The file has not changed, remove it from the list of files to upload.
						unset($backups[$position]);
						$same++;
					}
				} elseif ( $file['name'] != trailingslashit($site) . 'manifest.txt' ) {
					// File doesn't exist locally, delete it from S3.
					self::log('Deleted "' . $file['name'] . '" from S3 because it no longer exists locally');
					$s3->deleteObject($bucket, $file['name']);
					$deleted++;
				}
			}
			
			chdir(ABSPATH);
			
			$position = array_search(WP_CONTENT_DIR . '/uploads/awb/log.txt', $backups);
			if ( $position !== false ) {
				unset($backups[$position]);
			}
			
			// Upload new/updated files
			self::log("\nUploading changed/new files");
			foreach ( $backups as $file ) {
				$file = str_replace(ABSPATH, '', $file);
				$upload = $s3->inputFile($file);
				$result = $s3->putObject($upload, $bucket, urlencode($site . '/' . $file));
				self::log(' - Uploading "' . $file . '"... ' . ( $result ? 'success!' : 'upload failed' ));
				$changed++;
			}
			
			self::log("\nUpload verification");
			$backups = array_merge($backups);
			$remote_files = $s3->getBucket($bucket, $site);
			$success = true;
			if ( count($backups) > 10 ) {
				$top = count($backups) - 1;
				for ( $i = 0; $i < 10; $i++ ) {
					do {
						$j = rand(0, $top);
					} while ( !isset($backups[$j]) );
					$file = str_replace(ABSPATH, '', $backups[$j]);
					unset($backups[$j]);
					if ( !isset($remote_files[$site . '/' . $file]) || md5_file($file) != $remote_files[$site . '/' . $file]['hash'] ) {
						self::log(' - Verifying "' . $file . '"... failed');
						$success = false;
					} else {
						self::log(' - Verifying "' . $file . '"... success');
					}
				}
			} else {
				foreach ( $backups as $file ) {
					$file = str_replace(ABSPATH, '', $file);
					if ( !isset($remote_files[$site . '/' . $file]) || md5_file($file) != $remote_files[$site . '/' . $file]['hash'] ) {
						self::log(' - Verifying "' . $file . '"... failed');
						$success = false;
					} else {
						self::log(' - Verifying "' . $file . '"... success');
					}
				}
			}
			
			if ( $success ) {
				$manifest = array();
				foreach ( $sections as $section ) {
					switch ( $section ) {
						case 'config':
							$manifest[] = 'Config files and .htaccess (if applicable)';
							break;
						case 'database':
							$manifest[] = 'SQL dump of database tables with the WordPress table prefix';
							break;
						case 'themes':
							$manifest[] = 'Themes directory (including inactive themes)';
							break;
						case 'plugins':
							$manifest[] = 'Plugins directory (including inactive plugins)';
							break;
						case 'uploads':
							$manifest[] = 'Uploads directory';
							break;
					}
				}
				
				$s3->putObject("Backup generated by Automatic WordPress Backup v" . self::$version . " on " . date('Y-m-d H:i:s T') ." with WordPress " . $wp_version . "\n\nThe following sections were backed up:\n * " . implode("\n * ", $manifest) . "\n\nMachine Readable: " . serialize(array('wordpress'=>$wp_version,'time'=>time(),'version'=>self::$version, 'sections'=>$sections)), $bucket, urlencode($site . '/manifest.txt'));
				self::log("\nAdding manifest");
			}
			
			// Local cleanup
			if ( is_file(WP_CONTENT_DIR . '/uploads/awb/awb-database-backup.zip') ) {
				self::log('Deleting local copy of zipped SQL file');
				@unlink(WP_CONTENT_DIR . '/uploads/awb/awb-database-backup.zip');
			}
			chdir($cwd);

			// Log backup
		} else {
			// Zip based backups.
			if ( isset($settings['cleanup']) && $settings['cleanup'] ) {
				$backups = $s3->getBucket(get_option('s3b-bucket'), $site);
				ksort($backups);
				$months = array();
				$_month = strtotime("-1 month");
				$_year = strtotime("-1 year");
				foreach ( $backups as $key => $backup ) {
					if ( preg_match('|[0-9]{4}\.zip$|', $backup['name']) ) {
						if ( !$settings['cleanup-save-manual'] && $_month > $backup['time'] ) {
							$s3->deleteObject(get_option('s3b-bucket'), $backup['name']);
						}
					} else {
						$month = date('Ym', $backup['time']);
						if ( $settings['cleanup-save-monthly'] && $_year < $backup['time'] && !in_array($month, $months) ) {
							$months[] = $month;
						} elseif ( $_month > $backup['time'] ) {
							$s3->deleteObject(get_option('s3b-bucket'), $backup['name']);
						}
					}
				}
			}

			$sections = get_option('s3b-section');
			if ( !$sections ) {
				$sections = array();
			}

			$file = WP_CONTENT_DIR . '/uploads/awb/automatic-wordpress-backup.zip';

			$cwd = getcwd();
			chdir(ABSPATH);
			
			$backups = array();
			if ( in_array('config', $sections) ) {
				$backups[] = ABSPATH . 'wp-config.php';
				if ( is_file(ABSPATH . '.htaccess') ) {
					$backups[] = ABSPATH . '.htaccess';
				}
			}
			if ( in_array('themes', $sections) ) $backups[] = WP_CONTENT_DIR . '/themes';
			if ( in_array('plugins', $sections) ) $backups[] = WP_CONTENT_DIR . '/plugins';
			if ( in_array('uploads', $sections) ) $backups[] = WP_CONTENT_DIR . '/uploads';
			
			
			if ( !empty($backups) ) {
				foreach ( $backups as $key => $value ) {
					$backups[$key] = str_replace(ABSPATH, '', $value);
				}
				$result = shell_exec('zip -r ' . $file . ' ' . implode(' ', apply_filters('awb_backup_folders', $backups)) . ' -x *uploads/awb*');

				chdir(WP_CONTENT_DIR . '/uploads/awb');
				if ( in_array('database', $sections) ) {
					$tables = $wpdb->get_col("SHOW TABLES LIKE '" . $wpdb->prefix . "%'");
					$sql = '';
					foreach ( $tables as $table ) {
						$sql .= self::backup_table($table);
					}

					file_put_contents('awb-database-backup.sql', $sql);
					$result = shell_exec('zip -u ' . $file . ' awb-database-backup.sql');
					@unlink(WP_CONTENT_DIR . '/uploads/awb/awb-database-backup.sql');
				}
				
				$manifest = array();
				foreach ( $sections as $section ) {
					switch ( $section ) {
						case 'config':
							$manifest[] = 'Config files and .htaccess (if applicable)';
							break;
						case 'database':
							$manifest[] = 'SQL dump of database tables with the WordPress table prefix';
							break;
						case 'themes':
							$manifest[] = 'Themes directory (including inactive themes)';
							break;
						case 'plugins':
							$manifest[] = 'Plugins directory (including inactive plugins)';
							break;
						case 'uploads':
							$manifest[] = 'Uploads directory';
							break;
					}
				}
				
				file_put_contents('manifest.txt', "Backup generated by Automatic WordPress Backup v" . self::$version . " on " . date('Y-m-d H:i:s T') ." with WordPress " . $wp_version . "\n\nThe following sections were backed up:\n * " . implode("\n * ", $manifest) . "\n\nMachine Readable: " . serialize(array('wordpress'=>$wp_version,'time'=>time(),'version'=>self::$version, 'sections'=>$sections)));
				$result = shell_exec('zip -u ' . $file . ' manifest.txt');
				@unlink('manifest.txt');
				
				$upload = $s3->inputFile($file);
				if ( $manual ) {
					$s3->putObject($upload, get_option('s3b-bucket'), 'awb/' . next(explode('//', get_bloginfo('siteurl'))) . '/' . date('Y-m-d-Hi') . '.zip');
				} else {
					$s3->putObject($upload, get_option('s3b-bucket'), 'awb/' . next(explode('//', get_bloginfo('siteurl'))) . '/' . date('Y-m-d') . '.zip');
				}
				
				$remote_files = $s3->getBucket(get_option('s3b-bucket'), 'awb/' . next(explode('//', get_bloginfo('siteurl'))) . '/');
				$success = ( isset($remote_files['awb/' . next(explode('//', get_bloginfo('siteurl'))) . '/' . date('Y-m-d-Hi') . '.zip']) && md5_file($file) == $remote_files['awb/' . next(explode('//', get_bloginfo('siteurl'))) . '/' . date('Y-m-d-Hi') . '.zip']['hash'] );
				self::log("Success: " . ($success ? 'yes' : 'no'));
				
				@unlink($file);
			}
			
			if ( $success ) {
				//wp_mail(get_option('admin_email'), 'A backup has completed on ' . next(explode('//', get_bloginfo('siteurl'))), '');
			} else {
				//wp_mail(get_option('admin_email'), 'A backup has failed to complete on ' . next(explode('//', get_bloginfo('siteurl'))), '');
			}
			chdir($cwd);
		}
	}
	
	function cron_schedules($schedules) {
		$schedules['weekly'] = array('interval'=>604800, 'display' => 'Once Weekly');
		$schedules['monthly'] = array('interval'=>2592000, 'display' => 'Once Monthly');
		return $schedules;
	}
	
	function settings_link($links) {
		$settings_link = '<a href="' . self::get_plugin_page() . '">' . __('Settings', 'automatic-wordpress-backup') . '</a>'; 
		array_unshift( $links, $settings_link ); 
		return $links; 
	}
	
	function wdc_plugins($plugins) {
		if ( is_array($plugins) ) {
			$plugins[] = 'Automatic WordPress Backup v' . self::$version;
		}
		return $plugins;
	}
	
	function restore() {
		if ( !wp_verify_nonce($_GET['_wpnonce'], 'awb') ) die("{success: false, error: 'Invalid nonce'}");
		$url = get_bloginfo("url");
		
		global $wpdb, $wp_version, $wp_filesystem;
		
		require_once('S3.php');
		
		self::setup();

		if ( isset($_POST['method']) && !defined('FS_METHOD') ) {
			define('FS_METHOD', $_POST['method']);
		}
		
		require_once ABSPATH . '/wp-admin/includes/file.php';

		if ( isset($_POST['hostname']) ) {
			WP_Filesystem(array(
				'hostname' => $_POST['hostname'],
				'username' => $_POST['username'],
				'password' => $_POST['password']
			), ABSPATH);
		}
		
		$fsd = new WP_Filesystem_Direct(1);
		$settings = self::get_settings();
		$s3 = new S3($settings['access-key'], $settings['secret-key']); 	
		
		$awb_dir = WP_CONTENT_DIR . '/uploads/awb';
		$restore_dir = $awb_dir . '/tmp';
		
		mkdir($restore_dir);
		$cwd = getcwd();
		chdir($restore_dir);

		$delete = array();

		if ( AWB_ZIP ) {
			exec("wget --no-check-certificate -O backup.zip '" . $s3->getObjectURL(get_option('s3b-bucket'), $_POST['backup']) . "'");
			exec('unzip backup.zip');
		} else {
			$bucket = get_option('s3b-bucket');
			$prefix = 'awb/' . next(explode('//', get_bloginfo('siteurl'))) . '-fbf/';
			$timestamp = $_POST['backup'];
			$objects = $s3->getBucketVersions($bucket, $prefix);
			
			foreach ( $objects as $key => $versions ) {
				$last_time = array_pop(array_keys($versions));
				foreach ( $versions as $time => $version ) {
					if ( $timestamp > $time ) {
						if ( $version['deleteMarker'] ) {
							$delete = str_replace($prefix, '', $key);
							break;
						}
						$path = dirname(str_replace($prefix, '', $key));
						if ( !is_dir($path) ) {
							$path = explode('/', $path);
							$new_dir = '';
							foreach ( $path as $sub_path ) {
								$new_dir .= $sub_path . '/';
								if ( !is_dir($new_dir) ) {
									mkdir($new_dir);
								}
							}
						}
						
						//TODO: fix this...
						
						if ( substr($key, 0, 63) != 'awb/blart.me-fbf/wp-content/plugins/automatic-wordpress-backup/' ) {
							$path = str_replace($prefix, '', $key);
							if ( !is_file(trailingslashit(ABSPATH) . $path) || $version['hash'] != md5_file(trailingslashit(ABSPATH) . $path) ) {
								$s3->getObject($bucket, urlencode($key), $path, $version['versionId']);
								if ( filesize($path) != $version['size'] ) {
									die("ERROR TRANSFERRING FILE");
								}
							}
						}
						break;
					}
					
					if ( $time == $last_time ) {
						$delete = str_replace($prefix, '', $key);
					}
				}
			}
			
			$manifest_file = 'awb/' . next(explode('//', get_bloginfo('siteurl'))) . '-fbf/manifest.txt';
			$manifest = $s3->getBucketVersions(get_option('s3b-bucket'), $manifest_file);
			if ( isset($manifest[$manifest_file][$timestamp]) ) {
				$s3->getObject($bucket, $manifest_file, 'manifest.txt', $manifest[$manifest_file][$timestamp]['versionId']);
			}
			
		}
		//exit;
		$contents = self::rscandir($restore_dir, true);
		
		if ( !is_file('manifest.txt') ) {
			chdir($cwd);
			self::rrmdir($restore_dir);
			die(json_encode(array('success' => false, 'error' => __("No manifest was found. It is possible that an old version of this plugin created a backup that is not able to be automatically restored."))));
		}
		
		$manifest = file_get_contents('manifest.txt');
		if ( !(preg_match('|Machine Readable: (.*)|', $manifest, $matches) && ($manifest = unserialize($matches[1]))) ) {
			chdir($cwd);
			self::rrmdir($restore_dir);
			die(json_encode(array('success' => false, 'error' => __('The file manifest seems to be corrupted.  There may be a problem with your backup.'))));
		}
		
		$manifest['test'] = (float) $wp_version;
		if ( (float) $manifest['wordpress'] > (float) $wp_version ) {
			chdir($cwd);
			self::rrmdir($restore_dir);
			die(json_encode(array('success' => false, 'error' => __('Cannot restore to an older version of WordPress'), 'manifest' => $manifest)));
		}
		
		// Run a manual backup...
		//self::backup(true);
		
		if ( is_null($wp_filesystem) ) {
			$ready = true;
			foreach ( $manifest['sections'] as $section ) {
				if ( $section == 'config' ) {
					$method = get_filesystem_method(array(), $fsd->abspath());
					if ( $method != 'direct' ) {
						$ready = false;
						break;
					}
				} elseif ( $section == 'themes' ) {
					$method = get_filesystem_method(array(), $fsd->wp_themes_dir());
					if ( $method != 'direct' ) {
						$ready = false;
						break;
					}
				} elseif ( $section == 'plugins' ) {
					$method = get_filesystem_method(array(), $fsd->wp_plugins_dir());
					if ( $method != 'direct' ) {
						$ready = false;
						break;
					}
				} elseif ( $section == 'uploads' ) {
					$method = get_filesystem_method(array(), $fsd->wp_content_dir() . '/uploads');
					if ( $method != 'direct' ) {
						$ready = false;
						break;
					}
				}
			}
		
			if ( !$ready ) {
				chdir($cwd);
				self::rrmdir($awb_dir . '/tmp');
				die(json_encode(array('success' => false, 'error' => 'Insufficient Permissions', 'method' => $method, 'manifest' => $manifest)));
			}
		}
		
		if ( !defined('FS_METHOD') ) define('FS_METHOD', 'direct');
		if ( is_null($wp_filesystem) ) WP_Filesystem(array(), ABSPATH);
		
		$rdir = $wp_filesystem->find_folder($awb_dir . '/tmp');
		
		if ( in_array('database', $manifest['sections']) ) {
			if ( AWB_ZIP ) {
				$sql = file_get_contents('awb-database-backup.sql');
				$wp_filesystem->delete($rdir . '/awb-database-backup.sql');
			} else {
				if ( is_file('wp-content/uploads/awb/awb-database-backup.zip') ) {
					exec('unzip wp-content/uploads/awb/awb-database-backup.zip');
					$sql = file_get_contents('awb-database-backup.sql');
					$wp_filesystem->delete($rdir . '/awb-database-backup.sql');
				} elseif ( is_file('wp-content/uploads/awb/awb-database-backup.sql') ) {
					$sql = file_get_contents('wp-content/uploads/awb/awb-database-backup.sql');
					$wp_filesystem->delete($rdir . '/wp-content/uploads/awb/awb-database-backup.sql');
				}
			}
			$sql = preg_replace('|^\#.*$|m', '', $sql);
			$sql = explode(";\n", $sql);
			foreach ( $sql as $statement ) {
				if ( trim($statement) != '' ) {
					$wpdb->query($statement);
				}
			}
			$restore_url = $wpdb->get_var('SELECT option_value FROM ' . $wpdb->prefix . 'options WHERE option_name = "siteurl"');
			if ( $url != $restore_url ) {
				$wpdb->query('UPDATE ' . $wpdb->prefix . 'options SET option_value = "' . $url . '" WHERE option_value = "'. $restore_url . '"');
			}
		} else {
			$wp_filesystem->delete($rdir . '/awb-database-backup.sql');
			$wp_filesystem->delete('wp-content/uploads/awb/awb-database-backup.zip');
			$wp_filesystem->delete('wp-content/uploads/awb/awb-database-backup.sql');
		}
		
		if ( AWB_ZIP ) {
			$wp_filesystem->delete($rdir . '/backup.zip');
		}

		if ( isset($_POST['use_existing_db']) && $_POST['use_existing_db'] && is_file(trailingslashit($rdir) . 'wp-config.php') ) {
			$config_contents = $wp_filesystem->get_contents(trailingslashit($rdir) . 'wp-config.php');
			$config_contents = preg_replace(
				"|define\(.DB_NAME., .*\);|", 
				"define('DB_NAME', '" . DB_NAME . "');", 
				$config_contents
			);
			$config_contents = preg_replace(
				"|define\(.DB_USER., .*\);|", 
				"define('DB_USER', '" . DB_USER . "');", 
				$config_contents
			);
			$config_contents = preg_replace(
				"|define\(.DB_PASSWORD., .*\);|", 
				"define('DB_PASSWORD', '" . DB_PASSWORD . "');", 
				$config_contents
			);
			$config_contents = preg_replace(
				"|define\(.DB_HOST., .*\);|", 
				"define('DB_HOST', '" . DB_HOST . "');", 
				$config_contents
			);
			$wp_filesystem->put_contents(trailingslashit($rdir) . 'wp-config.php', $config_contents);
		}
		$wp_filesystem->delete($rdir . '/manifest.txt');
		//echo $restore_dir;
		
		$files = self::rscandir($restore_dir);
		foreach ( $files as $file ) {
			$file = substr($file, strlen($restore_dir));
			self::create_folder_for_file($wp_filesystem, $file);
			@$wp_filesystem->move($rdir . $file, $wp_filesystem->abspath() . $file, true);
		}

		chdir($cwd);
		$wp_filesystem->delete($rdir, true);
		
		die(json_encode(array('success' => true, 'manifest' => $manifest)));

	}
	
	function restore_dir($source, $source_file, $dest_file) {
	
	}
	
	function rscandir($base='', $include_directories = false) {
		if ( !is_dir($base) ) return false;
		$data = array_diff(scandir($base), array('.', '..'));
	
		$subs = array();
		foreach($data as $key => $value) :
			if ( is_dir($base . '/' . $value) ) :
				if ( $include_directories ) {
					$data[$key] = $base . '/' . $value;
				} else {
					unset($data[$key]);
				}
				$subs[] = self::rscandir($base . '/' . $value, $include_directories);
			elseif ( is_file($base . '/' . $value) ) :
				$data[$key] = $base . '/' . $value;
			endif;
		endforeach;
	
		foreach ( $subs as $sub ) {
			$data = array_merge($data, $sub);
		}
		return $data;
	}
	
	function rrmdir($dir) {
		$contents = self::rscandir($dir, true);
		
		rsort($contents);
		foreach ( $contents as $item ) {
			if ( is_file($item) ) {
				unlink($item);
			} else {
				rmdir($item);
			}
		}
		rmdir($dir);

	}

	/**
	 * Taken partially from phpMyAdmin and partially from
	 * Alain Wolf, Zurich - Switzerland
	 * Website: http://restkultur.ch/personal/wolf/scripts/db_backup/
	
	 * Modified by Scott Merrill (http://www.skippy.net/) 
	 * to use the WordPress $wpdb object
	 *
	 * Taken from WP DB Backup
	 * Modified again by Dan Coulter (http://dancoulter.com/)
	 * for use in the Automatic WordPress Backup plugin.
	 *
	 * @param string $table
	 * @param string $segment
	 * @return string
	 */
	function backup_table($table, $segment = 'none') {
		global $wpdb;
		$output = "";

		$table_structure = $wpdb->get_results("DESCRIBE $table");
		if (! $table_structure) {
			//$this->error(__('Error getting table details','automatic-wordpress-backup') . ": $table");
			return false;
		}
	
		if(($segment == 'none') || ($segment == 0)) {
			// Add SQL statement to drop existing table
			$output .= "\n\n";
			$output .= "#\n";
			$output .= "# " . sprintf(__('Delete any existing table %s','automatic-wordpress-backup'),self::db_backquote($table)) . "\n";
			$output .= "#\n";
			$output .= "\n";
			$output .= "DROP TABLE IF EXISTS " . self::db_backquote($table) . ";\n";
			
			// Table structure
			// Comment in SQL-file
			$output .= "\n\n";
			$output .= "#\n";
			$output .= "# " . sprintf(__('Table structure of table %s','automatic-wordpress-backup'),self::db_backquote($table)) . "\n";
			$output .= "#\n";
			$output .= "\n";
			
			$create_table = $wpdb->get_results("SHOW CREATE TABLE $table", ARRAY_N);
			if (false === $create_table) {
				$err_msg = sprintf(__('Error with SHOW CREATE TABLE for %s.','automatic-wordpress-backup'), $table);
				//$this->error($err_msg);
				$output .= "#\n# $err_msg\n#\n";
			}
			$output .= $create_table[0][1] . ' ;';
			
			if (false === $table_structure) {
				$err_msg = sprintf(__('Error getting table structure of %s','automatic-wordpress-backup'), $table);
				//$this->error($err_msg);
				$output .= "#\n# $err_msg\n#\n";
			}
		
			// Comment in SQL-file
			$output .= "\n\n";
			$output .= "#\n";
			$output .= '# ' . sprintf(__('Data contents of table %s','automatic-wordpress-backup'),self::db_backquote($table)) . "\n";
			$output .= "#\n";
		}
		
		if(($segment == 'none') || ($segment >= 0)) {
			$defs = array();
			$ints = array();
			foreach ($table_structure as $struct) {
				if ( (0 === strpos($struct->Type, 'tinyint')) ||
					(0 === strpos(strtolower($struct->Type), 'smallint')) ||
					(0 === strpos(strtolower($struct->Type), 'mediumint')) ||
					(0 === strpos(strtolower($struct->Type), 'int')) ||
					(0 === strpos(strtolower($struct->Type), 'bigint')) ) {
						$defs[strtolower($struct->Field)] = ( null === $struct->Default ) ? 'NULL' : $struct->Default;
						$ints[strtolower($struct->Field)] = "1";
				}
			}
			
			
			// Batch by $row_inc
			
			if($segment == 'none') {
				$row_start = 0;
				$row_inc = 100;
			} else {
				$row_start = $segment * 100;
				$row_inc = 100;
			}
			
			do {	
				$table_data = $wpdb->get_results("SELECT * FROM $table $where LIMIT {$row_start}, {$row_inc}", ARRAY_A);

				$entries = 'INSERT INTO ' . self::db_backquote($table) . ' VALUES (';	
				//    \x08\\x09, not required
				$search = array("\x00", "\x0a", "\x0d", "\x1a");
				$replace = array('\0', '\n', '\r', '\Z');
				if($table_data) {
					foreach ($table_data as $row) {
						$values = array();
						foreach ($row as $key => $value) {
							if ($ints[strtolower($key)]) {
								// make sure there are no blank spots in the insert syntax,
								// yet try to avoid quotation marks around integers
								$value = ( null === $value || '' === $value) ? $defs[strtolower($key)] : $value;
								$values[] = ( '' === $value ) ? "''" : $value;
							} else {
								$values[] = "'" . str_replace($search, $replace, self::db_addslashes($value)) . "'";
							}
						}
						$output .= "\n" . $entries . implode(', ', $values) . ');';
					}
					$row_start += $row_inc;
				}
			} while((count($table_data) > 0) and ($segment=='none'));
		}
		
		if(($segment == 'none') || ($segment < 0)) {
			// Create footer/closing comment in SQL-file
			$output .= "\n";
			$output .= "#\n";
			$output .= "# " . sprintf(__('End of data contents of table %s','automatic-wordpress-backup'),self::db_backquote($table)) . "\n";
			$output .= "# --------------------------------------------------------\n";
			$output .= "\n";
		}
		
		return $output;
	} // end backup_table()

	/**
	 * Better addslashes for SQL queries.
	 * Taken from phpMyAdmin.
	 * Taken (again) from WP DB Backup
	 */
	function db_addslashes($a_string = '', $is_like = false) {
		if ($is_like) $a_string = str_replace('\\', '\\\\\\\\', $a_string);
		else $a_string = str_replace('\\', '\\\\', $a_string);
		return str_replace('\'', '\\\'', $a_string);
	} 
	
	/**
	 * Add backquotes to tables and db-names in
	 * SQL queries. Taken from phpMyAdmin.
	 * Taken (again) from WP DB Backup
	 */
	function db_backquote($a_name) {
		if (!empty($a_name) && $a_name != '*') {
			if (is_array($a_name)) {
				$result = array();
				reset($a_name);
				while(list($key, $val) = each($a_name)) 
					$result[$key] = '`' . $val . '`';
				return $result;
			} else {
				return '`' . $a_name . '`';
			}
		} else {
			return $a_name;
		}
	} 
	
	function ajax_manual_is_running() {
		$crons = _get_cron_array();
		foreach ( $crons as $cron ) {
			if ( isset($cron['s3-backup']) ) {
				$cron = current($cron['s3-backup']);
				if ( $cron['args'][0] ) {
					die('{"running": true}');
				}
			}
		}
		die('{"running": false}');
		exit;
	}
	
	function create_folder_for_file($wpf, $file) {
		if ( !$wpf->exists($wpf->abspath() . dirname($file)) ) {
			self::create_folder_for_file($wpf, dirname($file));
			$wpf->mkdir($wpf->abspath() . dirname($file));
		}
	}
	
	function wdc_menu_url($label) {
		return plugin_basename(__FILE__);
	}
	
	function wdc_menu_page($callback) {
		return array('cmAWB', 'settings_page');
	}

}

add_action('wdc-menu-pages', array('cmAWB', 'add_settings_page'), 1, 1);
add_action('s3-backup', array('cmAWB', 'backup'), 1);
add_action('wp_ajax_awb_restore', array('cmAWB', 'restore'));
add_action('wp_ajax_awb_running', array('cmAWB', 'ajax_manual_is_running'));
add_action('admin_init', array('cmAWB', 'init'));

add_filter('cron_schedules', array('cmAWB', 'cron_schedules'));
add_filter("plugin_action_links_" . plugin_basename(__FILE__), array('cmAWB', 'settings_link') ); 
add_filter('wdc_plugins', array('cmAWB', 'wdc_plugins'));
add_filter('wdc-settings-url', array('cmAWB', 'wdc_menu_url'), 1, 100);
add_filter('wdc-settings-page', array('cmAWB', 'wdc_menu_page'), 1, 100);
?>