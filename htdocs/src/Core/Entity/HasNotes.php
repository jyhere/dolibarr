<?php

declare(strict_types=1);

namespace Dolibarr\Core\Entity;

/**
 * An entity carrying a public and a private note.
 */
interface HasNotes
{
	public function getPublicNote(): ?string;

	public function getPrivateNote(): ?string;
}
