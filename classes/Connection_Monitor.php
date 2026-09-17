<?php
/**
 * Weekly background test of the Google Ads connection.
 *
 * Runs the same two-phase connection test as the settings page's Test
 * Connection button once a week, retries a failed test twice with growing
 * delays, and emails the site administrator only when all attempts in a
 * weekly round have failed. The weekly token refresh also keeps the OAuth
 * client active, since Google deletes clients that have been unused for
 * six months.
 *
 * @package Kntnt\Ad_Attribution_Gads
 * @since   1.10.0
 */

declare( strict_types = 1 );

namespace Kntnt\Ad_Attribution_Gads;

use DateTimeImmutable;
use Kntnt\Ad_Attribution\Logger;

/**
 * Schedules and runs the weekly connection check rounds.
 *
 * A round starts every Monday at 01:00 in the site's timezone. Attempt 2 is
 * scheduled one hour after a failed attempt 1, and attempt 3 two hours after
 * a failed attempt 2. Round state lives in its own option so that the check
 * never writes to the settings option (saving settings resets failed queue
 * jobs as a side effect).
 *
 * @since 1.10.0
 */
final class Connection_Monitor {

	/**
	 * Cron hook for the weekly start of a check round.
	 *
	 * @var string
	 * @since 1.10.0
	 */
	public const WEEKLY_HOOK = 'kntnt_ad_attr_gads_weekly_connection_check';

	/**
	 * Cron hook for a retry within a check round.
	 *
	 * @var string
	 * @since 1.10.0
	 */
	public const RETRY_HOOK = 'kntnt_ad_attr_gads_connection_check_retry';

	/**
	 * Option key for the state of the current check round.
	 *
	 * @var string
	 * @since 1.10.0
	 */
	public const STATE_OPTION = 'kntnt_ad_attr_gads_connection_check';

	/**
	 * Weekday on which each round starts, as a PHP relative date format.
	 *
	 * @var string
	 * @since 1.10.0
	 */
	private const START_WEEKDAY = 'monday';

	/**
	 * Local hour at which each round starts.
	 *
	 * @var int
	 * @since 1.10.0
	 */
	private const START_HOUR = 1;

	/**
	 * Maximum number of attempts in a round.
	 *
	 * @var int
	 * @since 1.10.0
	 */
	private const MAX_ATTEMPTS = 3;

	/**
	 * Seconds to wait after a failed attempt, keyed by the next attempt number.
	 *
	 * @var array<int,int>
	 * @since 1.10.0
	 */
	private const RETRY_DELAYS = [
		2 => 3600,
		3 => 7200,
	];

	/**
	 * Minimum seconds between two round starts.
	 *
	 * A weekly callback this soon after the previous round began is a
	 * duplicate cron run, not a new week. The margin is far longer than a
	 * complete round and far shorter than a week.
	 *
	 * @var int
	 * @since 1.10.0
	 */
	private const MIN_ROUND_SPACING = 43200;

	/**
	 * Maximum length of an error message kept in the round state and email.
	 *
	 * @var int
	 * @since 1.10.0
	 */
	private const MAX_ERROR_LENGTH = 300;

	/**
	 * Creates the monitor with Settings and Logger dependencies.
	 *
	 * @param Settings $settings Plugin settings instance.
	 * @param Logger   $logger   Core diagnostic logger instance.
	 *
	 * @since 1.10.0
	 */
	public function __construct(
		private readonly Settings $settings,
		private readonly Logger $logger,
	) {}

	/**
	 * Schedules the next weekly start unless one is already scheduled.
	 *
	 * Hooked to `init` so that sites updated from an earlier version get
	 * the schedule without reactivation, and so that a lost event is
	 * recreated. Idempotent across page loads.
	 *
	 * @return void
	 * @since 1.10.0
	 */
	public function ensure_scheduled(): void {
		if ( wp_next_scheduled( self::WEEKLY_HOOK ) === false ) {
			wp_schedule_single_event( self::next_start( time() ), self::WEEKLY_HOOK );
		}
	}

	/**
	 * Removes all scheduled checks and the round state.
	 *
	 * @return void
	 * @since 1.10.0
	 */
	public static function unschedule(): void {
		wp_unschedule_hook( self::WEEKLY_HOOK );
		wp_unschedule_hook( self::RETRY_HOOK );
		delete_option( self::STATE_OPTION );
	}

