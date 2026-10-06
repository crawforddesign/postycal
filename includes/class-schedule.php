<?php
/**
 * Schedule Data Class
 *
 * Represents a single PostyCal schedule configuration.
 *
 * @package PostyCal
 * @since 2.0.0
 */

declare(strict_types=1);

namespace PostyCal;

/**
 * Schedule data object.
 */
class Schedule {

    /**
     * Schedule name.
     *
     * @var string
     */
    public readonly string $name;

    /**
     * Post type to monitor.
     *
     * @var string
     */
    public readonly string $post_type;

    /**
     * Taxonomy for term assignment.
     *
     * @var string
     */
    public readonly string $taxonomy;

    /**
     * Term slug assigned before the go-live date.
     *
     * @var string
     */
    public readonly string $upcoming_term;

    /**
     * Term slug assigned between go-live and expiration dates.
     *
     * @var string
     */
    public readonly string $active_term;

    /**
     * Term slug assigned after the expiration date.
     *
     * @var string
     */
    public readonly string $past_term;

    /**
     * Whether to compare datetimes (true) or date-only (false).
     *
     * @var bool
     */
    public readonly bool $use_time;

    /**
     * Whether the post list swaps the Date column for Go-Live and Expiration columns.
     *
     * @var bool
     */
    public readonly bool $show_columns;

    /**
     * Stable unique key used to derive post meta keys.
     *
     * Generated once on schedule creation and preserved across updates
     * so that meta keys remain stable if the schedule name changes.
     *
     * @var string
     */
    public readonly string $schedule_key;

    /**
     * Constructor.
     *
     * @param array<string, mixed> $data Schedule data array.
     */
    public function __construct( array $data ) {
        $this->name          = sanitize_text_field( $data['name'] ?? '' );
        $this->post_type     = sanitize_key( $data['post_type'] ?? '' );
        $this->taxonomy      = sanitize_key( $data['taxonomy'] ?? '' );
        $this->upcoming_term = sanitize_title( $data['upcoming_term'] ?? '' );
        $this->active_term   = sanitize_title( $data['active_term'] ?? '' );
        $this->past_term     = sanitize_title( $data['past_term'] ?? '' );
        $this->use_time      = (bool) ( $data['use_time'] ?? false );
        $this->show_columns  = (bool) ( $data['show_columns'] ?? false );
        $this->schedule_key  = ! empty( $data['schedule_key'] )
            ? sanitize_key( $data['schedule_key'] )
            : $this->generate_key();
    }

    /**
     * Generate a stable unique key for this schedule.
     *
     * @return string
     */
    private function generate_key(): string {
        return 'pc_' . substr( md5( uniqid( '', true ) ), 0, 8 );
    }

    /**
     * Get the post meta key for the go-live date.
     *
     * @return string
     */
    public function get_go_live_meta_key(): string {
        return '_postycal_' . $this->schedule_key . '_go_live';
    }

    /**
     * Get the post meta key for the expiration date.
     *
     * @return string
     */
    public function get_expiration_meta_key(): string {
        return '_postycal_' . $this->schedule_key . '_expiration';
    }

    /**
     * Get the post meta key for this schedule's per-post override.
     *
     * @return string
     */
    public function get_override_meta_key(): string {
        return '_postycal_' . $this->schedule_key . '_override';
    }

    /**
     * Check if schedule configuration is complete.
     *
     * @return bool True if all required fields are present.
     */
    public function is_valid(): bool {
        if ( empty( $this->name ) || empty( $this->post_type ) || empty( $this->taxonomy ) ) {
            return false;
        }

        if ( empty( $this->upcoming_term ) || empty( $this->active_term ) || empty( $this->past_term ) ) {
            return false;
        }

        return true;
    }

    /**
     * Validate that the referenced post type is registered.
     *
     * @return bool
     */
    public function post_type_exists(): bool {
        return post_type_exists( $this->post_type );
    }

    /**
     * Validate that the referenced taxonomy is registered.
     *
     * @return bool
     */
    public function taxonomy_exists(): bool {
        return taxonomy_exists( $this->taxonomy );
    }

    /**
     * Validate that all three terms exist in the taxonomy.
     *
     * @return array<string, bool> Keys: upcoming, active, past.
     */
    public function terms_exist(): array {
        return [
            'upcoming' => term_exists( $this->upcoming_term, $this->taxonomy ) !== null,
            'active'   => term_exists( $this->active_term, $this->taxonomy ) !== null,
            'past'     => term_exists( $this->past_term, $this->taxonomy ) !== null,
        ];
    }

    /**
     * Convert schedule to a plain array for storage.
     *
     * @return array<string, string|bool>
     */
    public function to_array(): array {
        return [
            'name'          => $this->name,
            'post_type'     => $this->post_type,
            'taxonomy'      => $this->taxonomy,
            'upcoming_term' => $this->upcoming_term,
            'active_term'   => $this->active_term,
            'past_term'     => $this->past_term,
            'use_time'      => $this->use_time,
            'show_columns'  => $this->show_columns,
            'schedule_key'  => $this->schedule_key,
        ];
    }

    /**
     * Check if this schedule applies to a post type.
     *
     * @param string $post_type Post type slug.
     * @return bool
     */
    public function matches_post_type( string $post_type ): bool {
        return $this->post_type === $post_type;
    }
}
