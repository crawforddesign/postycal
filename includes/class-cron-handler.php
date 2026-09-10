<?php
/**
 * Cron Handler Class
 *
 * Handles scheduled tasks and post lifecycle transitions.
 *
 * Transition rules:
 *   - Posts in the upcoming term whose go-live date has arrived (inclusive —
 *     a go-live date of today counts) are published and moved to the active
 *     term, and stamped with the go-live date as their post date.
 *   - Published posts in the active term whose expiration date has passed
 *     are set to private and moved to the past term. Expiration is exclusive,
 *     so a post stays live for the whole of its expiration day.
 *
 * On manual post save, the correct term is set immediately based on the
 * current date vs the stored dates. Post status changes are deferred to
 * the cron so editors retain control during the current editing session.
 *
 * Any post can opt out of the date-driven rules with a per-post override
 * (see the Override class): either held entirely untouched, or pinned to a
 * specific lifecycle state regardless of its dates.
 *
 * @package PostyCal
 * @since 2.0.0
 */

declare(strict_types=1);

namespace PostyCal;

/**
 * Handles cron operations and post lifecycle for PostyCal.
 */
class Cron_Handler {

    /**
     * Schedule manager instance.
     *
     * @var Schedule_Manager
     */
    private Schedule_Manager $schedule_manager;

    /**
     * Guard flag to prevent re-entrant save_post processing when
     * wp_update_post is called from within a save_post hook.
     *
     * @var bool
     */
    private bool $is_updating = false;

    /**
     * How many posts to hydrate at a time when walking a result set.
     *
     * @var int
     */
    private const CHUNK_SIZE = 200;

    /**
     * Constructor.
     *
     * @param Schedule_Manager $schedule_manager The schedule manager.
     */
    public function __construct( Schedule_Manager $schedule_manager ) {
        $this->schedule_manager = $schedule_manager;
    }

    // -------------------------------------------------------------------------
    // Cron entry point
    // -------------------------------------------------------------------------

    /**
     * Process all schedules (called by the daily cron event).
     *
     * @return void
     */
    public function process_all_schedules(): void {
        $schedules = $this->schedule_manager->get_all();

        if ( empty( $schedules ) ) {
            Logger::debug( 'No schedules to process' );
            return;
        }

        Logger::info( 'Starting scheduled check', [ 'schedule_count' => count( $schedules ) ] );

        foreach ( $schedules as $schedule ) {
            $this->process_schedule( $schedule );
        }

        Logger::info( 'Completed scheduled check' );
    }

    /**
     * Process a single schedule: check go-live and expiration transitions.
     *
     * @param Schedule $schedule The schedule to process.
     * @return void
     */
    private function process_schedule( Schedule $schedule ): void {
        if ( ! $schedule->taxonomy_exists() ) {
            Logger::warning(
                'Skipping schedule with invalid taxonomy',
                [ 'schedule' => $schedule->name, 'taxonomy' => $schedule->taxonomy ]
            );
            return;
        }

        $published = $this->process_go_live_transitions( $schedule );
        $expired   = $this->process_expiry_transitions( $schedule );
        $pinned    = $this->process_overrides( $schedule );

        if ( $published > 0 || $expired > 0 || $pinned > 0 ) {
            Logger::info(
                'Processed schedule transitions',
                [
                    'schedule'  => $schedule->name,
                    'published' => $published,
                    'expired'   => $expired,
                    'pinned'    => $pinned,
                ]
            );
        }
    }

