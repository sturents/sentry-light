<?php

namespace Kodus\Sentry\Model;

use JsonSerializable;

/**
 * @link https://docs.sentry.io/clientdev/interfaces/exception/
 */
class ExceptionInfo implements JsonSerializable
{
    /**
     * @var string
     */
    public string $type;

    /**
     * @var string
     */
    public string $value;

    /**
     * @var StackTrace
     */
    public StackTrace $stacktrace;

    public function __construct(string $type, string $value, StackTrace $stacktrace)
    {
        $this->type = $type;
        $this->value = $value;
        $this->stacktrace = $stacktrace;
    }

    /**
     * @internal
     */
    public function jsonSerialize(): array
    {
        return array_filter(get_object_vars($this));
    }
}
