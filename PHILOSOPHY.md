# The Ennerd philosophy

Software you depend on for a decade should be software you can own.

- **No external dependencies.** Beyond PHP itself and standard interfaces (PSR), phasync,
  phasync-ext, swerve, Tether, phasync/net and Mini depend only on each other (and swerve on
  charm/terminal, by the same author). There is no dependency tree to audit, no upstream to wait
  for, and no churn you did not choose.
- **Small enough to own whole.** phasync and swerve are about 9,500 lines of PHP each, Tether and
  phasync/net under 2,000, phasync-ext about 5,800 lines of C, and Mini, a full framework, about
  70,000. Behaviour is pinned by tests and written down, so a developer, or a coding agent such as
  Claude Code or Codex, can read all of it and change any part of it with confidence, the C
  extension included.
- **Built on PHP's stability.** PHP rarely breaks working code. A stack without third-party
  libraries inherits that stability instead of the release cycles of dozens of projects. An
  application meant to run for twenty years spends its effort on its own features, not on keeping
  up with its framework's dependencies.
- **A head start, not a vendor.** Treat this code as the first years of your own product, as if you
  had hired its author early on. The MIT licence lets you fork it, change it and keep it.
- **Measured, not claimed.** Performance claims come with the method and the raw results to
  reproduce them.
