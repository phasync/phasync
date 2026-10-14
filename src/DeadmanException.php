<?php

namespace phasync;

use phasync\Internal\RethrowExceptionTrait;

/**
 * The writer of a StringBuffer exited without ending it: a read throws this once the buffered data is read.
 *
 * A writer that holds the buffer's deadman switch (`getDeadmanSwitch()`) and lets go of it without calling `end()`, as when its coroutine terminates, triggers it: the readers get what it wrote, and then this exception instead of waiting for data that will never come.
 *
 * ```php
 * phasync::run(function () {
 *     $buffer = new phasync\Util\StringBuffer();
 *     phasync::go(function () use ($buffer) {
 *         $switch = $buffer->getDeadmanSwitch();
 *         $buffer->write('partial');      // and exits without $buffer->end()
 *     });
 *     echo $buffer->read(100), "\n";      // partial
 *     try {
 *         $buffer->read(100);
 *     } catch (phasync\DeadmanException $e) {
 *         echo $e->getMessage(), "\n";    // Writer terminated unexpectedly
 *     }
 * });
 * ```
 *
 * @see phasync\Util\StringBuffer::getDeadmanSwitch
 * @see phasync\Util\StringBuffer::read
 * @see phasync\Util\StringBuffer::end
 *
 * @topic streams-and-files
 */
class DeadmanException extends \RuntimeException implements RethrowExceptionInterface
{
    use RethrowExceptionTrait;
}
