<?php

namespace Plugin\RedirectManager\Backend\Services;

use Illuminate\Support\Facades\DB;
use Plugin\RedirectManager\Backend\Models\RedirectIssue;
use Plugin\RedirectManager\Backend\Models\RedirectRule;

/**
 * Write a problem down, once per path.
 *
 * The one place that knows how deduplication works, because two callers need it and they must
 * not drift: the 404 listener, and the rule editor checking whether what an operator just
 * saved points into a loop.
 *
 * Nothing here throws. Recording a problem happens while answering a 404 or saving a rule, and
 * failing to file the paperwork must never be what the visitor or the operator sees.
 */
class IssueRecorder
{
    /**
     * Record one occurrence, incrementing the count if the path is already known.
     *
     * `update`-then-`insert` keyed on the unique `(type, path)`, rather than read-then-write:
     * the alternative races two concurrent crawlers into a duplicate-key error on exactly the
     * traffic this exists to survive. A duplicate key on the insert means another request won,
     * which is the correct outcome — the row exists either way.
     *
     * **`context` is replaced, not merged.** It describes the most recent occurrence, and a
     * merged bag would accumulate every referrer a scanner ever sent.
     *
     * @param  array<string,mixed>  $context
     */
    public function record(string $type, string $path, array $context = []): void
    {
        $path = RedirectRule::normalisePath($path);

        if ($path === '' || ! in_array($type, RedirectIssue::TYPES, true)) {
            return;
        }

        try {
            $affected = DB::table((new RedirectIssue)->getTable())
                ->where('type', $type)
                ->where('path', $path)
                ->update([
                    'hits'         => DB::raw('hits + 1'),
                    'context'      => json_encode($context),
                    'last_seen_at' => now(),
                    'updated_at'   => now(),
                ]);

            if ($affected === 0) {
                RedirectIssue::create([
                    'type'         => $type,
                    'path'         => $path,
                    'context'      => $context,
                    'hits'         => 1,
                    'last_seen_at' => now(),
                ]);
            }
        } catch (\Throwable $e) {
            report($e);
        }
    }

    /**
     * Mark every open issue for a path as dealt with.
     *
     * Called when a rule covering that path is created. Without it, fixing a broken link
     * leaves the path sitting on the "still open" list and the operator has to remember to
     * tidy up after themselves — which is exactly the loop this plugin exists to close.
     */
    public function resolveFor(string $path): void
    {
        $path = RedirectRule::normalisePath($path);

        if ($path === '') {
            return;
        }

        try {
            RedirectIssue::query()
                ->where('path', $path)
                ->where('resolved', false)
                ->update(['resolved' => true, 'updated_at' => now()]);
        } catch (\Throwable $e) {
            report($e);
        }
    }
}
