<?php
/**
 * Vista «Otros plugins HUP»: las tarjetas las imprime hup-ui/otros-plugins.php.
 *
 * Se carga con `require` dentro de Admin\Menu::render(), así que sus variables
 * son locales a ese método.
 *
 * phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound
 *
 * @package HUP\CamposSeoIA
 */

defined( 'ABSPATH' ) || exit;

require __DIR__ . '/partials/layout-open.php';

$hcsai_otros = include HCSAI_PATH . 'hup-ui/otros-plugins.php';
if ( is_callable( $hcsai_otros ) ) {
    $hcsai_otros( 'hup-campos-seo-con-ia' );
}

require __DIR__ . '/partials/layout-close.php';
