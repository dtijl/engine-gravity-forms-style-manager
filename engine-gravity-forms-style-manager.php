<?php
/**
 * Plugin Name: Engine Gravity Forms Style Manager
 * Plugin URI: https://enginebranding.nl
 * Description: Beheer Gravity Forms styles per formulier via GF CSS classes en laad optioneel theme/assets/gf-class.css.
 * Version: 1.2.0
 * Author: Daniël Tijl
 * Author URI: https://enginebranding.nl
 * License: GPL-2.0+
 * Text Domain: engine-gravity-forms-style-manager
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Engine_Gravity_Forms_Style_Manager {

	/**
	 * Singleton instance.
	 *
	 * @var Engine_Gravity_Forms_Style_Manager|null
	 */
	private static $instance = null;

	/**
	 * Plugin version.
	 *
	 * @var string
	 */
	private $version = '1.2.0';

	/**
	 * Reserved control classes.
	 *
	 * @var array<string, string>
	 */
	private $control_classes = [
		'load_theme_file'    => 'egfsm-theme-file',
		'disable_theme_css'  => 'egfsm-disable-theme-css',
		'disable_legacy_css' => 'egfsm-disable-legacy-css',
		'disable_all_css'    => 'egfsm-disable-all-css',
	];

	/**
	 * Boot plugin.
	 *
	 * @return Engine_Gravity_Forms_Style_Manager
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * Constructor.
	 */
	private function __construct() {
		add_action( 'gform_enqueue_scripts', [ $this, 'handle_form_assets' ], 999, 2 );
		add_filter( 'style_loader_tag', [ $this, 'maybe_tag_theme_style' ], 10, 4 );
		add_action( 'admin_menu', [ $this, 'register_admin_page' ], 20 );

		// Form Settings: custom field for submit button classes.
		add_filter( 'gform_form_settings_fields', [ $this, 'add_form_settings_fields' ], 10, 2 );
		add_filter( 'gform_form_settings_before_save', [ $this, 'save_form_settings' ] );

		// Frontend: append configured classes to the submit button.
		add_filter( 'gform_submit_button', [ $this, 'filter_submit_button' ], 10, 2 );
	}

	/**
	 * Handle per-form assets based on GF form classes.
	 *
	 * @param array $form
	 * @param bool  $is_ajax
	 * @return void
	 */
	public function handle_form_assets( $form, $is_ajax ) {
		$form_classes = $this->get_form_classes( $form );

		$has_disable_all    = in_array( $this->control_classes['disable_all_css'], $form_classes, true );
		$has_disable_theme  = in_array( $this->control_classes['disable_theme_css'], $form_classes, true );
		$has_disable_legacy = in_array( $this->control_classes['disable_legacy_css'], $form_classes, true );
		$has_theme_file     = in_array( $this->control_classes['load_theme_file'], $form_classes, true );

		if ( $has_disable_all || $has_disable_theme ) {
			$this->dequeue_theme_styles();
		}

		if ( $has_disable_all || $has_disable_legacy ) {
			$this->dequeue_legacy_styles();
		}

		if ( $has_theme_file ) {
			$this->enqueue_theme_gf_class_css( $form );
		}
	}

	/**
	 * Register submenu page under Forms.
	 *
	 * @return void
	 */
	public function register_admin_page() {
		if ( class_exists( 'GFForms' ) ) {
			add_submenu_page(
				'gf_edit_forms',
				__( 'Style Manager', 'engine-gravity-forms-style-manager' ),
				__( 'Style Manager', 'engine-gravity-forms-style-manager' ),
				'gravityforms_edit_forms',
				'engine-gravity-forms-style-manager',
				[ $this, 'render_admin_page' ]
			);

			return;
		}

		add_options_page(
			__( 'GF Style Manager', 'engine-gravity-forms-style-manager' ),
			__( 'GF Style Manager', 'engine-gravity-forms-style-manager' ),
			'manage_options',
			'engine-gravity-forms-style-manager',
			[ $this, 'render_admin_page' ]
		);
	}

	/**
	 * Render admin page.
	 *
	 * @return void
	 */
	public function render_admin_page() {
		if ( ! current_user_can( 'gravityforms_edit_forms' ) && ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$theme_file        = $this->get_theme_gf_class_file_data();
		$form_settings_url = admin_url( 'admin.php?page=gf_edit_forms' );
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Engine Gravity Forms Style Manager', 'engine-gravity-forms-style-manager' ); ?></h1>

			<p>
				<?php esc_html_e( 'Deze plugin beheert Gravity Forms styling op basis van CSS classes die je per formulier invult in de formulierinstellingen.', 'engine-gravity-forms-style-manager' ); ?>
			</p>

			<hr>

			<h2><?php esc_html_e( 'Waar stel je dit in?', 'engine-gravity-forms-style-manager' ); ?></h2>
			<p>
				<?php esc_html_e( 'Ga naar Gravity Forms → open een formulier → Settings → Form Settings → CSS Class Name.', 'engine-gravity-forms-style-manager' ); ?>
			</p>
			<p>
				<?php esc_html_e( 'Op dezelfde pagina vind je ook het veld voor submit button classes.', 'engine-gravity-forms-style-manager' ); ?>
			</p>
			<p>
				<a class="button button-secondary" href="<?php echo esc_url( $form_settings_url ); ?>">
					<?php esc_html_e( 'Open Gravity Forms', 'engine-gravity-forms-style-manager' ); ?>
				</a>
			</p>

			<hr>

			<h2><?php esc_html_e( 'Beschikbare manager classes', 'engine-gravity-forms-style-manager' ); ?></h2>
			<table class="widefat striped" style="max-width: 1100px;">
				<thead>
					<tr>
						<th style="width: 260px;"><?php esc_html_e( 'Class', 'engine-gravity-forms-style-manager' ); ?></th>
						<th><?php esc_html_e( 'Functie', 'engine-gravity-forms-style-manager' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<tr>
						<td><code><?php echo esc_html( $this->control_classes['load_theme_file'] ); ?></code></td>
						<td><?php esc_html_e( 'Laadt theme/assets/gf-class.css uit het child theme of parent theme.', 'engine-gravity-forms-style-manager' ); ?></td>
					</tr>
					<tr>
						<td><code><?php echo esc_html( $this->control_classes['disable_theme_css'] ); ?></code></td>
						<td><?php esc_html_e( 'Schakelt moderne Gravity Forms theme/framework styles uit voor dit formulier.', 'engine-gravity-forms-style-manager' ); ?></td>
					</tr>
					<tr>
						<td><code><?php echo esc_html( $this->control_classes['disable_legacy_css'] ); ?></code></td>
						<td><?php esc_html_e( 'Schakelt legacy/default Gravity Forms styles uit voor dit formulier.', 'engine-gravity-forms-style-manager' ); ?></td>
					</tr>
					<tr>
						<td><code><?php echo esc_html( $this->control_classes['disable_all_css'] ); ?></code></td>
						<td><?php esc_html_e( 'Probeert alle bekende Gravity Forms styles uit te schakelen. Alleen gebruiken voor simpele formulieren en goed testen.', 'engine-gravity-forms-style-manager' ); ?></td>
					</tr>
				</tbody>
			</table>

			<hr>

			<h2><?php esc_html_e( 'Submit button classes', 'engine-gravity-forms-style-manager' ); ?></h2>
			<p>
				<?php esc_html_e( 'Per formulier kun je in Form Settings extra classes invullen voor de submit button.', 'engine-gravity-forms-style-manager' ); ?>
			</p>
			<pre style="background:#fff;padding:12px;border:1px solid #ccd0d4;max-width:1100px;overflow:auto;"><code>button button--gradient full-width text-center</code></pre>

			<hr>

			<h2><?php esc_html_e( 'Voorbeelden', 'engine-gravity-forms-style-manager' ); ?></h2>
			<table class="widefat striped" style="max-width: 1100px;">
				<thead>
					<tr>
						<th style="width: 420px;"><?php esc_html_e( 'Classes in formulier', 'engine-gravity-forms-style-manager' ); ?></th>
						<th><?php esc_html_e( 'Resultaat', 'engine-gravity-forms-style-manager' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<tr>
						<td><code>egfsm-theme-file egfsm-disable-theme-css form-contact</code></td>
						<td><?php esc_html_e( 'Laadt jouw gf-class.css en zet alleen de moderne GF theme CSS uit.', 'engine-gravity-forms-style-manager' ); ?></td>
					</tr>
					<tr>
						<td><code>form-newsletter</code></td>
						<td><?php esc_html_e( 'Geen speciale pluginactie; Gravity Forms styling blijft standaard actief.', 'engine-gravity-forms-style-manager' ); ?></td>
					</tr>
					<tr>
						<td><code>egfsm-theme-file egfsm-disable-all-css form-plain</code></td>
						<td><?php esc_html_e( 'Laadt jouw gf-class.css en probeert alle bekende GF styles uit te schakelen.', 'engine-gravity-forms-style-manager' ); ?></td>
					</tr>
				</tbody>
			</table>

			<hr>

			<h2><?php esc_html_e( 'Status van gf-class.css', 'engine-gravity-forms-style-manager' ); ?></h2>
			<table class="widefat striped" style="max-width: 1100px;">
				<tbody>
					<tr>
						<th style="width: 240px;"><?php esc_html_e( 'Gevonden in', 'engine-gravity-forms-style-manager' ); ?></th>
						<td>
							<?php
							if ( $theme_file['found'] ) {
								echo '<strong style="color:green;">' . esc_html__( 'Ja', 'engine-gravity-forms-style-manager' ) . '</strong>';
								echo ' — <code>' . esc_html( $theme_file['path'] ) . '</code>';
							} else {
								echo '<strong style="color:#b32d2e;">' . esc_html__( 'Nee', 'engine-gravity-forms-style-manager' ) . '</strong>';
							}
							?>
						</td>
					</tr>
					<tr>
						<th><?php esc_html_e( 'Relatief pad', 'engine-gravity-forms-style-manager' ); ?></th>
						<td><code>assets/gf-class.css</code></td>
					</tr>
					<tr>
						<th><?php esc_html_e( 'Laadvolgorde', 'engine-gravity-forms-style-manager' ); ?></th>
						<td><?php esc_html_e( 'Eerst child theme, daarna parent theme.', 'engine-gravity-forms-style-manager' ); ?></td>
					</tr>
				</tbody>
			</table>

			<hr>

			<p>
				<?php
				printf(
					esc_html__( 'Pluginversie: %s', 'engine-gravity-forms-style-manager' ),
					esc_html( $this->version )
				);
				?>
			</p>
		</div>
		<?php
	}

	/**
	 * Add custom fields to the Gravity Forms Form Settings page.
	 *
	 * @param array $fields
	 * @param array $form
	 * @return array
	 */
	public function add_form_settings_fields( $fields, $form ) {
		if ( isset( $fields['form_button']['fields'] ) && is_array( $fields['form_button']['fields'] ) ) {
			$fields['form_button']['fields'][] = [
				'name'          => 'engine_submit_button_classes',
				'label'         => __( 'Submit button classes', 'engine-gravity-forms-style-manager' ),
				'type'          => 'text',
				'class'         => 'large',
				'tooltip'       => __( 'CSS classes die aan de Gravity Forms submit button worden toegevoegd voor dit formulier.', 'engine-gravity-forms-style-manager' ),
				'default_value' => isset( $form['engine_submit_button_classes'] ) ? $form['engine_submit_button_classes'] : '',
			];
		}

		return $fields;
	}

	/**
	 * Save custom form settings.
	 *
	 * @param array $form
	 * @return array
	 */
	public function save_form_settings( $form ) {
		$form['engine_submit_button_classes'] = isset( $_POST['engine_submit_button_classes'] )
			? sanitize_text_field( wp_unslash( $_POST['engine_submit_button_classes'] ) )
			: '';

		return $form;
	}

	/**
	 * Append configured classes to the submit button.
	 *
	 * Keeps the existing Gravity Forms button markup/attributes intact.
	 *
	 * @param string $button
	 * @param array  $form
	 * @return string
	 */
	public function filter_submit_button( $button, $form ) {
		$custom_classes = isset( $form['engine_submit_button_classes'] )
			? trim( (string) $form['engine_submit_button_classes'] )
			: '';

		if ( $custom_classes === '' ) {
			return $button;
		}

		if ( ! class_exists( 'DOMDocument' ) ) {
			// Fallback for servers without DOM extension.
			if ( preg_match( '/class=[\'"]([^\'"]*)[\'"]/', $button ) ) {
				return preg_replace(
					'/class=([\'"])([^\'"]*)([\'"])/',
					'class=$1$2 ' . esc_attr( $custom_classes ) . '$3',
					$button,
					1
				);
			}

			return preg_replace(
				'/<(input|button)\b/',
				'<$1 class="' . esc_attr( $custom_classes ) . '"',
				$button,
				1
			);
		}

		$previous = libxml_use_internal_errors( true );

		$dom = new DOMDocument();
		$dom->loadHTML(
			'<?xml encoding="utf-8" ?>' . $button,
			LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD
		);

		$button_el = $dom->getElementsByTagName( 'button' )->item( 0 );
		$input_el  = $dom->getElementsByTagName( 'input' )->item( 0 );

		if ( $button_el instanceof DOMElement ) {
			$existing = trim( $button_el->getAttribute( 'class' ) );
			$button_el->setAttribute(
				'class',
				trim( $existing . ' ' . $custom_classes )
			);

			$result = $dom->saveHTML( $button_el );
			libxml_clear_errors();
			libxml_use_internal_errors( $previous );

			return $result ? $result : $button;
		}

		if ( $input_el instanceof DOMElement ) {
			$existing = trim( $input_el->getAttribute( 'class' ) );
			$input_el->setAttribute(
				'class',
				trim( $existing . ' ' . $custom_classes )
			);

			$result = $dom->saveHTML( $input_el );
			libxml_clear_errors();
			libxml_use_internal_errors( $previous );

			return $result ? $result : $button;
		}

		libxml_clear_errors();
		libxml_use_internal_errors( $previous );

		return $button;
	}

	/**
	 * Get form classes from Gravity Forms form object.
	 *
	 * @param array $form
	 * @return array<int, string>
	 */
	private function get_form_classes( $form ) {
		$raw = '';

		if ( isset( $form['cssClass'] ) && is_string( $form['cssClass'] ) ) {
			$raw = $form['cssClass'];
		}

		$classes = preg_split( '/\s+/', trim( $raw ) );
		$classes = is_array( $classes ) ? $classes : [];

		return array_values(
			array_filter(
				array_map( 'sanitize_html_class', $classes )
			)
		);
	}

	/**
	 * Get child/parent theme gf-class.css info.
	 *
	 * @return array<string, mixed>
	 */
	private function get_theme_gf_class_file_data() {
		$relative_path = '/assets/gf-class.css';

		$child_file = trailingslashit( get_stylesheet_directory() ) . ltrim( $relative_path, '/' );
		if ( file_exists( $child_file ) ) {
			return [
				'found' => true,
				'path'  => $child_file,
				'uri'   => trailingslashit( get_stylesheet_directory_uri() ) . ltrim( $relative_path, '/' ),
				'scope' => 'child',
			];
		}

		$parent_file = trailingslashit( get_template_directory() ) . ltrim( $relative_path, '/' );
		if ( file_exists( $parent_file ) ) {
			return [
				'found' => true,
				'path'  => $parent_file,
				'uri'   => trailingslashit( get_template_directory_uri() ) . ltrim( $relative_path, '/' ),
				'scope' => 'parent',
			];
		}

		return [
			'found' => false,
			'path'  => '',
			'uri'   => '',
			'scope' => '',
		];
	}

	/**
	 * Enqueue theme/assets/gf-class.css from child or parent theme.
	 *
	 * @param array $form
	 * @return void
	 */
	private function enqueue_theme_gf_class_css( $form ) {
		$file = $this->get_theme_gf_class_file_data();

		if ( empty( $file['found'] ) || empty( $file['path'] ) || empty( $file['uri'] ) ) {
			return;
		}

		$handle = 'engine-gf-class';

		wp_enqueue_style(
			$handle,
			$file['uri'],
			[],
			(string) filemtime( $file['path'] )
		);

		wp_style_add_data(
			$handle,
			'engine_gf_form_id',
			isset( $form['id'] ) ? (string) $form['id'] : ''
		);
	}

	/**
	 * Dequeue newer Gravity Forms theme/framework styles.
	 *
	 * @return void
	 */
	private function dequeue_theme_styles() {
		$handles = apply_filters(
			'engine_gfsm_theme_style_handles',
			[
				'gravity_forms_theme_reset',
				'gravity_forms_theme_foundations',
				'gravity_forms_theme_framework',
				'gravity_forms_orbital_theme',

				'gform_theme',
				'gforms_theme',
				'gforms_theme_css',
				'gform_theme_css',
				'gform_orbital_theme',
				'gforms_orbital_theme',
				'gform_theme_components',
				'gform_theme_ie11',
			]
		);

		if ( ! is_array( $handles ) ) {
			return;
		}

		foreach ( $handles as $handle ) {
			if ( is_string( $handle ) && $handle !== '' ) {
				wp_dequeue_style( $handle );
				wp_deregister_style( $handle );
			}
		}
	}

	/**
	 * Dequeue older Gravity Forms legacy styles.
	 *
	 * @return void
	 */
	private function dequeue_legacy_styles() {
		$handles = apply_filters(
			'engine_gfsm_legacy_style_handles',
			[
				'gforms_reset_css',
				'gforms_datepicker_css',
				'gforms_formsmain_css',
				'gforms_ready_class_css',
				'gforms_browsers_css',
			]
		);

		if ( ! is_array( $handles ) ) {
			return;
		}

		foreach ( $handles as $handle ) {
			if ( is_string( $handle ) && $handle !== '' ) {
				wp_dequeue_style( $handle );
				wp_deregister_style( $handle );
			}
		}
	}

	/**
	 * Add debugging data attribute to our own stylesheet tag.
	 *
	 * @param string $html
	 * @param string $handle
	 * @param string $href
	 * @param string $media
	 * @return string
	 */
	public function maybe_tag_theme_style( $html, $handle, $href, $media ) {
		if ( 'engine-gf-class' !== $handle ) {
			return $html;
		}

		return str_replace(
			"rel='stylesheet'",
			"rel='stylesheet' data-engine-gfsm='theme-gf-class'",
			$html
		);
	}
}

Engine_Gravity_Forms_Style_Manager::instance();