<?php
/**
 * Unit tests for Connection_Monitor.
 *
 * The tests drive the monitor through a small in-memory emulation of
 * WordPress options, cron, and HTTP, and run the real Google_Ads_Client
 * against it. Time is simulated, so the retry delays and daylight saving
 * changes are verified without waiting.
 *
 * @package Tests\Unit
 * @since   1.10.0
 */

declare(strict_types=1);

use Kntnt\Ad_Attribution_Gads\Connection_Monitor;
use Kntnt\Ad_Attribution_Gads\Settings;
use Kntnt\Ad_Attribution\Logger;
use Brain\Monkey\Functions;

/**
 * Returns a complete, configured settings option value.
 *
 * @return array<string,string>
 */
function monitor_settings(): array {
    return [
        'customer_id'          => '1234567890',
        'conversion_action_id' => '99',
        'developer_token'      => 'dev_token_secret',
        'client_id'            => 'client.apps.googleusercontent.com',
        'client_secret'        => 'client_secret_value',
        'refresh_token'        => 'refresh_token_value',
        'login_customer_id'    => '',
        'conversion_value'     => '0',
        'currency_code'        => 'SEK',
    ];
}

/**
 * Converts a local Stockholm time to a Unix timestamp.
 */
function stockholm(string $datetime): int {
    return (new DateTimeImmutable($datetime, new DateTimeZone('Europe/Stockholm')))->getTimestamp();
}

/**
 * Installs the emulated WordPress environment and returns its state.
 *
 * `outcomes` is consumed once per connection test: 'ok' lets both phases
 * succeed, 'fail' makes the token refresh fail as a deleted OAuth client
 * does, and 'throw' makes the HTTP layer throw.
 */
function monitor_env(int $now): stdClass {
    $env = new stdClass();
    $env->now         = $now;
    $env->options     = [
        Settings::OPTION_KEY => monitor_settings(),
        'admin_email'        => 'admin@example.com',
        'date_format'        => 'Y-m-d',
        'time_format'        => 'H:i',
    ];
    $env->events      = [];
    $env->requests    = [];
    $env->outcomes    = [];
    $env->mails       = [];
    $env->mail_result = true;

    \Patchwork\redefine('time', fn () => $env->now);

    Functions\when('get_option')->alias(fn (string $key, mixed $default = false) => $env->options[$key] ?? $default);
    Functions\when('update_option')->alias(function (string $key, mixed $value) use ($env) {
        $env->options[$key] = $value;
        return true;
    });
    Functions\when('delete_option')->alias(function (string $key) use ($env) {
        unset($env->options[$key]);
        return true;
    });

    Functions\when('wp_timezone')->justReturn(new DateTimeZone('Europe/Stockholm'));
    Functions\when('wp_date')->alias(
        fn (string $format, int $ts) => (new DateTimeImmutable('@' . $ts))->setTimezone(new DateTimeZone('Europe/Stockholm'))->format($format),
    );

    // Cron emulation, including core's refusal to add an identical event
    // within ten minutes of an existing one.
    Functions\when('wp_next_scheduled')->alias(function (string $hook, array $args = []) use ($env) {
        $times = array_column(array_filter($env->events, fn ($e) => $e['hook'] === $hook && $e['args'] === $args), 'timestamp');
        return $times ? min($times) : false;
    });
    Functions\when('wp_schedule_single_event')->alias(function (int $timestamp, string $hook, array $args = []) use ($env) {
        foreach ($env->events as $event) {
            if ($event['hook'] === $hook && $event['args'] === $args && abs($event['timestamp'] - $timestamp) <= 600) {
                return false;
            }
        }
        $env->events[] = ['timestamp' => $timestamp, 'hook' => $hook, 'args' => $args];
        return true;
    });
    Functions\when('wp_unschedule_hook')->alias(function (string $hook) use ($env) {
        $env->events = array_values(array_filter($env->events, fn ($e) => $e['hook'] !== $hook));
        return 1;
    });

    // HTTP emulation for Google_Ads_Client.
    Functions\when('wp_remote_post')->alias(function (string $url, array $args) use ($env) {
        $env->requests[] = ['url' => $url, 'args' => $args];
        if (str_contains($url, 'oauth2.googleapis.com/token')) {
            $outcome = array_shift($env->outcomes) ?? 'ok';
            if ($outcome === 'throw') {
                throw new RuntimeException('Transport exploded');
            }
            if ($outcome === 'fail') {
                return ['response' => ['code' => 401], 'body' => json_encode(['error' => 'deleted_client', 'error_description' => 'The OAuth client was deleted.'])];
            }
            return ['response' => ['code' => 200], 'body' => json_encode(['access_token' => 'access', 'expires_in' => 3600])];
        }
        return ['response' => ['code' => 200], 'body' => json_encode(['results' => [['conversionAction' => ['name' => 'Lead', 'category' => 'SUBMIT_LEAD_FORM']]]])];
    });
    Functions\when('is_wp_error')->justReturn(false);
    Functions\when('set_transient')->justReturn(true);
    Functions\when('wp_json_encode')->alias(fn ($data) => json_encode($data));
    Functions\when('wp_remote_retrieve_response_code')->alias(fn ($r) => $r['response']['code'] ?? 0);
    Functions\when('wp_remote_retrieve_body')->alias(fn ($r) => $r['body'] ?? '');

    // Email.
    Functions\when('get_bloginfo')->justReturn('Example Site');
    Functions\when('wp_specialchars_decode')->returnArg();
    Functions\when('wp_strip_all_tags')->alias(fn (string $text) => strip_tags($text));
    Functions\when('admin_url')->alias(fn (string $path) => 'https://example.com/wp-admin/' . $path);
    Functions\when('wp_mail')->alias(function (string $to, string $subject, string $message) use ($env) {
        $env->mails[] = compact('to', 'subject', 'message');
        return $env->mail_result;
    });

    return $env;
}

