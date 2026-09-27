<?php
/**
 * Inline SVG line icons.
 *
 * @package Deea
 */

defined( 'ABSPATH' ) || exit;

/**
 * Icon paths (24×24, stroke = currentColor).
 *
 * @return array
 */
function deea_icon_paths() {
	return array(
		'code'        => '<path d="m16 18 6-6-6-6M8 6l-6 6 6 6"/>',
		'app'         => '<rect x="3" y="4" width="18" height="16" rx="2"/><path d="M3 9h18M9 9v11"/>',
		'search'      => '<circle cx="11" cy="11" r="7"/><path d="m20 20-3.5-3.5"/>',
		'megaphone'   => '<path d="m3 11 18-5v12L3 14v-3z"/><path d="M11.6 16.8a3 3 0 1 1-5.8-1.6"/>',
		'pen-tool'    => '<path d="m12 19 7-7 3 3-7 7-3-3z"/><path d="m18 13-1.5-7.5L2 2l3.5 14.5L13 18l5-5z"/><path d="m2 2 7.6 7.6"/><circle cx="11" cy="11" r="2"/>',
		'camera'      => '<path d="M23 19a2 2 0 0 1-2 2H3a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h4l2-3h6l2 3h4a2 2 0 0 1 2 2z"/><circle cx="12" cy="13" r="4"/>',
		'video'       => '<path d="m23 7-7 5 7 5V7z"/><rect x="1" y="5" width="15" height="14" rx="2"/>',
		'star'        => '<path d="m12 2 3.1 6.3 6.9 1-5 4.9 1.2 6.8-6.2-3.2-6.2 3.2L7 14.2 2 9.3l6.9-1L12 2z"/>',
		'spark'       => '<path d="M12 3v4M12 17v4M3 12h4M17 12h4M5.6 5.6l2.8 2.8M15.6 15.6l2.8 2.8M5.6 18.4l2.8-2.8M15.6 8.4l2.8-2.8"/>',
		'arrow-right' => '<path d="M5 12h14M13 6l6 6-6 6"/>',
		'check'       => '<path d="M20 6 9 17l-5-5"/>',
		'calendar'    => '<rect x="3" y="4" width="18" height="18" rx="2"/><path d="M16 2v4M8 2v4M3 10h18"/>',
		'mail'        => '<rect x="2" y="4" width="20" height="16" rx="2"/><path d="m22 6-10 7L2 6"/>',
		'phone'       => '<rect x="6" y="2" width="12" height="20" rx="2"/><path d="M11 18h2"/>',
		'map-pin'     => '<path d="M20 10c0 6-8 12-8 12s-8-6-8-12a8 8 0 0 1 16 0Z"/><circle cx="12" cy="10" r="3"/>',
		'menu'        => '<path d="M3 6h18M3 12h18M3 18h18"/>',
		'users'       => '<path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.9M16 3.1a4 4 0 0 1 0 7.8"/>',
		'layers'      => '<path d="m12 2 10 5-10 5L2 7l10-5z"/><path d="m2 17 10 5 10-5M2 12l10 5 10-5"/>',
		'key'         => '<circle cx="7.5" cy="15.5" r="5.5"/><path d="m21 2-9.6 9.6M15.5 7.5l3 3L22 7l-3-3"/>',
		'trending'    => '<path d="m23 6-9.5 9.5-5-5L1 18"/><path d="M17 6h6v6"/>',
	);
}

/**
 * Icons offered for services.
 *
 * @return array
 */
function deea_service_icons() {
	return array( 'code', 'app', 'search', 'megaphone', 'pen-tool', 'camera', 'video', 'star', 'spark', 'layers', 'trending', 'users' );
}

/**
 * Return an inline SVG icon (safe, theme-controlled markup).
 *
 * @param string $name  Icon name.
 * @param string $class CSS class.
 * @return string
 */
function deea_icon( $name, $class = 'icon' ) {
	$paths = deea_icon_paths();
	$path  = isset( $paths[ $name ] ) ? $paths[ $name ] : $paths['spark'];
	return '<svg class="' . esc_attr( $class ) . '" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">' . $path . '</svg>';
}

/**
 * Allowed SVG tags for wp_kses when echoing icons.
 *
 * @return array
 */
function deea_svg_kses() {
	$common = array(
		'd'               => true,
		'x'               => true,
		'y'               => true,
		'cx'              => true,
		'cy'              => true,
		'r'               => true,
		'rx'              => true,
		'width'           => true,
		'height'          => true,
	);
	return array(
		'svg'    => array(
			'class'           => true,
			'viewbox'         => true,
			'fill'            => true,
			'stroke'          => true,
			'stroke-width'    => true,
			'stroke-linecap'  => true,
			'stroke-linejoin' => true,
			'aria-hidden'     => true,
			'focusable'       => true,
		),
		'path'   => $common,
		'rect'   => $common,
		'circle' => $common,
	);
}

/**
 * Echo an icon.
 *
 * @param string $name Icon name.
 */
function deea_the_icon( $name ) {
	echo wp_kses( deea_icon( $name ), deea_svg_kses() );
}
