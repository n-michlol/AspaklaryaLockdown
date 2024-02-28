<?php

namespace MediaWiki\Extension\AspaklaryaLockDown;

use Title;
use User;
use ApiBase;
use Article;
use ManualLogEntry;
use MediaWiki\Api\Hook\ApiCheckCanExecuteHook;
use MediaWiki\Hook\BeforeParserFetchTemplateRevisionRecordHook;
use MediaWiki\Hook\MediaWikiServicesHook;
use MediaWiki\Linker\LinkTarget;
use MediaWiki\Logger\LoggerFactory;
use MediaWiki\MediaWikiServices;
use MediaWiki\Page\Hook\PageDeleteCompleteHook;
use MediaWiki\Page\ProperPageIdentity;
use MediaWiki\Permissions\Authority;
use MediaWiki\Permissions\Hook\GetUserPermissionsErrorsHook;
use MediaWiki\Revision\RevisionRecord;
use RequestContext;
use UserGroupMembership;

class AspaklaryaLockdown implements
	GetUserPermissionsErrorsHook,
	BeforeParserFetchTemplateRevisionRecordHook,
	PageDeleteCompleteHook,
	ApiCheckCanExecuteHook,
	MediaWikiServicesHook {

	/**
	 * @param MediaWikiServices $services
	 * @return bool|void True or no return value to continue or false to abort
	 */
	public function onMediaWikiServices($services) {
		$services->redefineService('RevisionStoreFactory', static function ( MediaWikiServices $services ): ALRevisionStoreFactory {
			return new ALRevisionStoreFactory(
				$services->getDBLoadBalancerFactory(),
				$services->getBlobStoreFactory(),
				$services->getNameTableStoreFactory(),
				$services->getSlotRoleRegistry(),
				$services->getMainWANObjectCache(),
				$services->getLocalServerObjectCache(),
				$services->getCommentStore(),
				$services->getActorMigration(),
				$services->getActorStoreFactory(),
				LoggerFactory::getInstance( 'RevisionStore' ),
				$services->getContentHandlerFactory(),
				$services->getPageStoreFactory(),
				$services->getTitleFactory(),
				$services->getHookContainer()
			);
		},);
	}

	/**
	 * @inheritDoc
	 */
	public function onGetUserPermissionsErrors($title, $user, $action, &$result) {
		if ($title->isSpecialPage()) {
			return;
		}
		$request = RequestContext::getMain()->getRequest();
		$titleId = $title->getArticleID();


		if ($action === 'upload') {
			return;
		}
		if ($action === 'create' || $action === 'createpage' || $action === 'createtalk' || $titleId < 1) {
			if ($action == 'aspaklarya_lockdown' && $user->isAllowed('aspaklarya_lockdown')) {
				return;
			}
			// check if page is eliminated for create
			$pageElimination = ALDBData::isCreateEliminated($title->getNamespace(), $title->getDBkey());
			if ($pageElimination === true) {
				$result = ["aspaklarya_lockdown-create-error"];
				return false;
			}
			return;
		}


		$article = new Article($title);
		$oldId = $article->getOldID();


		if ($action === "edit") {
			if ($user->isSafeToLoad() && $user->isAllowed('aspaklarya-edit-locked')) {
				return;
			}
			// check if page is eliminated for edit
			$pageElimination = ALDBData::isEditEliminated($titleId);
			if ($pageElimination === true) {
				$result = ["aspaklarya_lockdown-error", implode(', ', self::getLinks('aspaklarya-edit-locked')), wfMessage('aspaklarya-' . $action)];
				return false;
			}
			if ($oldId == 0) {
				return;
			}
		}

		if ($user->isSafeToLoad() && $user->isAllowed('aspaklarya-read-locked')) {
			return;
		}

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
			$result = ["aspaklarya_lockdown-error", implode(', ', self::getLinks('aspaklarya-read-locked')), wfMessage('aspaklarya-' . $action)];
			return false;
		}
		// if ($oldId > 0) {
		// 	$locked = ALDBData::isRevisionLocked($oldId);
		// 	if ($locked === true) {
		// 		$result = ["aspaklarya_lockdown-rev-error", implode(', ', self::getLinks('aspaklarya-read-locked')), wfMessage('aspaklarya-' . $action)];
		// 		return false;
		// 	}
		// 	if ($request->getText('diff') == 'next' || $request->getText('diff') == 'prev') {

		// 		$revLookup = MediaWikiServices::getInstance()->getRevisionStore();
		// 		$revision = $revLookup->getRevisionById($oldId);
		// 		$rev = null;
		// 		if ($request->getText('diff') == 'next') {
		// 			$rev = $revLookup->getNextRevision($revision);
		// 		} else if ($request->getText('diff') == 'prev') {
		// 			$rev = $revLookup->getPreviousRevision($revision);
		// 		}
		// 		if ($rev) {
		// 			$locked = ALDBData::isRevisionLocked($rev->getId());
		// 			if ($locked === true) {
		// 				$result = ["aspaklarya_lockdown-rev-error", implode(', ', self::getLinks('aspaklarya-read-locked')), wfMessage('aspaklarya-' . $action)];
		// 				return false;
		// 			}
		// 		}
		// 	}
		// }
		// $oldId = $request->getIntOrNull('diff');
		// if ($oldId > 0) {
		// 	$locked = ALDBData::isRevisionLocked($oldId);
		// 	if ($locked === true) {
		// 		$result = ["aspaklarya_lockdown-rev-error", implode(', ', self::getLinks('aspaklarya-read-locked')), wfMessage('aspaklarya-' . $action)];
		// 		return false;
		// 	}
		// 	$revLookup = MediaWikiServices::getInstance()->getRevisionLookup();
		// 	$revision = $revLookup->getRevisionById($oldId);
		// 	$rev = $revLookup->getPreviousRevision($revision);
		// 	if ($rev) {
		// 		$locked = ALDBData::isRevisionLocked($rev->getId());
		// 		if ($locked === true) {
		// 			$result = ["aspaklarya_lockdown-rev-error", implode(', ', self::getLinks('aspaklarya-read-locked')), wfMessage('aspaklarya-' . $action)];
		// 			return false;
		// 		}
		// 	}
		// }
		// if ($request->getCheck('rev1') || $request->getCheck('rev2')) {
		// 	$rev1 = $request->getIntOrNull('rev1');
		// 	$rev2 = $request->getIntOrNull('rev2');
		// 	if ($rev1) {
		// 		$locked = ALDBData::isRevisionLocked($rev1);
		// 		if ($locked === true) {
		// 			$result = ["aspaklarya_lockdown-rev-error", implode(', ', self::getLinks('aspaklarya-read-locked')), wfMessage('aspaklarya-' . $action)];
		// 			return false;
		// 		}
		// 	}
		// 	if ($rev2) {
		// 		$locked = ALDBData::isRevisionLocked($rev2);
		// 		if ($locked === true) {
		// 			$result = ["aspaklarya_lockdown-rev-error", implode(', ', self::getLinks('aspaklarya-read-locked')), wfMessage('aspaklarya-' . $action)];
		// 			return false;
		// 		}
		// 	}
		// }
	}

	/**
	 * @inheritDoc
	 */
	public function onBeforeParserFetchTemplateRevisionRecord(?LinkTarget $contextTitle, LinkTarget $title, bool &$skip, ?RevisionRecord &$revRecord) {
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
	public function onPageDeleteComplete(ProperPageIdentity $page, Authority $deleter, string $reason, int $pageID, RevisionRecord $deletedRev, ManualLogEntry $logEntry, int $archivedRevisionCount) {
		$dbw = MediaWikiServices::getInstance()->getDBLoadBalancer()->getConnection(DB_PRIMARY);
		$dbw->delete(ALDBData::getPagesTableName(), ['al_page_id' => $pageID], __METHOD__);
		$dbw->delete(ALDBData::getRevisionsTableName(), ['al_page_id' => $pageID], __METHOD__);
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
	public function onApiCheckCanExecute($module, $user, &$message) {
		$params = $module->extractRequestParams();
		$page = $params['page'] ?? $page['title'] ?? null;
		if ($page) {
			$title = Title::newFromText($page);
			$action = $module->isWriteMode() ? 'edit' : 'read';
			$allowed = self::onGetUserPermissionsErrors($title, $user, $action, $result);
			if ($allowed === false) {
				$module->dieWithError($result);
			}
		}
	}

	/**
	 * get group links for messages
	 * @param string $right
	 * @return array
	 */
	private static function getLinks(string $right) {
		$groups = MediaWikiServices::getInstance()->getGroupPermissionsLookup()->getGroupsWithPermission($right);
		$links = [];
		foreach ($groups as $group) {
			$links[] = UserGroupMembership::getLink($group, RequestContext::getMain(), "wiki");
		}
		return $links;
	}
}