/**
 * Creates a monitor wired to the real Settings class.
 */
function make_monitor(): Connection_Monitor {
    return new Connection_Monitor(new Settings(), Mockery::mock(Logger::class)->shouldIgnoreMissing());
}

/**
 * Runs the earliest scheduled event the way WP-Cron does: advances the clock
 * to its time, removes it from the schedule, and fires its hook.
 *
 * @return array{timestamp: int, hook: string, args: array} The event that ran.
 */
function cron_tick(stdClass $env, Connection_Monitor $monitor): array {
    usort($env->events, fn ($a, $b) => $a['timestamp'] <=> $b['timestamp']);
    $event    = array_shift($env->events);
    $env->now = max($env->now, $event['timestamp']);

    match ($event['hook']) {
        Connection_Monitor::WEEKLY_HOOK => $monitor->run_weekly(),
        Connection_Monitor::RETRY_HOOK  => $monitor->run_retry(...$event['args']),
    };

    return $event;
}

/**
 * Returns the scheduled events for a hook.
 *
 * @return array<int,array{timestamp: int, hook: string, args: array}>
 */
function events_for(stdClass $env, string $hook): array {
    return array_values(array_filter($env->events, fn ($e) => $e['hook'] === $hook));
}

/**
 * Counts token refresh requests, i.e. connection tests that were run.
 */
function token_requests(stdClass $env): int {
    return count(array_filter($env->requests, fn ($r) => str_contains($r['url'], 'oauth2.googleapis.com/token')));
}

// ─── next_start() ───

describe('Connection_Monitor::next_start()', function () {

    beforeEach(function () {
        Functions\when('wp_timezone')->justReturn(new DateTimeZone('Europe/Stockholm'));
    });

    it('returns the coming Monday at 01:00 local time', function () {
        expect(Connection_Monitor::next_start(stockholm('2026-09-16 12:00')))->toBe(stockholm('2026-09-21 01:00'));
        expect(Connection_Monitor::next_start(stockholm('2026-09-20 23:59')))->toBe(stockholm('2026-09-21 01:00'));
    });

    it('returns the same Monday before 01:00 and the next Monday from 01:00', function () {
        expect(Connection_Monitor::next_start(stockholm('2026-09-21 00:30')))->toBe(stockholm('2026-09-21 01:00'));
        expect(Connection_Monitor::next_start(stockholm('2026-09-21 01:00')))->toBe(stockholm('2026-09-28 01:00'));
    });

    it('keeps 01:00 local time when summer time ends', function () {
        $start = Connection_Monitor::next_start(stockholm('2026-10-20 12:00'));

        expect($start)->toBe(gmmktime(0, 0, 0, 10, 26, 2026));
        expect($start - stockholm('2026-10-19 01:00'))->toBe(7 * 86400 + 3600);
    });

    it('keeps 01:00 local time when summer time begins', function () {
        $start = Connection_Monitor::next_start(stockholm('2026-03-24 12:00'));

        expect($start)->toBe(gmmktime(23, 0, 0, 3, 29, 2026));
        expect($start - stockholm('2026-03-23 01:00'))->toBe(7 * 86400 - 3600);
    });

});

