# Changelog

Releases before 2.0.0 are listed on the GitHub releases page.

## 2.0.2 (2026-10-19)

Documentation corrections.

## 2.0.1 (2026-10-19)

phasync no longer declares fiber-interop's exception interfaces; the fiber-interop phasync
adapter translates phasync's exceptions, as adapters do for every runtime. This also lets
phasync load alongside TrueAsync, whose `Async\` namespace collides with the old `async\`.

## 2.0.0 (2026-10-19)

Coroutines on stock PHP Fibers. Release packages carry prebuilt binaries of the optional
phasync extension, ext-phasync 2.0.0 (also `pie install phasync/ext-phasync`), with the
`Scope` class from `fiber-interop/ext-scope` bundled inside it. Upgrading from 1.x:
[UPGRADING.md](UPGRADING.md).
