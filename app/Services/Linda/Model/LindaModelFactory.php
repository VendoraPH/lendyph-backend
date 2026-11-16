<?php

namespace App\Services\Linda\Model;

/**
 * Builds the model for a driver (`config('linda.driver')`): `none`, `fake` or
 * `anthropic`. An unknown driver gets no model, so a mistyped setting keeps
 * every question on the server instead of failing open.
 *
 * The fake and the Anthropic model are taken from the container when one is
 * bound there, so a test can hand in its own (and read what it was sent).
 */
final class LindaModelFactory
{
    public static function make(string $driver): LindaModel
    {
        return match ($driver) {
            'fake' => app(FakeModel::class),
            'anthropic' => app()->bound(AnthropicModel::class) ? app(AnthropicModel::class) : AnthropicModel::fromConfig(),
            default => new NoneModel,
        };
    }
}
