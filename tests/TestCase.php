<?php

namespace Plugin\RedirectManager\Tests;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase as HostTestCase;

/**
 * Base for the tests that touch this package's tables.
 *
 * A package's own tests need two things: its classes on the autoloader, and its tables in the
 * schema. `autoload.php` answers the first permanently. This answers the second, which is the
 * half that kept coming back — the tables are real DDL in a shared test schema, and anything
 * that drops them leaves this suite red until somebody reinstalls.
 *
 * Core's own `RedirectManagerTest` drops all three in `tearDown` (deliberately — it creates
 * them itself and must not leak them into the next run), so simply running the app-side suite
 * leaves the package suite erroring with `Base table or view not found`. That reads as a code
 * regression and is not one, which is the expensive part.
 */
abstract class TestCase extends HostTestCase
{
    /**
     * The tables this package owns, in the order the manifest drops them — children first.
     *
     * Read from `plugin.json` rather than restated here. The manifest already has to name them
     * for uninstall, and a second list would be one more thing to forget.
     *
     * @var array<int,string>|null
     */
    private static ?array $tables = null;

    /** Once per process. `spl_autoload_register` aside, nothing here is worth repeating. */
    private static bool $checked = false;

    /**
     * Create the package's tables if they are missing.
     *
     * Deliberately in `refreshApplication()`, not `setUp()`. Laravel boots the application here
     * and only afterwards runs `setUpTraits()`, which is where `DatabaseTransactions` opens its
     * transaction — so this is the last moment a migration can run without MySQL's implicit
     * commit on DDL silently ending the transaction the test was relying on for its rollback.
     */
    protected function refreshApplication(): void
    {
        parent::refreshApplication();

        if (self::$checked) {
            return;
        }

        self::$checked = true;

        if (Schema::hasTable('redirect_manager_rules')) {
            return;
        }

        // A dropped table can leave its `migrations` row behind — core's test deletes both, but
        // anything that drops the tables directly would not. `migrate` trusts that row and would
        // skip the file, so the schema would never converge and the error would not change.
        foreach ($this->tables() as $table) {
            DB::table('migrations')->where('migration', 'like', '%' . $table . '%')->delete();
        }

        Artisan::call('migrate', [
            '--path'     => dirname(__DIR__) . '/backend/migrations',
            '--realpath' => true,
            '--force'    => true,
        ]);
    }

    /** @return array<int,string> */
    private function tables(): array
    {
        if (self::$tables !== null) {
            return self::$tables;
        }

        $manifest = json_decode(
            (string) file_get_contents(dirname(__DIR__) . '/plugin.json'),
            true
        );

        return self::$tables = $manifest['uninstall']['drop_tables'] ?? [];
    }
}
