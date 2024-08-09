<?php

namespace phasync\Internal;

use phasync\SelectableInterface;
use phasync\SelectorInterface;

/**
 * This class creates a Selector for closures by assuming that the
 * first bound static variable in the use() clause is the selectable.
 *
 * @internal
 */
final class ClosureSelector implements SelectorInterface, ObjectPoolInterface
{
    use ObjectPoolTrait;

    private int $id;
    private ?\Closure $closure;
    private SelectableInterface|SelectorInterface|null $otherSelectable;
    private bool $returnOtherSelectableToPool = false;

    public static function create(\Closure $closure): ClosureSelector
    {
        try {
            $instance              = self::popInstance() ?? new self();
            $instance->closure     = $closure;
            $instance->importValidSelectable();

            return $instance;
        } catch (\Throwable $e) {
            $instance->returnToPool();
            throw $e;
        }
    }

    private function __construct()
    {
        $this->id = \spl_object_id($this);
    }

    public function returnToPool(): void
    {
        $this->closure = null;
        if ($this->returnOtherSelectableToPool) {
            $this->otherSelectable->returnToPool();
        }
        $this->otherSelectable              = null;
        self::$pool[self::$instanceCount++] = $this;
    }

    public function isReady(): bool
    {
        return $this->otherSelectable->isReady();
    }

    public function await(float $timeout = \PHP_FLOAT_MAX): void
    {
        $this->otherSelectable->await($timeout);
    }

    public function getSelected(): \Closure
    {
        return $this->closure;
    }

    private function importValidSelectable(): void
    {
        $rc   = new \ReflectionFunction($this->closure);
        $vars = $rc->getStaticVariables();

        if (empty($vars)) {
            throw ExceptionTool::popTrace(new \InvalidArgumentException('Closures can only be used for select if they capture a selectable with `use`.'), __FILE__);
        }

        foreach ($vars as $keyName => $value) {
            break;
        }

        if ($value instanceof SelectableInterface) {
            $this->otherSelectable             = $value;
            $this->returnOtherSelectableToPool = false;

            return;
        }
        if (null !== ($selector = Selector::create($value))) {
            $this->otherSelectable             = $selector;
            $this->returnOtherSelectableToPool = true;

            return;
        }

        throw ExceptionTool::popTrace(new \InvalidArgumentException("The first `use (\$$keyName)` in the closure must be a selectable"), __FILE__);
    }
}
