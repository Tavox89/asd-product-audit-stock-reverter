<?php

namespace ASDLabs\TVXWooChangeLog\Support;

use DateTimeImmutable;
use DateTimeZone;

final class Time {
	public static function now_utc() {
		return gmdate( 'Y-m-d H:i:s' );
	}

	public static function utc_to_site_datetime( $utc_datetime ) {
		$utc_datetime = trim( (string) $utc_datetime );
		if ( '' === $utc_datetime ) {
			return '';
		}

		$timestamp = strtotime( $utc_datetime . ' UTC' );
		if ( ! $timestamp ) {
			return '';
		}

		return wp_date( 'Y-m-d H:i:s', $timestamp, wp_timezone() );
	}

	public static function site_now() {
		return wp_date( 'Y-m-d H:i:s', null, wp_timezone() );
	}

	public static function site_date_to_utc_boundary( $date, $boundary = 'start' ) {
		$date = sanitize_text_field( (string) $date );
		if ( '' === $date ) {
			return '';
		}

		$suffix   = ( 'end' === $boundary ) ? '23:59:59' : '00:00:00';
		$timezone = wp_timezone();
		$utc      = new DateTimeZone( 'UTC' );

		$datetime = DateTimeImmutable::createFromFormat( 'Y-m-d H:i:s', $date . ' ' . $suffix, $timezone );
		if ( ! $datetime ) {
			return '';
		}

		return $datetime->setTimezone( $utc )->format( 'Y-m-d H:i:s' );
	}
}
