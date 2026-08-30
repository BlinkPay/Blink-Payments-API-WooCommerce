/**
 * Registers BlinkPay on the block-based checkout. No build step: relies on the
 * globals WooCommerce Blocks exposes.
 */
( function () {
	'use strict';

	var registerPaymentMethod = window.wc.wcBlocksRegistry.registerPaymentMethod;
	var getSetting = window.wc.wcSettings.getSetting;
	var decodeEntities = window.wp.htmlEntities.decodeEntities;
	var createElement = window.wp.element.createElement;

	var settings = getSetting( 'blinkpay_data', {} );
	var title = decodeEntities( settings.title || 'Checkout with BlinkPay' );

	// The label pairs the title with the BlinkPay logo when one is configured.
	var label = createElement(
		'span',
		{ style: { display: 'flex', alignItems: 'center', gap: '0.5em', width: '100%' } },
		title,
		settings.icon
			? createElement( 'img', {
				src: settings.icon,
				alt: '',
				style: { maxHeight: '24px', marginLeft: 'auto' },
			} )
			: null
	);

	var Content = function () {
		return createElement( 'div', null, decodeEntities( settings.description || '' ) );
	};

	registerPaymentMethod( {
		name: 'blinkpay',
		label: label,
		ariaLabel: title,
		content: createElement( Content ),
		edit: createElement( Content ),
		canMakePayment: function () {
			return true;
		},
		supports: {
			features: settings.supports || [ 'products' ],
		},
	} );
} )();
