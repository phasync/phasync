<?php

namespace phasync\Internal;

use Closure;
use Fiber;
use InvalidArgumentException;
use phasync\Debug;
use phasync\SelectableInterface;
use phasync\SelectorInterface;
use ReflectionFunction;

final class Selector implements SelectorInterface {
    use ObjectPoolTrait;

    public static function isSelectableReady(mixed $selectable): bool {
        if ($selectable instanceof SelectableInterface) {
            return $selectable->isReady();
        } elseif ($selectable instanceof Fiber) {
            return $selectable->isTerminated();
        /*
        } elseif ($selectable instanceof Closure) {
            $rc = new ReflectionFunction($selectable);
            $vars = $rc->getStaticVariables();
            foreach ($vars as $name => $value) {
                return self::isReady($value);
            }
            throw new InvalidArgumentException("Can't select on closure unless closure is using a bound selectable");
        */
        } else {
            throw new InvalidArgumentException(\get_debug_type($selectable) . " is not selectable");
        }
    }

    public static function create(mixed $selectable): ?SelectorInterface {
        if ($selectable instanceof SelectorInterface) {
            return $selectable;
        } elseif ($selectable instanceof SelectableInterface) {
            $instance = self::popInstance() ?? new self();
            $instance->selectable = $selectable;
            return $instance;
        } elseif ($selectable instanceof Fiber) {
            return FiberSelector::create($selectable);
        } elseif ($selectable instanceof Closure) {
            return ClosureSelector::create($selectable);
        }
        return null;
    }

    private ?SelectableInterface $selectable = null;

    private function __construct() {}

    public function isReady(): bool {
        return $this->selectable->isReady();
    }

    public function getSelected(): mixed {
        return $this->selectable;
    }

    public function await(): void {
        $this->selectable->await();
    }

    public function returnToPool(): void {
        $this->selectable = null;
        self::$pool[self::$instanceCount++] = $this;
    }

}