    /**
     * Publish posts whose go-live date has arrived.
     *
     * Also handles the edge case where both dates have already passed
     * (goes directly to the expired state).
     *
     * @param Schedule $schedule The schedule.
     * @return int Number of posts actually transitioned.
     */
    private function process_go_live_transitions( Schedule $schedule ): int {
        // 'publish' is included deliberately: an editor who publishes a post
        // manually before its go-live date leaves it published but still in
        // the upcoming term, and it would otherwise never be picked up by
        // either pass again. Posts already in their correct state are no-ops.
        $post_ids = $this->get_posts_by_terms(
            $schedule,
            [ $schedule->upcoming_term, $schedule->active_term ],
            [ 'draft', 'pending', 'publish' ]
        );

        $count = 0;

        foreach ( $this->in_chunks( $post_ids ) as $post_id ) {
            if ( ! Override::is_automatic( $this->get_override( $post_id, $schedule ) ) ) {
                continue;
            }

            $go_live = Date_Handler::get_go_live_date( $post_id, $schedule );

            if ( null === $go_live ) {
                continue;
            }

            // Inclusive: a post whose go-live date is *today* has arrived.
            if ( ! Date_Handler::is_date_reached( $go_live, null, $schedule->use_time ) ) {
                continue;
            }

            $expiration = Date_Handler::get_expiration_date( $post_id, $schedule );

            if ( null !== $expiration && Date_Handler::is_date_past( $expiration, null, $schedule->use_time ) ) {
                // Both dates passed — expire directly without going through active.
                $changed = $this->apply_expiry( $post_id, $schedule );
            } else {
                $changed = $this->apply_go_live( $post_id, $schedule, $go_live );
            }

            if ( $changed ) {
                ++$count;
            }
        }

        return $count;
    }

    /**
     * Expire published posts whose expiration date has passed.
     *
     * @param Schedule $schedule The schedule.
     * @return int Number of posts transitioned.
     */
    private function process_expiry_transitions( Schedule $schedule ): int {
        $post_ids = $this->get_posts_by_terms(
            $schedule,
            [ $schedule->active_term ],
            [ 'publish' ]
        );

        $count = 0;

        foreach ( $this->in_chunks( $post_ids ) as $post_id ) {
            if ( ! Override::is_automatic( $this->get_override( $post_id, $schedule ) ) ) {
                continue;
            }

            $expiration = Date_Handler::get_expiration_date( $post_id, $schedule );

            if ( null === $expiration ) {
                continue;
            }

            if ( ! Date_Handler::is_date_past( $expiration, null, $schedule->use_time ) ) {
                continue;
            }

            if ( $this->apply_expiry( $post_id, $schedule ) ) {
                ++$count;
            }
        }

        return $count;
    }

    /**
     * Reconcile posts pinned to a state by a per-post override.
     *
     * These posts are skipped by the date-driven passes, so they need their
     * own query — a pinned post is not necessarily in any of the terms those
     * passes look at. Posts set to HOLD are never matched here, which is the
     * whole point of that value.
     *
     * @param Schedule $schedule The schedule.
     * @return int Number of posts actually changed.
     */
    private function process_overrides( Schedule $schedule ): int {
        // Unlike the other passes this one is meta-driven, not term-driven, so
        // a broken taxonomy would not stop it finding posts and changing their
        // status. Bail explicitly.
        if ( ! $schedule->taxonomy_exists() ) {
            return 0;
        }

        $query = new \WP_Query(
            [
                'post_type'              => $schedule->post_type,
                'post_status'            => 'any',
                'posts_per_page'         => -1,
                'no_found_rows'          => true,
                'fields'                 => 'ids',
                'update_post_meta_cache' => false,
                'update_post_term_cache' => false,
                'meta_query'             => [
                    [
                        'key'     => $schedule->get_override_meta_key(),
                        'value'   => Override::pinned_values(),
                        'compare' => 'IN',
                    ],
                ],
            ]
        );

        $count = 0;

        foreach ( $this->in_chunks( array_map( 'intval', $query->posts ) ) as $post_id ) {
            $override = $this->get_override( $post_id, $schedule );
            $term     = Override::term_for( $override, $schedule );
            $status   = Override::status_for( $override );

            if ( null === $term || null === $status ) {
                continue;
            }

            $term_changed   = $this->set_post_term( $post_id, $schedule, $term );
            $status_changed = $this->update_post_status( $post_id, $status );

            if ( ! $term_changed && ! $status_changed ) {
                continue;
            }

            Logger::info(
                'Applied schedule override',
                [ 'post_id' => $post_id, 'schedule' => $schedule->name, 'override' => $override ]
            );

            ++$count;
        }

        return $count;
    }

