<?php

// A library keeps one process-wide setting; each request wants its own. The hook swaps it
// whenever the loop switches to a coroutine of another request.
final class Settings
{
    public static string $locale = 'en';
}

$locales = new WeakMap();   // Scope => locale
phasync::onScopeChange(function (Scope $new, ?Scope $old) use ($locales) {
    if (null !== $old) {
        $locales[$old] = Settings::$locale;   // keep what the request that ran last left
    }
    Settings::$locale = $locales[$new] ?? 'en';
});

phasync::run(function () {
    foreach (['nb', 'de'] as $locale) {
        (new Scope())->run(function () use ($locale) {
            phasync::go(function () use ($locale) {
                Settings::$locale = $locale;
                phasync::sleep(0.01);             // the other request runs meanwhile
                echo 'still ', Settings::$locale, "\n";
            });
        });
    }
});
