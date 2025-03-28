<?php

namespace Kodus\Sentry\Model;

use JsonSerializable;

/**
 * @link https://docs.sentry.io/clientdev/interfaces/http/
 */
class Request implements JsonSerializable
{
    /**
     * @var string full URL of the request
     */
    public string $url;

    /**
     * @var string HTTP method used
     */
    public string $method;

    /**
     * @var string|null unparsed query string
     */
    public string|null $query_string = null;

    /**
     * @var array cookie values (unparsed, as a string)
     */
    public array $cookies = [];

    /**
     * @var string[] map where header-name => header-value
     */
    public array $headers = [];

    /**
     * @var string|array|null Submitted data in whatever format makes most sense
     */
    public string|array|null $data = null;

    /**
     * @var string[] map where key => ernvironment value
     */
    public array $env = [];

    /**
     * @param string $url
     * @param string $method
     */
    public function __construct(string $url, string $method)
    {
        $this->url = $url;
        $this->method = $method;
    }

    public function jsonSerialize(): array
    {
        return array_filter(get_object_vars($this));
    }
}
