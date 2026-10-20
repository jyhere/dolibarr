<?php

declare(strict_types=1);

namespace Dolibarr\Module\User\Entity;

/**
 * How much of a user may be exposed to a given viewer.
 */
enum Visibility: string
{
	case Public = 'public';
	case SameUser = 'same_user';
	case Admin = 'admin';
}
