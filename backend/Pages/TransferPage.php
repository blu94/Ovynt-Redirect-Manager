<?php

namespace Plugin\RedirectManager\Backend\Pages;

use Plugin\RedirectManager\Backend\Models\RedirectRule;
use Plugin\RedirectManager\Backend\Services\RedirectMatcher;

/**
 * Import and export rules as CSV.
 *
 * A migration does not arrive as a hundred forms filled in by hand. It arrives as a file
 * somebody exported from the site being replaced — Redirection, Yoast, or a spreadsheet a
 * developer assembled — and the first thing an operator wants is to paste it in.
 *
 * **Text areas rather than a file picker, and this is a platform limit rather than a choice.**
 * A plugin registers no routes, so everything it does goes through the nine generic module
 * endpoints, and the one that serves a custom page always wraps its return value in
 * `response()->json()`. There is no way for a package to stream a file back, and there is no
 * `file` field type to send one up. Pasting works, costs nothing, and is honest about what it
 * is; the alternative was a download button that produced a `.csv` full of JSON.
 *
 * Ovynt's own import pipeline is not an option either: `ImporterRegistry::DRIVERS` is a
 * private constant, so a plugin cannot add a resource to it.
 */
class TransferPage
{
    /**
     * Columns, in the order they are written and the order assumed when a file has no header.
     */
    private const COLUMNS = [
        'match_type', 'from_path', 'query_match', 'to_path',
        'code', 'priority', 'status', 'starts_at', 'ends_at',
    ];

    /** Errors reported back. Beyond this the list stops being read and starts being scrolled. */
    private const MAX_ERRORS = 20;

    /** @return array<string,mixed> */
    public function data(): array
    {
        return [
            'export_csv' => $this->export(),
            'import_csv' => '',
            'rules_total' => RedirectRule::query()->count(),
        ];
    }

    /**
     * Every rule as CSV, header first.
     *
     * Written through `fputcsv` to a memory stream rather than assembled with commas: a
     * destination containing a comma or a quote is ordinary, and hand-joining is how an export
     * that looks right in testing corrupts somebody's real data.
     */
    private function export(): string
    {
        $handle = fopen('php://temp', 'r+');

        // The escape character is passed explicitly on every CSV call here. PHP 8.4 deprecates
        // relying on the default because the default is changing, and an empty string is both
        // the future behaviour and the correct one: backslash escaping is a PHP quirk that no
        // spreadsheet writes and no other reader expects.
        fputcsv($handle, self::COLUMNS, ',', '"', '');

        RedirectRule::query()->orderBy('id')->chunk(500, function ($rules) use ($handle) {
            foreach ($rules as $rule) {
                fputcsv($handle, [
                    $rule->match_type,
                    $rule->from_path,
                    $rule->query_match,
                    $rule->to_path,
                    $rule->code,
                    $rule->priority,
                    $rule->status,
                    $rule->starts_at?->toDateTimeString(),
                    $rule->ends_at?->toDateTimeString(),
                ], ',', '"', '');
            }
        });

        rewind($handle);
        $csv = (string) stream_get_contents($handle);
        fclose($handle);

        return $csv;
    }

    /**
     * @param  array<string,mixed>  $data
     * @return array<string,mixed>
     */
    public function save(array $data): array
    {
        $csv = trim((string) ($data['import_csv'] ?? ''));

        if ($csv === '') {
            return ['message' => 'Nothing to import — paste some CSV first.'];
        }

        $result = $this->import($csv);

        RedirectMatcher::forget();

        return ['message' => $this->summarise($result)] + $result;
    }

    /**
     * Parse and upsert.
     *
     * **Upserted on the rule's identity, not appended.** Re-importing a corrected file is the
     * normal way anyone fixes a bad migration, and an import that only ever inserted would
     * fail on the unique index the second time — leaving the operator with a half-applied
     * file and no way to tell which half.
     *
     * @return array<string,mixed>
     */
    private function import(string $csv): array
    {
        $rows = $this->rows($csv);

        if ($rows === []) {
            return ['created' => 0, 'updated' => 0, 'skipped' => 0, 'errors' => ['No readable rows.']];
        }

        $header = $this->header($rows[0]);

        if ($header !== null) {
            array_shift($rows);
        }

        $created = $updated = $skipped = 0;
        $errors  = [];

        foreach ($rows as $index => $row) {
            $line   = $index + ($header !== null ? 2 : 1);
            $values = $this->map($row, $header ?? self::COLUMNS);

            $error = $this->validate($values);

            if ($error !== null) {
                $skipped++;

                if (count($errors) < self::MAX_ERRORS) {
                    $errors[] = "Line {$line}: {$error}";
                }

                continue;
            }

            try {
                $rule = RedirectRule::withTrashed()
                    ->firstOrNew([
                        'match_type'  => $values['match_type'],
                        'from_path'   => RedirectRule::normalisePath($values['from_path']),
                        'query_match' => ltrim($values['query_match'], '?'),
                    ]);

                $existed = $rule->exists;

                // A rule that was deleted and is being imported again is being asked for, so
                // it comes back rather than colliding with a soft-deleted row the operator
                // cannot see.
                $rule->deleted_at = null;

                $rule->fill([
                    'to_path'   => $values['to_path'],
                    'code'      => (int) ($values['code'] ?: 301),
                    'priority'  => (int) ($values['priority'] ?: 0),
                    'status'    => $values['status'] ?: RedirectRule::STATUS_ACTIVE,
                    'starts_at' => $values['starts_at'] ?: null,
                    'ends_at'   => $values['ends_at'] ?: null,
                ])->save();

                $existed ? $updated++ : $created++;
            } catch (\Throwable $e) {
                $skipped++;

                if (count($errors) < self::MAX_ERRORS) {
                    $errors[] = "Line {$line}: {$e->getMessage()}";
                }
            }
        }

        return compact('created', 'updated', 'skipped', 'errors');
    }

