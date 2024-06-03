<?php
namespace phasync;

use Throwable;

/**
 * Exceptions implementing this interface will be rethrown by the
 * phasync class to provide a more helpful stack trace.
 * 
 * @package phasync
 */
interface RethrowExceptionInterface extends Throwable {}
