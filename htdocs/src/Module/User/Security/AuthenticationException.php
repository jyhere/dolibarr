<?php

declare(strict_types=1);

namespace Dolibarr\Module\User\Security;

/**
 * The session could not be opened: a USER_LOGIN trigger or an afterLogin hook failed.
 */
final class AuthenticationException extends \RuntimeException
{
}
