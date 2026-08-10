<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Recurring, path-scoped problems an operator should fix — counted, not streamed.
     *
     * The shape is inherited from the core `site_issues` table this plugin replaces, and so
     * is the admission test written into it: a kind belongs here only if it is deduplicated
     * by path, counted by hits, and resolvable. Anything append-only, or without a path,
     * belongs in `activity_log` or the Laravel log.
     *
     * Three kinds qualify. A **404** is a path with no page. A **loop** is a set of rules
     * that points in a circle, so a visitor never lands anywhere. A **chain** is a rule
     * pointing at a path another rule redirects away from — which works, but costs the
     * visitor an extra round trip and search engines a diluted signal. All three are about
     * one path, recur, and are fixed by editing rules.
     *
     * Core kept the equivalent table generic across future problem kinds that were never
     * built. Here every kind belongs to this plugin, so there is no need for the typed
     * subclass and global scope that pattern required — the screen shows all three together,
     * filtered by a column, because to an operator they are one list of things to fix.
     */
    public function up(): void
    {
        Schema::create('redirect_manager_issues', function (Blueprint $table) {
            $table->id();

            // Short on purpose. `type` + `path` form the unique index below, and utf8mb4
            // charges 4 bytes per character against InnoDB's 3072-byte key limit — path
            // alone is already 1020.
            $table->string('type', 32);

            // Normalised like a rule's `from_path`: no surrounding slashes, no query string.
            $table->string('path');

            // Per-kind detail that does not deserve a column of its own: a 404 stores the
            // referrer and the query string it arrived with, a loop or chain stores the rule
            // ids it walked through.
            //
            // MySQL 8 supports functional indexes over JSON, so a kind that later needs to
            // filter on one of these costs one migration line rather than a column that is
            // null for every other kind.
            $table->json('context')->nullable();

            $table->unsignedBigInteger('hits')->default(1);
            $table->timestamp('last_seen_at')->nullable();

            // Set once an operator has dealt with it, so the working list hides what is
            // handled without destroying the evidence.
            $table->boolean('resolved')->default(false);

            $table->timestamps();

            // The load-bearing constraint: one row per problem per path. Without it this is
            // an event stream — a crawler hitting one dead URL 10,000 times writes 10,000
            // rows, and the screen's actual question, which paths still get traffic, is
            // buried under volume.
            $table->unique(['type', 'path']);

            // `path` alone as well as inside the unique key above. A composite index can only
            // be used left-to-right, so "is this path a problem of any kind?" — which is what
            // the suggestion pass and the rule editor both ask — could not use the unique key
            // and would scan.
            $table->index('path');

            // Deliberately no index on `hits` or `last_seen_at`, though the screen sorts by
            // both. This table is written on every miss and read occasionally: hot writes,
            // cold reads. Those are the columns a hit rewrites, so indexing them makes a
            // crawler walking a dead sitemap the dominant cost of the feature. Dedup bounds
            // the table at one row per distinct dead path — hundreds after a typical
            // migration, not millions — so sorting it in memory is immaterial. If a store
            // ever accumulates enough distinct dead paths for the sort to show, add the index
            // then; the trade reverses at that point.
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('redirect_manager_issues');
    }
};
