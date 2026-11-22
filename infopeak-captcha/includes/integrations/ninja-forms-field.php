<?php
/**
 * The "InfoPeak Captcha" field type for Ninja Forms, built like Ninja Forms' own reCAPTCHA
 * and Turnstile fields. Loaded from ninja-forms.php once Ninja Forms registers its fields.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class IPKCAP_Ninja_Forms_Field extends NF_Abstracts_Field {

	protected $_name = 'infopeak_captcha';

	protected $_type = 'infopeak_captcha';

	protected $_section = 'misc';

	protected $_icon = 'shield';

	// The browser template is fields-infopeak-captcha.html (tmpl-nf-field-infopeak-captcha).
	protected $_templates = 'infopeak-captcha';

	protected $_test_value = '';

	protected $_settings = array( 'label', 'label_pos', 'classes' );

	public function __construct() {
		parent::__construct();
		$this->_nicename = esc_html__( 'InfoPeak Captcha', 'infopeak-captcha' );
		if ( isset( $this->_settings['label'] ) ) {
			$this->_settings['label']['value'] = esc_html__( 'InfoPeak Captcha', 'infopeak-captcha' );
		}
		if ( isset( $this->_settings['label_pos'] ) ) {
			$this->_settings['label_pos']['value'] = 'hidden';
		}
		add_filter( 'nf_sub_hidden_field_types', array( $this, 'hide_field_type' ) );
	}

	// Runs while a form with this field is drawn: the widget needs its sitekey and script.
	public function localize_settings( $settings, $form_id ) {
		$settings['site_key'] = esc_attr( ipkcap_get_settings()['sitekey'] );
		$settings['ipkcap_on'] = ipkcap_integration_active( 'enable_ninja' ) ? 1 : 0;
		if ( $settings['ipkcap_on'] ) {
			ipkcap_enqueue_for_forms();
		}
		return $settings;
	}

	// Ninja Forms' check of each field, before the submission is stored and before any action
	// (email, save, redirect) runs. The message is shown under this field.
	public function validate( $field, $data ) {
		if ( ! ipkcap_integration_active( 'enable_ninja' ) ) {
			return array();
		}
		$token = isset( $field['value'] ) && is_string( $field['value'] ) ? sanitize_text_field( $field['value'] ) : '';
		if ( ! ipkcap_verify_token( $token ) ) {
			return ipkcap_error_message();
		}
		return array();
	}

	// The token has done its job: it is not stored with the submission or put in emails.
	public function process( $field, $data ) {
		if ( isset( $field['id'] ) && isset( $data['fields'][ $field['id'] ] ) && is_array( $data['fields'][ $field['id'] ] ) ) {
			$data['fields'][ $field['id'] ]['value'] = '';
		}
		return $data;
	}

	public function hide_field_type( $field_types ) {
		$field_types[] = $this->_name;
		return $field_types;
	}
}
