<?php

namespace phasync\Interfaces;

// Moved to phasync\QueueInterface in 1.1.0, for consistent placement of the small
// public interfaces and traits (matching phasync\SelectableInterface, phasync\DeadmanSwitchTrait
// and similar). This alias keeps the old namespace working.
\class_alias(\phasync\QueueInterface::class, __NAMESPACE__ . '\QueueInterface');
