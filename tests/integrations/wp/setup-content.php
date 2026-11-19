<?php
/**
 * Pages and forms for the integration tests. Run with `wp eval-file` on the throwaway
 * test site after the form plugins are active. Running it again updates the pages.
 * Synthetic content only.
 */

function ipk_page( $slug, $title, $content, $type = 'page', $comments = 'closed' ) {
	$args = array(
		'post_name'      => $slug,
		'post_title'     => $title,
		'post_content'   => $content,
		'post_status'    => 'publish',
		'post_type'      => $type,
		'comment_status' => $comments,
	);
	$existing = get_page_by_path( $slug, OBJECT, $type );
	if ( $existing ) {
		$args['ID'] = $existing->ID;
		return wp_update_post( wp_slash( $args ) );
	}
	return wp_insert_post( wp_slash( $args ) );
}

function ipk_existing_id( $type, $title ) {
	$p = get_posts( array( 'post_type' => $type, 'title' => $title, 'post_status' => 'any', 'numberposts' => 1 ) );
	return $p ? $p[0]->ID : 0;
}

// --- Contact Form 7 ---
function ipk_cf7( $title ) {
	$id = ipk_existing_id( 'wpcf7_contact_form', $title );
	if ( ! $id ) {
		$form = WPCF7_ContactForm::get_template( array( 'title' => $title ) );
		$id   = $form->save();
	}
	return '[contact-form-7 id="' . (int) $id . '" title="' . $title . '"]';
}
$cf7a = ipk_cf7( 'IPK CF7 A' );
$cf7b = ipk_cf7( 'IPK CF7 B' );

// --- WPForms ---
function ipk_wpforms( $title, $ajax ) {
	$id = ipk_existing_id( 'wpforms', $title );
	if ( ! $id ) {
		$id = wp_insert_post( array( 'post_type' => 'wpforms', 'post_status' => 'publish', 'post_title' => $title, 'post_content' => '{}' ) );
	}
	$data = array(
		'id'       => (string) $id,
		'field_id' => 3,
		'fields'   => array(
			0 => array( 'id' => '0', 'type' => 'name', 'label' => 'Name', 'format' => 'simple', 'required' => '1', 'size' => 'medium' ),
			1 => array( 'id' => '1', 'type' => 'email', 'label' => 'Email', 'required' => '1', 'size' => 'medium' ),
			2 => array( 'id' => '2', 'type' => 'textarea', 'label' => 'Message', 'size' => 'medium' ),
		),
		'settings' => array(
			'form_title'          => $title,
			'submit_text'         => 'Submit',
			'submit_text_processing' => 'Sending...',
			'ajax_submit'         => $ajax ? '1' : '0',
			'notification_enable' => '1',
			'notifications'       => array(
				1 => array( 'email' => '{admin_email}', 'subject' => 'WPForms entry: ' . $title, 'sender_name' => 'IPK test', 'sender_address' => '{admin_email}', 'message' => '{all_fields}' ),
			),
			'confirmations'       => array(
				1 => array( 'type' => 'message', 'message' => '<p>Thanks for contacting us! IPK-WPFORMS-OK</p>', 'message_scroll' => '1' ),
			),
		),
		'meta'     => array( 'template' => 'blank' ),
	);
	wp_update_post( array( 'ID' => $id, 'post_content' => wpforms_encode( $data ) ) );
	return '[wpforms id="' . (int) $id . '"]';
}
$wpf_ajax  = ipk_wpforms( 'IPK WPForms AJAX', true );
$wpf_ajax2 = ipk_wpforms( 'IPK WPForms AJAX 2', true );
$wpf_post  = ipk_wpforms( 'IPK WPForms postback', false );

