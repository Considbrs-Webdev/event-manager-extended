<?php

declare(strict_types=1);

namespace EventManagerExtended;

/**
 * Register Event import-review classes. Each class wires hooks in __construct().
 */
class App
{
    /**
     * @var object[]
     */
    private array $instances = [];

    public function __construct()
    {
        $this->registerInstances();
    }

    private function registerInstances(): void
    {
        $classes = [
            ExternalContent\ImportReview::class,
            ExternalContent\ImportReviewList::class,
            ExternalContent\ImportReviewChanges::class,
        ];

        foreach ($classes as $class) {
            if (!class_exists($class)) {
                continue;
            }

            $this->instances[] = new $class();
        }
    }
}