// ─── Scheduling lifecycle ───

describe('Connection_Monitor scheduling', function () {

    it('schedules exactly one weekly start across repeated page loads', function () {
        $env     = monitor_env(stockholm('2026-09-17 10:00'));
        $monitor = make_monitor();

        for ($i = 0; $i < 5; $i++) {
            $env->now += 60;
            $monitor->ensure_scheduled();
        }

        expect($env->events)->toHaveCount(1);
        expect($env->events[0]['hook'])->toBe(Connection_Monitor::WEEKLY_HOOK);
        expect($env->events[0]['timestamp'])->toBe(stockholm('2026-09-21 01:00'));
    });

    it('keeps a single weekly start at 01:00 local time week after week across a DST change', function () {
        $env     = monitor_env(stockholm('2026-10-14 10:00'));
        $monitor = make_monitor();
        $monitor->ensure_scheduled();

        $starts = [];
        for ($week = 0; $week < 3; $week++) {
            $starts[] = cron_tick($env, $monitor)['timestamp'];
            $monitor->ensure_scheduled();
            expect(events_for($env, Connection_Monitor::WEEKLY_HOOK))->toHaveCount(1);
        }

        expect($starts)->toBe([stockholm('2026-10-19 01:00'), stockholm('2026-10-26 01:00'), stockholm('2026-11-02 01:00')]);
    });

    it('unschedule() removes the weekly start, pending retries, and round state', function () {
        $env     = monitor_env(stockholm('2026-09-17 10:00'));
        $monitor = make_monitor();
        $monitor->ensure_scheduled();
        $env->outcomes = ['fail'];
        cron_tick($env, $monitor);

        expect(events_for($env, Connection_Monitor::RETRY_HOOK))->toHaveCount(1);
        expect($env->options)->toHaveKey(Connection_Monitor::STATE_OPTION);

        Connection_Monitor::unschedule();

        expect($env->events)->toBe([]);
        expect($env->options)->not->toHaveKey(Connection_Monitor::STATE_OPTION);

        // Reactivation starts cleanly with one weekly start.
        $monitor->ensure_scheduled();
        $monitor->ensure_scheduled();
        expect($env->events)->toHaveCount(1);
        expect($env->events[0]['hook'])->toBe(Connection_Monitor::WEEKLY_HOOK);
    });

});

// ─── Check rounds ───

