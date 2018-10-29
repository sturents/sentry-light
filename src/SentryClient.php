<?php

namespace Kodus\Sentry;

use Closure;
use Kodus\Sentry\Model\Breadcrumb;
use Kodus\Sentry\Model\BrowserContext;
use Kodus\Sentry\Model\Event;
use Kodus\Sentry\Model\ExceptionInfo;
use Kodus\Sentry\Model\ExceptionList;
use Kodus\Sentry\Model\Level;
use Kodus\Sentry\Model\OSContext;
use Kodus\Sentry\Model\Request;
use Kodus\Sentry\Model\RuntimeContext;
use Kodus\Sentry\Model\StackFrame;
use Kodus\Sentry\Model\StackTrace;
use Kodus\Sentry\Model\UserInfo;
use Psr\Http\Message\ServerRequestInterface;
use ReflectionException;
use ReflectionFunction;
use ReflectionFunctionAbstract;
use ReflectionMethod;
use RuntimeException;
use SplFileObject;
use Throwable;

class SentryClient
{
    /**
     * @string version of this client package
     */
    const VERSION = "1.0.0";

    /**
     * @var string[] map where PHP error-level => Sentry Event error-level
     *
     * @link http://php.net/manual/en/errorfunc.constants.php
     *
     * @link https://docs.sentry.io/clientdev/attributes/#optional-attributes
     */
    public $error_levels = [
        E_DEPRECATED        => Level::WARNING,
        E_USER_DEPRECATED   => Level::WARNING,
        E_WARNING           => Level::WARNING,
        E_USER_WARNING      => Level::WARNING,
        E_RECOVERABLE_ERROR => Level::WARNING,
        E_ERROR             => Level::FATAL,
        E_PARSE             => Level::FATAL,
        E_CORE_ERROR        => Level::FATAL,
        E_CORE_WARNING      => Level::FATAL,
        E_COMPILE_ERROR     => Level::FATAL,
        E_COMPILE_WARNING   => Level::FATAL,
        E_USER_ERROR        => Level::ERROR,
        E_NOTICE            => Level::INFO,
        E_USER_NOTICE       => Level::INFO,
        E_STRICT            => Level::INFO,
    ];

    /**
     * @var string[] map where regular expression pattern => browser name (or "bot")
     */
    public $browser_patterns = [
        "/AOLShield\/([0-9\._]+)/"                           => "aol",
        "/Edge\/([0-9\._]+)/"                                => "edge",
        "/YaBrowser\/([0-9\._]+)/"                           => "yandexbrowser",
        "/Vivaldi\/([0-9\.]+)/"                              => "vivaldi",
        "/KAKAOTALK\s([0-9\.]+)/"                            => "kakaotalk",
        "/SamsungBrowser\/([0-9\.]+)/"                       => "samsung",
        "/(?!Chrom.*OPR)Chrom(?:e|ium)\/([0-9\.]+)(:?\s|$)/" => "chrome",
        "/PhantomJS\/([0-9\.]+)(:?\s|$)/"                    => "phantomjs",
        "/CriOS\/([0-9\.]+)(:?\s|$)/"                        => "crios",
        "/Firefox\/([0-9\.]+)(?:\s|$)/"                      => "firefox",
        "/FxiOS\/([0-9\.]+)/"                                => "fxios",
        "/Opera\/([0-9\.]+)(?:\s|$)/"                        => "opera",
        "/OPR\/([0-9\.]+)(:?\s|$)$/"                         => "opera",
        "/Trident\/7\.0.*rv\:([0-9\.]+).*\).*Gecko$/"        => "ie",
        "/MSIE\s([0-9\.]+);.*Trident\/[4-7].0/"              => "ie",
        "/MSIE\s(7\.0)/"                                     => "ie",
        "/BB10;\sTouch.*Version\/([0-9\.]+)/"                => "bb10",
        "/Android\s([0-9\.]+)/"                              => "android",
        "/Version\/([0-9\._]+).*Mobile.*Safari.*/"           => "ios",
        "/Version\/([0-9\._]+).*Safari/"                     => "safari",
        "/FBAV\/([0-9\.]+)/"                                 => "facebook",
        "/Instagram\s([0-9\.]+)/"                            => "instagram",
        "/AppleWebKit\/([0-9\.]+).*Mobile/"                  => "ios-webview",

        "/(nuhk|slurp|ask jeeves\/teoma|ia_archiver|alexa|crawl|crawler|crawling|facebookexternalhit|feedburner|google web preview|nagios|postrank|pingdom|slurp|spider|yahoo!|yandex|\w+bot)/i" => "bot",
    ];

