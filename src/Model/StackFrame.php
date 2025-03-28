<?php

namespace Kodus\Sentry\Model;

use JsonSerializable;

/**
 * @link https://docs.sentry.io/clientdev/interfaces/exception/
 */
class StackFrame implements JsonSerializable
{
    /**
     * @var string|null relative (to project root) path/filename
     */
    public string|null $filename;

    /**
     * @var string|null absolute path/filename
     */
    public string|null $abs_path = null;

    /**
     * @var string|null
     */
    public string|null $function;

    /**
     * @var int|null
     */
    public int|null $lineno;

    /**
     * @var string|null
     */
    public string|null $context_line = null;

    /**
     * @var string[]
     */
    public array $pre_context = [];

    /**
     * @var string[]
     */
    public array $post_context = [];

    /**
     * @var string[] map where parameter-name => string representation of value
     */
    public array $vars = [];

    public function __construct(
        ?string $filename,
        ?string $function,
        ?int $lineno
    ) {
        $this->filename = $filename;
        $this->function = $function;
        $this->lineno = $lineno;
    }

    /**
     * @internal
     */
    public function jsonSerialize(): array
    {
        return array_filter(get_object_vars($this));
    }
}
