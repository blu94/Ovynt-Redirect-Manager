<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('redirect_manager_rules', function (Blueprint $table) {
            $table->id();

            // How `from_path` is interpreted. Three kinds, in ascending cost:
            //
            //   exact  — one equality lookup on the index below. The common case, and the
            //            only one a visitor's 404 pays a query for.
            //   prefix — "everything under blog/" in one rule, with the remainder carried
            //            through to the destination. A site that moved a whole branch would
            //            otherwise need one rule per page.
            //   regex  — a full pattern with captures, for the migrations the first two
            //            cannot express.
            //
            // Prefix and regex rules cannot be looked up by equality, so they are loaded as
            // a set and tested in PHP. Keeping the kind in a column rather than sniffing the
            // string for a "*" is what makes that split a query the database can plan.
            $table->string('match_type', 16)->default('exact');

            // The path to match, stored NORMALISED for an exact rule: no surrounding
            // slashes, no query string. A prefix rule is stored the same way; a regex is
            // stored verbatim, because trimming a pattern would corrupt anchors.
            //
            // Case is preserved. Paths are case-sensitive on the web, and lowering them here
            // would silently make "/Sale" and "/sale" one rule.
            $table->string('from_path');

            // The query string this rule requires, e.g. "p=123".
            //
            // Empty means "match whatever the query string is", which is how a redirect
            // normally behaves — a moved page is moved for every campaign link pointing at
            // it. A value means the rule only fires for that query, which is what a
            // WordPress migration needs: /?p=123 and /?p=124 are two different articles
            // sharing one path.
            //
            // NOT NULL with an empty-string default, deliberately: MySQL treats NULLs as
            // distinct in a unique index, so a nullable column here would let the same rule
            // be entered twice and the unique key below would be decorative.
            $table->string('query_match', 255)->default('');

            // Either an internal path ("new-page") or an absolute URL. One column, because a
            // redirect target is one thing — splitting it would mean every read has to ask
            // which of the two is set. For prefix and regex rules this may contain $1..$9,
            // filled from what the match captured.
            $table->string('to_path');

            // 301 permanent, 302 temporary, 307 and 308 the method-preserving pair. Stored
            // as an integer because it IS an HTTP status.
            //
            // 307/308 exist here and not in the module this replaces because a moved POST
            // endpoint — a form action, a webhook a supplier still calls — is silently
            // turned into a GET by 301 and 302, and the request body is dropped. That failure
            // is invisible from the admin: the redirect "works" and the data never arrives.
            $table->unsignedSmallInteger('code')->default(301);

            // Which rule wins when more than one could match.
            //
            // Only pattern rules can genuinely overlap ("blog/*" and "blog/2019/*"), and
            // without an explicit order the winner is whatever the database returned first —
            // stable in practice, unexplainable in principle, and liable to change when a row
            // is edited. Higher runs first.
            $table->integer('priority')->default(0);

            // An optional window. A campaign URL points somewhere for a fortnight and then
            // should stop, and nobody remembers to switch it off manually.
            $table->timestamp('starts_at')->nullable();
            $table->timestamp('ends_at')->nullable();

            // Usage, so an operator can see which rules still earn their keep and prune the
            // rest. Incremented with a bare UPDATE on hit — no hydration, no model events.
            $table->unsignedBigInteger('hits')->default(0);
            $table->timestamp('last_hit_at')->nullable();

            $table->string('status')->default('active');
            $table->timestamps();
            $table->softDeletes();

            // One rule per (kind, path, query). Not `unique(from_path)` as the module this
            // replaces had: the same path legitimately carries several rules once the query
            // string is part of the match, and an exact rule and a prefix rule can share a
            // path without conflicting.
            $table->unique(['match_type', 'from_path', 'query_match'], 'redirect_rules_identity_unique');

            // The hot lookup is `where status = 'active' and match_type = 'exact'
            // and from_path = ?`. Leading with status keeps the pattern-rule sweep — which
            // filters on status and match_type but not path — on the same index.
            $table->index(['status', 'match_type', 'from_path'], 'redirect_rules_lookup_index');

            // Deliberately no index on `hits` or `last_hit_at`, though the list sorts by
            // both. Those are the two columns a hit rewrites, so indexing them would mean
            // every redirect served updates an index as well as the row — paid on the hot
            // path to speed up a screen one operator opens occasionally. The table is bounded
            // by how many rules a human writes, so the sort is immaterial.
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('redirect_manager_rules');
    }
};
