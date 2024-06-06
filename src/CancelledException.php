<?php

namespace phasync;

use RuntimeException;

/**
 * The operation that was being awaited has been cancelled.
 * 
 * @package phasync
 */
class CancelledException extends RuntimeException implements RethrowExceptionInterface {}
