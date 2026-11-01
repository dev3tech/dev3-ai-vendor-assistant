<?php
/**
 * Vendor photo uploads and resized image payloads for vision prompts.
 *
 * @package Dev3\AiVendorAssistant
 */

namespace Dev3\AiVendorAssistant\Features;

use Dev3\AiVendorAssistant\Core3\Api\Api_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Photo helpers.
 */
class Photos {

	const MAX_BYTES = 8388608;
	const MIMES     = array( 'image/jpeg', 'image/png', 'image/webp' );

	/**
	 * Stores an uploaded file as an attachment owned by the current user.
	 *
	 * @param array $file $_FILES entry.
	 * @return array|\WP_Error { id, url, thumb }
	 */
	public static function upload( array $file ) {
		if ( empty( $file['tmp_name'] ) || ! empty( $file['error'] ) ) {
			return Api_Error::make( 'dev3va_upload_failed', __( 'The photo could not be uploaded.', 'dev3-ai-vendor-assistant' ), '', 400 );
		}
		if ( (int) $file['size'] > self::MAX_BYTES ) {
			return Api_Error::make( 'dev3va_photo_too_big', __( 'Photos can be up to 8 MB.', 'dev3-ai-vendor-assistant' ), '', 413 );
		}
		$check = wp_check_filetype_and_ext( $file['tmp_name'], $file['name'] );
		$type  = $check['type'] ? $check['type'] : '';
		if ( ! in_array( $type, self::MIMES, true ) ) {
			return Api_Error::make( 'dev3va_photo_type', __( 'Use a JPEG, PNG or WebP photo.', 'dev3-ai-vendor-assistant' ), '', 415 );
		}
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/media.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';
		$moved = wp_handle_upload(
			$file,
			array(
				'test_form' => false,
				'mimes'     => array(
					'jpg|jpeg|jpe' => 'image/jpeg',
					'png'          => 'image/png',
					'webp'         => 'image/webp',
				),
			)
		);
		if ( isset( $moved['error'] ) ) {
			return Api_Error::make( 'dev3va_upload_failed', (string) $moved['error'], '', 400 );
		}
		$id = wp_insert_attachment(
			array(
				'post_mime_type' => $moved['type'],
				'post_title'     => sanitize_file_name( pathinfo( $file['name'], PATHINFO_FILENAME ) ),
				'post_status'    => 'inherit',
				'post_author'    => get_current_user_id(),
			),
			$moved['file']
		);
		if ( is_wp_error( $id ) || ! $id ) {
			return Api_Error::make( 'dev3va_upload_failed', __( 'The photo could not be saved.', 'dev3-ai-vendor-assistant' ), '', 500 );
		}
		wp_update_attachment_metadata( $id, wp_generate_attachment_metadata( $id, $moved['file'] ) );
		return self::shape( $id );
	}

	/**
	 * Attachment summary.
	 *
	 * @param int $id Attachment.
	 * @return array
	 */
	public static function shape( $id ) {
		$thumb = wp_get_attachment_image_url( $id, 'thumbnail' );
		return array(
			'id'    => (int) $id,
			'url'   => (string) wp_get_attachment_url( $id ),
			'thumb' => $thumb ? (string) $thumb : (string) wp_get_attachment_url( $id ),
			'alt'   => (string) get_post_meta( $id, '_wp_attachment_image_alt', true ),
		);
	}

	/**
	 * Base64 payloads (max 1024 px) for Gemini vision.
	 *
	 * @param int[] $ids Attachments (ownership checked by the caller).
	 * @return array[] { mime, data }
	 */
	public static function payload( array $ids ) {
		$out = array();
		foreach ( $ids as $id ) {
			$path = get_attached_file( (int) $id );
			if ( ! $path || ! is_readable( $path ) ) {
				continue;
			}
			$mime                 = (string) get_post_mime_type( (int) $id );
			list( $bytes, $type ) = self::resized( $path, $mime );
			if ( '' === $bytes ) {
				continue;
			}
			$out[] = array(
				'mime' => $type,
				'data' => base64_encode( $bytes ), // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- image payload for the Gemini API.
			);
		}
		return $out;
	}

	/**
	 * Reads a file.
	 *
	 * @param string $path Path.
	 * @return string
	 */
	private static function read( $path ) {
		$data = file_get_contents( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- local upload.
		return false === $data ? '' : $data;
	}

	/**
	 * Bytes of a JPEG copy no larger than 1024 px (or the original when it is small enough).
	 *
	 * @param string $path Path.
	 * @param string $mime Mime.
	 * @return array { 0: bytes, 1: mime }
	 */
	private static function resized( $path, $mime ) {
		$size     = @getimagesize( $path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		$original = array( filesize( $path ) <= 4194304 ? self::read( $path ) : '', $mime );
		if ( $size && max( $size[0], $size[1] ) <= 1024 && filesize( $path ) <= 1572864 ) {
			return $original;
		}
		$editor = wp_get_image_editor( $path );
		if ( is_wp_error( $editor ) ) {
			return $original;
		}
		$editor->resize( 1024, 1024, false );
		$editor->set_quality( 82 );
		$tmp   = wp_tempnam( 'dev3va' );
		$saved = $editor->save( $tmp . '.jpg', 'image/jpeg' );
		wp_delete_file( $tmp );
		if ( is_wp_error( $saved ) || empty( $saved['path'] ) ) {
			return $original;
		}
		$bytes = self::read( $saved['path'] );
		wp_delete_file( $saved['path'] );
		return array( $bytes, 'image/jpeg' );
	}
}
