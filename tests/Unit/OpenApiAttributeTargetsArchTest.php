<?php

/**
 * `#[OA\...]` attributes must decorate the endpoint they document.
 *
 * PHP binds an attribute to whatever declaration follows it. Insert a helper
 * between an `#[OA\Get]` block and its endpoint — which is exactly what
 * happened to ReportController::exportDuePastDue() when daysLate() was added
 * between them — and the attribute silently transfers to the helper, leaving
 * the real endpoint undocumented.
 *
 * Nothing downstream complains. `path:` is a literal, so the generated spec
 * still names the right URL and looks entirely correct; swagger-php is simply
 * documenting a private method. That invisibility is the whole reason this is
 * a test rather than something a reviewer is expected to spot.
 *
 * No database and no application boot: this reads the classes' own attributes.
 */
$apiControllers = static function (): array {
    $classes = [];

    foreach (glob(dirname(__DIR__, 2).'/app/Http/Controllers/Api/*.php') ?: [] as $file) {
        $class = 'App\\Http\\Controllers\\Api\\'.basename($file, '.php');

        if (class_exists($class)) {
            $classes[] = $class;
        }
    }

    return $classes;
};

it('never leaves an OpenAPI attribute on a method that cannot serve a request', function () use ($apiControllers) {
    $controllers = $apiControllers();

    expect($controllers)->not->toBeEmpty('No API controllers were found — the glob path is wrong.');

    $misattached = [];

    foreach ($controllers as $class) {
        $reflection = new ReflectionClass($class);

        foreach ($reflection->getMethods() as $method) {
            // Only methods this class declares itself, so an inherited helper
            // is not reported once per subclass.
            if ($method->isPublic() || $method->getDeclaringClass()->getName() !== $class) {
                continue;
            }

            foreach ($method->getAttributes() as $attribute) {
                if (! str_starts_with($attribute->getName(), 'OpenApi\\Attributes')) {
                    continue;
                }

                $misattached[] = sprintf(
                    '%s::%s() is %s but carries %s',
                    $reflection->getShortName(),
                    $method->getName(),
                    $method->isPrivate() ? 'private' : 'protected',
                    $attribute->getName(),
                );
            }
        }
    }

    if ($misattached !== []) {
        $this->fail(
            "An OpenAPI attribute is documenting a method no route can reach:\n\n  "
            .implode("\n  ", $misattached)
            ."\n\nAlmost always this means a helper was inserted between an #[OA\\...] block and the "
            .'endpoint it was written for, so the attribute bound to the helper instead. Move the helper '
            .'above the attribute block or below the endpoint — do not move the attribute, since the '
            ."endpoint it belongs to is the one that lost it.\n\nThis does NOT show up in the generated "
            .'spec: `path:` is a literal, so the output still looks right.'
        );
    }

    expect($misattached)->toBe([]);
});
