<?php

namespace phasync\Interfaces;

// Moved to phasync\LockInterface in 1.1.0, for consistent placement of the small
// public interfaces and traits (matching phasync\SelectableInterface, phasync\DeadmanSwitchTrait
// and similar). This alias keeps the old namespace working.
\class_alias(\phasync\LockInterface::class, __NAMESPACE__ . '\LockInterface');
