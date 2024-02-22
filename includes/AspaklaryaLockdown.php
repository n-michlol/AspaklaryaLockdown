<?php

namespace MediaWiki\Extension\AspaklaryaLockDown;

use Title;
use User;
use ApiBase;
use ManualLogEntry;
use MediaWiki\Linker\LinkTarget;
use MediaWiki\MediaWikiServices;
use MediaWiki\Page\ProperPageIdentity;
use MediaWiki\Permissions\Authority;
use MediaWiki\Revision\RevisionRecord;
use RequestContext;
use UserGroupMembership;

class AspaklaryaLockdown {

	/**
	 * Main hook
	 *
	 * @param Title $title
	 * @param User $user
	 * @param string $action
	 * @param string &$result
	 * @return false|void
	 */
	public static function onGetUserPermissionsErrors($title, $user, $action, &$result) {

		if ($title->isSpecialPage()) {
			return;
		}
		if($action === 'aspaklarya_lockdown' && $user->isAllowed('aspaklarya_lockdown')) {
			return;
		}
		$titleId = $title->getArticleID();


		if ($action === 'upload') {
			return;
		}
		if ($action === 'create' || $action === 'createpage' || $action === 'createtalk' || $titleId < 1) {
			// check if page is eliminated for create
			$pageElimination = ALDBData::isCreateEliminated($title->getNamespace(), $title->getDBkey());
			if ($pageElimination === true) {
				$result = ["aspaklarya_lockdown-create-error"];
				return false;
			}
			return;
		}
		if ($action === "edit") {
			if ($user->isSafeToLoad() && $user->isAllowed('aspaklarya-edit-locked')) {
				return;
			}
			// check if page is eliminated for edit
			$pageElimination = ALDBData::isEditEliminated($titleId);
			if ($pageElimination === true) {
				$groups = MediaWikiServices::getInstance()->getGroupPermissionsLookup()->getGroupsWithPermission('aspaklarya-edit-locked');
				$links = [];
				foreach ($groups as $group) {
					$links[] = UserGroupMembership::getLink($group, RequestContext::getMain(), "wiki");
				}
				$result = ["aspaklarya_lockdown-error", implode(', ', $links)];
				return false;
			}
			return;
		}

		if ($user->isSafeToLoad() && $user->isAllowed('aspaklarya-read-locked')) {
			return;
		}
		// get the title id
		$cache = MediaWikiServices::getInstance()->getMainWANObjectCache();
		$cacheKey = $cache->makeKey('aspaklarya-read', "$titleId");
		$cachedData = $cache->getWithSetCallback($cacheKey, (60 * 60 * 24 * 30), function () use ($titleId) {
			// check if page is eliminated for read
			$pageElimination = ALDBData::isReadEliminated($titleId);
			if ($pageElimination === true) {
				return 1;
			}
			return 0;
		});

		if ($cachedData === 1) {
			$groups = MediaWikiServices::getInstance()->getGroupPermissionsLookup()->getGroupsWithPermission('aspaklarya-read-locked');
			$links = [];
			foreach ($groups as $group) {
				$links[] = UserGroupMembership::getLink($group, RequestContext::getMain(), "wiki");
			}
			$result = ["aspaklarya_lockdown-error", implode(', ', $links)];
			return false;
		}
	}

	/**
	 * @inheritDoc
	 */
	public static function onBeforeParserFetchTemplateRevisionRecord(?LinkTarget $contextTitle, LinkTarget $title, bool &$skip, ?RevisionRecord &$revRecord) {
		$user = RequestContext::getMain()->getUser();
		if ($user->isSafeToLoad() && $user->isAllowed('aspaklarya-read-locked')) {
			$skip = false;
			return;
		}
		// get the title id
		$titleId = Title::newFromLinkTarget($title)->getArticleID();
		if ($titleId < 1) {
			$skip = false;
			return;
		}
		// check if page is eliminated for read
		$pageElimination = ALDBData::isReadEliminated($titleId);
		if ($pageElimination === true) {
			$skip = true;
			return;
		}
		$skip = false;
		return;
	}

	/**
	 * @inheritDoc
	 */
	public static function onPageDeleteComplete(ProperPageIdentity $page, Authority $deleter, string $reason, int $pageID, RevisionRecord $deletedRev, ManualLogEntry $logEntry, int $archivedRevisionCount) {
		$dbw = MediaWikiServices::getInstance()->getDBLoadBalancer()->getConnection(DB_PRIMARY);
		$dbw->delete(ALDBData::getPagesTableName(), ['al_page_id' => $pageID], __METHOD__);
		$cache = MediaWikiServices::getInstance()->getMainWANObjectCache();
		$cacheKey = $cache->makeKey('aspaklarya-read', $pageID);
		$cache->delete($cacheKey);
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
	public static function onApiCheckCanExecute($module, $user, &$message) {
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