    /**
     * @var string[] map where regular expression pattern => OS name
     */
    public $os_patterns = [
        "/iP(hone|od|ad)/"                    => "iOS",
        "/Android/"                           => "Android OS",
        "/BlackBerry|BB10/"                   => "BlackBerry OS",
        "/IEMobile/"                          => "Windows Mobile",
        "/Kindle/"                            => "Amazon OS",
        "/Win16/"                             => "Windows 3.11",
        "/(Windows 95)|(Win95)|(Windows_95)/" => "Windows 95",
        "/(Windows 98)|(Win98)/"              => "Windows 98",
        "/(Windows NT 5.0)|(Windows 2000)/"   => "Windows 2000",
        "/(Windows NT 5.1)|(Windows XP)/"     => "Windows XP",
        "/(Windows NT 5.2)/"                  => "Windows Server 2003",
        "/(Windows NT 6.0)/"                  => "Windows Vista",
        "/(Windows NT 6.1)/"                  => "Windows 7",
        "/(Windows NT 6.2)/"                  => "Windows 8",
        "/(Windows NT 6.3)/"                  => "Windows 8.1",
        "/(Windows NT 10.0)/"                 => "Windows 10",
        "/Windows ME/"                        => "Windows ME",
        "/OpenBSD/"                           => "Open BSD",
        "/SunOS/"                             => "Sun OS",
        "/(Linux)|(X11)/"                     => "Linux",
        "/(Mac_PowerPC)|(Macintosh)/"         => "Mac OS",
        "/QNX/"                               => "QNX",
        "/BeOS/"                              => "BeOS",
        "/OS\/2/"                             => "OS/2",
    ];

    /**
     * List of trusted header-names from which the User's IP may be obtained.
     *
     * @var string[] map where header-name => regular expression pattern
     *
     * @see applyRequestDetails()
     */
    public $user_ip_headers = [
        "X-Forwarded-For" => '/^([^,\s$]+)/i',  // https://en.wikipedia.org/wiki/X-Forwarded-For
        "Forwarded"       => '/for=([^;,]+)/i', // https://tools.ietf.org/html/rfc7239
    ];

    // TODO grouping / fingerprints https://docs.sentry.io/learn/rollups/?platform=node#custom-grouping

    /**
     * @var string Sentry API endpoint
     */
    private $url;

    /**
     * @var string X-Sentry authentication header template
     */
    private $auth_header;

    /**
     * @var string
     */
    private $dsn;

    /**
     * @var null|string root path (with trailing directory-separator) (if defined)
     */
    private $root_path;

    /**
     * @var OSContext
     */
    private $os;

    /**
     * @var RuntimeContext
     */
    private $runtime;

    /**
     * @var Breadcrumb[] list of Breadcrumbs being collected for the next Event
     */
    private $breadcrumbs = [];

    /**
     * @param string      $dsn       Sentry DSN
     * @param string|null $root_path absolute project root-path (e.g. Composer root path; optional)
     */
    public function __construct(string $dsn, ?string $root_path = null)
    {
        $this->dsn = $dsn;
        $this->root_path = $root_path ? rtrim($root_path, "/\\") . "/" : null;

        $url = parse_url($this->dsn);

        $auth_tokens = implode(
            ", ",
            [
                "Sentry sentry_version=7",
                "sentry_timestamp=%s",
                "sentry_key={$url['user']}",
                "sentry_client=kodus-sentry/" . self::VERSION,
            ]
        );

        $this->auth_header = "X-Sentry-Auth: " . $auth_tokens;

        $this->url = "{$url['scheme']}://{$url['host']}/api{$url['path']}/store/";

        $this->runtime = $this->createRuntimeContext();

        $this->os = $this->createOSContext();
    }

    /**
     * Create and capture details about a given {@see Throwable} and (optionally) an
     * associated {@see ServerRequestInterface}.
     *
     * @param Throwable                   $exception the Exception to be logged
     * @param ServerRequestInterface|null $request   the related PSR-7 Request (if applicable)
     */
    public function captureException(Throwable $exception, ?ServerRequestInterface $request = null): void
    {
        $event = $this->createEvent($exception, $request);

        $this->captureEvent($event);
    }