	/**
	 * Calculates the first weekly start strictly after the given time.
	 *
	 * The start is computed in local wall-clock time and converted to a Unix
	 * timestamp, so it stays at 01:00 local time across daylight saving
	 * changes, which a fixed interval of 604800 seconds would not.
	 *
	 * @param int $now Unix timestamp.
	 *
	 * @return int Unix timestamp of the next weekly start.
	 * @since 1.10.0
	 */
	public static function next_start( int $now ): int {
		$local = ( new DateTimeImmutable( '@' . $now ) )->setTimezone( wp_timezone() );
		$start = $local->modify( self::START_WEEKDAY . ' this week' )->setTime( self::START_HOUR, 0 );

		if ( $start->getTimestamp() <= $now ) {
			$start = $start->modify( '+1 week' )->setTime( self::START_HOUR, 0 );
		}

		return $start->getTimestamp();
	}

	/**
	 * Starts a new weekly round with its first attempt.
	 *
	 * @return void
	 * @since 1.10.0
	 */
	public function run_weekly(): void {
		$now = time();

		// Chain next week's start before testing, so that neither a failing
		// test nor a fatal error can break the weekly schedule. The event is
		// single rather than recurring to keep the local start hour fixed.
		wp_schedule_single_event( self::next_start( $now ), self::WEEKLY_HOOK );

		$state = $this->get_state();
		if ( $state !== null && $now - $state['round'] < self::MIN_ROUND_SPACING ) {
			$this->logger->info( 'GADS', 'Connection check — duplicate weekly start ignored' );
			return;
		}

		$this->attempt( [
			'round'    => $now,
			'attempts' => [],
			'finished' => false,
		] );
	}

	/**
	 * Runs a scheduled retry within a round.
	 *
	 * Ignores retries that belong to another round, to a finished round, or
	 * to an attempt that has already run, so that stale or duplicate cron
	 * callbacks cannot cause extra attempts or emails.
	 *
	 * @param int $round   Start time of the round the retry belongs to.
	 * @param int $attempt Attempt number the retry was scheduled as.
	 *
	 * @return void
	 * @since 1.10.0
	 */
	public function run_retry( int $round, int $attempt ): void {
		$state = $this->get_state();

		if ( $state === null
			|| $state['round'] !== $round
			|| $state['finished']
			|| count( $state['attempts'] ) + 1 !== $attempt ) {
			$this->logger->info( 'GADS', "Connection check — stale retry ignored (round {$round}, attempt {$attempt})" );
			return;
		}

		$this->attempt( $state );
	}

	/**
	 * Runs one attempt and decides whether to finish, retry, or notify.
	 *
	 * @param array{round: int, attempts: array<int,array{time: int, error: string}>, finished: bool} $state Current round state.
	 *
	 * @return void
	 * @since 1.10.0
	 */
	private function attempt( array $state ): void {
		$number = count( $state['attempts'] ) + 1;

		// An unconfigured plugin has nothing to verify; ending the round
		// avoids pointless Google requests and alarms on fresh installs.
		if ( ! $this->settings->is_configured() ) {
			$state['finished'] = true;
			$this->save_state( $state );
			$this->logger->info( 'GADS', 'Connection check — skipped, plugin is not configured' );
			return;
		}

		// Record the attempt before the slow HTTP calls so that an
		// overlapping duplicate callback sees it and backs off.
		$state['attempts'][] = [ 'time' => time(), 'error' => '' ];
		$this->save_state( $state );

		$this->logger->info( 'GADS', "Connection check — attempt {$number} of " . self::MAX_ATTEMPTS );
		$result = $this->test_connection();

		if ( $result['success'] ) {
			$state['finished'] = true;
			$this->save_state( $state );
			$this->logger->info( 'GADS', "Connection check — attempt {$number} succeeded" );
			return;
		}

		$error = self::safe_error( $result['error'] );
		$state['attempts'][ $number - 1 ]['error'] = $error;
		$this->logger->error( 'GADS', "Connection check — attempt {$number} failed: {$error}" );

		if ( $number < self::MAX_ATTEMPTS ) {
			$next = $number + 1;
			wp_schedule_single_event( time() + self::RETRY_DELAYS[ $next ], self::RETRY_HOOK, [ $state['round'], $next ] );
			$this->save_state( $state );
			return;
		}

		// Mark the round finished before emailing so that a duplicate
		// callback cannot send a second email for the same round.
		$state['finished'] = true;
		$this->save_state( $state );
		$this->notify( $state );
	}

