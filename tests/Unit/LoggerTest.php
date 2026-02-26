<?php
/**
 * Unit tests for Logger.
 *
 * @package Tests\Unit
 * @since   1.4.0
 */

declare(strict_types=1);

use Kntnt\Ad_Attribution_Gads\Logger;
use Kntnt\Ad_Attribution_Gads\Settings;
use Brain\Monkey\Functions;

/**
 * Creates a temporary directory for log testing.
 *
 * @return string Absolute path to the temp directory.
 */
function create_temp_log_dir(): string {
    $dir = sys_get_temp_dir() . '/kntnt-logger-test-' . uniqid();
    mkdir($dir, 0755, true);
    return $dir;
}

/**
 * Recursively removes a directory and its contents.
 *
 * @param string $dir Directory to remove.
 *
 * @return void
 */
function remove_temp_dir(string $dir): void {
    if (!is_dir($dir)) {
        return;
    }
    foreach (glob($dir . '/{,.}*', GLOB_BRACE) as $file) {
        if (basename($file) === '.' || basename($file) === '..') {
            continue;
        }
        is_dir($file) ? remove_temp_dir($file) : unlink($file);
    }
    rmdir($dir);
}

/**
 * Stubs WordPress functions needed by Logger for file operations.
 *
 * @param string $basedir The base uploads directory path.
 *
 * @return void
 */
function stub_logger_wp_functions(string $basedir): void {
    Functions\when('wp_upload_dir')->justReturn(['basedir' => $basedir]);
    Functions\when('wp_mkdir_p')->alias(function (string $dir) {
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        return true;
    });
    Functions\when('wp_delete_file')->alias(function (string $path) {
        if (file_exists($path)) {
            unlink($path);
        }
    });
    Functions\when('wp_date')->justReturn('2026-02-26 14:30:00+01:00');
}

// ─── mask() ───

describe('Logger::mask()', function () {

    it('masks a long string showing only last 4 characters', function () {
        expect(Logger::mask('my_secret_value_1234'))->toBe('****************1234');
    });

    it('masks a short string completely when 4 chars or fewer', function () {
        expect(Logger::mask('abc'))->toBe('***');
        expect(Logger::mask('abcd'))->toBe('****');
    });

    it('returns empty string for empty input', function () {
        expect(Logger::mask(''))->toBe('');
    });

    it('masks a single character', function () {
        expect(Logger::mask('x'))->toBe('*');
    });

    it('masks exactly 5 characters showing last 4', function () {
        expect(Logger::mask('abcde'))->toBe('*bcde');
    });

});

// ─── info() and error() ───

describe('Logger::info() and Logger::error()', function () {

    beforeEach(function () {
        $this->tempDir = create_temp_log_dir();
        $this->logDir  = $this->tempDir . '/kntnt-ad-attr-gads';
        $this->logFile = $this->logDir . '/kntnt-ad-attr-gads.log';
        stub_logger_wp_functions($this->tempDir);
    });

    afterEach(function () {
        remove_temp_dir($this->tempDir);
    });

    it('writes INFO entry to log file', function () {
        $settings = Mockery::mock(Settings::class);
        $settings->shouldReceive('get')->with('enable_logging')->andReturn('1');

        $logger = new Logger($settings);
        $logger->info('Token refresh successful');

        $contents = file_get_contents($this->logFile);
        expect($contents)->toContain('[2026-02-26 14:30:00+01:00] INFO Token refresh successful');
    });

    it('writes ERROR entry to log file', function () {
        $settings = Mockery::mock(Settings::class);
        $settings->shouldReceive('get')->with('enable_logging')->andReturn('1');

        $logger = new Logger($settings);
        $logger->error('Upload failed');

        $contents = file_get_contents($this->logFile);
        expect($contents)->toContain('[2026-02-26 14:30:00+01:00] ERROR Upload failed');
    });

    it('creates directory and file if they do not exist', function () {
        $settings = Mockery::mock(Settings::class);
        $settings->shouldReceive('get')->with('enable_logging')->andReturn('1');

        expect(is_dir($this->logDir))->toBeFalse();

        $logger = new Logger($settings);
        $logger->info('First entry');

        expect(is_dir($this->logDir))->toBeTrue();
        expect(file_exists($this->logFile))->toBeTrue();
    });

    it('appends multiple entries to the same file', function () {
        $settings = Mockery::mock(Settings::class);
        $settings->shouldReceive('get')->with('enable_logging')->andReturn('1');

        $logger = new Logger($settings);
        $logger->info('First');
        $logger->error('Second');
        $logger->info('Third');

        $contents = file_get_contents($this->logFile);
        expect(substr_count($contents, "\n"))->toBe(3);
        expect($contents)->toContain('INFO First');
        expect($contents)->toContain('ERROR Second');
        expect($contents)->toContain('INFO Third');
    });

    it('does not write when logging is disabled', function () {
        $settings = Mockery::mock(Settings::class);
        $settings->shouldReceive('get')->with('enable_logging')->andReturn('');

        $logger = new Logger($settings);
        $logger->info('Should not appear');
        $logger->error('Also should not appear');

        expect(file_exists($this->logFile))->toBeFalse();
    });

});

// ─── clear(), exists(), get_contents() ───

