<?php

namespace MediaWiki\Extension\AspaklaryaLockDown;

use Title;
use User;
use ApiBase;
use TextExtracts\ApiQueryExtracts;
use Article;
use ManualLogEntry;
use MediaWiki\Api\Hook\ApiCheckCanExecuteHook;
use MediaWiki\Hook\BeforeParserFetchTemplateRevisionRecordHook;
use MediaWiki\Hook\InfoActionHook;
use MediaWiki\Hook\MediaWikiServicesHook;
use MediaWiki\Linker\LinkTarget;
use MediaWiki\Logger\LoggerFactory;
use MediaWiki\MediaWikiServices;
use MediaWiki\Page\Hook\PageDeleteCompleteHook;
use MediaWiki\Page\ProperPageIdentity;
use MediaWiki\Permissions\Authority;
use MediaWiki\Permissions\Hook\GetUserPermissionsErrorsHook;
use MediaWiki\Revision\RevisionFactory;
use MediaWiki\Revision\RevisionLookup;
use MediaWiki\Revision\RevisionRecord;
use RequestContext;
use UserGroupMembership;
use WANObjectCache;
use Wikimedia\Rdbms\ILoadBalancer;

class AspaklaryaLockdown implements
	GetUserPermissionsErrorsHook,
	BeforeParserFetchTemplateRevisionRecordHook,
	PageDeleteCompleteHook,
	ApiCheckCanExecuteHook,
	MediaWikiServicesHook,
	InfoActionHook {

	/**
	 * @var ILoadBalancer
	 */
	private $loadBalancer;

	/**
	 * @var WANObjectCache
	 */
	private $cache;

	public function __construct(ILoadBalancer $loadBalancer, WANObjectCache $cache) {
		$this->loadBalancer = $loadBalancer;
		$this->cache = $cache;
	}

	/**
	 * @param MediaWikiServices $services
	 * @return bool|void True or no return value to continue or false to abort
	 */
	public function onMediaWikiServices($services) {

		$services->redefineService('RevisionStoreFactory', static function (MediaWikiServices $services): ALRevisionStoreFactory {
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
				LoggerFactory::getInstance('RevisionStore'),
				$services->getContentHandlerFactory(),
				$services->getPageStoreFactory(),
				$services->getTitleFactory(),
				$services->getHookContainer()
			);
		});
		$services->redefineService('RevisionStore', static function (MediaWikiServices $services): ALRevisionStore {
			return $services->getRevisionStoreFactory()->getRevisionStore();
		});
		$services->redefineService('RevisionFactory', static function (MediaWikiServices $services): RevisionFactory {
			return $services->getRevisionStore();
		});

		$services->redefineService('RevisionLookup', static function (MediaWikiServices $services): RevisionLookup {
			return $services->getRevisionStore();
		});
	}

	/**
	 * @inheritDoc
	 */
	public function onGetUserPermissionsErrors($title, $user, $action, &$result) {
		if ($title->isSpecialPage()) {
			return;
		}
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

		$cacheKey = $this->cache->makeKey('aspaklarya-read', "$titleId");
		$cachedData = $this->cache->getWithSetCallback($cacheKey, (60 * 60 * 24 * 30), function () use ($titleId) {
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
		$dbw = $this->loadBalancer->getConnection(DB_PRIMARY);
		$dbw->delete(ALDBData::getPagesTableName(), ['al_page_id' => $pageID], __METHOD__);
		$dbw->delete(ALDBData::getRevisionsTableName(), ['alr_page_id' => $pageID], __METHOD__);

		$cacheKey = $this->cache->makeKey('aspaklarya-read', $pageID);
		$this->cache->delete($cacheKey);
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
	 * @inheritDoc
	 */
	public function onInfoAction($context, &$pageInfo) {
		$titleId = $context->getTitle()->getArticleID();
		$pageElimination = false;
		$cacheKey = $this->cache->makeKey('aspaklarya-read', "$titleId");
		$cachedData = $this->cache->getWithSetCallback($cacheKey, (60 * 60 * 24 * 30), function () use ($titleId, $pageElimination) {
			// check if page is eliminated for read
			$pageElimination = ALDBData::getPageLimitation($titleId);
			if ($pageElimination === ALDBData::READ) {
				return 1;
			}
			return 0;
		});
		if ($titleId > 0) {
			$pageInfo['header-basic'][] = [
				$context->msg('aspaklarya-info-label'),
				$context->msg(
					'aspaklarya-info-' . ($cachedData === 1) ? 'read' : ($pageElimination === false ? 'none' : 'edit')
				)
			];
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
