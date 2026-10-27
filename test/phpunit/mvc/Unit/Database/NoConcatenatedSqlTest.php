<?php

declare(strict_types=1);

namespace Dolibarr\Tests\Unit\Database;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * The MVC core runs no SQL built by concatenation (spec §5.4): no call left to the legacy query API of Connection
 * (legacyFetch*, legacyExecute, escape), to DoliDB::escape() or to the legacy natural search functions, and no
 * second database link. The check reads the PHP tokens: comments, docblocks and strings naming these functions
 * are not calls.
 *
 * Exception: Core/Database/LegacyDriver.php interpolates the values escaped by DoliDB::escape() (the fallback
 * driver of the DoliDB without native driver). Query::toLegacySql() needs none: it quotes through SqlLiterals.
 */
final class NoConcatenatedSqlTest extends TestCase
{
	/** Files of htdocs/src allowed to call ->escape() */
	private const ESCAPE_ALLOWED = ['Core/Database/LegacyDriver.php'];

	/** Legacy functions building SQL fragments by concatenation */
	private const LEGACY_FUNCTIONS = ['natural_search', 'dol_natural_search_phone'];

	/** Functions opening a database link of their own */
	private const LINK_FUNCTIONS = ['pg_connect', 'pg_pconnect', 'mysqli_connect'];

	/** Classes opening a database link of their own */
	private const LINK_CLASSES = ['mysqli', 'pdo'];

	public function testNoLegacyQueryApiLeft(): void
	{
		$root = dirname(__DIR__, 5).'/htdocs/src/';
		$offences = [];
		$files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, RecursiveDirectoryIterator::SKIP_DOTS));
		foreach ($files as $file) {
			if ($file->getExtension() !== 'php') {
				continue;
			}
			$relative = substr($file->getPathname(), strlen($root));
			foreach (self::offences((string) file_get_contents($file->getPathname()), in_array($relative, self::ESCAPE_ALLOWED, true)) as $offence) {
				$offences[] = $relative.':'.$offence;
			}
		}
		sort($offences);

		self::assertSame([], $offences, 'Bind the values with Connection::fetchAll()/execute() (see test/mvc/README.md, Database and ORM).');
	}

	public function testOnlyCallsAreReported(): void
	{
		$code = <<<'PHP'
			<?php
			/** natural_search() and $db->escape() are documented here */
			// legacyExecute('x'); new mysqli();
			function natural_search($value) {}
			$label = 'natural_search(';
			$search->natural_search('a');
			NaturalSearch::dol_natural_search_phone('a');
			$fine = $connection->fetchAll('SELECT 1');
			PHP;
		self::assertSame([], self::offences($code, false));

		$code = <<<'PHP'
			<?php
			$where = natural_search('t.label', $value);
			$where .= \dol_natural_search_phone('t.phone', $value);
			$sql = "'".$db->escape($value)."'";
			$sql = "'".$this->connection?->escape($value)."'";
			$rows = $connection->legacyFetchAllObjects($sql);
			$connection->legacyExecute($sql);
			$link = new \mysqli();
			$link = new PDO('x');
			$link = pg_connect('x');
			PHP;
		self::assertSame([
			'2 natural_search()',
			'3 dol_natural_search_phone()',
			'4 ->escape()',
			'5 ->escape()',
			'6 legacyFetchAllObjects',
			'7 legacyExecute',
			'8 new mysqli',
			'9 new PDO',
			'10 pg_connect()',
		], self::offences($code, false));
		self::assertSame(['4 ->escape()', '5 ->escape()'], array_values(array_diff(self::offences($code, false), self::offences($code, true))));
	}

	/**
	 * @return list<string> 'line what' of each forbidden call of $code
	 */
	private static function offences(string $code, bool $escapeAllowed): array
	{
		$tokens = array_values(array_filter(
			token_get_all($code),
			static fn (array|string $token): bool => !is_array($token) || !in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)
		));
		$offences = [];
		foreach ($tokens as $index => $token) {
			if (!is_array($token) || !in_array($token[0], [T_STRING, T_NAME_FULLY_QUALIFIED], true)) {
				continue;
			}
			$name = strtolower(ltrim($token[1], '\\'));
			$previous = $tokens[$index - 1] ?? null;
			$previousKind = is_array($previous) ? $previous[0] : $previous;
			$isCall = ($tokens[$index + 1] ?? null) === '(';
			$isMember = in_array($previousKind, [T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR, T_DOUBLE_COLON], true);
			$line = $token[2];

			if (str_starts_with($name, 'legacyfetch') || $name === 'legacyexecute') {
				$offences[] = $line.' '.$token[1];
			} elseif ($name === 'escape' && $isCall && in_array($previousKind, [T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR], true) && !$escapeAllowed) {
				$offences[] = $line.' ->escape()';
			} elseif ($previousKind === T_NEW && in_array($name, self::LINK_CLASSES, true)) {
				$offences[] = $line.' new '.ltrim($token[1], '\\');
			} elseif ($isCall && !$isMember && $previousKind !== T_FUNCTION && in_array($name, [...self::LEGACY_FUNCTIONS, ...self::LINK_FUNCTIONS], true)) {
				$offences[] = $line.' '.$name.'()';
			}
		}

		return $offences;
	}
}
