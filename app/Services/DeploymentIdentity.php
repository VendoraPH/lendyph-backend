<?php

namespace App\Services;

/**
 * Reports which commit a deployment is actually serving.
 *
 * Ten deployments share this codebase and differ only by their .env and the
 * branch they track, so "is this box current?" was previously unanswerable
 * without SSH. This reads the answer out of .git directly rather than shelling
 * out: the git CLI refuses to run as www-data on these boxes ("detected dubious
 * ownership"), while the ref files themselves are perfectly readable.
 *
 * Every accessor degrades to null rather than throwing. A health endpoint that
 * 500s because it could not find a ref is worse than one that admits it does
 * not know.
 */
class DeploymentIdentity
{
    private const SHA_LENGTH = 7;

    public function __construct(private readonly string $basePath) {}

    /**
     * @return array{commit: ?string, branch: ?string, env: string}
     */
    public function toArray(): array
    {
        $head = $this->head();

        return [
            'commit' => $this->shortCommit($head),
            'branch' => $this->branch($head),
            'env' => app()->environment(),
        ];
    }

    private function shortCommit(?string $head): ?string
    {
        $sha = $this->resolve($head);

        return $sha ? substr($sha, 0, self::SHA_LENGTH) : null;
    }

    private function branch(?string $head): ?string
    {
        if ($head === null || ! str_starts_with($head, 'ref: refs/heads/')) {
            return null; // detached HEAD — a commit, but no branch
        }

        return substr($head, strlen('ref: refs/heads/'));
    }

    /**
     * The contents of .git/HEAD: either "ref: refs/heads/<branch>" or a raw SHA.
     */
    private function head(): ?string
    {
        return $this->read($this->gitDir().'/HEAD');
    }

    private function resolve(?string $head): ?string
    {
        if ($head === null) {
            return null;
        }

        if (! str_starts_with($head, 'ref: ')) {
            return $head; // detached HEAD holds the SHA itself
        }

        $ref = substr($head, strlen('ref: '));

        return $this->read($this->gitDir().'/'.$ref)
            ?? $this->fromPackedRefs($ref);
    }

    /**
     * A ref that has been packed has no loose file; it lives in packed-refs as
     * "<sha> <ref>" lines. Freshly cloned deployments hit this path.
     */
    private function fromPackedRefs(string $ref): ?string
    {
        $packed = $this->read($this->gitDir().'/packed-refs');

        if ($packed === null) {
            return null;
        }

        foreach (explode("\n", $packed) as $line) {
            if (str_ends_with($line, ' '.$ref)) {
                return strtok($line, ' ');
            }
        }

        return null;
    }

    /**
     * Usually base_path('.git'), but a worktree checkout makes .git a file
     * containing "gitdir: <path>".
     */
    private function gitDir(): string
    {
        $path = $this->basePath.'/.git';

        if (! is_file($path)) {
            return $path;
        }

        $contents = $this->read($path);

        return $contents !== null && str_starts_with($contents, 'gitdir: ')
            ? substr($contents, strlen('gitdir: '))
            : $path;
    }

    private function read(string $path): ?string
    {
        if (! is_readable($path) || ! is_file($path)) {
            return null;
        }

        $contents = @file_get_contents($path);

        return $contents === false ? null : trim($contents);
    }
}