describe('Logger file management', function () {

    beforeEach(function () {
        $this->tempDir = create_temp_log_dir();
        $this->logDir  = $this->tempDir . '/kntnt-ad-attr-gads';
        $this->logFile = $this->logDir . '/kntnt-ad-attr-gads.log';
        stub_logger_wp_functions($this->tempDir);
    });

    afterEach(function () {
        remove_temp_dir($this->tempDir);
    });

    it('exists() returns false when no log file', function () {
        $settings = Mockery::mock(Settings::class);
        $logger   = new Logger($settings);
        expect($logger->exists())->toBeFalse();
    });

    it('exists() returns true after writing', function () {
        $settings = Mockery::mock(Settings::class);
        $settings->shouldReceive('get')->with('enable_logging')->andReturn('1');

        $logger = new Logger($settings);
        $logger->info('Test');
        expect($logger->exists())->toBeTrue();
    });

    it('get_contents() returns empty string when no file', function () {
        $settings = Mockery::mock(Settings::class);
        $logger   = new Logger($settings);
        expect($logger->get_contents())->toBe('');
    });

    it('get_contents() returns file contents', function () {
        $settings = Mockery::mock(Settings::class);
        $settings->shouldReceive('get')->with('enable_logging')->andReturn('1');

        $logger = new Logger($settings);
        $logger->info('Hello world');

        $contents = $logger->get_contents();
        expect($contents)->toContain('Hello world');
    });

    it('clear() deletes the log file', function () {
        $settings = Mockery::mock(Settings::class);
        $settings->shouldReceive('get')->with('enable_logging')->andReturn('1');

        $logger = new Logger($settings);
        $logger->info('To be deleted');
        expect($logger->exists())->toBeTrue();

        $logger->clear();
        expect($logger->exists())->toBeFalse();
    });

    it('clear() is safe when no file exists', function () {
        $settings = Mockery::mock(Settings::class);
        $logger   = new Logger($settings);

        $logger->clear();
        expect($logger->exists())->toBeFalse();
    });

});

// ─── get_path() and get_relative_path() ───

describe('Logger path methods', function () {

    it('get_path() returns absolute path in uploads directory', function () {
        Functions\when('wp_upload_dir')->justReturn(['basedir' => '/var/www/html/wp-content/uploads']);

        $settings = Mockery::mock(Settings::class);
        $logger   = new Logger($settings);

        expect($logger->get_path())->toBe('/var/www/html/wp-content/uploads/kntnt-ad-attr-gads/kntnt-ad-attr-gads.log');
    });

    it('get_relative_path() strips ABSPATH', function () {
        Functions\when('wp_upload_dir')->justReturn(['basedir' => '/tmp/wordpress/wp-content/uploads']);

        $settings = Mockery::mock(Settings::class);
        $logger   = new Logger($settings);

        // ABSPATH is defined as '/tmp/wordpress/' in WpStubs.php.
        expect($logger->get_relative_path())->toBe('wp-content/uploads/kntnt-ad-attr-gads/kntnt-ad-attr-gads.log');
    });

});

// ─── File truncation ───

describe('Logger file truncation', function () {

    beforeEach(function () {
        $this->tempDir = create_temp_log_dir();
        $this->logDir  = $this->tempDir . '/kntnt-ad-attr-gads';
        $this->logFile = $this->logDir . '/kntnt-ad-attr-gads.log';
        mkdir($this->logDir, 0755, true);
        stub_logger_wp_functions($this->tempDir);
    });

    afterEach(function () {
        remove_temp_dir($this->tempDir);
    });

    it('trims file when size exceeds 500 KB', function () {
        $settings = Mockery::mock(Settings::class);
        $settings->shouldReceive('get')->with('enable_logging')->andReturn('1');

        // Pre-fill the log with ~600 KB of complete log lines.
        $line       = "[2026-02-26 14:00:00+01:00] INFO " . str_repeat('x', 200) . "\n";
        $lineLen    = strlen($line);
        $numLines   = (int) ceil(600_000 / $lineLen);
        $content    = str_repeat($line, $numLines);
        file_put_contents($this->logFile, $content);
        expect(filesize($this->logFile))->toBeGreaterThan(512_000);

        // Trigger a write — should trim before appending.
        $logger = new Logger($settings);
        $logger->info('After trim');

        $newSize = filesize($this->logFile);
        expect($newSize)->toBeLessThan(300_000);
        expect($newSize)->toBeGreaterThan(100_000);

        // Verify the new entry was appended after trimming.
        $contents = file_get_contents($this->logFile);
        expect($contents)->toContain('After trim');

        // Verify no partial first line (trim cuts at newline boundary).
        $firstLine = strtok($contents, "\n");
        expect($firstLine)->toStartWith('[');
    });

    it('does not trim file under 500 KB', function () {
        $settings = Mockery::mock(Settings::class);
        $settings->shouldReceive('get')->with('enable_logging')->andReturn('1');

        // Pre-fill with ~100 KB of data.
        $content = str_repeat("[2026-02-26 14:00:00+01:00] INFO test line\n", 2300);
        file_put_contents($this->logFile, $content);
        $originalSize = filesize($this->logFile);
        expect($originalSize)->toBeLessThan(512_000);

        $logger = new Logger($settings);
        $logger->info('New entry');

        // File should have grown, not shrunk.
        expect(filesize($this->logFile))->toBeGreaterThan($originalSize);
    });

});
