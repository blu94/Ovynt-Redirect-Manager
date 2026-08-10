<?php

namespace Plugin\RedirectManager\Backend\Repositories;

use Plugin\RedirectManager\Backend\Models\RedirectIssue;
use Plugin\RedirectManager\Backend\Models\RedirectRule;
use Plugin\RedirectManager\Backend\Pages\OverviewPage;
use Plugin\RedirectManager\Backend\Pages\SettingsPage;
use Plugin\RedirectManager\Backend\Pages\TransferPage;
use Plugin\RedirectManager\Backend\Services\IssueRecorder;
use Plugin\RedirectManager\Backend\Services\RedirectMatcher;
use RuntimeException;

/**
 * The rules module: list, create, edit, delete, plus the custom pages hanging off it.
 *
 * There is no base class and no interface — Ovynt resolves this by name and calls the methods
 * below. Anything extra here is the plugin's own business.
 */
class RedirectRuleRepository
{
    public function __construct(
        private readonly IssueRecorder $recorder,
    ) {
    }

    public function baseIndexQuery(array $filters = [])
    {
        $query = RedirectRule::query();

        // `!empty`, not `isset`: DataTables sends `status=` when a filter is cleared, and
        // `isset('')` is true — which would silently filter the list down to nothing.
        foreach (['status', 'match_type'] as $column) {
            if (! empty($filters[$column])) {
                $query->where($column, $filters[$column]);
            }
        }

        if (! empty($filters['code'])) {
            $query->where('code', (int) $filters['code']);
        }

        return $query;
    }

    public function create(array $data)
    {
        $this->guard($data);

        // A rule the operator is typing again is one they are asking for back, so a
        // soft-deleted row with the same identity is revived rather than collided with.
        //
        // `TransferPage` already does exactly this on import, for exactly this reason. Until
        // both did, the create form was a dead end: the unique index covers trashed rows, so
        // the save failed, and the message told the operator to "restore or permanently
        // remove" the old rule using an action the rules screen does not have. The only way
        // out was to export a CSV and import it back.
        //
        // Reviving keeps the rule's `hits` and `last_hit_at`, which is the honest reading —
        // it is the same rule for the same path, and its history did not stop being true.
        $rule = $this->reviveTrashed($data) ?? RedirectRule::create($data);

        $this->afterWrite($rule);

        return $rule;
    }

    /**
     * Bring back a soft-deleted rule with the identity being created, or null.
     *
     * Matched on the three columns the unique index covers, normalised the same way the model
     * normalises them on save — otherwise a path typed as "/old-page/" would miss the trashed
     * row stored as "old-page" and fall through to an insert the database then refuses.
     *
     * @param  array<string,mixed>  $data
     */
    private function reviveTrashed(array $data): ?RedirectRule
    {
        $matchType = $data['match_type'] ?? RedirectRule::MATCH_EXACT;
        $from      = (string) ($data['from_path'] ?? '');

        $trashed = RedirectRule::onlyTrashed()
            ->where('match_type', $matchType)
            ->where('from_path', $matchType === RedirectRule::MATCH_REGEX
                ? $from
                : RedirectRule::normalisePath($from))
            ->where('query_match', ltrim(trim((string) ($data['query_match'] ?? '')), '?'))
            ->first();

        if ($trashed === null) {
            return null;
        }

        $trashed->deleted_at = null;
        $trashed->fill($data)->save();

        return $trashed;
    }

    public function find($id)
    {
        return RedirectRule::find($id);
    }

    public function update($id, array $data)
    {
        $rule = RedirectRule::findOrFail($id);

        $this->guard($data, $rule);

        $rule->update($data);

        $this->afterWrite($rule);

        return $rule;
    }

    public function delete($id)
    {
        $deleted = RedirectRule::destroy($id);

        RedirectMatcher::forget();

        return $deleted;
    }

    public function getOptions(array $columns = [])
    {
        $out = [];

        foreach (array_intersect($columns, ['status', 'match_type']) as $column) {
            $out[$column] = RedirectRule::query()->distinct()->pluck($column)->filter()->values();
        }

        return $out;
    }

    /**
     * Refuse a rule that cannot work, before it reaches the 404 path.
     *
     * **A pattern that will not compile is the case that matters.** `form.json` can require
     * the field, but no validation rule can tell whether a string is a valid regular
     * expression, and the matcher deliberately treats an uncompilable pattern as "no match"
     * so a visitor still gets a page. Those two safe behaviours combine into an unsafe one:
     * an operator saves a broken pattern, the screen says saved, and the rule silently never
     * fires. Checking here is what turns that into an error they can see.
     */
    private function guard(array $data, ?RedirectRule $existing = null): void
    {
        $matchType = $data['match_type'] ?? $existing?->match_type ?? RedirectRule::MATCH_EXACT;
        $from      = (string) ($data['from_path'] ?? $existing?->from_path ?? '');
        $query     = (string) ($data['query_match'] ?? $existing?->query_match ?? '');

        // An empty path is allowed — it is the site's front page, which is what an old
        // WordPress permalink like `/?p=123` needs. An empty path *and* an empty query is not:
        // that rule says "match the front page however it is asked for" and would send every
        // visitor arriving at the home page somewhere else.
        //
        // The form cannot express this — no validation rule can require one field only when
        // another is blank — which is why it is checked here rather than in `form.json`.
        if (trim($from) === '' && trim($query) === '') {
            throw new RuntimeException(
                'A rule needs something to match on. Give it a path, or leave the path empty and '
                . 'name a query — for example a query of "p=123" to catch an old /?p=123 link. '
                . 'A rule with neither would redirect your home page.'
            );
        }

        if ($matchType === RedirectRule::MATCH_REGEX
            && @preg_match('#' . str_replace('#', '\#', $from) . '#u', '') === false) {
            throw new RuntimeException(
                'That pattern is not a valid regular expression, so the rule would never match '
                . 'anything: ' . preg_last_error_msg() . '. Write the pattern without delimiters — '
                . 'for example blog/(\d+)/(.+) rather than /blog/(\d+)/(.+)/.'
            );
        }

        $this->guardDuplicate($data, $matchType, $from, $existing);
    }

