<?php

namespace Plugin\RedirectManager\Backend\Services;

use Plugin\RedirectManager\Backend\Models\RedirectRule;

/**
 * What the matcher concluded about one path.
 *
 * A value object rather than a bare rule, because resolving a path can end three ways and the
 * caller has to tell them apart: a destination to send the visitor to, a fault in the rules
 * worth recording, or nothing at all. Returning only a rule would collapse the second into
 * the third, and a redirect loop would look exactly like a path nobody wrote a rule for.
 *
 * **The matcher does not write anything.** It reports `$problem` and the caller records it.
 * Keeping the read path free of writes is what lets the matcher be exercised without a
 * transaction, and it keeps the decision about whether to log — an operator setting — in one
 * place rather than two.
 */
final class RedirectMatch
{
    /**
     * @param  RedirectRule|null  $rule     the rule that matched the request; the one to count
     * @param  string|null        $target   where to send the visitor, or null if nowhere
     * @param  int                $code     HTTP status to answer with
     * @param  array<int,string>  $walked   the paths passed through, in order
     * @param  string|null        $problem  a RedirectIssue::TYPE_* the caller should record
     */
    public function __construct(
        public readonly ?RedirectRule $rule = null,
        public readonly ?string $target = null,
        public readonly int $code = 301,
        public readonly array $walked = [],
        public readonly ?string $problem = null,
    ) {
    }

    /** Whether there is somewhere to send the visitor. */
    public function hasTarget(): bool
    {
        return $this->rule !== null && $this->target !== null && $this->target !== '';
    }

    /** Nothing matched and nothing was wrong — the path is simply a miss. */
    public static function none(): self
    {
        return new self();
    }
}
