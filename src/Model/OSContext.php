<?php

namespace Kodus\Sentry\Model;

class OSContext implements Context
{
    /**
     * @var string
     */
    public string $name;

    /**
     * @var string
     */
    public string $version;

    /**
     * @var string
     */
    public string $build;

    public function __construct(string $name, string $version, string $build)
    {
        $this->name = $name;
        $this->version = $version;
        $this->build = $build;
    }

    public function getType(): string
    {
        return "os";
    }

    public function jsonSerialize(): array
    {
        return get_object_vars($this);
    }
}
