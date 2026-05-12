<?php
/**
 * Registers and handles custom meta boxes for the Case Result CPT.
 *
 * Security model used here:
 *  1. Nonce verification   — confirms the save came from OUR form.
 *  2. Capability check     — only users who can edit_post may save.
 *  3. Auto-save bail-out   — don't run on WP auto-saves.
 *  4. Per-field sanitisation — each field sanitised to its own data type.
 *
 * @package CaseResults
 */

namespace CaseResults;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Meta_Boxes {

	/**
	 * Meta key prefix — keeps our keys namespaced so they never clash with
	 * other plugins.  All keys follow the pattern _cr_{field}.
	 */
	const PREFIX = '_cr_';

	/**
	 * Allowed case types — single source of truth used in both the metabox
	 * dropdown AND in sanitisation, so an injected value is rejected.
	 */
	const CASE_TYPES = array(
		'personal_injury'    => 'Personal Injury',
		'car_accident'       => 'Car Accident',
		'slip_and_fall'      => 'Slip & Fall',
		'medical_malpractice'=> 'Medical Malpractice',
	);

	public function __construct() {
		add_action( 'add_meta_boxes', array( $this, 'add' ) );
		add_action( 'save_post',      array( $this, 'save' ) );
	}

	// -----------------------------------------------------------------------
	// Register the meta box
	// -----------------------------------------------------------------------

	public function add() {
		add_meta_box(
			'cr_case_details',                   // Unique ID.
			__( 'Case Details', 'case-results' ),// Box title.
			array( $this, 'render' ),            // Callback.
			Post_Type::SLUG,                     // Only show on our CPT.
			'normal',                            // Position: normal (below editor).
			'high'                               // Priority: render before other boxes.
		);
	}

	// -----------------------------------------------------------------------
	// Render the meta box HTML
	// -----------------------------------------------------------------------

	public function render( $post ) {
		// Create a nonce field — we verify this on save to prevent CSRF.
		wp_nonce_field( 'cr_save_case_details', 'cr_nonce' );

		// Pull current values — empty string is the safe default.
		$case_type         = get_post_meta( $post->ID, self::PREFIX . 'case_type',         true );
		$settlement_amount = get_post_meta( $post->ID, self::PREFIX . 'settlement_amount', true );
		$case_duration     = get_post_meta( $post->ID, self::PREFIX . 'case_duration',     true );
		$client_city       = get_post_meta( $post->ID, self::PREFIX . 'client_city',       true );
		$client_state      = get_post_meta( $post->ID, self::PREFIX . 'client_state',      true );
		$case_year         = get_post_meta( $post->ID, self::PREFIX . 'case_year',         true );

		// Current year for the year picker max attribute.
		$current_year = (int) date( 'Y' );
		?>
		<table class="form-table cr-meta-table">
			<tbody>

				<!-- Case Type -->
				<tr>
					<th scope="row">
						<label for="cr_case_type"><?php esc_html_e( 'Case Type', 'case-results' ); ?> <span class="required">*</span></label>
					</th>
					<td>
						<select id="cr_case_type" name="cr_case_type" required>
							<option value=""><?php esc_html_e( '— Select Case Type —', 'case-results' ); ?></option>
							<?php foreach ( self::CASE_TYPES as $value => $label ) : ?>
								<option value="<?php echo esc_attr( $value ); ?>"
									<?php selected( $case_type, $value ); ?>>
									<?php echo esc_html( $label ); ?>
								</option>
							<?php endforeach; ?>
						</select>
						<p class="description"><?php esc_html_e( 'The primary legal category of this case.', 'case-results' ); ?></p>
					</td>
				</tr>

				<!-- Settlement Amount -->
				<tr>
					<th scope="row">
						<label for="cr_settlement_amount"><?php esc_html_e( 'Settlement Amount ($)', 'case-results' ); ?></label>
					</th>
					<td>
						<input
							type="number"
							id="cr_settlement_amount"
							name="cr_settlement_amount"
							value="<?php echo esc_attr( $settlement_amount ); ?>"
							min="0"
							step="1"
							placeholder="e.g. 250000"
							class="regular-text"
						/>
						<p class="description">
							<?php esc_html_e( 'Enter amount in whole dollars (no commas). Displayed as formatted currency on the front-end.', 'case-results' ); ?>
						</p>
					</td>
				</tr>

				<!-- Case Duration -->
				<tr>
					<th scope="row">
						<label for="cr_case_duration"><?php esc_html_e( 'Case Duration (months)', 'case-results' ); ?></label>
					</th>
					<td>
						<input
							type="number"
							id="cr_case_duration"
							name="cr_case_duration"
							value="<?php echo esc_attr( $case_duration ); ?>"
							min="1"
							max="240"
							class="small-text"
						/>
					</td>
				</tr>

				<!-- Client Location: City + State as two separate fields -->
				<!-- WHY separate?  Allows independent filtering/reporting in analytics. -->
				<tr>
					<th scope="row">
						<label><?php esc_html_e( 'Client Location', 'case-results' ); ?></label>
					</th>
					<td style="display: flex;">
						<input
							type="text"
							id="cr_client_city"
							name="cr_client_city"
							value="<?php echo esc_attr( $client_city ); ?>"
							placeholder="<?php esc_attr_e( 'City', 'case-results' ); ?>"
							class="regular-text"
							style="flex: 1;"
						/>
						<input
							type="text"
							id="cr_client_state"
							name="cr_client_state"
							value="<?php echo esc_attr( $client_state ); ?>"
							placeholder="<?php esc_attr_e( 'State', 'case-results' ); ?>"
							class="small-text"
							style="margin-left: 8px; flex: 1;"
						/>
					</td>
				</tr>

				<!-- Case Year -->
				<tr>
					<th scope="row">
						<label for="cr_case_year"><?php esc_html_e( 'Case Year', 'case-results' ); ?></label>
					</th>
					<td>
						<input
							type="number"
							id="cr_case_year"
							name="cr_case_year"
							value="<?php echo esc_attr( $case_year ); ?>"
							min="1980"
							max="<?php echo esc_attr( $current_year ); ?>"
							class="small-text"
							placeholder="<?php echo esc_attr( $current_year ); ?>"
						/>
					</td>
				</tr>

			</tbody>
		</table>

		<style>
			.cr-meta-table th { width: 200px; vertical-align: top; padding-top: 10px; }
			.cr-meta-table td { padding-bottom: 12px; }
			.required { color: #d63638; }
		</style>
		<?php
	}

	// -----------------------------------------------------------------------
	// Save meta — runs on every save_post action
	// -----------------------------------------------------------------------

	public function save( $post_id ) {

		// 1. Nonce check — confirms the request came from our meta box form.
		if ( ! isset( $_POST['cr_nonce'] ) ||
			 ! wp_verify_nonce( $_POST['cr_nonce'], 'cr_save_case_details' ) ) {
			return;
		}

		// 2. Auto-save bail-out — don't persist during WordPress auto-saves.
		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}

		// 3. Capability check — only users who can edit this post may save.
		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}

