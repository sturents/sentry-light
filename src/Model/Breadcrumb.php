<?php

namespace Kodus\Sentry\Model;

use JsonSerializable;

/**
 * @link https://docs.sentry.io/clientdev/interfaces/breadcrumbs/
 */
class Breadcrumb implements JsonSerializable
{
    /**
     * @var int UNIX timestamp
     */
    public int $timestamp;

    /**
     * @var string Severity level
     *
     * @see Level
     */
    public string $level;

    /**
     * @var string
     */
    public string $message;

    /**
     * @var array
     */
    private array $data;

    public function __construct(int $timestamp, string $level, string $message, array $data)
    {
        $this->timestamp = $timestamp;
        $this->level = $level;
        $this->message = $message;
        $this->data = $data;
    }

    /**
     * @internal
     */
    public function jsonSerialize(): array
    {
		// Avoids a psalm error for unused property on "data"
		$out = array_merge(get_object_vars($this), [
			'data' => $this->data,
		]);
        return array_filter($out);
    }
}
