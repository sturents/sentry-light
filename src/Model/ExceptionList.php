<?php

namespace Kodus\Sentry\Model;

/**
 * @link https://docs.sentry.io/clientdev/interfaces/exception/
 */
class ExceptionList
{
    /**
     * @var ExceptionInfo[]
     */
    public array $values = [];

    /**
     * @param ExceptionInfo[] $values
     */
    public function __construct(array $values)
    {
        $this->values = $values;
    }
}
