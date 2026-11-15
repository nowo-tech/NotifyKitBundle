<?php

declare(strict_types=1);

namespace Nowo\NotifyKitBundle\Tests\Fixtures;

/**
 * Records the bundle events (test only).
 */
final class RecordingListener
{
    /** @var list<object> */
    public array $events = [];

    public function onEvent(object $event): void
    {
        $this->events[] = $event;
    }

    /**
     * @template T of object
     *
     * @param class-string<T> $class
     *
     * @return list<T>
     */
    public function of(string $class): array
    {
        $found = [];
        foreach ($this->events as $event) {
            if ($event instanceof $class) {
                $found[] = $event;
            }
        }

        return $found;
    }

    public function clear(): void
    {
        $this->events = [];
    }
}