    // -------------------------------------------------------------------------
    // Save-post entry point
    // -------------------------------------------------------------------------

    /**
     * Assign the correct taxonomy term when a post is saved.
     *
     * Called by save_post at priority 20, after meta box data has been
     * saved at priority 10. Only sets the term — post status changes
     * are handled by the cron so editors aren't surprised by immediate
     * publish/private transitions while they are actively editing.
     *
     * @param int $post_id The post ID.
     * @return void
     */
    public function assign_term_on_save( int $post_id ): void {
        // Prevent re-entrant calls triggered by wp_update_post inside this class.
        if ( $this->is_updating ) {
            return;
        }

        if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
            return;
        }

        if ( wp_is_post_revision( $post_id ) ) {
            return;
        }

        $post_type = get_post_type( $post_id );

        if ( false === $post_type ) {
            return;
        }

        $schedules = $this->schedule_manager->get_for_post_type( $post_type );

        if ( empty( $schedules ) ) {
            return;
        }

        foreach ( $schedules as $schedule ) {
            $this->set_term_by_dates( $post_id, $schedule );
        }
    }

    // -------------------------------------------------------------------------
    // Manual trigger
    // -------------------------------------------------------------------------

    /**
     * Run all schedule transitions immediately (admin manual trigger).
     *
     * @return array<string, array{published: int, expired: int, pinned: int}> Results keyed by schedule name.
     */
    public function trigger_manual_run(): array {
        $results   = [];
        $schedules = $this->schedule_manager->get_all();

        Logger::info( 'Manual trigger initiated', [ 'schedule_count' => count( $schedules ) ] );

        foreach ( $schedules as $schedule ) {
            $results[ $schedule->name ] = [
                'published' => $this->process_go_live_transitions( $schedule ),
                'expired'   => $this->process_expiry_transitions( $schedule ),
                'pinned'    => $this->process_overrides( $schedule ),
            ];
        }

        Logger::info( 'Manual trigger completed', [ 'results' => $results ] );

        return $results;
    }

    // -------------------------------------------------------------------------
    // Core transition helpers
    // -------------------------------------------------------------------------

    /**
     * Publish a post and assign the active term.
     *
     * @param int                     $post_id  The post ID.
     * @param Schedule                $schedule The schedule.
     * @param \DateTimeImmutable|null $go_live  Go-live date, stamped as the post date on publish.
     * @return bool True if anything actually changed.
     */
    private function apply_go_live( int $post_id, Schedule $schedule, ?\DateTimeImmutable $go_live = null ): bool {
        $term_changed   = $this->set_post_term( $post_id, $schedule, $schedule->active_term );
        $status_changed = $this->update_post_status( $post_id, 'publish', $go_live );

        if ( ! $term_changed && ! $status_changed ) {
            return false;
        }

        Logger::info( 'Post published (go-live)', [ 'post_id' => $post_id, 'schedule' => $schedule->name ] );

        return true;
    }

    /**
     * Set a post to private and assign the past term.
     *
     * @param int      $post_id  The post ID.
     * @param Schedule $schedule The schedule.
     * @return bool True if anything actually changed.
     */
    private function apply_expiry( int $post_id, Schedule $schedule ): bool {
        $term_changed   = $this->set_post_term( $post_id, $schedule, $schedule->past_term );
        $status_changed = $this->update_post_status( $post_id, 'private' );

        if ( ! $term_changed && ! $status_changed ) {
            return false;
        }

        Logger::info( 'Post expired', [ 'post_id' => $post_id, 'schedule' => $schedule->name ] );

        return true;
    }

    /**
     * Determine the correct term for a post based on its dates and set it.
     *
     * Does not change post_status — that is the cron's responsibility.
     *
     * @param int      $post_id  The post ID.
     * @param Schedule $schedule The schedule.
     * @return void
     */
    private function set_term_by_dates( int $post_id, Schedule $schedule ): void {
        if ( ! $schedule->taxonomy_exists() ) {
            return;
        }

        $override = $this->get_override( $post_id, $schedule );

        if ( ! Override::is_automatic( $override ) ) {
            // A pinned override still gets its term applied immediately, so
            // the editor sees the effect of their choice on save. HOLD pins
            // nothing and is left strictly alone. Status changes remain the
            // scheduled run's job, as they are for automatic posts.
            $term = Override::term_for( $override, $schedule );

            if ( null !== $term ) {
                $this->set_post_term( $post_id, $schedule, $term );
            }

            return;
        }

        $go_live    = Date_Handler::get_go_live_date( $post_id, $schedule );
        $expiration = Date_Handler::get_expiration_date( $post_id, $schedule );

        if ( null === $go_live && null === $expiration ) {
            Logger::debug(
                'Skipping term assignment — no dates set',
                [ 'post_id' => $post_id, 'schedule' => $schedule->name ]
            );
            return;
        }

        // A single missing date must still produce a term, otherwise the post
        // matches neither cron query and is invisible to the plugin forever.
        // An absent go-live date means "no gate"; an absent expiration means
        // "never expires".
        $is_expiry_passed  = null !== $expiration
            && Date_Handler::is_date_past( $expiration, null, $schedule->use_time );
        $is_go_live_reached = null === $go_live
            || Date_Handler::is_date_reached( $go_live, null, $schedule->use_time );

        if ( $is_expiry_passed ) {
            $term = $schedule->past_term;
        } elseif ( $is_go_live_reached ) {
            $term = $schedule->active_term;
        } else {
            $term = $schedule->upcoming_term;
        }

        Logger::debug(
            'Setting term on save',
            [
                'post_id'  => $post_id,
                'schedule' => $schedule->name,
                'term'     => $term,
            ]
        );

        $this->set_post_term( $post_id, $schedule, $term );
    }

    // -------------------------------------------------------------------------
    // Low-level helpers
    // -------------------------------------------------------------------------

    /**
     * Read a post's override for a given schedule.
     *
     * @param int      $post_id  The post ID.
     * @param Schedule $schedule The schedule.
     * @return string One of the Override constants.
     */
    private function get_override( int $post_id, Schedule $schedule ): string {
        return Override::sanitize( get_post_meta( $post_id, $schedule->get_override_meta_key(), true ) );
    }

    /**
     * Query posts matching specific taxonomy terms and post statuses.
     *
     * Only IDs are fetched. The passes below need nothing from the post row
     * itself, and hydrating every match into a WP_Post costs hundreds of
     * megabytes on a large archive — enough to exhaust the memory limit and
     * kill the cron run outright. Caches are primed per chunk instead, by
     * in_chunks().
     *
     * @param Schedule $schedule The schedule.
     * @param string[] $terms    Term slugs to match (IN operator).
     * @param string[] $statuses Post statuses to match.
     * @return int[] Post IDs.
     */
    private function get_posts_by_terms( Schedule $schedule, array $terms, array $statuses ): array {
        $args = [
            'post_type'              => $schedule->post_type,
            'post_status'            => $statuses,
            'posts_per_page'         => -1,
            'no_found_rows'          => true,
            'fields'                 => 'ids',
            'update_post_meta_cache' => false,
            'update_post_term_cache' => false,
            'tax_query'              => [
                [
                    'taxonomy' => $schedule->taxonomy,
                    'field'    => 'slug',
                    'terms'    => $terms,
                    'operator' => 'IN',
                ],
            ],
        ];

        return array_map( 'intval', ( new \WP_Query( $args ) )->posts );
    }

    /**
     * Walk post IDs, priming the post, meta and term caches a chunk at a time.
     *
     * Each pass reads several meta values and the current terms for every
     * post it looks at. Priming in batches keeps that to a couple of queries
     * per chunk instead of one per post, without holding the whole archive
     * in memory at once.
     *
     * @param int[] $post_ids Post IDs to iterate.
     * @return \Generator<int>
     */
    private function in_chunks( array $post_ids ): \Generator {
        $taxonomies = get_object_taxonomies( get_post_type( reset( $post_ids ) ?: 0 ) ?: '' );

        foreach ( array_chunk( $post_ids, self::CHUNK_SIZE ) as $chunk ) {
            _prime_post_caches( $chunk, true, true );

            foreach ( $chunk as $post_id ) {
                yield $post_id;
            }

            // Release what this chunk primed, or the object cache simply
            // accumulates the whole archive and the memory saved by fetching
            // IDs is given straight back. Targeted deletes rather than
            // clean_post_cache(), which would fire invalidation hooks —
            // and any listening plugin's handlers — once per post.
            foreach ( $chunk as $post_id ) {
                wp_cache_delete( $post_id, 'posts' );
                wp_cache_delete( $post_id, 'post_meta' );

                foreach ( $taxonomies as $taxonomy ) {
                    wp_cache_delete( $post_id, $taxonomy . '_relationships' );
                }
            }
        }
    }

    /**
     * Set a single taxonomy term on a post, replacing any existing terms.
     *
     * @param int      $post_id  The post ID.
     * @param Schedule $schedule The schedule.
     * @param string   $term     Term slug to assign.
     * @return bool True if the post's terms were actually changed.
     */
    private function set_post_term( int $post_id, Schedule $schedule, string $term ): bool {
        $term_obj = get_term_by( 'slug', $term, $schedule->taxonomy );

        if ( false === $term_obj ) {
            Logger::error(
                'Cannot assign term — term does not exist',
                [ 'post_id' => $post_id, 'term' => $term, 'taxonomy' => $schedule->taxonomy ]
            );
            return false;
        }

        // Skip the write when the post is already in exactly this term, so
        // callers can distinguish a real transition from a no-op.
        // get_the_terms() reads the object-term cache primed by in_chunks();
        // wp_get_object_terms() bypasses that cache and costs one query per
        // post, which on a large archive is the whole run's query budget.
        $current_terms = get_the_terms( $post_id, $schedule->taxonomy );
        $current       = is_array( $current_terms ) ? wp_list_pluck( $current_terms, 'slug' ) : [];

        if ( [ $term ] === $current ) {
            return false;
        }

        $result = wp_set_object_terms( $post_id, $term, $schedule->taxonomy, false );

        if ( is_wp_error( $result ) ) {
            Logger::error(
                'wp_set_object_terms failed',
                [ 'post_id' => $post_id, 'term' => $term, 'error' => $result->get_error_message() ]
            );
            return false;
        }

        return true;
    }

    /**
     * Update post status without triggering re-entrant processing.
     *
     * @param int                     $post_id      The post ID.
     * @param string                  $status       Target post status ('publish' or 'private').
     * @param \DateTimeImmutable|null $publish_date Post date to stamp on the post, if any.
     * @return bool True if the status was actually changed.
     */
    private function update_post_status( int $post_id, string $status, ?\DateTimeImmutable $publish_date = null ): bool {
        if ( get_post_status( $post_id ) === $status ) {
            return false;
        }

        $args = [
            'ID'          => $post_id,
            'post_status' => $status,
        ];

        // Without this the post keeps whatever date the draft was created on,
        // and the editor UI for changing it is hidden while a schedule is
        // active — so a post going live today would show a months-old date.
        if ( null !== $publish_date ) {
            $args['edit_date']     = true;
            $args['post_date']     = $publish_date->setTimezone( Date_Handler::get_timezone() )->format( 'Y-m-d H:i:s' );
            $args['post_date_gmt'] = $publish_date->setTimezone( new \DateTimeZone( 'UTC' ) )->format( 'Y-m-d H:i:s' );
        }

        $this->is_updating = true;
        $result            = wp_update_post( $args, true );
        $this->is_updating = false;

        if ( is_wp_error( $result ) ) {
            Logger::error(
                'Failed to update post status',
                [ 'post_id' => $post_id, 'status' => $status, 'error' => $result->get_error_message() ]
            );
            return false;
        }

        return true;
    }
}
