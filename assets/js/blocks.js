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
	var label = decodeEntities( settings.title || 'Pay by bank with BlinkPay' );

	var Content = function () {
		return createElement( 'div', null, decodeEntities( settings.description || '' ) );
	};

	registerPaymentMethod( {
		name: 'blinkpay',
		label: label,
		ariaLabel: label,
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
