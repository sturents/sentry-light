<?php

namespace Kodus\Sentry\Model;

use JsonSerializable;

/**
 * @link https://docs.sentry.io/clientdev/attributes/
 */
class Event implements JsonSerializable
{
    /**
     * @var string ISO 8601 date format (as required by Sentry)
     *
     * @see gmdate()
     */
    public const DATE_FORMAT = "Y-m-d\TH:i:s";

    /**
     * @var string auto-generated UUID v4 (without dashes, as required by Sentry)
     */
    public string $event_id;

    /**
     * @var string severity level of this Event
     *
     * @see Level
     */
    public string $level = Level::ERROR;

    /**
     * @var int timestamp
     */
    public int $timestamp;

    /**
     * @var string human-readable message
     */
    public string $message;

    /**
     * The name of the transaction which caused this exception.
     *
     * For example, in a web app, this might be the route name: `/welcome`
     *
     * @var string|null
     */
    public string|null $transaction = null;

    /**
     * @var string platform name
     */
    public string $platform = "php";

    /**
     * @var string[] map where tag name => value
     */
    public array $tags = [];

    /**
     * @var ExceptionList|null
     */
    public ExceptionList|null $exception = null;

    /**
     * @var Request|null
     */
    public Request|null $request = null;

    /**
     * @var UserInfo
     */
    public UserInfo $user;

    /**
     * @var string|null the name of the logger that captured this Event
     */
    public string|null $logger = null;

    /**
     * @var string|null project release/version information (e.g. Git SHA, Composer version number, etc.)
     */
    public string|null $release = null;

    /**
     * @var string|null project configuration/environment information (e.g. "production", "staging", etc.)
     */
    public string|null $environment = null;

    /**
     * @var string[] map where module-name => version number (e.g. ["kodus/user" => "1.2.3"], etc.)
     */
    public array $modules = [];

    /**
     * @var array map of arbitrary meta-data to store with the Event (e.g. ["some_key" => 1234], etc.)
     */
    public array $extra = [];

    /**
     * @var Breadcrumb[] breadcrumbs collected prior to the capture of this Event
     */
    public array $breadcrumbs = [];

    /**
     * @var Context[] map where Context Type => Context
     */
    protected array $contexts = [];

    /**
     * @param string       $event_id
     * @param int          $timestamp
     * @param string       $message
     * @param UserInfo     $user
     */
    public function __construct(string $event_id, int $timestamp, string $message, UserInfo $user)
    {
        $this->event_id = $event_id;
        $this->timestamp = $timestamp;
        $this->message = $message;
        $this->user = $user;
    }

    /**
     * Add/replace a given {@see Context} instance.
     *
     * @param Context $context
     */
    public function addContext(Context $context):void
    {
        $this->contexts[$context->getType()] = $context;
    }

    /**
     * Add/replace a given "tag" name/value pair
     *
     * @param string $name
     * @param string $value
     */
    public function addTag(string $name, string $value): void
    {
        $this->tags[$name] = $value;
    }

    /**
     * @internal
     */
    public function jsonSerialize(): array
    {
        $data = array_filter(get_object_vars($this));

        $data["timestamp"] = gmdate(self::DATE_FORMAT, $this->timestamp);

        if (isset($data["breadcrumbs"])) {
            $data["breadcrumbs"] = ["values" => $data["breadcrumbs"]];
        }

        return $data;
    }
}