    /**
     * Refuse a rule that already exists.
     *
     * **Not a `unique:` validation rule**, because uniqueness here is the three columns the
     * table's index covers — kind, path and query — and `unique:table,from_path` would reject
     * the second half of the pair a WordPress migration needs: `/?p=123` and `/?p=124` are
     * two different articles legitimately sharing one path.
     *
     * Without this the duplicate reaches the database, and the operator gets MySQL's integrity
     * constraint message with the index name in it instead of a sentence about their rule.
     */
    private function guardDuplicate(
        array $data,
        string $matchType,
        string $from,
        ?RedirectRule $existing,
    ): void {
        $query = (string) ltrim(trim((string) ($data['query_match'] ?? $existing?->query_match ?? '')), '?');

        $normalised = $matchType === RedirectRule::MATCH_REGEX
            ? $from
            : RedirectRule::normalisePath($from);

        $clash = RedirectRule::withTrashed()
            ->where('match_type', $matchType)
            ->where('from_path', $normalised)
            ->where('query_match', $query)
            ->when($existing !== null, fn ($q) => $q->whereKeyNot($existing->getKey()))
            // On create a trashed row is not a clash — `create()` revives it. Only an edit
            // colliding with a deleted rule still needs telling, because reviving there would
            // silently merge two rules the operator is holding apart.
            ->when($existing === null, fn ($q) => $q->whereNull('deleted_at'))
            ->first();

        if ($clash === null) {
            return;
        }

        throw new RuntimeException($clash->trashed()
            ? 'A deleted rule for that path still exists, so this edit would collide with it. '
                . 'Create the rule again from the Rules screen instead — that revives the deleted '
                . 'one — or import the replacement from Import & Export.'
            : "There is already a rule for that path (#{$clash->id}), sending it to "
                . "\"{$clash->to_path}\". Edit that one instead."
        );
    }

    /**
     * Housekeeping after a rule changes.
     *
     * Two things. The cached pattern set is dropped, because an operator who edits a rule and
     * then tests it must not be told it works when what they are seeing is the previous
     * version. And any open issue for the path the rule now covers is closed, because the
     * whole point of the Broken Links screen is that fixing something takes it off the list —
     * a list that only grows is one an operator stops reading.
     */
    private function afterWrite(RedirectRule $rule): void
    {
        RedirectMatcher::forget();

        if ($rule->match_type === RedirectRule::MATCH_EXACT) {
            $this->recorder->resolveFor($rule->from_path);
        }
    }

    /**
     * Data for a custom page. The slug matches the key in `module.json`.
     *
     * @return array<string,mixed>
     */
    public function pageData(string $slug)
    {
        return match ($slug) {
            'overview' => app(OverviewPage::class)->data(),
            'settings' => app(SettingsPage::class)->data(),
            'transfer' => app(TransferPage::class)->data(),
            default    => [],
        };
    }

    /**
     * @return array<string,mixed>|null
     */
    public function savePageData(string $slug, array $data)
    {
        return match ($slug) {
            'settings' => app(SettingsPage::class)->save($data),
            'transfer' => app(TransferPage::class)->save($data),
            default    => null,
        };
    }

    /**
     * Every rule whose destination is itself redirected, or which points into a circle.
     *
     * Read by the Overview so the operator sees the count without waiting for a visitor to
     * trip over it. Deliberately not run on write: a chain is created by the *second* rule,
     * and which of the two is wrong is a judgement only the operator can make.
     */
    public function auditChains(): array
    {
        $matcher = app(RedirectMatcher::class);
        $found   = ['chains' => 0, 'loops' => 0];

        RedirectRule::query()->active()->chunkById(200, function ($rules) use ($matcher, &$found) {
            foreach ($rules as $rule) {
                if ($rule->match_type !== RedirectRule::MATCH_EXACT) {
                    continue;
                }

                $match = $matcher->resolve($rule->from_path);

                if ($match->problem === RedirectIssue::TYPE_LOOP) {
                    $found['loops']++;
                    $this->recorder->record(RedirectIssue::TYPE_LOOP, $rule->from_path, [
                        'walked' => $match->walked,
                        'rule'   => $rule->id,
                    ]);
                } elseif ($match->problem === RedirectIssue::TYPE_CHAIN) {
                    $found['chains']++;
                    $this->recorder->record(RedirectIssue::TYPE_CHAIN, $rule->from_path, [
                        'walked' => $match->walked,
                        'rule'   => $rule->id,
                    ]);
                }
            }
        });

        return $found;
    }
}
