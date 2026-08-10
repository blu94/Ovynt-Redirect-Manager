<?php

namespace Plugin\RedirectManager\Backend\Models;

use App\Traits\LogsSystemActivity;
use Illuminate\Database\Eloquent\Model;

/**
 * How the plugin behaves — one row, id 1.
 *
 * Read on the 404 path, so `current()` is memoised for the life of the request: a single page
 * miss can consult these several times (once to decide whether to log, once per suggestion
 * pass) and none of them should be a separate query.
 */
class RedirectSetting extends Model
{
    /**
     * Switching off 404 logging, or widening the ignore list, makes evidence stop appearing —
     * which looks exactly like nothing being broken. The audit trail is what distinguishes the
     * two months later.
     */
    use LogsSystemActivity;

    protected $table = 'redirect_manager_settings';

    protected $fillable = [
        'log_404',
        'ignore_patterns',
        'suggest_enabled',
        'suggest_threshold',
        'storefront_suggestions',
        'retention_days',
    ];

    protected $casts = [
        'log_404'                => 'boolean',
        'ignore_patterns'        => 'array',
        'suggest_enabled'        => 'boolean',
        'suggest_threshold'      => 'integer',
        'storefront_suggestions' => 'boolean',
        'retention_days'         => 'integer',
    ];

    private static ?self $cached = null;

    /**
     * The settings row, created with the column defaults if it is not there yet.
     *
     * `firstOrCreate` rather than a seeder: the row has to exist the first time anything reads
     * it, and a plugin's migrations run on enable while its first 404 could arrive a
     * millisecond later.
     *
     * **`refresh()` is load-bearing, not tidiness.** Every default here lives on the column, and
     * `firstOrCreate(['id' => 1])` inserts that one attribute and hands back a model carrying
     * only what it was given — Laravel does not re-read the row, so the defaults the database
     * just applied are absent from the instance. The caller that creates the row therefore sees
     * `null` for the threshold, the log switch and everything else.
     *
     * That caller is whoever hits the first missing page after the plugin is enabled: the
     * suggester takes `int $threshold`, gets null, throws a `TypeError`, and the listener's own
     * catch turns it into a log line and no redirect. Silent, once, on a fresh install — the
     * hardest shape of bug to be told about. Re-reading costs one query, on one request, ever.
     */
    public static function current(): self
    {
        return self::$cached ??= self::query()->firstOrCreate(['id' => 1])->refresh();
    }

    /** Drop the memo. Called after a save so the next read sees what was just written. */
    public static function forget(): void
    {
        self::$cached = null;
    }

    /**
     * Whether a path should be left unrecorded.
     *
     * Globs rather than regular expressions: an operator writing an ignore list is thinking
     * "anything under wp-admin", and `fnmatch` says that as `wp-admin/*` without a chance of
     * writing a pattern that backtracks catastrophically on the 404 path.
     *
     * `FNM_CASEFOLD` because these are noise filters, not routing — someone excluding
     * `wp-admin/*` means it whichever case the scanner used.
     */
    public function ignores(string $path): bool
    {
        foreach ((array) $this->ignore_patterns as $pattern) {
            $pattern = trim((string) $pattern, "/ \t\n\r\0\x0B");

            if ($pattern === '') {
                continue;
            }

            if (fnmatch($pattern, $path, FNM_CASEFOLD)) {
                return true;
            }
        }

        return false;
    }
}