    /**
     * Create an {@see Event} instance with details about a given {@see Throwable} and
     * (optionally) an associated {@see ServerRequestInterface}.
     *
     * @param Throwable                   $exception the Exception to be logged
     * @param ServerRequestInterface|null $request   the related PSR-7 Request (if applicable)
     *
     * @return Event
     */
    protected function createEvent(Throwable $exception, ?ServerRequestInterface $request = null): Event
    {
        $timestamp = $this->createTimestamp();

        $event_id = $this->createEventID();

        $event = new Event($event_id, $timestamp, $exception->getMessage(), new UserInfo(), $this->breadcrumbs);

        $this->clearBreadcrumbs();

        // NOTE: the `transaction` field is actually not intended for the *source* of the error, but for
        //       something that describes the command that resulted in the error - something application
        //       dependent, like the web-route or console-command that triggered the problem. Since those
        //       things can't be established from here, and since we want something meaningful to display
        //       in the title of the Sentry error-page, this is the best we can do for now.

        $event->transaction = $exception->getFile() . "#" . $exception->getLine();

        $event->exception = $this->createExceptionList($exception);

        $event->addContext($this->os);

        $event->addContext($this->runtime);

        $event->addTag("server_name", php_uname('n'));

        if ($request) {
            $this->applyRequestDetails($event, $request);
        }

        return $event;
    }

