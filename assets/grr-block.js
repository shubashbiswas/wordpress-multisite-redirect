/**
 * Geo Regional Router - Gutenberg Block Registration
 * Registers the "Regional Store Switcher" block in Gutenberg Block Inserter (under Widgets).
 */
(function (wp) {
	'use strict';

	if (!wp || !wp.blocks || !wp.element) {
		return;
	}

	var registerBlockType = wp.blocks.registerBlockType;
	var el = wp.element.createElement;
	var __ = wp.i18n.__;
	var InspectorControls = wp.blockEditor ? wp.blockEditor.InspectorControls : wp.editor.InspectorControls;
	var PanelBody = wp.components.PanelBody;
	var SelectControl = wp.components.SelectControl;

	registerBlockType('grr/region-switcher', {
		title: __('Regional Store Switcher', 'geo-regional-router'),
		description: __('Displays location pin and regional switcher button with modal selector.', 'geo-regional-router'),
		category: 'widgets',
		icon: 'location-alt',
		keywords: [
			__('region', 'geo-regional-router'),
			__('country', 'geo-regional-router'),
			__('store', 'geo-regional-router'),
			__('switcher', 'geo-regional-router')
		],
		attributes: {
			style: {
				type: 'string',
				default: 'cart'
			}
		},
		supports: {
			html: false,
			align: ['left', 'center', 'right']
		},
		edit: function (props) {
			var attributes = props.attributes;
			var setAttributes = props.setAttributes;
			var currentStyle = attributes.style || 'cart';

			var previewLabel = currentStyle === 'footer'
				? '📍 Region: BD ▾'
				: '📍 BD';

			var controls = el(
				InspectorControls,
				{ key: 'grr-controls' },
				el(
					PanelBody,
					{ title: __('Switcher Options', 'geo-regional-router'), initialOpen: true },
					el(SelectControl, {
						label: __('Display Style', 'geo-regional-router'),
						value: currentStyle,
						options: [
							{ label: __('Compact Header Pill (📍 BD)', 'geo-regional-router'), value: 'cart' },
							{ label: __('Full Button (📍 Region: BD ▾)', 'geo-regional-router'), value: 'footer' }
						],
						onChange: function (newVal) {
							setAttributes({ style: newVal });
						}
					})
				)
			);

			var blockPreview = el(
				'div',
				{
					key: 'grr-preview',
					className: 'grr-block-preview',
					style: {
						display: 'inline-flex',
						alignItems: 'center',
						gap: '8px',
						padding: '8px 14px',
						borderRadius: '8px',
						border: '1.5px solid #2563eb',
						background: 'rgba(37, 99, 235, 0.05)',
						color: '#0f172a',
						fontFamily: 'inherit',
						fontSize: '13px',
						fontWeight: '600',
						cursor: 'default',
						userSelect: 'none'
					}
				},
				previewLabel,
				el(
					'span',
					{
						style: {
							fontSize: '10px',
							fontWeight: '700',
							textTransform: 'uppercase',
							padding: '2px 6px',
							borderRadius: '4px',
							background: '#2563eb',
							color: '#ffffff',
							marginLeft: '6px'
						}
					},
					__('Live Switcher', 'geo-regional-router')
				)
			);

			return [controls, blockPreview];
		},
		save: function () {
			// Dynamic server-side rendering via render_callback
			return null;
		}
	});
})(window.wp);
