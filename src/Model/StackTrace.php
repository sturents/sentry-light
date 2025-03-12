<?php

namespace Kodus\Sentry\Model;

use JsonSerializable;

/**
 * @link https://docs.sentry.io/clientdev/interfaces/stacktrace/
 */
class StackTrace implements JsonSerializable
{
    /**
     * @var StackFrame[]
     */
    public array $frames = [];

    /**
     * @var array{int, int}|null tuple like [int $start_frame, int $end_frame]
     */
    public array|null $frames_omitted = null;

    /**
     * @param StackFrame[] $frames
     */
    public function __construct($frames)
    {
        $this->frames = $frames;
    }

    public function setFramesOmitted(int $start, int $end)
    {
        $this->frames_omitted = [$start, $end];
    }

    /**
     * @internal
     */
    public function jsonSerialize(): array
    {
        return array_filter(get_object_vars($this));
    }
}
