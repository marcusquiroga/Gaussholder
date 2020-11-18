<?php

namespace Gaussholder\Frontend;

use Gaussholder;
use Gaussholder\JPEG;

/**
 * Set up hooked callbacks on plugins_loaded
 */
function bootstrap() {
	add_action( 'wp_footer', __NAMESPACE__ . '\\output_script' );

	add_filter( 'the_content', __NAMESPACE__ . '\\mangle_images', 30 );
	add_filter( 'wp_get_attachment_image_attributes', __NAMESPACE__ .  '\\add_placeholder_to_attributes', 10, 3 );
}

/**
 * Output the Gaussholder script onto the page.
 */
function output_script() {
	echo '<script>';

	// Output header onto the page
	$header = JPEG\build_header();
	$header['header'] = base64_encode( $header['header'] );
	echo 'var GaussholderHeader = ' . json_encode( $header ) . ";\n";

	echo file_get_contents( Gaussholder\PLUGIN_DIR . '/dist/gaussholder.min.js' ) . "\n";

	echo '</script>';

	// Clipping path for Firefox compatibility on fade in
	echo '<svg width="0" height="0" style="position: absolute"><clipPath id="gaussclip" clipPathUnits="objectBoundingBox"><rect width="1" height="1"></rect></clipPath></svg>';
}

/**
 * Mangle <img> tags in the post content.
 *
 * Replaces the <img> tag src to stop browsers loading the source early, as well
 * as adding the Gaussholder data.
 * @param [type] $content [description]
 * @return [type] [description]
 */
function mangle_images( $content ) {
	// Find images
	$searcher = '#<img[^>]+(?:class=[\'"]([^\'"]*wp-image-(\d+)[^\'"]*)|data-gaussholder-id="(\d+)")[^>]+>#x';
	$preg_match_result = preg_match_all( $searcher, $content, $images, PREG_SET_ORDER );
	/**
	 * Filter the regexp results when looking for images in a post content.
	 *
	 * By default, Gaussholder applies the $searcher regexp inside the_content filter callback. Some page builders
	 * manage images in different ways so the result could be false.
	 *
	 * This filter allows to change that result but also the images list generated by preg_match_all( $searcher, $content, $images, PREG_SET_ORDER )
	 * The $images parameter must be returned with the same format. That is:
	 *
	 * [
	 *  0 => Image tag (<img class="..." src="..." ... />)
	 *  1 => Image tag class
	 *  2 => Attachment ID
	 * ]
	 */
	$preg_match_result = apply_filters_ref_array( 'gaussholder.mangle_images_regexp_results', [ $preg_match_result, &$images, $content, $searcher ] );
	if ( ! $preg_match_result ) {
		return $content;
	}

	$blank = file_get_contents( Gaussholder\PLUGIN_DIR . '/assets/blank.gif' );
	$blank_url = 'data:image/gif;base64,' . base64_encode( $blank );

	foreach ( $images as $image ) {
		$tag = $image[0];
		if ( ! empty( $image[2] ) ) {
			// Singular image, using `class="wp-image-<id>"`
			$id = $image[2];
			$class = $image[1];

			// Attempt to get the image size from a size class.
			if ( ! preg_match( '#\bsize-([\w-]+)\b#', $class, $size_match ) ) {
				// If we don't have a size class, the only other option is to search
				// all the URLs for image sizes that we support, and see if the src
				// attribute matches.
				preg_match( '#\bsrc=[\'|"]([^\'"]*)#', $tag, $src_match );
				$all_sizes = array_keys( Gaussholder\get_enabled_sizes() );
				foreach ( $all_sizes as $single_size ) {
					$url = wp_get_attachment_image_src( $id, $single_size );
					// WordPress applies esc_attr (and sometimes esc_url) to all image attributes,
					// so we have decode entities when making a comparison.
					if ( $url[0] === html_entity_decode( $src_match[1] ) ) {
						$size = $single_size;
						break;
					}
				}
				// If we still were not able to find the image size from the src
				// attribute, then skip this image.
				if ( ! isset( $size ) ) {
					continue;
				}
			} else {
				$size = $size_match[1];
			}

		} else {
			// Gallery, using `data-gaussholder-id="<id>"`
			$id = $image[3];
			if ( ! preg_match( '# class=[\'"][^\'"]*\battachment-([\w-]+)\b#', $tag, $size_match ) ) {
				continue;
			}
			$size = $size_match[1];
		}

		if ( ! Gaussholder\is_enabled_size( $size ) ) {
			continue;
		}

		$new_attrs = array();

		// Replace src with our blank GIF
		$new_attrs[] = 'src="' . esc_attr( $blank_url ) . '"';

		// Remove srcset
		$new_attrs[] = 'srcset=""';

		// Add the actual placeholder
		$placeholder = Gaussholder\get_placeholder( $id, $size );
		$new_attrs[] = 'data-gaussholder="' . esc_attr( $placeholder ) . '"';

		// Add final size
		$image_data = wp_get_attachment_image_src( $id, $size );
		$size_data = [
			'width'  => $image_data[1],
			'height' => $image_data[2],
		];
		$radius = Gaussholder\get_blur_radius_for_size( $size );

		// Has the size been overridden?
		if ( preg_match( '#height=[\'"](\d+)[\'"]#i', $tag, $matches ) ) {
			$size_data['height'] = absint( $matches[1] );
		}
		if ( preg_match( '#width=[\'"](\d+)[\'"]#i', $tag, $matches ) ) {
			$size_data['width'] = absint( $matches[1] );
		}
		$new_attrs[] = sprintf(
			'data-gaussholder-size="%s,%s,%s"',
			$size_data['width'],
			$size_data['height'],
			$radius
		);

		$mangled_tag = str_replace(
			array(
				' srcset="',
				' src="',
				),
			array(
				' data-originalsrcset="',
				' ' . implode( ' ', $new_attrs ) . ' data-originalsrc="',
				),
			$tag
		);

		$content = str_replace( $tag, $mangled_tag, $content );
	}

	return $content;
}

/**
 * Adds a style attribute to image HTML.
 *
 * @param $attr
 * @param $attachment
 * @param $size
 *
 * @return mixed
 */
function add_placeholder_to_attributes( $attr, $attachment, $size ) {
	// Are placeholders enabled for this size?
	if ( ! Gaussholder\is_enabled_size( $size ) ) {
		return $attr;
	}

	$attr['data-gaussholder-id'] = $attachment->ID;

	return $attr;
}