describe('Connection_Monitor check rounds', function () {

    beforeEach(function () {
        $this->env     = monitor_env(stockholm('2026-09-17 10:00'));
        $this->monitor = make_monitor();
        $this->monitor->ensure_scheduled();
    });

    it('runs the shared read-only test once and ends the round on immediate success', function () {
        $this->env->outcomes = ['ok'];

        cron_tick($this->env, $this->monitor);

        $urls = array_column($this->env->requests, 'url');
        expect($urls)->toHaveCount(2);
        expect($urls[0])->toContain('oauth2.googleapis.com/token');
        expect($urls[1])->toEndWith('/customers/1234567890/googleAds:search');
        expect(json_decode($this->env->requests[1]['args']['body'], true)['query'])->toContain('conversion_action.id = 99');
        expect(implode(' ', $urls))->not->toContain('uploadClickConversions');

        expect(events_for($this->env, Connection_Monitor::RETRY_HOOK))->toBe([]);
        expect($this->env->mails)->toBe([]);
        expect($this->env->options[Connection_Monitor::STATE_OPTION]['finished'])->toBeTrue();
        expect($this->env->options[Settings::OPTION_KEY])->toBe(monitor_settings());
    });

    it('retries one hour after a first failure and stops on success', function () {
        $this->env->outcomes = ['fail', 'ok'];

        $first  = cron_tick($this->env, $this->monitor);
        $second = cron_tick($this->env, $this->monitor);

        expect($second['hook'])->toBe(Connection_Monitor::RETRY_HOOK);
        expect($second['timestamp'] - $first['timestamp'])->toBe(3600);
        expect(token_requests($this->env))->toBe(2);
        expect(events_for($this->env, Connection_Monitor::RETRY_HOOK))->toBe([]);
        expect($this->env->mails)->toBe([]);
    });

    it('waits two more hours after a second failure and stops on success', function () {
        $this->env->outcomes = ['fail', 'fail', 'ok'];

        $first  = cron_tick($this->env, $this->monitor);
        $second = cron_tick($this->env, $this->monitor);
        $third  = cron_tick($this->env, $this->monitor);

        expect($third['hook'])->toBe(Connection_Monitor::RETRY_HOOK);
        expect([$first['timestamp'], $second['timestamp'], $third['timestamp']])
            ->toBe([stockholm('2026-09-21 01:00'), stockholm('2026-09-21 02:00'), stockholm('2026-09-21 04:00')]);
        expect(token_requests($this->env))->toBe(3);
        expect(events_for($this->env, Connection_Monitor::RETRY_HOOK))->toBe([]);
        expect($this->env->mails)->toBe([]);
    });

    it('measures the retry delay from when the failed attempt ran', function () {
        $this->env->outcomes = ['fail'];

        // Cron fires the weekly start 25 minutes late.
        $this->env->now = stockholm('2026-09-21 01:25');
        cron_tick($this->env, $this->monitor);

        expect(events_for($this->env, Connection_Monitor::RETRY_HOOK)[0]['timestamp'])->toBe(stockholm('2026-09-21 02:25'));
    });

    it('emails the administrator once after three failures and makes no fourth attempt', function () {
        $this->env->outcomes = ['fail', 'fail', 'fail', 'ok'];

        cron_tick($this->env, $this->monitor);
        cron_tick($this->env, $this->monitor);
        cron_tick($this->env, $this->monitor);

        expect(token_requests($this->env))->toBe(3);
        expect($this->env->events)->toHaveCount(1);
        expect($this->env->events[0]['hook'])->toBe(Connection_Monitor::WEEKLY_HOOK);
        expect($this->env->events[0]['timestamp'])->toBe(stockholm('2026-09-28 01:00'));

        expect($this->env->mails)->toHaveCount(1);
        $mail = $this->env->mails[0];
        expect($mail['to'])->toBe('admin@example.com');
        expect($mail['subject'])->toContain('Example Site');
        expect($mail['message'])
            ->toContain('Example Site')
            ->toContain('3 attempts')
            ->toContain('2026-09-21 01:00: The OAuth client was deleted.')
            ->toContain('2026-09-21 02:00: The OAuth client was deleted.')
            ->toContain('2026-09-21 04:00: The OAuth client was deleted.')
            ->toContain('https://example.com/wp-admin/options-general.php?page=kntnt-ad-attr-gads');

        foreach (['client_secret_value', 'refresh_token_value', 'dev_token_secret', 'access', 'HTTP 401'] as $secret) {
            expect($mail['message'])->not->toContain($secret);
        }
    });

    it('starts a fresh round next week and emails again if all attempts fail again', function () {
        $this->env->outcomes = array_fill(0, 6, 'fail');

        for ($i = 0; $i < 6; $i++) {
            cron_tick($this->env, $this->monitor);
        }

        expect(token_requests($this->env))->toBe(6);
        expect($this->env->mails)->toHaveCount(2);
        expect($this->env->mails[1]['message'])->toContain('2026-09-28 01:00')->not->toContain('2026-09-21');
    });

    it('starts next week with a reset attempt count after a failed round', function () {
        $this->env->outcomes = ['fail', 'fail', 'fail', 'fail', 'ok'];

        for ($i = 0; $i < 5; $i++) {
            cron_tick($this->env, $this->monitor);
        }

        expect($this->env->mails)->toHaveCount(1);
        expect(count($this->env->options[Connection_Monitor::STATE_OPTION]['attempts']))->toBe(2);
        expect(events_for($this->env, Connection_Monitor::RETRY_HOOK))->toBe([]);
    });

    it('does not move the next weekly start when retries run', function () {
        $this->env->outcomes = ['fail', 'fail', 'fail'];

        cron_tick($this->env, $this->monitor);
        $weekly = events_for($this->env, Connection_Monitor::WEEKLY_HOOK);
        cron_tick($this->env, $this->monitor);
        cron_tick($this->env, $this->monitor);

        expect(events_for($this->env, Connection_Monitor::WEEKLY_HOOK))->toBe($weekly);
    });

    it('ignores a duplicate weekly callback within the same round', function () {
        $this->env->outcomes = ['fail', 'fail', 'fail', 'fail'];

        cron_tick($this->env, $this->monitor);
        $this->env->now += 30;
        $this->monitor->run_weekly();

        expect(token_requests($this->env))->toBe(1);
        expect(events_for($this->env, Connection_Monitor::RETRY_HOOK))->toHaveCount(1);
        expect(events_for($this->env, Connection_Monitor::WEEKLY_HOOK))->toHaveCount(1);
    });

    it('ignores duplicate and stale retries without extra attempts or emails', function () {
        $this->env->outcomes = ['fail', 'fail', 'fail', 'fail', 'fail'];

        cron_tick($this->env, $this->monitor);
        $retry2 = cron_tick($this->env, $this->monitor);
        $round  = $retry2['args'][0];

        // The same attempt fires twice.
        $this->monitor->run_retry($round, 2);
        expect(token_requests($this->env))->toBe(2);

        cron_tick($this->env, $this->monitor);
        expect($this->env->mails)->toHaveCount(1);

        // Replays after the round finished.
        $this->monitor->run_retry($round, 3);
        $this->monitor->run_retry($round, 4);
        expect(token_requests($this->env))->toBe(3);
        expect($this->env->mails)->toHaveCount(1);

        // A retry from the old round arrives during next week's round.
        cron_tick($this->env, $this->monitor);
        $this->monitor->run_retry($round, 2);
        expect(token_requests($this->env))->toBe(4);
        expect(count($this->env->options[Connection_Monitor::STATE_OPTION]['attempts']))->toBe(1);
    });

    it('uses the saved settings current at each attempt', function () {
        $this->env->outcomes = ['fail', 'ok'];

        cron_tick($this->env, $this->monitor);
        $this->env->options[Settings::OPTION_KEY]['client_id']            = 'new-client.apps.googleusercontent.com';
        $this->env->options[Settings::OPTION_KEY]['conversion_action_id'] = '42';
        cron_tick($this->env, $this->monitor);

        expect($this->env->requests[0]['args']['body']['client_id'])->toBe('client.apps.googleusercontent.com');
        expect($this->env->requests[1]['args']['body']['client_id'])->toBe('new-client.apps.googleusercontent.com');
        expect(json_decode($this->env->requests[2]['args']['body'], true)['query'])->toContain('conversion_action.id = 42');
    });

    it('ends the round without Google requests, retries, or email when not configured', function () {
        $this->env->options[Settings::OPTION_KEY]['refresh_token'] = '';

        cron_tick($this->env, $this->monitor);

        expect($this->env->requests)->toBe([]);
        expect(events_for($this->env, Connection_Monitor::RETRY_HOOK))->toBe([]);
        expect(events_for($this->env, Connection_Monitor::WEEKLY_HOOK))->toHaveCount(1);
        expect($this->env->mails)->toBe([]);
    });

    it('counts an exception from the test as a failed attempt', function () {
        $this->env->outcomes = ['throw'];

        cron_tick($this->env, $this->monitor);

        expect(events_for($this->env, Connection_Monitor::RETRY_HOOK))->toHaveCount(1);
        expect($this->env->options[Connection_Monitor::STATE_OPTION]['attempts'][0]['error'])->toBe('Transport exploded');
    });

    it('logs a failed email without scheduling anything extra', function () {
        $logged = [];
        \Patchwork\redefine('error_log', function (string $message) use (&$logged) {
            $logged[] = $message;
            return true;
        });
        $this->env->outcomes    = ['fail', 'fail', 'fail'];
        $this->env->mail_result = false;

        cron_tick($this->env, $this->monitor);
        cron_tick($this->env, $this->monitor);
        cron_tick($this->env, $this->monitor);

        expect($this->env->mails)->toHaveCount(1);
        expect($logged)->toHaveCount(1);
        expect($logged[0])->toContain('notification email could not be sent');
        expect($this->env->events)->toHaveCount(1);
        expect($this->env->events[0]['hook'])->toBe(Connection_Monitor::WEEKLY_HOOK);
    });

    it('keeps email errors short and free of markup', function () {
        Functions\when('wp_remote_post')->alias(fn () => [
            'response' => ['code' => 400],
            'body'     => json_encode(['error_description' => '<b>Bad</b> ' . str_repeat('x', 1000)]),
        ]);

        for ($i = 0; $i < 3; $i++) {
            cron_tick($this->env, $this->monitor);
        }

        $error = $this->env->options[Connection_Monitor::STATE_OPTION]['attempts'][0]['error'];
        expect($error)->toStartWith('Bad xxx')->not->toContain('<b>');
        expect(mb_strlen($error))->toBe(301);
    });

});
