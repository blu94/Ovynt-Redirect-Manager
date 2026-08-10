<?php

namespace Plugin\RedirectManager\Backend\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A recurring, path-scoped problem an operator should fix.
 *
 * Three kinds, all about one path, all recurring, all fixed by editing rules — which is the
 * admission test the table's migration writes down. They live in one table and on one screen
 * because to an operator they are one list: things that are wrong with the site's links.
 *
 * **No typed subclass and no global scope**, unlike the core model this replaces. Core needed
 * that pattern because its table was shared with problem kinds belonging to other features
 * that had not been built; here every kind belongs to this plugin and the screen deliberately
 * shows them together, so a scope would exist only to be disabled.
 *
 * **Deliberately no `LogsSystemActivity`**, unlike the rules. Rows here are written by
 * anonymous visitors — one per 404, on traffic a crawler can generate by the thousand — and a
 * model-event trait would put every one of them in the audit trail, burying the operator
 * actions the trail exists to record. The operator actions this table *does* have (marking an
 * entry dealt with, deleting one, pruning history) are logged explicitly in
 * `RedirectIssueRepository`, which is the only place they can happen.
 */
class RedirectIssue extends Model
{
    protected $table = 'redirect_manager_issues';

    /** A path that resolved to nothing. */
    public const TYPE_NOT_FOUND = 'NOT_FOUND';

    /** Rules that point in a circle, so a visitor never lands. */
    public const TYPE_LOOP = 'REDIRECT_LOOP';

    /** A rule pointing at a path another rule redirects away from. */
    public const TYPE_CHAIN = 'REDIRECT_CHAIN';

    public const TYPES = [self::TYPE_NOT_FOUND, self::TYPE_LOOP, self::TYPE_CHAIN];

    protected $fillable = [
        'type',
        'path',
        'context',
        'hits',
        'last_seen_at',
        'resolved',
    ];

    protected $casts = [
        'context'      => 'array',
        'hits'         => 'integer',
        'resolved'     => 'boolean',
        'last_seen_at' => 'datetime',
    ];

    /**
     * Computed columns the list needs.
     *
     * Appended rather than added at the controller, because a plugin's list is served by
     * `GenericModuleController`, which builds its own DataTables instance and gives a
     * repository no place to hang an `addColumn`. What a plugin controls is the model, so
     * anything the table shows beyond a real column has to arrive through `toArray()`.
     */
    protected $appends = ['referrer', 'state', 'kind'];

    /**
     * Where the visitor came from on the most recent hit.
     *
     * In `context` rather than a column because only a 404 has one — a loop or a chain is
     * found by walking the rules, not by someone arriving from somewhere.
     */
    public function getReferrerAttribute(): ?string
    {
        return $this->context['referrer'] ?? null;
    }

    /**
     * The list renders a status chip and "resolved" is a boolean. Mapping it to a word here
     * keeps the schema's `type: status` cell generic instead of teaching it about booleans.
     */
    public function getStateAttribute(): string
    {
        return $this->resolved ? 'resolved' : 'open';
    }

    /** The type as something worth reading in a table cell. */
    public function getKindAttribute(): string
    {
        return match ($this->type) {
            self::TYPE_LOOP  => 'Loop',
            self::TYPE_CHAIN => 'Chain',
            default          => 'Not found',
        };
    }

    /**
     * The rule that would fix this path, if one exists.
     *
     * Joined by the path string rather than a foreign key: a rule can be created or removed
     * independently of the 404 that suggested it, and a key would either block that or leave
     * a dangling reference.
     */
    public function rule(): ?RedirectRule
    {
        return RedirectRule::where('from_path', $this->path)->first();
    }
}
