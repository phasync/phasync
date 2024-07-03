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
    private bool $returnOtherSelectable = false;

    public function await(): void
    {
        $this->otherSelectable->await();
    }

    public function getSelectManager(): SelectManager
    {
        return $this->otherSelectable->getSelectManager();
    }

    public function getSelected(): \Closure
    {
        return $this->closure;
    }

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
        if ($this->returnOtherSelectable) {
            $this->otherSelectable->returnToPool();
        }
        $this->otherSelectable              = null;
        self::$pool[self::$instanceCount++] = $this;
    }

    public function selectWillBlock(): bool
    {
        return $this->otherSelectable->selectWillBlock();
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
            $this->otherSelectable       = $value;
            $this->returnOtherSelectable = false;

            return;
        }
        if (null !== ($selector = Selector::create($value))) {
            $this->otherSelectable       = $selector;
            $this->returnOtherSelectable = true;

            return;
        }

        throw ExceptionTool::popTrace(new \InvalidArgumentException("The first `use (\$$keyName)` in the closure must be a selectable"), __FILE__);
    }
}
