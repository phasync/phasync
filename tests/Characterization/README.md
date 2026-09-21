# Characterization tests

These tests pin how phasync behaves **today**, so that a change to its semantics is
always a conscious, approved decision. They are not a statement of how phasync *should*
behave. That is `docs/SEMANTICS.md`.

## Rules

1. **A failing characterization test means: stop and tell the maintainer.** Never edit,
   loosen, skip or delete one to make a change pass. The maintainer decides whether the new
   behaviour is accepted; only then is the test updated, in a commit that says so.
2. Files are `tests/Characterization/<Area>Test.php`. Every file calls
   `uses()->group('characterization')`.
3. Test names start with the rule ID from `docs/SEMANTICS.md`:
   `test('SCH-2: go() runs the child before returning', ...)`.
4. If today's behaviour is one that `docs/SEMANTICS.md` marks ❌ (a known divergence), the
   test asserts **today's** behaviour, adds `->group('divergence')`, and its name ends
   with `[DIVERGENCE]`. A comment states what the contract expects. When the divergence is
   fixed, the test fails on purpose, and that failure is the signal to report.
5. If you find behaviour that surprises you and `docs/SEMANTICS.md` doesn't mention it, pin
   it anyway with `->group('surprise')` and report it.
6. APIs that work both inside and outside a coroutine get a test for each (phasync keeps
   these symmetrical on purpose).
7. Tests must be deterministic: no network, no wall-clock ratios, generous absolute bounds,
   `stream_socket_pair()` for IO. Each test finishes in about a second.
8. Do not modify `src/`, `phasync.php` or `io.php` from a characterization test task.

## Running

```bash
vendor/bin/pest --group=characterization
vendor/bin/pest --group=divergence      # only the pinned known-bad behaviour
```

The `PHP Deprecated: ReflectionMethod::setAccessible()` lines come from the installed Pest
version, not from these tests.
