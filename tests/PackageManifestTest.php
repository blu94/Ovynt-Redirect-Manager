<?php

namespace Plugin\RedirectManager\Tests;

use App\Services\Plugin\PluginManifest;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The one claim the manifest makes that this package cannot survive being wrong about.
 */
class PackageManifestTest extends TestCase
{
    private function manifest(): PluginManifest
    {
        $path = dirname(__DIR__) . '/plugin.json';

        $this->assertFileExists($path);

        return PluginManifest::fromArray(
            json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR)
        );
    }

    /**
     * A root rule needs a core that asks about the root.
     *
     * An empty `from_path` plus a query is how an old permalink like `/?p=123` is caught — one
     * naming the page in the query rather than the path — and it only works on a build whose
     * `ThemeController` dispatches `PathNotResolved` for `/` when the request carries a query
     * string. That build is 1.3.0.
     *
     * While the constraint said `>=1.2.0` the package installed happily onto a build with no
     * root dispatch, where the rule saves, appears in the list, and silently never fires —
     * which is precisely the failure this package exists to prevent, reproduced in its own
     * manifest. Loosening the floor again would bring it back with no other symptom.
     */
    #[Test]
    public function the_package_refuses_a_core_that_cannot_dispatch_a_root_miss(): void
    {
        $manifest = $this->manifest();

        $this->assertFalse($manifest->satisfiedBy('1.2.0'), 'A build with no root dispatch must be refused.');
        $this->assertFalse($manifest->satisfiedBy('1.2.9'));
        $this->assertTrue($manifest->satisfiedBy('1.3.0'));

        // The upper bound is the other half of the promise: a 2.x core is a different
        // contract, and installing into one unread is how a package breaks quietly.
        $this->assertFalse($manifest->satisfiedBy('2.0.0'));
    }
}
