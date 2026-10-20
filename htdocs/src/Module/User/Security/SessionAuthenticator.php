<?php

declare(strict_types=1);

namespace Dolibarr\Module\User\Security;

use Dolibarr\Core\Database\Connection;
use Dolibarr\Core\Event\EventDispatcher;
use Dolibarr\Core\Legacy\LegacyContext;
use Dolibarr\Core\Legacy\LegacyHooks;
use Dolibarr\Core\Logger;
use Dolibarr\Module\User\Event\UserLoggedIn;

/**
 * Opens the legacy session of an authenticated user, exactly as main.inc.php
 * l.1098-1178 does, so that the legacy pages and the MVC routes share it.
 */
final class SessionAuthenticator
{
	public function __construct(
		private readonly LegacyContext $ctx,
		private readonly EventDispatcher $dispatcher,
		private readonly LegacyHooks $hooks,
		private readonly Connection $connection,
		private readonly Logger $log
	) {
	}

	/**
	 * @param \User                $user       Legacy user, fetched with its personal conf
	 * @param string               $authMode   Mode that validated the credentials
	 * @param array<string,string> $clientInfo Posted fields tz, tz_string, dst_observed, dst_first, dst_second, screenwidth,
	 *                                         screenheight, dol_hide_topmenu, dol_hide_leftmenu, dol_optimize_smallscreen,
	 *                                         dol_no_mouse_hover, dol_use_jmobile, dol_openinpopup
	 * @throws AuthenticationException When a USER_LOGIN trigger or an afterLogin hook fails: the session is destroyed
	 */
	public function login(\User $user, string $authMode, array $clientInfo): void
	{
		$conf = $this->ctx->conf;

		// Values computed as main.inc.php l.574-583 and l.812-830 from the posted fields
		$tz = self::text($clientInfo, 'tz');
		$tzString = self::text($clientInfo, 'tz_string');
		$tzString = (string) preg_replace('/\s*\(.+\)$/', '', $tzString);
		$tzString = (string) preg_replace('/,/', '/', $tzString);
		$tzString = (string) preg_replace('/\s/', '_', $tzString);
		$dstFirst = self::text($clientInfo, 'dst_first');
		$dstSecond = self::text($clientInfo, 'dst_second');
		$dst = 0;
		if ($dstFirst !== '' && $dstSecond !== '') {
			include_once DOL_DOCUMENT_ROOT.'/core/lib/date.lib.php';
			$now = dol_now();
			if ($now >= dol_stringtotime($dstFirst) && $now < dol_stringtotime($dstSecond)) {
				$dst = 1;
			}
		}
		$screenWidth = self::text($clientInfo, 'screenwidth');
		$screenHeight = self::text($clientInfo, 'screenheight');
		$inPopup = self::text($clientInfo, 'dol_openinpopup') !== '';

		// A fresh session id: an id fixed before the login is worth nothing (the data, CSRF token included, is kept)
		if (session_status() === PHP_SESSION_ACTIVE && !session_regenerate_id(true)) {
			$this->log->warning('SessionAuthenticator: session id could not be regenerated');
		}

		// main.inc.php l.1102-1114: values always stored
		$_SESSION['dol_login'] = $user->login;
		$_SESSION['dol_logindate'] = dol_now('gmt');
		$_SESSION['dol_authmode'] = $authMode;
		$_SESSION['dol_tz'] = $tz;
		$_SESSION['dol_tz_string'] = $tzString;
		$_SESSION['dol_dst'] = $dst;
		$_SESSION['dol_dst_observed'] = self::int($clientInfo, 'dst_observed');
		$_SESSION['dol_dst_first'] = $dstFirst;
		$_SESSION['dol_dst_second'] = $dstSecond;
		$_SESSION['dol_screenwidth'] = $screenWidth;
		$_SESSION['dol_screenheight'] = $screenHeight;
		$_SESSION['dol_company'] = getDolGlobalString('MAIN_INFO_SOCIETE_NOM');
		$_SESSION['dol_entity'] = $conf->entity;
		// l.1115-1134: values stored only if defined, the hide-menu flags never from inside a dialog popup
		foreach (['dol_hide_topmenu', 'dol_hide_leftmenu', 'dol_optimize_smallscreen', 'dol_no_mouse_hover', 'dol_use_jmobile'] as $key) {
			$value = self::int($clientInfo, $key);
			if ($value !== 0 && !($inPopup && in_array($key, ['dol_hide_topmenu', 'dol_hide_leftmenu'], true))) {
				$_SESSION[$key] = $value;
			}
		}

		$this->log->info("This is a new started user session. _SESSION['dol_login']=".$user->login.' Session id='.session_id());

		// l.1142-1146: max number of concurrent sessions per user. The function is loaded by
		// main.inc.php l.115-116 only when the sessions are stored in database.
		$limit = (int) ($conf->file->main_limit_sessions_per_user ?? 0);
		if ($limit > 0 && function_exists('dolSessionsLimitForUser')) {
			dolSessionsLimitForUser($user->id, $limit, session_id());
		}

		$this->connection->begin();
		try {
			$user->update_last_login_date();

			// l.1151-1157
			$loginfo = 'TZ='.$tz.';TZString='.$tzString.';Screen='.$screenWidth.'x'.$screenHeight;
			$loginfo .= ' - authmode='.$authMode.' - entity='.$conf->entity;
			$user->context['audit'] = $loginfo;
			$user->context['authentication_method'] = $authMode;

			// USER_LOGIN trigger: run by LegacyTriggerBridge, which throws on failure
			$this->dispatcher->dispatch(new UserLoggedIn($user, $authMode, $loginfo));

			// l.1166-1173: hooks on successful login
			$action = '';
			$object = $user;
			$this->hooks->init(['login']);
			$result = $this->hooks->execute('afterLogin', ['dol_authmode' => $authMode, 'dol_loginfo' => $loginfo], $object, $action);
			if ($result->failed()) {
				throw new AuthenticationException('Error in some hooks afterLogin: '.implode(', ', $result->errors));
			}
		} catch (\Throwable $e) {
			$this->connection->rollback();
			if (session_status() === PHP_SESSION_ACTIVE) {
				session_destroy();
			}
			// session_destroy() leaves $_SESSION filled for the rest of the request: nothing may see a logged user
			$_SESSION = [];
			$this->log->error('Error in some triggers USER_LOGIN or in some hooks afterLogin: '.$e->getMessage());

			throw $e instanceof AuthenticationException ? $e : new AuthenticationException('Error in some triggers USER_LOGIN or in some hooks afterLogin', 0, $e);
		}
		$this->connection->commit();
	}

	/**
	 * @param array<string,string> $values
	 */
	private static function text(array $values, string $key): string
	{
		return $values[$key] ?? '';
	}

	/**
	 * @param array<string,string> $values
	 */
	private static function int(array $values, string $key): int
	{
		return (int) ($values[$key] ?? 0);
	}
}
