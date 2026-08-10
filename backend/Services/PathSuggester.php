<?php

namespace Plugin\RedirectManager\Backend\Services;

use App\Http\Controllers\Seo\SitemapController;
use App\Models\Blog;
use App\Models\Category;
use App\Models\Page;
use App\Models\Product;
use Illuminate\Support\Facades\Cache;
use Plugin\RedirectManager\Backend\Models\RedirectRule;
use Plugin\RedirectManager\Backend\Models\RedirectSetting;

/**
 * Given a path that leads nowhere, find the ones that do.
 *
 * Most broken links are near misses — a slug that was tidied, a section that was renamed, a
 * link somebody typed by hand. The destination is usually already on the site, and making the
 * operator go and find it is the tedious part of fixing a migration.
 *
 * **The candidate paths come from the same map the sitemap uses**: pages are bare slugs and
 * everything else sits under a prefix, mirroring `ThemeController`'s catch-all. That map is
 * `protected` on `SitemapController`, so it is restated below rather than called — if core
 * ever adds a content type with a public URL, this is the line that has to learn about it.
 */
class PathSuggester
{
    private const CACHE_KEY = 'redirect-manager:candidate-paths';

    private const CACHE_TTL = 600;

    /**
     * The most paths ever compared against.
     *
     * Scoring is a `similar_text` per candidate, and on a large catalogue an uncapped list
     * would make every suggestion a walk over tens of thousands of strings. Three good
     * suggestions out of the first few thousand paths is the same answer an operator would
     * have accepted from all of them, and the cap is what keeps this affordable on the 404
     * path when an operator switches storefront suggestions on.
     */
    private const MAX_CANDIDATES = 5000;

    /**
     * Paths worth offering instead of the broken one, best first.
     *
     * @return array<int,array{path:string,label:string,score:int}>
     */
    public function suggest(string $path, int $limit = 3): array
    {
        $settings = RedirectSetting::current();

        if (! $settings->suggest_enabled) {
            return [];
        }

        return $this->score($path, $settings->suggest_threshold, $limit);
    }

    /**
     * Score without consulting the settings.
     *
     * Separate from `suggest()` so the Broken Links screen can offer a suggestion even when an
     * operator has switched the storefront ones off — those are two different decisions, and
     * conflating them would mean turning off a visitor-facing feature also removed the tool
     * the operator uses to fix it.
     *
     * @return array<int,array{path:string,label:string,score:int}>
     */
    public function score(string $path, int $threshold = 70, int $limit = 3): array
    {
        $needle = RedirectRule::normalisePath($path);

        if ($needle === '') {
            return [];
        }

        $scored = [];

        foreach ($this->candidates() as $candidate) {
            if ($candidate['path'] === $needle) {
                continue;
            }

            similar_text($needle, $candidate['path'], $percent);

            if ($percent < $threshold) {
                continue;
            }

            $scored[] = $candidate + ['score' => (int) round($percent)];
        }

        // Highest score first; then the shorter path, because when two candidates score the
        // same the more specific one is usually the accident — "shop/shoes" beats
        // "shop/shoes/womens/running" as the home for a link to "shop/shoe".
        usort($scored, fn ($a, $b) => ($b['score'] <=> $a['score'])
            ?: (strlen($a['path']) <=> strlen($b['path'])));

        return array_slice($scored, 0, max(1, $limit));
    }

    /**
     * Every live storefront path, with something to call it.
     *
     * Cached because it is the same answer for every visitor and rebuilding it per 404 would
     * make a crawler walking a dead sitemap the most expensive thing the site does. The TTL is
     * short rather than event-driven: a path that appeared ten minutes ago being missing from
     * a suggestion list is a cosmetic staleness, and wiring this to every content save would
     * couple the plugin to models it does not own.
     *
     * @return array<int,array{path:string,label:string}>
     */
    private function candidates(): array
    {
        return Cache::remember(self::CACHE_KEY, self::CACHE_TTL, function () {
            $out = [];

            foreach ($this->sources() as $source) {
                $query = $source['model']::query()->where('status', 'active');

                if ($source['model'] === Page::class) {
                    $query->whereIn('type', SitemapController::INDEXABLE_PAGE_TYPES);
                }

                foreach ($query->get(['id', 'title', 'slug']) as $record) {
                    if (count($out) >= self::MAX_CANDIDATES) {
                        break 2;
                    }

                    $slug = $this->slug($record);

                    if ($slug === null) {
                        continue;
                    }

                    $out[] = [
                        'path'  => RedirectRule::normalisePath($source['prefix'] . $slug),
                        'label' => $this->label($record) ?: $slug,
                    ];
                }
            }

            return $out;
        });
    }

    /**
     * The content types with a public URL, and the prefix each answers on.
     *
     * Restated from `SitemapController::types()`, which is protected. Kept in the same order
     * so a tie between a page and a product resolves the way the sitemap lists them.
     *
     * @return array<int,array{model:class-string,prefix:string}>
     */
    private function sources(): array
    {
        return [
            ['model' => Page::class,     'prefix' => ''],
            ['model' => Product::class,  'prefix' => 'products/'],
            ['model' => Blog::class,     'prefix' => 'blogs/'],
            ['model' => Category::class, 'prefix' => 'collections/'],
        ];
    }

    /**
     * Slugs are translatable JSON. The current locale first, then the fallback, then whatever
     * the record has — a record with no slug in any locale has no URL and is skipped.
     */
    private function slug(mixed $record): ?string
    {
        $slug = $record->slug;

        if (is_array($slug)) {
            $slug = $slug[app()->getLocale()]
                ?? $slug[config('app.fallback_locale')]
                ?? (reset($slug) ?: null);
        }

        $slug = is_string($slug) ? trim($slug, '/') : null;

        return $slug !== '' ? $slug : null;
    }

    /** A human name for the destination, so a suggestion reads as a page and not a string. */
    private function label(mixed $record): ?string
    {
        $title = $record->title ?? null;

        if (is_array($title)) {
            $title = $title[app()->getLocale()]
                ?? $title[config('app.fallback_locale')]
                ?? (reset($title) ?: null);
        }

        return is_string($title) && trim($title) !== '' ? trim($title) : null;
    }

    /** Drop the cached path list. */
    public static function forget(): void
    {
        Cache::forget(self::CACHE_KEY);
    }
}
