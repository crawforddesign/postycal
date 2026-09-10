<?php
/**
 * Updater
 *
 * Serves plugin updates from GitHub Releases, so sites running PostyCal are
 * offered new versions in the WordPress dashboard like any plugin from
 * wordpress.org.
 *
 * This uses the Update URI header added in WordPress 5.8. Declaring
 * `Update URI: https://github.com/...` routes the check through the
 * `update_plugins_github.com` filter and — just as importantly — tells
 * WordPress to never accept an update for this plugin from wordpress.org,
 * which would otherwise be possible for anyone who published a plugin there
 * under the slug "postycal".
 *
 * The repository is public, so no credentials are involved. If it is ever
 * made private, both the API request and the package download would need an
 * access token, the latter via the http_request_args filter.
 *
 * @package PostyCal
 * @since 2.4.0
 */

declare(strict_types=1);

namespace PostyCal;

/**
 * Checks GitHub Releases for a newer version of the plugin.
 */
final class Updater {

    /**
     * The GitHub repository releases are published to.
     *
     * @var string
     */
    private const REPO = 'crawforddesign/postycal';

    /**
     * Transient holding the last successful (or failed) release lookup.
     *
     * @var string
     */
    private const CACHE_KEY = 'postycal_latest_release';

    /**
     * Marker stored when a lookup fails, so an outage does not mean a
     * request on every single update check.
     *
     * @var string
     */
    private const FAILURE_MARKER = 'unavailable';

    /**
     * Query arg and nonce action for the Plugins-screen check link.
     *
     * @var string
     */
    private const CHECK_ACTION = 'postycal_check_update';

    /**
     * Query arg carrying the check result back to the Plugins screen.
     *
     * @var string
     */
    private const RESULT_ARG = 'postycal_update_checked';

    /**
     * Register the update hooks.
     *
     * Not gated on is_admin(): update checks also run from wp-cron.
     *
     * @return void
     */
    public function register(): void {
        add_filter( 'update_plugins_github.com', [ $this, 'check_for_update' ], 10, 3 );
        add_filter( 'plugins_api', [ $this, 'plugin_details' ], 10, 3 );
        add_action( 'upgrader_process_complete', [ $this, 'clear_cache' ] );

        // Plugins-screen affordances. None of these fire outside the admin
        // anyway; registering them here keeps the front-end hook list short.
        if ( is_admin() ) {
            add_filter( 'plugin_row_meta', [ $this, 'add_check_link' ], 10, 2 );
            add_action( 'admin_init', [ $this, 'handle_manual_check' ] );
            add_action( 'admin_notices', [ $this, 'render_manual_check_notice' ] );
        }
    }

    // -------------------------------------------------------------------------
    // Manual check from the Plugins screen
    // -------------------------------------------------------------------------

    /**
     * Add a "Check for updates" link to the plugin's row on the Plugins screen.
     *
     * Core only offers "Check again" on Dashboard - Updates, which re-checks
     * every plugin and theme on the site. This is the per-plugin equivalent,
     * next to "View details", where someone looking at PostyCal expects it.
     *
     * @param string[] $plugin_meta The row's meta links.
     * @param string   $plugin_file Plugin basename for the row being rendered.
     * @return string[]
     */
    public function add_check_link( array $plugin_meta, string $plugin_file ): array {
        if ( POSTYCAL_PLUGIN_BASENAME !== $plugin_file || ! current_user_can( 'update_plugins' ) ) {
            return $plugin_meta;
        }

        $url = wp_nonce_url(
            add_query_arg( self::CHECK_ACTION, 1, self_admin_url( 'plugins.php' ) ),
            self::CHECK_ACTION
        );

        $plugin_meta[] = sprintf(
            '<a href="%s">%s</a>',
            esc_url( $url ),
            esc_html__( 'Check for updates', 'postycal' )
        );

        return $plugin_meta;
    }

    /**
     * Run a fresh check when that link is clicked, then redirect back.
     *
     * @return void
     */
    public function handle_manual_check(): void {
        if ( ! isset( $_GET[ self::CHECK_ACTION ] ) ) {
            return;
        }

        if ( ! current_user_can( 'update_plugins' ) ) {
            wp_die( esc_html__( 'You are not allowed to check for plugin updates.', 'postycal' ) );
        }

        check_admin_referer( self::CHECK_ACTION );

        // Drop the cached release *and* core's plugin update data. Clearing
        // ours alone would leave the Plugins screen showing the result of
        // core's last check, which it holds for up to 12 hours — so the link
        // would look like it had done nothing.
        delete_transient( self::CACHE_KEY );
        delete_site_transient( 'update_plugins' );
        wp_update_plugins();

        $updates = get_site_transient( 'update_plugins' );
        $status  = isset( $updates->response[ POSTYCAL_PLUGIN_BASENAME ] ) ? 'update' : 'current';

        // Reads the transient the check above just wrote, so this costs no
        // second request. A failed lookup leaves the failure marker behind
        // and reports null, which is the one case worth saying out loud:
        // "no update" and "could not ask" look identical otherwise.
        if ( null === $this->get_latest_release() ) {
            $status = 'error';
        }

        wp_safe_redirect( add_query_arg( self::RESULT_ARG, $status, self_admin_url( 'plugins.php' ) ) );
        exit;
    }

