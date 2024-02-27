<?php

namespace MediaWiki\Extension\AspaklaryaLockDown;

use Title;
use User;
use ApiBase;
use Article;
use ManualLogEntry;
use MediaWiki\Api\Hook\ApiCheckCanExecuteHook;
use MediaWiki\Diff\Hook\NewDifferenceEngineHook;
use MediaWiki\Hook\BeforeParserFetchTemplateRevisionRecordHook;
use MediaWiki\Linker\LinkTarget;
use MediaWiki\MediaWikiServices;
use MediaWiki\Page\Hook\PageDeleteCompleteHook;
use MediaWiki\Page\ProperPageIdentity;
use MediaWiki\Permissions\Authority;
use MediaWiki\Permissions\Hook\GetUserPermissionsErrorsHook;
use MediaWiki\Revision\RevisionRecord;
use RequestContext;
use UserGroupMembership;

class AspaklaryaLockdown implements
	NewDifferenceEngineHook,
	GetUserPermissionsErrorsHook,
	BeforeParserFetchTemplateRevisionRecordHook,
	PageDeleteCompleteHook,
	ApiCheckCanExecuteHook {

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
		if ($oldId > 0) {
			$locked = ALDBData::isRevisionLocked($oldId);
			if ($locked === true) {
				$result = ["aspaklarya_lockdown-rev-error", implode(', ', self::getLinks('aspaklarya-read-locked')), wfMessage('aspaklarya-' . $action)];
				return false;
			}
			if ($request->getText('diff') == 'next' || $request->getText('diff') == 'prev') {

				$revLookup = MediaWikiServices::getInstance()->getRevisionLookup();
				$revision = $revLookup->getRevisionById($oldId);
				$rev = null;
				if ($request->getText('diff') == 'next') {
					$rev = $revLookup->getNextRevision($revision);
				} else if ($request->getText('diff') == 'prev') {
					$rev = $revLookup->getPreviousRevision($revision);
				}
				if ($rev) {
					$locked = ALDBData::isRevisionLocked($rev->getId());
					if ($locked === true) {
						$result = ["aspaklarya_lockdown-rev-error", implode(', ', self::getLinks('aspaklarya-read-locked')), wfMessage('aspaklarya-' . $action)];
						return false;
					}
				}
			}
		}
		$oldId = $request->getIntOrNull('diff');
		if ($oldId > 0) {
			$locked = ALDBData::isRevisionLocked($oldId);
			if ($locked === true) {
				$result = ["aspaklarya_lockdown-rev-error", implode(', ', self::getLinks('aspaklarya-read-locked')), wfMessage('aspaklarya-' . $action)];
				return false;
			}
			$revLookup = MediaWikiServices::getInstance()->getRevisionLookup();
			$revision = $revLookup->getRevisionById($oldId);
			$rev = $revLookup->getPreviousRevision($revision);
			if ($rev) {
				$locked = ALDBData::isRevisionLocked($rev->getId());
				if ($locked === true) {
					$result = ["aspaklarya_lockdown-rev-error", implode(', ', self::getLinks('aspaklarya-read-locked')), wfMessage('aspaklarya-' . $action)];
					return false;
				}
			}
		}
		if ($request->getCheck('rev1') || $request->getCheck('rev2')) {
			$rev1 = $request->getIntOrNull('rev1');
			$rev2 = $request->getIntOrNull('rev2');
			if ($rev1) {
				$locked = ALDBData::isRevisionLocked($rev1);
				if ($locked === true) {
					$result = ["aspaklarya_lockdown-rev-error", implode(', ', self::getLinks('aspaklarya-read-locked')), wfMessage('aspaklarya-' . $action)];
					return false;
				}
			}
			if ($rev2) {
				$locked = ALDBData::isRevisionLocked($rev2);
				if ($locked === true) {
					$result = ["aspaklarya_lockdown-rev-error", implode(', ', self::getLinks('aspaklarya-read-locked')), wfMessage('aspaklarya-' . $action)];
					return false;
				}
			}
		}
	}

	/**
	 * this is very hacky and spcially for because of lack of proper hooks in mobile diff view
	 * the mobile diff view is in deprecation process and will be removed in future
	 * @todo remove after this https://phabricator.wikimedia.org/T358293 is final done
	 * @inheritDoc
	 */
	public function onNewDifferenceEngine($title, &$oldId, &$newId, $old, $new) {
		$user = RequestContext::getMain()->getUser();
		if ($user->isSafeToLoad() && $user->isAllowed('aspaklarya-read-locked')) {
			return;
		}
		$changed = false;
		if (is_numeric($oldId) && $oldId > 0) {
			$locked = ALDBData::isRevisionLocked($oldId);
			if ($locked === true) {
				$oldId = false;
				$changed = true;
			}
		}
		if (is_numeric($newId) && $newId > 0) {
			$locked = ALDBData::isRevisionLocked($newId);
			if ($locked === true) {
				$newId = false;
				$changed = true;
			}
		}
		if ($changed === true) {
			return false;
		}
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
		// $title = $module->getTitle();

		$page = $params['page'] ?? $page['title'] ?? null;
		if (
			$params['prop'] && in_array('revisions',  $params['prop']) && in_array('content', $params['prop'])
			// !empty($params['rvprop']) && 
			// ((is_array($params['rvprop']) && in_array('content', $params['rvprop'])) || 
			// (is_string($params['rvprop']) && in_array('content', explode('|', $params['rvprop']))))
		) {
			$title = Title::newFromText($page);
			if ($title->getArticleID() > 0) {
				$lockedRevisions = ALDBData::getLockedRevisions($title->getArticleID());
				if ($lockedRevisions && !$user->isAllowed('aspaklarya-read-locked')) {
					$module->dieWithError(['aspaklarya_lockdown-rev-error', implode(', ', self::getLinks('aspaklarya-read-locked')), wfMessage('aspaklarya-read')]);
					return false;
				}
			}
		}
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
