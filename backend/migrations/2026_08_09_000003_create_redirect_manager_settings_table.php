<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * One row (id 1) holding how the plugin behaves.
     *
     * Typed columns rather than a key/value bag. Every setting here is read on a 404 — the
     * hottest path this plugin touches — and a bag makes them all strings, pushing a cast
     * onto every read and losing the ability to give the form a real validation rule.
     *
     * `ignore_patterns` is the exception and is JSON on purpose: it is a short list of globs
     * loaded whole, never filtered or sorted by the database, so a table of its own would buy
     * a join and cost a second screen for something an operator edits as one field.
     */
    public function up(): void
    {
        Schema::create('redirect_manager_settings', function (Blueprint $table) {
            $table->id();

            // Whether unresolved paths are recorded at all. On by default — a shop that has
            // just migrated wants the evidence — but an operator who has finished the
            // migration and does not want the write on every bot probe can stop it.
            $table->boolean('log_404')->default(true);

            // Paths never recorded, as globs: "wp-admin/*", "*.php", ".env".
            //
            // Without this the Broken Links list is dominated by vulnerability scanners
            // probing for WordPress and dotfiles, and the handful of real content misses that
            // an operator can actually fix are buried. Filtering at write time rather than at
            // read time also keeps the rows from being written in the first place.
            $table->json('ignore_patterns')->nullable();

            // Whether to look through existing pages, products and posts for a path close to
            // a broken one and offer it as the destination.
            $table->boolean('suggest_enabled')->default(true);

            // How close a candidate must be, as a percentage similarity, before it is
            // offered. Below roughly 60 the suggestions stop being useful and start being
            // noise an operator has to read and reject.
            $table->unsignedTinyInteger('suggest_threshold')->default(70);

            // Whether the storefront's 404 page shows those suggestions to the visitor.
            //
            // Off by default, because it depends on the active theme calling the plugin's
            // slot. A theme that does not renders nothing, which is the correct outcome but
            // is indistinguishable from a broken setting — so this stays something an
            // operator switches on once they can see it working.
            $table->boolean('storefront_suggestions')->default(false);

            // How much history the manual prune on the Broken Links screen removes. Nullable
            // means "keep everything"; pruning is never automatic, because a plugin cannot
            // register a scheduled task and a retention setting that silently does nothing
            // would be worse than no setting at all.
            $table->unsignedInteger('retention_days')->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('redirect_manager_settings');
    }
};
