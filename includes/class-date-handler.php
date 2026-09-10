<?php
/**
 * Date Handler Class
 *
 * Handles date parsing, comparison, and retrieval from post meta.
 *
 * @package PostyCal
 * @since 2.0.0
 */

declare(strict_types=1);

namespace PostyCal;

use DateTimeImmutable;
use DateTimeZone;

/**
 * Handles date operations for PostyCal.
 */
class Date_Handler {

    /**
     * Get the go-live date for a post from its meta field.
     *
     * @param int      $post_id  The post ID.
     * @param Schedule $schedule The schedule configuration.
     * @return DateTimeImmutable|null Parsed date, or null if not set.
     */
    public static function get_go_live_date( int $post_id, Schedule $schedule ): ?DateTimeImmutable {
        $value = get_post_meta( $post_id, $schedule->get_go_live_meta_key(), true );

        if ( empty( $value ) ) {
            return null;
        }

        return self::parse_date( $value );
    }

    /**
     * Get the expiration date for a post from its meta field.
     *
     * @param int      $post_id  The post ID.
     * @param Schedule $schedule The schedule configuration.
     * @return DateTimeImmutable|null Parsed date, or null if not set.
     */
    public static function get_expiration_date( int $post_id, Schedule $schedule ): ?DateTimeImmutable {
        $value = get_post_meta( $post_id, $schedule->get_expiration_meta_key(), true );

        if ( empty( $value ) ) {
            return null;
        }

        return self::parse_date( $value );
    }

    /**
     * Parse a date string into a DateTimeImmutable object.
     *
     * Handles the formats produced by HTML date/datetime-local inputs:
     *   - Y-m-d         (date input:          2025-12-25)
     *   - Y-m-d\TH:i    (datetime-local input: 2025-12-25T14:30)
     *   - Y-m-d H:i:s   (MySQL datetime)
     *   - Y-m-d H:i     (MySQL without seconds)
     *
     * @param mixed $value Raw meta value.
     * @return DateTimeImmutable|null
     */
    public static function parse_date( mixed $value ): ?DateTimeImmutable {
        if ( empty( $value ) ) {
            return null;
        }

        if ( $value instanceof DateTimeImmutable ) {
            return $value;
        }

        if ( $value instanceof \DateTime ) {
            return DateTimeImmutable::createFromMutable( $value );
        }

        if ( ! is_string( $value ) ) {
            return null;
        }

        $timezone = self::get_timezone();
        $value    = trim( $value );

        // The leading '!' resets every field the format does not set. Without
        // it createFromFormat() fills the gaps from the *current* time, so a
        // 'Y-m-d' value parses as that date at whatever o'clock it happens to
        // be — which then gets stamped onto the post as its publish date.
        $formats = [
            '!Y-m-d\TH:i:s', // datetime-local with seconds
            '!Y-m-d\TH:i',   // datetime-local (HTML input native format)
            '!Y-m-d H:i:s',  // MySQL datetime
            '!Y-m-d H:i',    // MySQL without seconds
            '!Y-m-d',        // date input (HTML input native format)
        ];

        foreach ( $formats as $format ) {
            $date   = DateTimeImmutable::createFromFormat( $format, $value, $timezone );
            $errors = DateTimeImmutable::getLastErrors();

            if ( false !== $date && ( false === $errors || ( 0 === $errors['warning_count'] && 0 === $errors['error_count'] ) ) ) {
                return $date;
            }
        }

        // Natural-language fallback (handles ISO 8601 and similar).
        try {
            return new DateTimeImmutable( $value, $timezone );
        } catch ( \Exception $e ) {
            Logger::warning( 'Failed to parse date', [ 'value' => $value, 'error' => $e->getMessage() ] );
            return null;
        }
    }

    /**
     * Get the current date at midnight in the site timezone.
     *
     * @return DateTimeImmutable
     */
    public static function get_current_date(): DateTimeImmutable {
        return new DateTimeImmutable( 'today', self::get_timezone() );
    }

    /**
     * Get the current datetime in the site timezone.
     *
     * @return DateTimeImmutable
     */
    public static function get_current_datetime(): DateTimeImmutable {
        return new DateTimeImmutable( 'now', self::get_timezone() );
    }

    /**
     * Get the WordPress site timezone.
     *
     * Falls back to a UTC-offset timezone when no named timezone is configured.
     *
     * @return DateTimeZone
     */
    public static function get_timezone(): DateTimeZone {
        $timezone_string = get_option( 'timezone_string' );

        if ( ! empty( $timezone_string ) ) {
            return new DateTimeZone( $timezone_string );
        }

        $offset  = (float) get_option( 'gmt_offset', 0 );
        $hours   = (int) $offset;
        $minutes = (int) round( abs( $offset - $hours ) * 60 );
        $sign    = $offset >= 0 ? '+' : '-';

        return new DateTimeZone( sprintf( '%s%02d:%02d', $sign, abs( $hours ), $minutes ) );
    }

    /**
     * Check whether a date is in the past relative to now.
     *
     * When $use_time is false, only the calendar date is compared (time ignored).
     * When $use_time is true, the full datetime is compared.
     *
     * @param DateTimeImmutable      $date     The date to check.
     * @param DateTimeImmutable|null $now      Reference point (defaults to current time/date).
     * @param bool                   $use_time Compare full datetime (true) or date only (false).
     * @return bool True if the date is strictly before now.
     */
    public static function is_date_past( DateTimeImmutable $date, ?DateTimeImmutable $now = null, bool $use_time = false ): bool {
        if ( $use_time ) {
            $now = $now ?? self::get_current_datetime();
            return $date < $now;
        }

        $now        = $now ?? self::get_current_date();
        $date_start = $date->setTime( 0, 0, 0 );
        $now_start  = $now->setTime( 0, 0, 0 );

        return $date_start < $now_start;
    }

    /**
     * Check whether a date has been reached (i.e. is now or earlier).
     *
     * This is the inclusive counterpart to is_date_past() and is the correct
     * comparison for go-live dates: a post whose go-live date is *today* has
     * reached its go-live date and should be published, whereas is_date_past()
     * would not report true until the following day.
     *
     * When $use_time is false, only the calendar date is compared (time ignored).
     *
     * @param DateTimeImmutable      $date     The date to check.
     * @param DateTimeImmutable|null $now      Reference point (defaults to current time/date).
     * @param bool                   $use_time Compare full datetime (true) or date only (false).
     * @return bool True if the date is now or in the past.
     */
    public static function is_date_reached( DateTimeImmutable $date, ?DateTimeImmutable $now = null, bool $use_time = false ): bool {
        if ( $use_time ) {
            $now = $now ?? self::get_current_datetime();
            return $date <= $now;
        }

        $now = $now ?? self::get_current_date();

        return $date->setTime( 0, 0, 0 ) <= $now->setTime( 0, 0, 0 );
    }

    /**
     * Format a date for display using the site's date format.
     *
     * @param DateTimeImmutable $date   The date to format.
     * @param string            $format PHP date format string. Defaults to WP date format.
     * @return string
     */
    public static function format( DateTimeImmutable $date, string $format = '' ): string {
        if ( empty( $format ) ) {
            $format = get_option( 'date_format', 'Y-m-d' );
        }

        return $date->format( $format );
    }
}