    /**
     * Report the outcome of a manual check.
     *
     * @return void
     */
    public function render_manual_check_notice(): void {
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- displays a status this class set on its own redirect; changes nothing.
        $status = isset( $_GET[ self::RESULT_ARG ] ) ? sanitize_key( wp_unslash( $_GET[ self::RESULT_ARG ] ) ) : '';

        $notices = [
            'update'  => [ __( 'A new version of PostyCal is available — see the row below.', 'postycal' ), 'info' ],
            'current' => [ __( 'PostyCal is up to date.', 'postycal' ), 'success' ],
            'error'   => [ __( 'PostyCal could not reach GitHub to check for updates. Try again in a few minutes.', 'postycal' ), 'error' ],
        ];

        if ( ! isset( $notices[ $status ] ) ) {
            return;
        }

        [ $message, $type ] = $notices[ $status ];

        printf(
            '<div class="notice notice-%1$s is-dismissible"><p>%2$s</p></div>',
            esc_attr( $type ),
            esc_html( $message )
        );
    }

    // -------------------------------------------------------------------------
    // Update check
    // -------------------------------------------------------------------------

    /**
     * Supply release data for this plugin to the update system.
     *
     * Core compares the returned version against the installed one and files
     * the result under `response` or `no_update` itself, so this returns the
     * payload whether or not it is newer.
     *
     * @param mixed                $update      Update data from a previous filter, or false.
     * @param array<string, mixed> $plugin_data Plugin headers.
     * @param string               $plugin_file Plugin basename being checked.
     * @return mixed
     */
    public function check_for_update( mixed $update, array $plugin_data, string $plugin_file ): mixed {
        if ( POSTYCAL_PLUGIN_BASENAME !== $plugin_file ) {
            return $update;
        }

        $release = $this->get_latest_release();

        if ( null === $release ) {
            return $update;
        }

        return [
            'slug'         => 'postycal',
            'version'      => $release['version'],
            'url'          => $release['url'],
            'package'      => $release['package'],
            'requires'     => '6.0',
            'requires_php' => '8.2',
        ];
    }

    /**
     * Populate the "View details" modal.
     *
     * @param mixed  $result The result object or array, or false.
     * @param string $action The API action being performed.
     * @param object $args   Request arguments.
     * @return mixed
     */
    public function plugin_details( mixed $result, string $action, object $args ): mixed {
        if ( 'plugin_information' !== $action ) {
            return $result;
        }

        if ( ! isset( $args->slug ) || 'postycal' !== $args->slug ) {
            return $result;
        }

        $release = $this->get_latest_release();

        if ( null === $release ) {
            return $result;
        }

        return (object) [
            'name'          => 'PostyCal',
            'slug'          => 'postycal',
            'version'       => $release['version'],
            'author'        => '<a href="https://crawforddesigngroup.com/">Crawford Design Group</a>',
            'homepage'      => 'https://github.com/' . self::REPO,
            'requires'      => '6.0',
            'requires_php'  => '8.2',
            'download_link' => $release['package'],
            'trunk'         => $release['package'],
            'sections'      => [
                'description' => '<p>' . esc_html__( 'Manages the full lifecycle of time-sensitive posts: publishes them on a go-live date, moves them through Upcoming, Active and Past taxonomy terms, and retires them on an expiration date.', 'postycal' ) . '</p>',
                'changelog'   => $this->format_notes( $release['notes'] ),
            ],
        ];
    }

    /**
     * Drop the cached release after any plugin upgrade completes.
     *
     * @return void
     */
    public function clear_cache(): void {
        delete_transient( self::CACHE_KEY );
    }

    // -------------------------------------------------------------------------
    // GitHub lookup
    // -------------------------------------------------------------------------