    /**
     * Split the pasted text into rows.
     *
     * Line endings are normalised first because the file came off somebody else's machine, and
     * a CRLF export read as LF leaves a stray carriage return on the last column of every row —
     * which then becomes part of a stored path and matches nothing.
     *
     * @return array<int,array<int,string>>
     */
    private function rows(string $csv): array
    {
        $csv    = str_replace(["\r\n", "\r"], "\n", $csv);
        $handle = fopen('php://temp', 'r+');

        fwrite($handle, $csv);
        rewind($handle);

        $rows = [];

        while (($row = fgetcsv($handle, null, ',', '"', '')) !== false) {
            // fgetcsv gives [null] for a blank line.
            if ($row === [null] || $row === []) {
                continue;
            }

            $rows[] = array_map(fn ($v) => trim((string) $v), $row);
        }

        fclose($handle);

        return $rows;
    }

    /**
     * The header row, if the first row is one.
     *
     * Detected rather than required, because a file exported from another tool and trimmed by
     * hand may have lost it — and a header row silently imported as a rule creates a redirect
     * from "from_path" to "to_path" that an operator would never think to look for.
     *
     * @param  array<int,string>  $row
     * @return array<int,string>|null
     */
    private function header(array $row): ?array
    {
        $normalised = array_map(fn ($v) => strtolower(str_replace([' ', '-'], '_', $v)), $row);

        if (! in_array('from_path', $normalised, true) && ! in_array('from', $normalised, true)) {
            return null;
        }

        // Accept the short spellings a hand-made sheet tends to use.
        return array_map(fn ($v) => match ($v) {
            'from'  => 'from_path',
            'to'    => 'to_path',
            'type'  => 'match_type',
            'query' => 'query_match',
            default => $v,
        }, $normalised);
    }

    /**
     * @param  array<int,string>  $row
     * @param  array<int,string>  $header
     * @return array<string,string>
     */
    private function map(array $row, array $header): array
    {
        $values = array_fill_keys(self::COLUMNS, '');

        foreach ($header as $index => $column) {
            if (array_key_exists($column, $values)) {
                $values[$column] = $row[$index] ?? '';
            }
        }

        $values['match_type'] = strtolower($values['match_type']) ?: RedirectRule::MATCH_EXACT;
        $values['status']     = strtolower($values['status']);

        return $values;
    }

    /**
     * @param  array<string,string>  $values
     */
    private function validate(array $values): ?string
    {
        if ($values['from_path'] === '' || $values['to_path'] === '') {
            return 'a rule needs both a from and a to.';
        }

        if (! in_array($values['match_type'], RedirectRule::MATCH_TYPES, true)) {
            return "\"{$values['match_type']}\" is not a match type. Use "
                . implode(', ', RedirectRule::MATCH_TYPES) . '.';
        }

        if ($values['code'] !== '' && ! in_array((int) $values['code'], RedirectRule::CODES, true)) {
            return "\"{$values['code']}\" is not a redirect code. Use "
                . implode(', ', RedirectRule::CODES) . '.';
        }

        if ($values['match_type'] === RedirectRule::MATCH_REGEX
            && @preg_match('#' . str_replace('#', '\#', $values['from_path']) . '#u', '') === false) {
            return 'that pattern is not a valid regular expression.';
        }

        return null;
    }

    /** @param  array<string,mixed>  $result */
    private function summarise(array $result): string
    {
        $parts = [];

        if ($result['created']) {
            $parts[] = $result['created'] . ' created';
        }

        if ($result['updated']) {
            $parts[] = $result['updated'] . ' updated';
        }

        if ($result['skipped']) {
            $parts[] = $result['skipped'] . ' skipped';
        }

        return $parts === [] ? 'Nothing changed.' : 'Import finished: ' . implode(', ', $parts) . '.';
    }
}
