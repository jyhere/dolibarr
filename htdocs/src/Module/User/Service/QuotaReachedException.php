<?php

declare(strict_types=1);

namespace Dolibarr\Module\User\Service;

/**
 * The users quota of conf.php ($dolibarr_main_limit_users) would be reached: nothing was written.
 * The caller shows YourQuotaOfUsersIsReached.
 */
final class QuotaReachedException extends \RuntimeException
{
}
