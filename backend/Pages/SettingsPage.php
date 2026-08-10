<?php

namespace Plugin\RedirectManager\Backend\Pages;

use Plugin\RedirectManager\Backend\Models\RedirectSetting;
use Plugin\RedirectManager\Backend\Services\PathSuggester;

/**
 * The Settings screen: read the one row, write the one row.
 */
class SettingsPage
{
    /** @return array<string,mixed> */
    public function data(): array
    {
        $settings = RedirectSetting::current();

        return [
            'log_404'                => $settings->log_404,
            'suggest_enabled'        => $settings->suggest_enabled,
            'suggest_threshold'      => $settings->suggest_threshold,
            'storefront_suggestions' => $settings->storefront_suggestions,
            'retention_days'         => $settings->retention_days,

            // The form edits this as a repeater, which binds a list of objects rather than a
            // list of strings. Shaped on the way out and flattened on the way back in, so the
            // stored value stays the plain array everything else reads.
            'ignore_patterns' => array_map(
                fn ($p) => ['pattern' => $p],
                (array) $settings->ignore_patterns
            ),
        ];
    }

    /**
     * @param  array<string,mixed>  $data
     * @return array<string,mixed>
     */
    public function save(array $data): array
    {
        $settings = RedirectSetting::current();

        // Whitelisted, not mass-assigned from the payload. A custom page posts its whole bound
        // model back — `id` and timestamps included — and a settings row is exactly the kind
        // of record where quietly accepting an `id` would write to the wrong one.
        $settings->fill([
            'log_404'                => (bool) ($data['log_404'] ?? true),
            'suggest_enabled'        => (bool) ($data['suggest_enabled'] ?? true),
            'suggest_threshold'      => $this->threshold($data['suggest_threshold'] ?? 70),
            'storefront_suggestions' => (bool) ($data['storefront_suggestions'] ?? false),
            'retention_days'         => $this->retention($data['retention_days'] ?? null),
            'ignore_patterns'        => $this->patterns($data['ignore_patterns'] ?? []),
        ])->save();

        RedirectSetting::forget();

        // The threshold and the enable switch both change what the suggester would return, and
        // its candidate list is cached — an operator who lowers the threshold and sees no
        // change would reasonably conclude the setting does nothing.
        PathSuggester::forget();

        return ['message' => 'Settings saved.'];
    }

    /**
     * Clamped rather than validated, because the column is a `tinyint` and a percentage above
     * 100 or below 0 has no meaning to reject — it has a nearest sensible value.
     */
    private function threshold(mixed $value): int
    {
        return max(0, min(100, (int) $value));
    }

    /** Null means "keep everything"; zero would mean "delete everything", so it is not it. */
    private function retention(mixed $value): ?int
    {
        if ($value === null || $value === '' || (int) $value <= 0) {
            return null;
        }

        return (int) $value;
    }

    /**
     * Flatten the repeater back to a list of globs.
     *
     * Blank rows are dropped rather than stored: a repeater always carries the empty row an
     * operator was about to fill in, and an empty pattern would match nothing while looking
     * like a rule that does.
     *
     * @param  mixed  $value
     * @return array<int,string>
     */
    private function patterns(mixed $value): array
    {
        $out = [];

        foreach ((array) $value as $row) {
            $pattern = is_array($row) ? ($row['pattern'] ?? '') : $row;
            $pattern = trim((string) $pattern, "/ \t\n\r\0\x0B");

            if ($pattern !== '' && ! in_array($pattern, $out, true)) {
                $out[] = $pattern;
            }
        }

        return $out;
    }
}