    /**
     * Capture (HTTP `POST`) a given {@see Event} to Sentry.
     *
     * @param Event $event
     */
    protected function captureEvent(Event $event): void
    {
        $body = json_encode($event, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        $headers = [
            "Accept: application/json",
            "Content-Type: application/json",
            $this->createAuthHeader($event->timestamp),
        ];

        $response = $this->fetch("POST", $this->url, $body, $headers);

        $data = json_decode($response, true);

        $event->event_id = $data["id"];
    }

    /**
     * Adds a {@see Breadcrumb} for the next {@see Event}.
     *
     * Note that Breadcrumbs will collect until you call {@see createEvent()} or {@see captureException()},
     * or explicitly clear them by calling {@see clearBreadcrumbs()}.
     *
     * @see Level for severity-level constants
     *
     * @param string $message
     * @param string $level severity level
     * @param array  $data  optional message context data
     */
    public function addBreadcrumb(string $message, string $level = Level::INFO, array $data = []): void
    {
        $this->breadcrumbs[] = new Breadcrumb($this->createTimestamp(), $level, $message, $data);
    }

    /**
     * Clears any Breadcrumbs collected by {@see addBreadcrumb()}.
     */
    public function clearBreadcrumbs(): void
    {
        $this->breadcrumbs = [];
    }

    /**
     * @return int current time
     */
    protected function createTimestamp(): int
    {
        return time();
    }

    /**
     * @return string UUID v4 without the "-" separators (as required by Sentry)
     */
    protected function createEventID(): string
    {
        $bytes = unpack('C*', random_bytes(16));

        return sprintf(
            '%02x%02x%02x%02x%02x%02x%02x%02x%02x%02x%02x%02x%02x%02x%02x%02x',
            $bytes[1], $bytes[2], $bytes[3], $bytes[4],
            $bytes[5], $bytes[6],
            $bytes[7] & 0x0f | 0x40, $bytes[8],
            $bytes[9] & 0x3f | 0x80, $bytes[10],
            $bytes[11], $bytes[12], $bytes[13], $bytes[14], $bytes[15], $bytes[16]
        );
    }

    /**
     * Creates the `X-Sentry-Auth` header.
     *
     * @param int $timestamp
     *
     * @return string
     */
    private function createAuthHeader(int $timestamp)
    {
        return sprintf($this->auth_header, $timestamp);
    }

    /**
     * Populates the given {@see Event} instance with information about the given {@see ServerRequestInterface}.
     *
     * @param Event                  $event
     * @param ServerRequestInterface $request
     */
    protected function applyRequestDetails(Event $event, ServerRequestInterface $request)
    {
        $event->addTag("site", $request->getUri()->getHost());

        $event->request = new Request($request->getUri()->__toString(), $request->getMethod());

        $event->request->query_string = $request->getUri()->getQuery();

        $event->request->cookies = $request->getCookieParams();

        $headers = [];

        foreach (array_keys($request->getHeaders()) as $name) {
            $headers[$name] = $request->getHeaderLine($name);
        }

        $event->request->headers = $headers;

        if ($request->hasHeader("User-Agent")) {
            $this->applyBrowserContext($event, $request->getHeaderLine("User-Agent"));
        }

        $event->user->ip_address = $this->detectUserIP($request);
    }

    /**
     * Attempts to discover the client's IP address, from proxy-headers if necessary.
     *
     * Note that concerns about trusted proxies are ignored by this implementation - if
     * somebody spoofs their IP, it may get logged, but that's not a security issue for
     * this use-case, since we're reporting only.
     *
     * @param ServerRequestInterface $request
     *
     * @return string client IP address (or 'unknown')
     */
    protected function detectUserIP(ServerRequestInterface $request): string
    {
        $server = $request->getServerParams();

        if (isset($server["REMOTE_ADDR"])) {
            if ($this->isValidIP($server["REMOTE_ADDR"])) {
                return $server["REMOTE_ADDR"]; // prioritize an IP provided by the CGI back-end
            }
        }

        foreach ($this->user_ip_headers as $name => $pattern) {
            if ($request->hasHeader($name)) {
                $value = $request->getHeaderLine($name);

                if (preg_match_all($pattern, $value, $matches) !== false) {
                    foreach ($matches[1] as $match) {
                        $ip = trim(preg_replace('/\:\d+$/', '', trim($match, '"')), '[]');

                        if ($this->isValidIP($ip)) {
                            return $ip; // return the first matching valid IP
                        }
                    }
                }
            }
        }

        return "unknown";
    }

    /**
     * Validates a detected client IP address.
     *
     * @param string $ip
     *
     * @return bool
     */
    protected function isValidIP(string $ip): bool
    {
        return filter_var(
            $ip,
            FILTER_VALIDATE_IP,
            FILTER_FLAG_IPV4            // accept IP v4
            | FILTER_FLAG_IPV6          // accept IP v6
            | FILTER_FLAG_NO_PRIV_RANGE // reject private IPv4 ranges
            | FILTER_FLAG_NO_RES_RANGE  // reject reserved IPv4 ranges
        ) !== false;
    }

    /**
     * Creates an {@see ExceptionList} instance from a given {@see Throwable}.
     *
     * @param Throwable $exception
     *
     * @return ExceptionList
     */
    protected function createExceptionList(Throwable $exception): ExceptionList
    {
        $items = [];

        while ($exception) {
            $items[] = $this->createExceptionInfo($exception);

            $exception = $exception->getPrevious();
        }

        return new ExceptionList(array_reverse($items));
    }

    /**
     * Creates an {@see ExceptionInfo} intsance from a given {@see Throwable}.
     *
     * @param Throwable $exception
     *
     * @return ExceptionInfo
     */
    protected function createExceptionInfo(Throwable $exception): ExceptionInfo
    {
        $trace = $exception->getTrace();

        array_unshift(
            $trace,
            [
                "file" => $exception->getFile(),
                "line" => $exception->getLine(),
            ]
        );

        return new ExceptionInfo(
            get_class($exception),
            $exception->getMessage(),
            $this->createStackTrace($trace)
        );
    }

    /**
     * Creates a {@see StackTrace} instance from a given PHP stack-trace.
     *
     * @param array $trace PHP stack-trace
     *
     * @return StackTrace
     */
    protected function createStackTrace(array $trace): StackTrace
    {
        $frames = [];

        foreach ($trace as $index => $entry) {
            $frames[] = $this->createStackFrame($entry);
        }

        return new StackTrace(array_reverse($frames));
    }

    /**
     * Creates a {@see StackFrame} instance from a given PHP stack-trace entry.
     *
     * @param array $entry PHP stack-trace entry
     *
     * @return StackFrame
     */
    protected function createStackFrame(array $entry): StackFrame
    {
        $filename = isset($entry["file"])
            ? $entry["file"]
            : "{no file}";

        $function = isset($entry["class"])
            ? $entry["class"] . @$entry["type"] . @$entry["function"]
            : @$entry["function"];

        $lineno = array_key_exists("line", $entry)
            ? (int) $entry["line"]
            : 0;

        $frame = new StackFrame($filename, $function, $lineno);

        if ($this->root_path) {
            if (strpos($filename, $this->root_path) !== -1) {
                $frame->abs_path = $filename;
                $frame->filename = substr($filename, strlen($this->root_path));
            }
        }

        if ($filename !== "{no file}") {
            $this->loadContext($frame, $filename, $lineno, 5);
        }

        if (isset($entry['args'])) {
            $frame->vars = $this->extractVars($entry);
        }

        return $frame;
    }

    /**
     * Attempts to load lines of source-code "context" from a PHP script to a {@see StackFrame} instance.
     *
     * @param StackFrame $frame     Sentry Client StackFrame to populate
     * @param string     $filename  path to PHP script
     * @param int        $lineno
     * @param int        $num_lines number of lines of context
     */
    protected function loadContext(StackFrame $frame, string $filename, int $lineno, int $num_lines)
    {
        if (! is_file($filename) || ! is_readable($filename)) {
            return;
        }

        $target = max(0, ($lineno - ($num_lines + 1)));

        $currentLineNumber = $target + 1;

        try {
            $file = new SplFileObject($filename);

            $file->seek($target);

            while (! $file->eof()) {
                $line = rtrim($file->current(), "\r\n");

                if ($currentLineNumber == $lineno) {
                    $frame->context_line = $line;
                } elseif ($currentLineNumber < $lineno) {
                    $frame->pre_context[] = $line;
                } elseif ($currentLineNumber > $lineno) {
                    $frame->post_context[] = $line;
                }

                $currentLineNumber += 1;

                if ($currentLineNumber > $lineno + $num_lines) {
                    break;
                }

                $file->next();
            }
        } catch (\Exception $ex) {
            return;
        }
    }

    /**
     * Extracts a map of parameters names to human-readable values from a given stack-frame.
     *
     * @param array $entry PHP stack-frame entry
     *
     * @return string[] map where parameter name => human-readable value string
     */
    protected function extractVars(array $entry)
    {
        $reflection = $this->getReflection($entry);

        $names = $reflection
            ? $this->getParameterNames($reflection)
            : [];

        $vars = [];

        $values = $this->formatValues($entry['args']);

        foreach ($values as $index => $value) {
            $vars[$names[$index] ?? "#" . ($index + 1)] = $value;
        }

        return $vars;
    }

    /**
     * Attempts to obtain a Function Reflection for a given stack-frame.
     *
     * @param array $entry PHP stack-frame entry
     *
     * @return ReflectionFunctionAbstract|null
     */
    protected function getReflection(array $entry): ?ReflectionFunctionAbstract
    {
        try {
            if (isset($entry["class"])) {
                if (method_exists($entry["class"], $entry["function"])) {
                    return new ReflectionMethod($entry["class"], $entry["function"]);
                } elseif ("::" === $entry["type"]) {
                    return new ReflectionMethod($entry["class"], "__callStatic");
                } else {
                    return new ReflectionMethod($entry["class"], "__call");
                }
            } elseif (function_exists($entry["function"])) {
                return new ReflectionFunction($entry["function"]);
            }
        } catch (ReflectionException $exception) {
            return null;
        }

        return null;
    }

    /**
     * Creates a list of parameter-names for a given Function Reflection.
     *
     * @param ReflectionFunctionAbstract $reflection
     *
     * @return string[] list of parameter names
     */
    protected function getParameterNames(ReflectionFunctionAbstract $reflection): array
    {
        $names = [];

        foreach ($reflection->getParameters() as $param) {
            $names[] = "$" . $param->getName();
        }

        return $names;
    }

    /**
     * Formats an array of raw PHP values as human-readable strings
     *
     * @param mixed[] $values raw PHP values
     *
     * @return string[] formatted values
     */
    protected function formatValues(array $values): array
    {
        $formatted = [];

        foreach ($values as $value) {
            $formatted[] = $this->formatValue($value);
        }

        return $formatted;
    }

    /**
     * @var int maximum length of formatted string-values
     *
     * @see formatValue()
     */
    const MAX_STRING_LENGTH = 200;

    /**
     * Formats any given PHP value as a human-readable string
     *
     * @param mixed $value
     *
     * @return string
     */
    protected function formatValue($value): string
    {
        $type = is_array($value) && is_callable($value)
            ? "callable"
            : strtolower(gettype($value));

        switch ($type) {
            case "boolean":
                return $value ? "true" : "false";

            case "integer":
                return number_format($value, 0, "", "");

            case "double": // (for historical reasons "double" is returned in case of a float, and not simply "float")
                $formatted = sprintf("%.6g", $value);

                return $value == $formatted
                    ? "{$formatted}"
                    : "~{$formatted}";

            case "string":
                $string = strlen($value) > self::MAX_STRING_LENGTH
                    ? substr($value, 0, self::MAX_STRING_LENGTH) . "...[" . strlen($value) . "]"
                    : $value;

                return '"' . addslashes($string) . '"';

            case "array":
                return "array[" . count($value) . "]";

            case "object":
                if ($value instanceof Closure) {
                    $reflection = new ReflectionFunction($value);

                    return "{Closure in " . $reflection->getFileName() . "({$reflection->getStartLine()})}";
                }

                return "{" . ($value instanceof \stdClass ? "object" : get_class($value)) . "}";

            case "resource":
                return "{" . get_resource_type($value) . "}";

            case "resource (closed)":
                return "{unknown type}";

            case "callable":
                return is_object($value[0])
                    ? '{' . get_class($value[0]) . "}->{$value[1]}()"
                    : "{$value[0]}::{$value[1]}()";

            case "null":
                return "null";
        }

        return "{{$type}}"; // "unknown type" and possibly unsupported (future) types
    }

    /**
     * Perform an HTTP request and return the response body.
     *
     * The request must return a 200 status-code.
     *
     * @param string $method HTTP method ("GET", "POST", etc.)
     * @param string $url
     * @param string $body
     * @param array  $headers
     *
     * @return string response body
     */
    protected function fetch(string $method, string $url, string $body, array $headers = []): string
    {
        $context = stream_context_create([
            "http" => [
                // http://docs.php.net/manual/en/context.http.php
                "method"        => $method,
                "header"        => implode("\r\n", $headers),
                "content"       => $body,
                "ignore_errors" => true,
            ],
        ]);

        $stream = fopen($url, "r", false, $context);

        $response = stream_get_contents($stream);

        $headers = stream_get_meta_data($stream)['wrapper_data'];

        $status_line = $headers[0];

        fclose($stream);

        preg_match('{HTTP\/\S*\s(\d{3})}', $status_line, $match);

        $status = $match[1];

        if ($status !== "200") {
            throw new RuntimeException("unexpected response status: {$status_line} ({$method} {$url})");
        }

        return $response;
    }

    /**
     * Create run-time context information about this PHP installation.
     *
     * @return RuntimeContext
     */
    private function createRuntimeContext(): RuntimeContext
    {
        $name = "php";

        $raw_description = PHP_VERSION;

        preg_match("#^\d+(\.\d+){2}#", $raw_description, $version);

        return new RuntimeContext($name, $version[0], $raw_description);
    }

    /**
     * Create the OS context information about this Operating System.
     *
     * @return OSContext
     */
    private function createOSContext(): OSContext
    {
        $name = php_uname("s");
        $version = php_uname("v");
        $build = php_uname("r");

        return new OSContext($name, $version, $build);
    }

    /**
     * Create the Browser context information based on the `User-Agent` string.
     *
     * @link https://github.com/DamonOehlman/detect-browser
     *
     * @param Event  $event
     * @param string $user_agent
     */
    private function applyBrowserContext(Event $event, string $user_agent)
    {
        $browser_name = "unknown";

        foreach ($this->browser_patterns as $pattern => $name) {
            if (preg_match($pattern, $user_agent, $browser_matches) === 1) {
                $browser_name = $name;

                break;
            }
        }

        $browser_version = isset($browser_matches[1])
            ? strtolower(implode(".", preg_split('/[._]/', $browser_matches[1])))
            : "unknown";

        $event->addTag("browser.{$browser_name}", $browser_version);

        $browser_os = "unknown";

        if ($browser_name !== "bot" && $browser_name !== "unknown") {
            foreach ($this->os_patterns as $pattern => $os) {
                if (preg_match($pattern, $user_agent) === 1) {
                    $browser_os = $os;

                    break;
                }
            }
        }

        $event->addTag("browser.os", $browser_os);

        $context = $browser_name === "bot"
            ? new BrowserContext("{$browser_name}/{$browser_version}", null)
            : new BrowserContext(
                $browser_name,
                $browser_version === "unknown"
                    ? $user_agent // TODO maybe fall back on a User-Agent hash for brevity?
                    : "{$browser_version}/{$browser_os}");

        $event->addContext($context);
    }
}
