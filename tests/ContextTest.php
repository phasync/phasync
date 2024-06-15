<?php

use phasync\Context\DefaultContext;
use phasync\ContextUsedException;

test('context outside of corotuine', function () {
    expect(function () {
        phasync::getContext();
    })->toThrow(LogicException::class);
});

test('default context', function () {
    phasync::run(function () {
        expect(phasync::getContext()->isActivated())->toBeTrue();
        expect(function () {
            phasync::getContext()->activate();
        })->toThrow(ContextUsedException::class);

        phasync::getContext()['counter'] = 0;
        phasync::go(function () {
            phasync::getContext()['counter'] = phasync::getContext()['counter'] + 1;
        });
        phasync::go(context: new DefaultContext(), fn: function () {
            expect(isset(phasync::getContext()['counter']))->toBeFalse();
        });
        expect(phasync::getContext()['counter'])->toBe(1);
        unset(phasync::getContext()['counter']);
        expect(isset(phasync::getContext()['counter']))->toBeFalse();
    });
});
