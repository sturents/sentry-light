<?php

namespace Kodus\Sentry\Model;

/**
 * Sentry severity levels
 *
 * @see Event::$level
 * @see Breadcrumb::$level
 *
 * @link https://docs.sentry.io/clientdev/attributes/#optional-attributes
 */
abstract class Level
{
    public const FATAL   = "fatal";
	public const ERROR   = "error";
	public const WARNING = "warning";
	public const INFO    = "info";
	public const DEBUG   = "debug";
}
