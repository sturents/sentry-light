<?php

namespace Kodus\Sentry\Model;

/**
 * @see https://docs.sentry.io/clientdev/interfaces/user/
 */
class UserInfo
{
    /**
     * @var string|null the application-specific unique ID of the User
     */
    public string|null $id = null;

    /**
     * @var string|null the User's application-specific logical username (or display-name, etc.)
     */
    public string|null $username = null;

    /**
     * @var string|null the User's e-mail address
     */
    public string|null $email = null;

    /**
     * @var string|null the User's client IP address (dotted notation)
     */
    public string|null $ip_address = null;
}