// --- Jetpack Forms ---
function ipk_jp_block( $subject ) {
	return '<!-- wp:jetpack/contact-form {"subject":"' . $subject . '","to":"admin@example.test"} -->
<div class="wp-block-jetpack-contact-form"><!-- wp:jetpack/field-name {"required":true} /-->
<!-- wp:jetpack/field-email {"required":true} /-->
<!-- wp:jetpack/field-textarea /-->
<!-- wp:jetpack/button {"element":"button","text":"Send"} /--></div>
<!-- /wp:jetpack/contact-form -->';
}
function ipk_jp_shortcode( $subject ) {
	return '[contact-form subject="' . $subject . '" to="admin@example.test"][contact-field label="Name" type="name" required="1"/][contact-field label="Email" type="email" required="1"/][contact-field label="Message" type="textarea"/][/contact-form]';
}

// --- Pages ---
ipk_page( 'cf7', 'CF7', $cf7a );
ipk_page( 'cf7-two', 'CF7 two forms', $cf7a . "\n\n" . $cf7b );
ipk_page( 'cached-cf7', 'CF7 cached', $cf7a );

ipk_page( 'wpforms-ajax', 'WPForms AJAX', $wpf_ajax );
ipk_page( 'wpforms-post', 'WPForms postback', $wpf_post );
ipk_page( 'wpforms-two', 'WPForms two forms', $wpf_ajax . "\n\n" . $wpf_ajax2 );
ipk_page( 'cached-wpforms', 'WPForms cached', $wpf_ajax );

ipk_page( 'jetpack-block', 'Jetpack block form', ipk_jp_block( 'IPK JP block' ) );
ipk_page( 'jetpack-shortcode', 'Jetpack classic form', ipk_jp_shortcode( 'IPK JP shortcode' ) );
ipk_page( 'jetpack-two', 'Jetpack two forms', ipk_jp_block( 'IPK JP one' ) . "\n\n" . ipk_jp_block( 'IPK JP two' ) );
ipk_page( 'cached-jetpack', 'Jetpack cached', ipk_jp_block( 'IPK JP cached' ) );

ipk_page( 'ipk-comments', 'Comments', '<p>Synthetic post for comments.</p>', 'post', 'open' );
ipk_page( 'cached-comments', 'Comments cached', '<p>Synthetic post for comments, cached.</p>', 'post', 'open' );

// Several form plugins on one page, plus the comment form.
ipk_page( 'mixed', 'Mixed forms', $cf7a . "\n\n" . $wpf_ajax . "\n\n" . ipk_jp_block( 'IPK JP mixed' ), 'post', 'open' );

// --- WooCommerce (classic checkout, shortcode) ---
$product_id = wc_get_product_id_by_sku( 'IPK-TEST' );
if ( ! $product_id ) {
	$p = new WC_Product_Simple();
	$p->set_name( 'IPK test product' );
	$p->set_sku( 'IPK-TEST' );
	$p->set_regular_price( '10' );
	$p->set_virtual( true );
	$p->set_status( 'publish' );
	$product_id = $p->save();
}
update_option( 'ipk_test_product', $product_id );
$checkout = ipk_page( 'checkout', 'Checkout', '<!-- wp:shortcode -->[woocommerce_checkout]<!-- /wp:shortcode -->' );
$cart     = ipk_page( 'cart', 'Cart', '<!-- wp:shortcode -->[woocommerce_cart]<!-- /wp:shortcode -->' );
update_option( 'woocommerce_checkout_page_id', $checkout );
update_option( 'woocommerce_cart_page_id', $cart );
update_option( 'woocommerce_default_country', 'DK' );
update_option( 'woocommerce_currency', 'DKK' );
update_option( 'woocommerce_coming_soon', 'no' );
update_option( 'woocommerce_store_pages_only', 'no' );
update_option( 'woocommerce_enable_guest_checkout', 'yes' );
update_option( 'woocommerce_bacs_settings', array( 'enabled' => 'yes', 'title' => 'Direct bank transfer' ) );

echo wp_json_encode( array( 'product' => $product_id ) ) . "\n";
