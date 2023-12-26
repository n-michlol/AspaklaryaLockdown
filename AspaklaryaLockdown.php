<?php

use MediaWiki\MediaWikiServices;
use Wikimedia\Rdbms\ILoadBalancer;

class AspaklaryaLockdown
{

	/**
	 * Main hook
	 *
	 * @param Title $title
	 * @param User $user
	 * @param string $action
	 * @param string &$result
	 * @return false|void
	 */
	public static function onGetUserPermissionsErrors($title, $user, $action, &$result)
	{
		global $wgAspaklaryaLockdown;

		$explicitGroups = MediaWikiServices::getInstance()->getUserGroupManager()->getUserGroups($user);
		$implicitGroups = MediaWikiServices::getInstance()->getUserGroupManager()->getUserImplicitGroups($user);
		$userGroups = $explicitGroups + $implicitGroups;

		// Rules don't apply to admins
		if (in_array('sysop', $userGroups)) {
			return;
		}

		// get the title id
		$titleId = $title->getArticleID();

		// call the db to check if the given id has restrictions
		$lb = MediaWikiServices::getInstance()->getDBLoadBalancer();
		$dbr = $lb->getConnection(DB_REPLICA);
		$res = $dbr->newSelectQueryBuilder()
			->select(['page_id', 'page_restriction'])
			->from('aspaklarya_lockdown_pages')
			->where('page_id' === $titleId)
			->caller(__METHOD__)
			->fetchRow();
		if (!$res) {
			return;
		}
		if ($res->page_resriction === "edit") {
		}
		if ($res->page_resriction === "read") {
		}
		if ($res->page_resriction === "create") {
		}
	}

	/**
	 * API hook
	 *
	 * @todo This hook is rather hacky but should work well enough
	 *
	 * @param ApiBase $module
	 * @param User $user
	 * @param string &$message
	 * @return false|void
	 */
	public static function onApiCheckCanExecute($module, $user, &$message)
	{
		$params = $module->extractRequestParams();
		$page = $params['page'] ?? null;
		if ($page) {
			$title = Title::newFromText($page);
			$action = $module->isWriteMode() ? 'edit' : 'read';
			$allowed = self::onGetUserPermissionsErrors($title, $user, $action, $result);
			if ($allowed === false) {
				$module->dieWithError($result);
			}
		}
	}
}
