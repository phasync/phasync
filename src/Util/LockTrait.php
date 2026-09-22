<?php

namespace phasync\Util;

// Moved to phasync\LockTrait in 1.1.0, for consistent placement of the small
// public interfaces and traits (matching phasync\SelectableInterface, phasync\DeadmanSwitchTrait
// and similar). This alias keeps the old namespace working.
\class_alias(\phasync\LockTrait::class, __NAMESPACE__ . '\LockTrait');
