# Changelog

Earlier releases are listed on the GitHub releases page.

## 1.1.0-rc6 (unreleased)

### Fixed

- Coroutines waiting for a flag are now woken with a `CancelledException` when the flag object
  is garbage collected. `Flag::__destruct()` was meant to do this but never ran while a
  coroutine waited: `awaitFlag()` held its parameter for the whole wait, `flagGraph` held every
  flag, and `ObjectPoolTrait::popInstance()` left the popped object in the pool. A reused flag
  store could also make cancelling a timed out waiter fail with `Unable to cancel fiber`.
- All expired timeouts are delivered in the same timeout check, in registration order. Before,
  cancelling a fiber while iterating over the pending fibers made the loop skip about half of
  the expired ones, so ten simultaneous 0.2 s timeouts fired between 0.5 s and 2.0 s.
- `StringBuffer::read()` with a timeout throws `TimeoutException` when the timeout expires.
  Before it spun forever, and no other coroutine could run.
- `Process::run()` throws a `LogicException` on Windows. The Windows runner never worked in
  the 1.1 line.

### Added

- `WaitGroup::add(int $delta = 1)`, like Go's `WaitGroup.Add(delta)`. A negative delta marks
  work as done, and a delta that would make the counter negative throws a `LogicException`.

### Changed

- Waiting on a flag object that nothing else references, for example
  `phasync::awaitFlag(new stdClass())`, is undefined behaviour. It may end at once with a
  `CancelledException`.
- `tests/AllroundTests.php` is renamed to `AllroundTest.php` so that it is actually run.
- CI also tests PHP 8.4 and 8.5.

### Deprecated

- `phasync\Util\FastCGI\Record`: the FastCGI protocol code is moving to phasync/swerve and will
  be removed in 2.0.
- `phasync\Process\WindowsProcessRunner`: it does not work and is no longer used.

### Removed

- The dependencies `charm/options` and `laravel/serializable-closure`, which were not used,
  and the `psr/http-client-implementation` entry under `provide`, because phasync has no
  PSR-18 client.
- The internal classes `WorkerPool`, `ClosureStore`, `ChannelBuffered` and `ChannelUnbuffered`,
  which nothing used.
- `WebSocket`, `WebSocketFrame` and `LengthPrefixedFraming` in `phasync\Util\Protocols`. They
  were added during the 1.1.0 release candidates and were never in a stable release.
- `examples/legacy`, which used classes that no longer exist.

### Development

- `tests/Characterization` pins how the core behaves today, `benchmarks/bench.php` measures the
  performance of the core primitives, and `docs/SEMANTICS.md` describes the intended semantics
  with numbered rules.
