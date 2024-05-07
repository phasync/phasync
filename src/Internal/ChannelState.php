<?php
namespace phasync\Internal;

use Fiber;
use phasync\ChannelException;
use phasync\Internal\ObjectPool;
use WeakReference;

final class ChannelState {

    public int $bufferSize;
    public array $buffer = [];
    public bool $closed = false;
    public int $readOffset = 0;
    public int $writeOffset = 0;
    public int $refCount = 2;
    private ?WeakReference $creatorRef;

    public static function create(int $bufferSize, Fiber $creatingFiber): ChannelState {
        $instance = ObjectPool::pop(self::class);
        if ($instance) {
            $instance->bufferSize = $bufferSize;
            $instance->creatorRef = WeakReference::create($creatingFiber);
            return $instance;
        } else {
            return new ChannelState($bufferSize, $creatingFiber);
        }
    }

    private function __construct(int $bufferSize, Fiber $creatingFiber) {
        $this->bufferSize = $bufferSize;
        $this->creatorRef = WeakReference::create($creatingFiber);
    }

    public function assertValidFiber(): void {
        if ($this->creatorRef !== null && $this->creatorRef->get() === Fiber::getCurrent()) {
            throw new ChannelException("Channels can't be activated from the coroutine that created it");
        } else {
            $this->creatorRef = null;
        }
    }
    
    public static function initChannelState(): void {
        ObjectPool::addClass(self::class, static function(ChannelState $instance) {
            $instance->buffer = [];
            $instance->closed = false;
            $instance->readOffset = 0;
            $instance->writeOffset = 0;
            $instance->refCount = 2;
        });        
    }
}

ChannelState::initChannelState();

