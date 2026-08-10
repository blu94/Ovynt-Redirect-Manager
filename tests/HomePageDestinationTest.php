<?php

namespace Plugin\RedirectManager\Tests;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use PHPUnit\Framework\Attributes\Test;
use Plugin\RedirectManager\Backend\Models\RedirectRule;
use Plugin\RedirectManager\Backend\Repositories\RedirectRuleRepository;

require_once __DIR__ . '/autoload.php';

/**
 * Sending a dead URL back to the front page.
 *
 * A destination is stored normalised, so `/` is trimmed to `''`. That is the right thing to
 * store — `toUrl('')` builds `url('/')` — but it means the home page is represented by the
 * *absence* of a value, and every "is this filled in?" check downstream read it as missing.
 * The redirect worked; everything else about the rule stopped working.
 */
class HomePageDestinationTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        RedirectRule::withTrashed()->forceDelete();
    }

    protected function tearDown(): void
    {
        RedirectRule::withTrashed()->forceDelete();
        parent::tearDown();
    }

    private function repo(): RedirectRuleRepository
    {
        return app(RedirectRuleRepository::class);
    }

    private function homeRule(): RedirectRule
    {
        return $this->repo()->create([
            'match_type' => RedirectRule::MATCH_EXACT,
            'from_path'  => 'old-page',
            'to_path'    => '/',
            'code'       => 301,
            'status'     => RedirectRule::STATUS_ACTIVE,
        ]);
    }

    #[Test]
    public function a_slash_is_stored_as_the_empty_destination_the_matcher_wants(): void
    {
        $rule = $this->homeRule();

        // `getRawOriginal` deliberately, not `to_path` — the column is what the matcher looks
        // up, and the accessor is only how it is shown. Asserting the presented value here
        // would leave the storage side untested.
        $this->assertSame('', $rule->fresh()->getRawOriginal('to_path'));
        $this->assertSame(url('/'), $rule->fresh()->target());
    }

    /**
     * The reported bug: save `/`, reopen the rule, and the field is blank — so the `required`
     * rule on it refuses the next save, and a working redirect cannot have its dates, priority
     * or status changed ever again.
     */
    #[Test]
    public function reopening_a_home_page_rule_shows_a_destination_and_not_a_blank(): void
    {
        $id = $this->homeRule()->id;

        $this->assertSame('/', $this->repo()->find($id)->to_path);
    }

    /**
     * Every hand-back, not just the load. The form binds to whatever a write returns, so with
     * only `find()` presenting it the field went blank again the instant the save succeeded —
     * the operator watching the value they just typed disappear on a 200.
     */
    #[Test]
    public function saving_a_home_page_rule_hands_the_destination_back(): void
    {
        $rule = $this->homeRule();

        $this->assertSame('/', $rule->to_path, 'create() must hand it back too.');

        $this->assertSame('/', $this->repo()->update($rule->id, ['code' => 302])->to_path);
        $this->assertSame('', RedirectRule::find($rule->id)->getRawOriginal('to_path'), 'and still store the empty form.');
    }

    /**
     * Shown, never written. The accessor must not leak into the column, or the same rule saved
     * twice would drift between `''` and `/` depending on which read fed the write.
     */
    #[Test]
    public function presenting_the_slash_does_not_change_what_is_stored(): void
    {
        $id = $this->homeRule()->id;

        // A full round trip through the form's own path: read it, save it back untouched.
        $this->repo()->update($id, ['code' => 302]);

        $this->assertSame('', RedirectRule::find($id)->getRawOriginal('to_path'));
        $this->assertSame(url('/'), RedirectRule::find($id)->target());
    }

    /**
     * The list builds from `baseIndexQuery`, which hands back models rather than raw rows, so
     * the accessor reaches the table too. It previously rendered a dash — a rule that looked
     * like it had no destination at all, next to a form that said `/`.
     */
    #[Test]
    public function the_list_shows_the_destination_too(): void
    {
        $this->homeRule();

        $listed = $this->repo()->baseIndexQuery([])->get();

        $this->assertSame('/', $listed->firstWhere('from_path', 'old-page')->to_path);
    }

    /**
     * The same absence, one boundary further out: `TransferPage::validate()` refuses an empty
     * `to`, so a home-page rule exported verbatim produced a file whose own import skipped it.
     */
    #[Test]
    public function a_home_page_rule_survives_export_and_import(): void
    {
        $this->homeRule();

        $csv = app(\Plugin\RedirectManager\Backend\Pages\TransferPage::class)->data()['export_csv'];

        $this->assertStringContainsString('old-page', $csv);
        $this->assertMatchesRegularExpression('/^exact,old-page,,\/,301\b/m', $csv);
    }
}
