<?php

namespace Plugin\RedirectManager\Backend\Support;

use Plugin\RedirectManager\Backend\Models\RedirectSetting;
use Plugin\RedirectManager\Backend\Services\PathSuggester;

/**
 * What the storefront's 404 slot offers a visitor who reached a dead URL.
 *
 * The blade used to read the settings row and call the suggester itself, inside `@php`.
 * `.agent/rules/lessons-learned.md` §7 forbids that — data logic belongs in a driver, and the
 * template is presentation. It names core builder components and theme sections; a plugin slot
 * is a third case that did not exist when the rule was written, and the reasoning transfers
 * unchanged.
 *
 * A slot has no driver seam of its own the way a section does (`index.php` beside the blade),
 * because a plugin opts into a slot by shipping the file and registers nothing. So the seam is
 * an ordinary class in `Backend\Support\`, which the plugin autoloader resolves, and the blade
 * is left with a single call.
 */
class NotFoundSuggestions
{
    /**
     * Suggested destinations for a dead path, or an empty array.
     *
     * Empty whenever anything is off or goes wrong. **A 404 page that throws is a 500**, shown
     * to a visitor who merely mistyped a URL, so every failure here degrades to offering
     * nothing rather than propagating. The exception is still reported, because a suggester
     * failing on every 404 is worth knowing about even though no visitor should ever see it.
     *
     * @return array<int,array<string,mixed>>
     */
    public static function for(?string $path, int $limit = 3): array
    {
        try {
            if (! RedirectSetting::current()->storefront_suggestions) {
                return [];
            }

            return app(PathSuggester::class)->suggest(
                $path ?? request()->path(),
                $limit
            );
        } catch (\Throwable $e) {
            report($e);

            return [];
        }
    }
}
