<?php

namespace Plugin\RedirectManager\Tests;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use PHPUnit\Framework\Attributes\Test;
use Plugin\RedirectManager\Backend\Models\RedirectIssue;
use Plugin\RedirectManager\Backend\Models\RedirectRule;
use Plugin\RedirectManager\Backend\Repositories\RedirectRuleRepository;
use RuntimeException;
use Tests\TestCase;

/**
 * Creating, re-creating and matching rules — the operator-facing edges.
 *
 * Rules are soft-deleted but the unique index covers trashed rows, so "delete a rule, then
 * type it again" is a path that has to be handled deliberately rather than left to the
 * database. It is also the path a migration takes twice: import a CSV, tidy some rules,
 * import a corrected CSV.
 */
class RedirectRuleLifecycleTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        RedirectRule::withTrashed()->forceDelete();
        RedirectIssue::query()->forceDelete();
    }

    protected function tearDown(): void
    {
        RedirectRule::withTrashed()->forceDelete();
        RedirectIssue::query()->forceDelete();
        parent::tearDown();
    }

    private function repo(): RedirectRuleRepository
    {
        return app(RedirectRuleRepository::class);
    }

    private function attributes(array $overrides = []): array
    {
        return array_replace([
            'match_type' => RedirectRule::MATCH_EXACT,
            'from_path'  => 'old-page',
            'to_path'    => 'new-page',
            'code'       => 301,
            'status'     => RedirectRule::STATUS_ACTIVE,
        ], $overrides);
    }

    #[Test]
    public function re_creating_a_deleted_rule_revives_it(): void
    {
        // The dead end this fixes: the unique index covers trashed rows, so typing the rule
        // again failed, and the error pointed at a Restore action the rules screen has not
        // got. Import already revived; create now agrees with it.
        $original = $this->repo()->create($this->attributes());
        $this->repo()->delete($original->id);

        $this->assertSoftDeleted('redirect_manager_rules', ['id' => $original->id]);

        $again = $this->repo()->create($this->attributes(['to_path' => 'somewhere-else']));

        $this->assertSame($original->id, $again->id, 'The same rule should come back, not a second row.');
        $this->assertNull($again->fresh()->deleted_at);
        $this->assertSame('somewhere-else', $again->fresh()->to_path);
        $this->assertSame(1, RedirectRule::withTrashed()->count());
    }

    #[Test]
    public function reviving_keeps_the_rules_own_history(): void
    {
        // It is the same rule for the same path; its hit history did not stop being true.
        $rule = $this->repo()->create($this->attributes());
        $rule->forceFill(['hits' => 27])->save();
        $this->repo()->delete($rule->id);

        $revived = $this->repo()->create($this->attributes());

        $this->assertSame(27, (int) $revived->fresh()->hits);
    }

    #[Test]
    public function a_path_typed_with_slashes_still_finds_the_trashed_rule(): void
    {
        // Stored normalised, so a revive that matched the raw string would miss and fall
        // through to an insert the database then refuses.
        $rule = $this->repo()->create($this->attributes(['from_path' => 'old-page']));
        $this->repo()->delete($rule->id);

        $revived = $this->repo()->create($this->attributes(['from_path' => '/old-page/']));

        $this->assertSame($rule->id, $revived->id);
    }

    #[Test]
    public function a_live_duplicate_is_still_refused(): void
    {
        // Reviving must not become "silently overwrite the rule you already have".
        $this->repo()->create($this->attributes());

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('There is already a rule for that path');

        $this->repo()->create($this->attributes(['to_path' => 'other']));
    }

    #[Test]
    public function the_same_path_may_carry_two_rules_with_different_queries(): void
    {
        // The WordPress case the three-column index exists for.
        $this->repo()->create($this->attributes(['from_path' => '', 'query_match' => 'p=123', 'to_path' => 'a']));
        $this->repo()->create($this->attributes(['from_path' => '', 'query_match' => 'p=124', 'to_path' => 'b']));

        $this->assertSame(2, RedirectRule::count());
    }

    #[Test]
    public function an_uncompilable_pattern_is_refused_with_a_readable_message(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('not a valid regular expression');

        $this->repo()->create($this->attributes([
            'match_type' => RedirectRule::MATCH_REGEX,
            'from_path'  => '^products/((((',
        ]));
    }

    // -----------------------------------------------------------------------
    // The suggested fix shown against a broken link
    // -----------------------------------------------------------------------

    private function issue(string $path): RedirectIssue
    {
        return RedirectIssue::create([
            'type'         => RedirectIssue::TYPE_NOT_FOUND,
            'path'         => $path,
            'hits'         => 1,
            'last_seen_at' => now(),
        ]);
    }

    #[Test]
    public function a_broken_link_finds_the_active_exact_rule_that_fixes_it(): void
    {
        $rule = $this->repo()->create($this->attributes(['from_path' => 'gone']));

        $this->assertSame($rule->id, $this->issue('gone')->rule()?->id);
    }

    #[Test]
    public function a_disabled_rule_is_not_offered_as_the_fix(): void
    {
        // It fires for nobody, so showing the link as handled is the one wrong answer this
        // screen must not give.
        $this->repo()->create($this->attributes(['from_path' => 'gone', 'status' => 'inactive']));

        $this->assertNull($this->issue('gone')->rule());
    }

    #[Test]
    public function a_prefix_rule_sharing_the_path_is_not_offered_as_the_fix(): void
    {
        // A prefix rule covers everything *under* the path, not the path itself.
        $this->repo()->create($this->attributes([
            'match_type' => RedirectRule::MATCH_PREFIX,
            'from_path'  => 'gone',
            'to_path'    => 'elsewhere/$1',
        ]));

        $this->assertNull($this->issue('gone')->rule());
    }
}
