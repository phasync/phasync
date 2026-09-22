<?php

namespace phasync\Util\Collections;

// Moved to phasync\Util\Queue in 1.1.0: Util\Collections\ existed only to hold this one
// file, unlike phasync\Util\, which already holds WaitGroup, RateLimiter and StringBuffer.
// This alias keeps the old namespace working.
\class_alias(\phasync\Util\Queue::class, __NAMESPACE__ . '\Queue');