    /**
     * Get the latest published release, from cache where possible.
     *
     * @return array{version: string, package: string, url: string, notes: string}|null
     */
    private function get_latest_release(): ?array {
        if ( ! $this->is_forced_check() ) {
            $cached = get_transient( self::CACHE_KEY );

            if ( is_array( $cached ) ) {
                return $cached;
            }

            if ( self::FAILURE_MARKER === $cached ) {
                return null;
            }
        }

        $response = wp_remote_get(
            sprintf( 'https://api.github.com/repos/%s/releases/latest', self::REPO ),
            [
                'timeout' => 10,
                'headers' => [
                    'Accept'     => 'application/vnd.github+json',
                    'User-Agent' => 'PostyCal/' . POSTYCAL_VERSION . '; ' . home_url(),
                ],
            ]
        );

        $release = $this->parse_response( $response );

        if ( null === $release ) {
            set_transient( self::CACHE_KEY, self::FAILURE_MARKER, 15 * MINUTE_IN_SECONDS );
            return null;
        }

        set_transient( self::CACHE_KEY, $release, 6 * HOUR_IN_SECONDS );

        return $release;
    }

    /**
     * Whether the user pressed "Check again" on the updates screen.
     *
     * @return bool
     */
    private function is_forced_check(): bool {
        // Read-only presence check on a core-supplied flag; nothing is changed.
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        return isset( $_GET['force-check'] ) && current_user_can( 'update_plugins' );
    }

    /**
     * Turn the GitHub API response into the fields the updater needs.
     *
     * @param array<string, mixed>|\WP_Error $response Raw HTTP response.
     * @return array{version: string, package: string, url: string, notes: string}|null
     */
    private function parse_response( array|\WP_Error $response ): ?array {
        if ( is_wp_error( $response ) ) {
            Logger::warning( 'Update check failed', [ 'error' => $response->get_error_message() ] );
            return null;
        }

        $code = wp_remote_retrieve_response_code( $response );

        if ( 200 !== $code ) {
            Logger::warning( 'Update check returned an unexpected status', [ 'status' => $code ] );
            return null;
        }

        $body = json_decode( wp_remote_retrieve_body( $response ), true );

        if ( ! is_array( $body ) || empty( $body['tag_name'] ) ) {
            Logger::warning( 'Update check returned an unreadable body' );
            return null;
        }

        if ( ! empty( $body['draft'] ) || ! empty( $body['prerelease'] ) ) {
            return null;
        }

        $package = '';

        foreach ( (array) ( $body['assets'] ?? [] ) as $asset ) {
            if ( ! is_array( $asset ) || empty( $asset['name'] ) || empty( $asset['browser_download_url'] ) ) {
                continue;
            }

            if ( str_ends_with( strtolower( (string) $asset['name'] ), '.zip' ) ) {
                $package = (string) $asset['browser_download_url'];
                break;
            }
        }

        // A release with no zip attached cannot be installed. GitHub's own
        // source archives are not usable here: they unpack to a directory
        // named after the tag, so WordPress would install a second copy of
        // the plugin rather than upgrading this one.
        if ( '' === $package ) {
            Logger::warning( 'Latest release has no installable zip', [ 'tag' => $body['tag_name'] ] );
            return null;
        }

        return [
            'version' => ltrim( (string) $body['tag_name'], 'vV' ),
            'package' => $package,
            'url'     => (string) ( $body['html_url'] ?? '' ),
            'notes'   => (string) ( $body['body'] ?? '' ),
        ];
    }

    /**
     * Render the release notes as simple HTML for the details modal.
     *
     * Release notes come from the changelog and are a flat bullet list, so a
     * full Markdown parser would be overkill.
     *
     * @param string $notes Raw release body.
     * @return string
     */
    private function format_notes( string $notes ): string {
        if ( '' === trim( $notes ) ) {
            return '<p>' . esc_html__( 'See the release notes on GitHub.', 'postycal' ) . '</p>';
        }

        $html    = '';
        $in_list = false;

        foreach ( preg_split( '/\R/', $notes ) ?: [] as $line ) {
            $line = trim( $line );

            if ( '' === $line ) {
                continue;
            }

            if ( str_starts_with( $line, '- ' ) || str_starts_with( $line, '* ' ) ) {
                if ( ! $in_list ) {
                    $html   .= '<ul>';
                    $in_list = true;
                }
                $html .= '<li>' . esc_html( substr( $line, 2 ) ) . '</li>';
                continue;
            }

            if ( $in_list ) {
                $html   .= '</ul>';
                $in_list = false;
            }

            $html .= '<p>' . esc_html( $line ) . '</p>';
        }

        if ( $in_list ) {
            $html .= '</ul>';
        }

        return $html;
    }
}
