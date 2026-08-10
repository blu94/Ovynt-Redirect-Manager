# Redirect Manager

Redirect and broken-link management for [Ovynt](https://github.com/blu94/Ovynt) — the rules
that keep old URLs working after a site moves, and the evidence that tells you which ones
still need writing.

This is the extracted, extended replacement for the redirects module that used to ship inside
Ovynt itself. It is a plugin, so it survives a theme change and is updated on its own release
cycle.

---

## What it does

**Rules.** A rule sends one path somewhere else.

| Kind | Matches | Example |
| --- | --- | --- |
| **Exact** | one literal path | `old-page` → `new-page` |
| **Prefix** | a path and everything under it | `blog` → `news/$1` moves every article at once |
| **Pattern** | a regular expression, with captures | `blog/(\d+)/(.+)` → `news/$2` |

Every rule can additionally require a **query string** — `p=123`, which is what a WordPress
site needs when its old links are `/?p=123` rather than a readable slug. The requirement is a
subset test, so a rule for `p=123` still matches `?p=123&utm_source=newsletter`.

**Leave the path empty to match your front page.** `/?p=123` has no path at all once the
leading slash is stripped, so the rule that catches it is an empty `From path` plus a query of
`p=123`. A rule with neither is refused: it would match the home page however it was reached
and send every visitor away from it.

> This needs a build of Ovynt whose `ThemeController` dispatches `PathNotResolved` for the root
> when the request carries a query string. `/` always resolves to the home page, so nothing used
> to ask whether the address had moved and a root rule could never fire however it was written.
> That build is **1.3.0**, and the manifest requires it — on anything older the package is
> refused at install rather than allowed to save a rule that would silently never match. A bare
> `/` is still never offered to a rule, which is what keeps the home page safe.

Rules carry a **priority** (which wins when two patterns overlap) and an optional **window**
(`starts_at` / `ends_at`, for a campaign URL that should stop on its own).

Four codes are available: **301**, **302**, **307** and **308**. Use 307 or 308 for a path
something POSTs to — a moved form action or a webhook — because 301 and 302 permit the client
to turn the request into a GET and drop the body.

**Broken links.** Every path that resolves to nothing is recorded once, with a hit count and
the page that linked to it. A crawler hammering one dead URL is one row saying so, not ten
thousand rows burying everything else. Open an entry and the plugin scores every live path on
the site against it and offers the closest — one button then opens the rule form with both
ends filled in.

**Loops and chains.** A rule pointing at a path another rule redirects away from costs the
visitor an extra round trip; a set of rules pointing in a circle never lands anywhere. Both
are detected and listed alongside the 404s. A chain is followed through to its real
destination so the visitor still arrives in one hop, and the fault is reported so you can
collapse the rules.

**Import and export.** Rules move in and out as CSV, upserted on identity — so a corrected
file can simply be pasted again rather than producing duplicates or a half-applied import.

---

## What it does not do

- **No file upload or download.** Import and export are text areas. A plugin registers no
  routes, so everything it serves goes through Ovynt's generic module endpoints, and the one
  serving a custom page always wraps its return value in `response()->json()` — there is no
  way for a package to stream a file back, and no `file` field type to send one up. Pasting is
  honest about what it is; the alternative was a download button producing a `.csv` full of
  JSON.
- **No automatic pruning.** A plugin cannot register a scheduled task. `retention_days` is
  applied by the **Prune history** button, and open entries are never removed.
- **No automatic loop audit.** Chains are found when a visitor trips over one, or when the
  Overview is opened. A chain is created by the *second* rule, and which of the two is wrong
  is a judgement only an operator can make.
- **Suggestions are capped** at the first 5,000 live paths. Scoring is a string comparison per
  candidate, and an uncapped list would make every suggestion a walk over the whole catalogue.

---

## Requirements

Ovynt **>= 1.2.0**, and specifically a build that dispatches **`App\Events\PathNotResolved`**.

That event is the seam this plugin hangs on: Ovynt's storefront controller dispatches it after
it has failed to resolve a page, and a listener answers with a destination. Without it the
plugin installs, enables and manages rules perfectly well — and **serves no redirects**,
because nothing ever asks it to.

Storefront suggestions additionally need the active theme to call the slot:

```blade
<x-plugin-slot name="not-found" :data="['path' => $path]" />
```

A theme that omits it renders nothing. That is why the setting is off by default.

---

## Installing

```bash
php artisan plugin:import /path/to/redirect-manager --enable
```

A directory works as well as a zip. In Docker the path is the one inside the container, and
`plugins/` is not mounted — copy the package under `app/storage/app/` first. On Windows in Git
Bash, prefix with `MSYS_NO_PATHCONV=1`.

Enabling runs the migrations and creates the four `redirect_manager.*` permissions.

---

## Screens

**Redirects** in the sidebar:

| Screen | What it is for |
| --- | --- |
| Overview | Whether anything is still broken, whether the rules are being used, and the trend |
| Rules → List / New Redirect | The rules themselves |
| Broken Links | The evidence, and the actions that clear it |
| Import & Export | CSV in and out |
| Settings | What gets recorded, what gets suggested, and what gets ignored |

---

## Data

Three tables, all created by this plugin and all removed only if you tick "also delete this
plugin's data" when uninstalling.

| Table | Holds |
| --- | --- |
| `redirect_manager_rules` | the rules, with hits and last-hit |
| `redirect_manager_issues` | 404s, loops and chains — one row per path per kind |
| `redirect_manager_settings` | one row: logging, suggestions, ignore patterns, retention |

> **Uninstalling with data purge is genuinely destructive here.** Ovynt no longer ships a
> redirects module, so there is nothing to fall back to — every rule goes and every old URL
> starts 404ing again.

---

## Licence

MIT. Copy it as a starting point for your own plugin.