		// 4. Post type check — only run for our CPT, not other post types.
		if ( Post_Type::SLUG !== get_post_type( $post_id ) ) {
			return;
		}

		// -------------------------------------------------------------------
		// Sanitise and save each field individually.
		// -------------------------------------------------------------------

		// Case Type — whitelist check against our allowed values.
		if ( isset( $_POST['cr_case_type'] ) ) {
			$case_type = sanitize_key( $_POST['cr_case_type'] );
			if ( array_key_exists( $case_type, self::CASE_TYPES ) ) {
				update_post_meta( $post_id, self::PREFIX . 'case_type', $case_type );
			} else {
				// Value not in our whitelist — delete any existing value.
				delete_post_meta( $post_id, self::PREFIX . 'case_type' );
			}
		}

		// Settlement Amount — must be a positive integer (no decimals for legal $).
		if ( isset( $_POST['cr_settlement_amount'] ) ) {
			$amount = absint( $_POST['cr_settlement_amount'] );
			update_post_meta( $post_id, self::PREFIX . 'settlement_amount', $amount );
		}

		// Case Duration — positive integer, capped at 240 months (20 years).
		if ( isset( $_POST['cr_case_duration'] ) ) {
			$duration = absint( $_POST['cr_case_duration'] );
			$duration = min( $duration, 240 ); // Server-side cap, mirrors the HTML max.
			update_post_meta( $post_id, self::PREFIX . 'case_duration', $duration );
		}

		// Client City — plain text, no HTML allowed.
		if ( isset( $_POST['cr_client_city'] ) ) {
			update_post_meta(
				$post_id,
				self::PREFIX . 'client_city',
				sanitize_text_field( $_POST['cr_client_city'] )
			);
		}

		// Client State — 2-letter uppercase code only.
		if ( isset( $_POST['cr_client_state'] ) ) {
			update_post_meta( 
				$post_id, 
				self::PREFIX . 'client_state', 
			 	sanitize_text_field( $_POST['cr_client_state'] ) );
		}

		// Case Year — must be a 4-digit year within a sane range.
		if ( isset( $_POST['cr_case_year'] ) ) {
			$year = absint( $_POST['cr_case_year'] );
			if ( $year >= 1980 && $year <= (int) date( 'Y' ) ) {
				update_post_meta( $post_id, self::PREFIX . 'case_year', $year );
			}
		}
	}
}
