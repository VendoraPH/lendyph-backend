<?php

namespace App\Services\Linda;

use App\Models\Branch;
use App\Models\User;
use App\Services\BranchAccess;

/**
 * Which branches a Linda answer reads: the user's own (BranchAccess::for(),
 * null for every branch), or one of them when the question names it.
 *
 * A named branch is matched only among the branches the user can see,
 * ignoring case, spaces and a trailing "branch": an exact match first, then
 * a name that starts with it, when only one does. A branch the user can't
 * see is never matched, so it reads exactly like one that doesn't exist.
 */
final class LindaScope
{
    /**
     * @param  list<int>|null  $branchIds  BranchAccess::for()
     * @param  string  $label  "Main Branch", "all your branches" or "all branches"
     * @param  'not_found'|'ambiguous'|'no_branch_assigned'|null  $problem
     * @param  list<string>  $matches  the names that fit an ambiguous name
     */
    private function __construct(
        public readonly ?array $branchIds,
        public readonly ?int $branchId,
        public readonly string $label,
        public readonly ?string $problem = null,
        public readonly array $matches = [],
    ) {}

    public static function resolve(User $user, ?string $branchName): self
    {
        $branchIds = app(BranchAccess::class)->for($user);

        if ($branchIds === []) {
            return new self([], null, '', 'no_branch_assigned');
        }

        $visible = Branch::query()
            ->when($branchIds !== null, fn ($q) => $q->whereIn('id', $branchIds))
            ->orderBy('name')
            ->orderBy('id')
            ->get(['id', 'name']);

        $wanted = $branchName === null ? '' : self::normalise($branchName);

        if ($branchName === null || trim($branchName) === '') {
            return new self($branchIds, null, self::ownLabel($branchIds, $visible->first()?->name));
        }

        if ($wanted === '') {
            return new self($branchIds, null, '', 'not_found');
        }

        $exact = $visible->filter(fn (Branch $branch) => self::normalise($branch->name) === $wanted)->values();
        $matches = $exact->isNotEmpty()
            ? $exact
            : $visible->filter(fn (Branch $branch) => str_starts_with(self::normalise($branch->name), $wanted))->values();

        if ($matches->count() === 1) {
            $branch = $matches->first();

            return new self($branchIds, (int) $branch->id, (string) $branch->name);
        }

        if ($matches->isEmpty()) {
            return new self($branchIds, null, '', 'not_found');
        }

        return new self($branchIds, null, '', 'ambiguous', $matches->pluck('name')->map(fn ($name) => (string) $name)->all());
    }

    /**
     * The one branch the user can see whose name appears in a question as
     * whole words (case and spacing ignored, a trailing "Branch" optional),
     * as the name to resolve() with, or null when none or several do. A
     * branch the user can't see is never looked for, so it is never found.
     * For a question only the model understood, whose branch the matcher
     * never read.
     */
    public static function visibleBranchIn(User $user, #[\SensitiveParameter] string $text): ?string
    {
        $branchIds = app(BranchAccess::class)->for($user);

        if ($branchIds === []) {
            return null;
        }

        $clean = LindaText::clean($text);
        $found = [];

        $names = Branch::query()
            ->when($branchIds !== null, fn ($q) => $q->whereIn('id', $branchIds))
            ->pluck('name');

        foreach ($names as $name) {
            $words = LindaText::words(LindaText::clean((string) $name));

            if ($words !== [] && end($words) === 'branch') {
                array_pop($words);
            }

            if ($words === []) {
                continue;
            }

            $pattern = '/(?<![\pL\pN])'.implode('\s+', array_map(fn (string $word) => preg_quote($word, '/'), $words)).'(?:\s+branch)?(?![\pL\pN])/u';

            if (preg_match($pattern, $clean) === 1) {
                $found[implode(' ', $words)] = true;
            }
        }

        $found = array_keys($found);
        usort($found, fn (string $a, string $b) => strlen($b) <=> strlen($a));

        // A longer name that contains a shorter one ("San Jose North" and
        // "San Jose") is the one meant; two unrelated names are ambiguous.
        foreach (array_slice($found, 1) as $shorter) {
            if (! str_contains($found[0], $shorter)) {
                return null;
            }
        }

        return $found[0] ?? null;
    }

    public function ok(): bool
    {
        return $this->problem === null;
    }

    /**
     * The ReportService filters for this scope: `branch_ids` always, and
     * `branch_id` when a branch was named.
     *
     * @return array{branch_ids: list<int>|null, branch_id?: int}
     */
    public function filters(): array
    {
        return [
            'branch_ids' => $this->branchIds,
            ...($this->branchId === null ? [] : ['branch_id' => $this->branchId]),
        ];
    }

    /** @param  list<int>|null  $branchIds */
    private static function ownLabel(?array $branchIds, ?string $onlyBranch): string
    {
        if ($branchIds === null) {
            return 'all branches';
        }

        return count($branchIds) === 1 && $onlyBranch !== null ? $onlyBranch : 'all your branches';
    }

    /** "  Far   Branch " → "far" */
    private static function normalise(string $name): string
    {
        $squeezed = (string) preg_replace('/\s+/u', '', mb_strtolower($name));

        return (string) preg_replace('/branch$/u', '', $squeezed);
    }
}