	/**
	 * Runs the shared connection test with the currently saved settings.
	 *
	 * @return array{success: bool, error: string} Test result.
	 * @since 1.10.0
	 */
	private function test_connection(): array {
		$settings = $this->settings->get_all();

		$client = new Google_Ads_Client(
			customer_id: $settings['customer_id'],
			developer_token: $settings['developer_token'],
			client_id: $settings['client_id'],
			client_secret: $settings['client_secret'],
			refresh_token: $settings['refresh_token'],
			login_customer_id: $settings['login_customer_id'],
			conversion_action_id: $settings['conversion_action_id'],
			logger: $this->logger,
		);

		// An unexpected exception must count as a failed attempt; otherwise
		// the round would stop without a retry or a notification.
		try {
			return $client->test_connection();
		} catch ( \Throwable $e ) {
			return [ 'success' => false, 'error' => $e->getMessage() ];
		}
	}

	/**
	 * Emails the site administrator that all attempts in a round failed.
	 *
	 * The email contains only attempt times and the short error message.
	 * Raw Google responses and credential diagnostics are left out.
	 *
	 * @param array{round: int, attempts: array<int,array{time: int, error: string}>, finished: bool} $state Finished round state.
	 *
	 * @return void
	 * @since 1.10.0
	 */
	private function notify( array $state ): void {
		$site_name = wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES );
		$format    = get_option( 'date_format' ) . ' ' . get_option( 'time_format' );

		$lines = [];
		foreach ( $state['attempts'] as $attempt ) {
			$lines[] = sprintf( '- %s: %s', wp_date( $format, $attempt['time'] ), $attempt['error'] );
		}

		$subject = sprintf(
			/* translators: %s: Site name. */
			__( '[%s] Google Ads connection check failed', 'kntnt-ad-attr-gads' ),
			$site_name,
		);

		$message = sprintf(
			/* translators: 1: Site name, 2: Number of attempts, 3: List of attempt times and errors, 4: URL to the plugin settings page. */
			__( "The weekly check of the Google Ads connection on %1\$s failed on all %2\$d attempts.\n\n%3\$s\n\nConversions cannot be reported to Google Ads until the connection works. Check the credentials and use Test Connection on the settings page:\n%4\$s", 'kntnt-ad-attr-gads' ),
			$site_name,
			count( $state['attempts'] ),
			implode( "\n", $lines ),
			admin_url( 'options-general.php?page=kntnt-ad-attr-gads' ),
		);

		if ( wp_mail( get_option( 'admin_email' ), $subject, $message ) ) {
			$this->logger->info( 'GADS', 'Connection check — failure notification sent' );
			return;
		}

		// No retry of the email: the next weekly round reports again if the
		// problem persists.
		$this->logger->error( 'GADS', 'Connection check — failure notification could not be sent' );
		error_log( 'Kntnt Ad Attribution Gads: Weekly connection check failed three times, and the notification email could not be sent.' );
	}

	/**
	 * Reduces an error message to plain text of bounded length.
	 *
	 * @param string $error Raw error message from the connection test.
	 *
	 * @return string Sanitized error message.
	 * @since 1.10.0
	 */
	private static function safe_error( string $error ): string {
		$error = trim( (string) preg_replace( '/\s+/', ' ', wp_strip_all_tags( $error ) ) );

		if ( $error === '' ) {
			return 'Unknown error.';
		}

		return mb_strlen( $error ) > self::MAX_ERROR_LENGTH
			? mb_substr( $error, 0, self::MAX_ERROR_LENGTH ) . '…'
			: $error;
	}

	/**
	 * Reads the current round state.
	 *
	 * @return array{round: int, attempts: array<int,array{time: int, error: string}>, finished: bool}|null State, or null if no round has run.
	 * @since 1.10.0
	 */
	private function get_state(): ?array {
		$state = get_option( self::STATE_OPTION, null );
		return is_array( $state ) && isset( $state['round'], $state['attempts'], $state['finished'] ) ? $state : null;
	}

	/**
	 * Stores the round state without autoloading it on every request.
	 *
	 * @param array{round: int, attempts: array<int,array{time: int, error: string}>, finished: bool} $state Round state.
	 *
	 * @return void
	 * @since 1.10.0
	 */
	private function save_state( array $state ): void {
		update_option( self::STATE_OPTION, $state, false );
	}

}
