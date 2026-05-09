<?php

declare(strict_types=1);

namespace App\Service\PolicyWizard;

/**
 * W1 audit-defang gap #3 — Variable-substitution Leakage Detector.
 *
 * Pre-persist guard for the {@see DocumentGenerator}. Scans the rendered
 * Document body for unresolved Twig markers and aborts the run before
 * the corrupted output reaches the database. Per the External-Auditor
 * persona-review (`docs/plans/policy-wizard/persona-reviews/06-external-
 * auditor-review.md` lines 180-185) "auditors want clean prose, not
 * generator transparency" — a single `{{ tenant.legal_name }}` slipping
 * through undermines every other audit-defang in the wizard.
 *
 * Detection rules:
 *  - `{{ … }}` (Twig output) — always a leak.
 *  - `{% … %}` (Twig statement) — always a leak.
 *  - `{# … #}` (Twig comment) — always a leak (comments must be stripped
 *    by the renderer; their presence indicates the body bypassed the
 *    Twig pipeline entirely).
 *
 * Whitelist:
 *  - `[[ … ]]` cross-document references are LEGITIMATE markup (not
 *    Twig); the spec explicitly carves them out as the canonical
 *    cross-doc-reference syntax. The detector does NOT scan for them.
 *  - Literal `{{` / `{%` / `{#` inside fenced code blocks (```` ``` ```` /
 *    `~~~`) and inside `<code>…</code>` HTML wrappers are allowed —
 *    the wizard uses fenced blocks to demonstrate Twig syntax in
 *    operator-facing examples (e.g. the developer-docs section of the
 *    policy generator).
 *
 * The detector is stateless and intentionally a static-only API so the
 * caller does not need to wire a new service into the DI graph. The
 * cost of a single regex scan over the rendered body (typical body
 * size 5-20 KB) is negligible compared to the persistence path.
 */
final class SubstitutionLeakageDetector
{
    /**
     * Hide the constructor — pure static utility.
     */
    private function __construct()
    {
    }

    /**
     * Scan $renderedBody for unresolved Twig markers. Throws on any
     * leak; returns void on a clean body.
     *
     * @throws SubstitutionLeakageException when one or more leaks are
     *         present. The exception carries the full leak list so the
     *         caller can surface every offender (line + position) in a
     *         single audit-log entry rather than chasing leaks one at a
     *         time.
     */
    public static function assertNoLeaks(string $renderedBody): void
    {
        if ($renderedBody === '') {
            return;
        }

        $stripped = self::stripCodeFenceContents($renderedBody);
        $leaks = self::scanForLeaks($stripped, $renderedBody);
        if ($leaks === []) {
            return;
        }

        throw new SubstitutionLeakageException($leaks);
    }

    /**
     * Convenience wrapper for callers that want a non-throwing inspect
     * pass (e.g. a dev-tools UI listing leaks without aborting the
     * surrounding flow).
     *
     * @return list<array{token: string, line: int, position: int}>
     */
    public static function findLeaks(string $renderedBody): array
    {
        if ($renderedBody === '') {
            return [];
        }
        $stripped = self::stripCodeFenceContents($renderedBody);
        return self::scanForLeaks($stripped, $renderedBody);
    }

    /**
     * Replace fenced-code-block bodies with whitespace of the same
     * length so the line/column positions in the eventual leak list
     * still match the original source. Removes:
     *   - ```` ```…``` ```` (and ```` ```lang…``` ````)
     *   - `~~~…~~~`
     *   - `<code>…</code>` (single-line HTML inline)
     *   - `<pre>…</pre>` (multi-line HTML block)
     */
    private static function stripCodeFenceContents(string $body): string
    {
        $patterns = [
            '/```[a-zA-Z0-9_+\-]*\R.*?\R```/s',
            '/```[a-zA-Z0-9_+\-]*[^\n`]*?```/s',
            '/~~~[a-zA-Z0-9_+\-]*\R.*?\R~~~/s',
            '/<code\b[^>]*>.*?<\/code>/si',
            '/<pre\b[^>]*>.*?<\/pre>/si',
        ];

        return (string) preg_replace_callback(
            $patterns,
            static function (array $match): string {
                // Replace contents with same-length space-padding so
                // the leak detector preserves the original line/col
                // mapping. Newlines are kept intact so line counting
                // stays accurate (\R is not allowed inside character
                // classes — explicit \r/\n carve-out instead).
                return preg_replace('/[^\r\n]/', ' ', $match[0]) ?? str_repeat(' ', strlen($match[0]));
            },
            $body,
        );
    }

    /**
     * Run three regex sweeps. Order is deterministic so the leak list
     * is reproducible across runs.
     *
     * @param string $scannable body with code-fence contents masked.
     * @param string $original body as the caller passed it; used for
     *                         the human-friendly token snippet (so the
     *                         leak entry shows the actual offender even
     *                         when masking would have replaced it).
     * @return list<array{token: string, line: int, position: int}>
     */
    private static function scanForLeaks(string $scannable, string $original): array
    {
        $leaks = [];

        $patterns = [
            // Output marker  {{ ... }}
            '/\{\{.*?\}\}/s',
            // Statement marker  {% ... %}
            '/\{%.*?%\}/s',
            // Comment marker  {# ... #}
            '/\{#.*?#\}/s',
        ];

        foreach ($patterns as $pattern) {
            if (!preg_match_all($pattern, $scannable, $matches, PREG_OFFSET_CAPTURE)) {
                continue;
            }
            foreach ($matches[0] as $hit) {
                [$token, $offset] = [$hit[0], (int) $hit[1]];

                // Pull the original (un-masked) substring for the
                // leak report so operators see the actual leaked
                // variable name.
                $originalToken = substr($original, $offset, strlen($token));
                if ($originalToken === false || $originalToken === '') {
                    $originalToken = $token;
                }

                $leaks[] = [
                    'token'    => trim($originalToken),
                    'line'     => self::lineNumberAt($original, $offset),
                    'position' => $offset,
                ];
            }
        }

        // Stable order: by position ascending so the first leak in the
        // exception message is the first one in source order.
        usort(
            $leaks,
            static fn (array $a, array $b): int => $a['position'] <=> $b['position'],
        );

        return $leaks;
    }

    /**
     * 1-based line number of the byte at $offset.
     */
    private static function lineNumberAt(string $body, int $offset): int
    {
        if ($offset <= 0) {
            return 1;
        }
        $upTo = substr($body, 0, $offset);
        return substr_count($upTo, "\n") + 1;
    }
}
