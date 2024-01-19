<?php
/**
 * Plugin Name: Paid Memberships Pro - Add Membership New Member
 * Plugin URI: #
 * Description: PMPRO add membership in add new user in backend.
 * Version: 1.0
 * Author URI: #
 * Text Domain: pmpronewmm
 */


defined( "ABSPATH" ) || exit;

define( "PMPRONEWMM_DIR", plugin_dir_path( __FILE__ ) );
define( "PMPRONEWMM_URL", plugin_dir_url( __FILE__ ) );
define( "PMPRONEWMM_VER", "1.0");




/**
 * PMPRO Add membership new member main class
 */
class PMPRONEWMM {
	
	private static $instance;

	public static function get_instance(){
		if (null === self::$instance) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	public function __construct() {		
		register_activation_hook (__FILE__ , [ $this, 'pmprommpu_activation' ]);
		register_deactivation_hook( __FILE__ , [ $this, 'pmprommpu_deactivation' ]);

		// Add New member with membership levels
		add_action( 'user_new_form', [ $this, 'pilar_user_new_form' ] );
		add_action( 'user_register', [ $this, 'pilar_user_profile_update' ], 11 );
		add_action( 'wp_ajax_pmpprommpu_get_levels', [ $this, 'pmpprommpu_get_levels' ]);		
	}

	protected function pmprommpu_create_group($inname, $inallowmult = true) {
		global $wpdb;

		$allowmult = intval($inallowmult);
		$result = $wpdb->insert($wpdb->pmpro_groups, array('name' => $inname, 'allow_multiple_selections' => $allowmult), array('%s', '%d'));

		if( $result ) { 
			return $wpdb->insert_id; 
		} else { 
			return false; 
		}
	}

	// Set (or move) a membership level into a level group
	protected function pmprommpu_set_level_for_group($levelid, $groupid) {
		global $wpdb;

		$levelid = intval($levelid);
		$groupid = intval($groupid); // just to be safe

		// TODO: Error checking would be smart.
		$wpdb->delete( $wpdb->pmpro_membership_levels_groups, array( 'level' => $levelid ) );
		$wpdb->insert($wpdb->pmpro_membership_levels_groups, array('level' => $levelid, 'group' => $groupid), array('%d', '%d' ) );
	}


	// Return an array of all level groups, with the key being the level group id.
	// Groups have an id, name, displayorder, and flag for allow_multiple_selections
	protected function pmprommpu_get_groups() {
		global $wpdb;

		$allgroups = $wpdb->get_results("SELECT * FROM $wpdb->pmpro_groups ORDER BY id");
		$grouparr = array();
		foreach($allgroups as $curgroup) {
			$grouparr[$curgroup->id] = $curgroup;
		}

		return $grouparr;
	}

	public function pmprommpu_activation() {
		if ( !is_plugin_active( 'paid-memberships-pro/paid-memberships-pro.php' ) ) {
			deactivate_plugins( plugin_basename( __FILE__ ) );
			wp_die(
				__( 'Paid Memberships Pro must be active in order to activate the MMPU add-on.', 'pmpronewmm' ),
				__( 'Plugin dependency check', 'pmpronewmm' ),
				array( 'back_link' => true )
			);
		}

		// No groups in the DB? Create one with all levels, to maintain backward-compatibility out of the box.
		$curgroups = $this->pmprommpu_get_groups();
		if( count($curgroups) == 0 ) {
			$newgroupid = $this->pmprommpu_create_group(__( 'Main Group' , 'pmpronewmm' ), false);

			$alllevels = pmpro_getAllLevels(true, true);
			foreach($alllevels as $levelid => $leveldetail) {
				$this->pmprommpu_set_level_for_group($levelid, $newgroupid);
			}
		}

		update_option( 'pmprommpu_installed', 1, true);
	}

	public function pmprommpu_deactivation() {
		delete_option( 'pmprommpu_installed');
	}

	public function pilar_user_new_form($operation) {
		if ( 'add-new-user' !== $operation && ! current_user_can( 'edit_user', get_current_user_id() ) && !is_plugin_active( 'pmpro-multiple-memberships-per-user/pmpro-multiple-memberships-per-user.php' ) ) {
			return;
		}

		global $current_user;

		$membership_level_capability = apply_filters("pmpro_edit_member_capability", "manage_options");
		if(!current_user_can($membership_level_capability))
			return false;
		
		$current_level = ! empty( $_POST['membership_levels'] ) ? intval( $_POST['membership_levels'] ) : '';
	?>
	<h3><?php _e("Membership Levels", 'pilor_training'); ?></h3>
		<table class="wp-list-table widefat fixed pmprommpu_levels" width="100%" cellpadding="0" cellspacing="0" border="0">
		<thead>
			<tr>
	            <th><?php esc_html_e( 'Group', 'pilor_training' ); ?></th>
	            <th><?php esc_html_e( 'Membership Level', 'pilor_training' ); ?></th>
	            <th><?php esc_html_e( 'Expiration', 'pilor_training' ); ?></th>
	            <th>&nbsp;</th>
			</tr>
		</thead>
		<tbody>
		<?php
			$allgroups = $this->pmprommpu_get_groups();

			//some other vars
			$current_day = date("j", current_time('timestamp'));
			$current_month = date("M", current_time('timestamp'));
			$current_year = date("Y", current_time('timestamp'));

			?>
			<tr class="new_levels_tr">
				<td>
					<select class="new_levels_group" name="new_levels_group[]">
						<option value="">-- <?php _e("Choose a Group", 'pilor_training');?> --</option>
						<?php foreach($allgroups as $group) { ?>
							<option value="<?php echo $group->id;?>"><?php echo $group->name;?></option>
						<?php } ?>
					</select>
				</td>
				<td class="td_groups_level">
					<em><?php _e('Choose a group first.', 'pmpro-multiple-memberships-per-user');?></em>
				</td>
				<td>
					<?php
						//default enddate values
						$end_date = false;
						$selected_expires_day = $current_day;
						$selected_expires_month = date("m");
						$selected_expires_year = (int)$current_year + 1;
					?>
					<select class="expires new_levels_expires" name="new_levels_expires[]">
						<option value="0"><?php _e("No", 'pilor_training');?></option>
						<option value="1"><?php _e("Yes", 'pilor_training');?></option>
					</select>
					<span class="expires_date new_levels_expires_date" style="display: none;">
						on
						<select name="new_levels_expires_month[]">
							<?php
								for($i = 1; $i < 13; $i++)
								{
								?>
								<option value="<?php echo $i?>" <?php if($i == $selected_expires_month) { ?>selected="selected"<?php } ?>><?php echo date("M", strtotime($i . "/15/" . $current_year, current_time("timestamp")))?></option>
								<?php
								}
							?>
						</select>
						<input name="new_levels_expires_day[]" type="text" size="2" value="<?php echo $selected_expires_day?>" />
						<input name="new_levels_expires_year[]" type="text" size="4" value="<?php echo $selected_expires_year?>" />
					</span>
				</td>
				<td><a class="remove_level" href="javascript:void(0);"><?php _e('Remove', 'pilor_training');?></a></td>
			</tr>
			<tr>
				<td colspan="4"><a href="javascript:void(0);" class="add_level">+ <?php _e('Add Level', 'pilor_training');?></a></td>
			</tr>
		</tbody>
		</table>

		<script type="text/javascript">
			jQuery(document).ready(function($){
				$('.pmprommpu_levels').on('change', '.expires.new_levels_expires' , function(e){
					e.preventDefault();
					if ( $(this).val() == 0 ) {
						$(this).siblings('.new_levels_expires_date').hide();
					} else if ( $(this).val() == 1 ) {
						$(this).siblings('.new_levels_expires_date').show();						
					}
				});

				$('.pmprommpu_levels').on('click', '.remove_level' , function(e){
					e.preventDefault();
					$(this).parents('tr').remove();
				});

				$('.pmprommpu_levels').on('click', '.add_level' , function(e){
					e.preventDefault();
					var cloned = $(this).parents('tbody').find('.new_levels_tr').eq(0).clone();
					cloned.find('.new_levels_expires_date').hide();

					cloned.appendTo($(this).parents('tbody'));
				});

				$('.pmprommpu_levels').on('change', '.new_levels_group', function(e){
					e.preventDefault();
					var $this = $(this);
					$this.parents('.new_levels_tr').find('.td_groups_level').html('');

					let gruopId = $(this).val();

					if ( gruopId != '' ) {
					    jQuery.post({
					        url: "<?= admin_url( 'admin-ajax.php' ); ?>",
					        data: {
					            'action': 'pmpprommpu_get_levels',
					            'gruopId': gruopId
					        },
					        success: function(update) {
					        	if ( update.length > 2 ) {
					        		let results = JSON.parse(update);
					        		let leveltd = $this.parents('.new_levels_tr').find('.td_groups_level');

									if(results.length > 0) {
										var levelselect = jQuery('<select class="pilar-user-membership" name="new_levels_level[]" required></select>').appendTo(leveltd);

										levelselect.append('<option value="">-- Choose a Level --</option>');

										jQuery.map(results, function(item){
											levelselect.append('<option value="'+ item['id'] +'">'+ item['name']+'</option>');
										})
									} else {
										leveltd.html('<em>Choose a group first.</em>');
									}

					        	}
							}
					    });	
					}
				})
			});
		</script>

		<?php
	}

	public function pilar_user_profile_update($user_id) {
		
		if ( !empty($_POST['new_levels_level']) ) {
			global $wpdb;

			foreach($_POST['new_levels_level'] as $newkey => $leveltoadd) {
				$result = pmprommpu_addMembershipLevel($leveltoadd, $user_id, false);
				if(! $result) {
					pmprommpu_addMembershipLevel($leveltoadd, $user_id, true);
				}

				if ( $result ) {
					$doweexpire = $_POST['new_levels_expires'][$newkey];
					if(!empty($doweexpire)) {
						$expiration_date = intval($_POST['new_levels_expires_year'][$newkey]) . "-" . str_pad(intval($_POST['new_levels_expires_month'][$newkey]), 2, "0", STR_PAD_LEFT) . "-" . str_pad(intval($_POST['new_levels_expires_day'][$newkey]), 2, "0", STR_PAD_LEFT);

						$wpdb->update(
							$wpdb->pmpro_memberships_users,
							array( 'enddate' => $expiration_date ),
							array(
								'status' => 'active',
								'membership_id' => $leveltoadd,
								'user_id' => $user_id ), // Where clause
							array( '%s' ),  // format for data
							array( '%s', '%d', '%d' ) // format for where clause
						);
					} else { // No expiration for me!
						$wpdb->update(
							$wpdb->pmpro_memberships_users,
							array( 'enddate' => NULL ),
							array(
								'status' => 'active',
								'membership_id' => $leveltoadd,
								'user_id' => $user_id
							),
							array( NULL ),
							array( '%s', '%d', '%d' )
						);
					}
				}
			}
		}
	}

	public function pmpprommpu_get_levels() {

		if ( !empty($_POST['gruopId']) ) {
			global $wpdb;
			$levels = $wpdb->get_col( $wpdb->prepare( "SELECT `level` FROM `wp_pmpro_membership_levels_groups` WHERE `group` = %d ORDER BY `level` ASC", intval($_POST['gruopId']) ) );

			if ( !empty($levels) ) {
				$splitlevel = implode(',', $levels);

				$alllevels = $wpdb->get_results( "SELECT `id`, `name` FROM {$wpdb->pmpro_membership_levels} WHERE `id` IN ({$splitlevel}) ORDER BY `id` ASC", ARRAY_A );
				echo json_encode( $alllevels );
			}
		}
		wp_die();
	}	

}


$pmpronewmm = PMPRONEWMM::get_instance();
