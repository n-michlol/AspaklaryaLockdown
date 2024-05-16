<?php

namespace MediaWiki\Extension\AspaklaryaLockDown;

use ApiBase;
use Article;
use Error;
use ManualLogEntry;
use MediaWiki\Api\Hook\ApiCheckCanExecuteHook;
use MediaWiki\Hook\BeforePageDisplayHook;
use MediaWiki\Hook\BeforeParserFetchTemplateRevisionRecordHook;
use MediaWiki\Hook\GetLinkColoursHook;
use MediaWiki\Hook\InfoActionHook;
use MediaWiki\Hook\MediaWikiServicesHook;
use MediaWiki\Hook\SkinTemplateNavigation__UniversalHook;
use MediaWiki\Linker\Hook\HtmlPageLinkRendererBeginHook;
use MediaWiki\Linker\Hook\HtmlPageLinkRendererEndHook;
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
use MediaWiki\Title\Title;
use OutputPage;
use RequestContext;
use Skin;
use SkinTemplate;
use User;
use UserGroupMembership;
use WANObjectCache;
use Wikimedia\Rdbms\ILoadBalancer;

/**
 * @ingroup Hooks
 */
class AspaklaryaLockdown implements
	GetUserPermissionsErrorsHook,
	BeforeParserFetchTemplateRevisionRecordHook,
	PageDeleteCompleteHook,
	ApiCheckCanExecuteHook,
	MediaWikiServicesHook,
	InfoActionHook,
	BeforePageDisplayHook,
	SkinTemplateNavigation__UniversalHook,
	GetLinkColoursHook,
	HtmlPageLinkRendererEndHook,
	HtmlPageLinkRendererBeginHook {

	/**
	 * @var ILoadBalancer
	 */
	private $loadBalancer;

	/**
	 * @var WANObjectCache
	 */
	private $cache;

	public function __construct() {
		$service = MediaWikiServices::getInstance();
		$this->loadBalancer = $service->getDBLoadBalancer();
		$this->cache = $service->getMainWANObjectCache();
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
			if (($action == 'aspaklarya_lockdown' || $action == 'read') && $user->isAllowed('aspaklarya_lockdown')) {
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
			$pageElimination = $this->getCachedvalue($titleId, 'page');
			if ($pageElimination !== 'none') {
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

		$cached = $this->getCachedvalue($titleId, 'page');

		if ($cached === ALDBData::READ) {
			$result = ["aspaklarya_lockdown-error", implode(', ', self::getLinks('aspaklarya-read-locked')), wfMessage('aspaklarya-' . $action)];
			return false;
		}
		if ($oldId > 0) {
			$locked = $this->getCachedvalue($oldId, 'revision');
			if ($locked === 1) {
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
		$pageElimination = $this->getCachedvalue($titleId, 'page');
		if ($pageElimination === 'read') {
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
		$revisions = ALDBData::getLockedRevisions($pageID);
		if ($revisions !== false) {
			$dbw->delete(ALDBData::getRevisionsTableName(), ['alr_page_id' => $pageID], __METHOD__);
			foreach ($revisions as $revision) {
				$this->cache->delete($this->cache->makeKey('aspaklarya-lockdown', 'revision', $revision->alr_rev_id));
			}
		}

		$cacheKey = $this->cache->makeKey('aspaklarya-lockdown', $pageID);
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
		if ($titleId > 0) {
			$pageElimination = $this->getCachedvalue($titleId, 'page');

			$info = 'aspaklarya-info-';
			if (!$pageElimination) {
				$info .= 'none';
			} elseif ($pageElimination === ALDBData::READ) {
				$info .= 'read';
			} else {
				$info .= 'edit';
			}

			$pageInfo['header-basic'][] = [
				$context->msg('aspaklarya-info-label'),
				$context->msg($info),
			];
		}
	}

	/**
	 * @inheritDoc
	 * @param OutputPage $out The page being output.
	 * @param Skin $skin Skin object used to generate the page. Ignored
	 * @return void This hook must not abort, it must return no value
	 */
	public function onBeforePageDisplay($out, $skin): void {
		$title = $out->getTitle();
		if (!$title) {
			return;
		}
		$titleId = $title->getArticleID();
		$cached = $this->getCachedvalue($titleId, 'page');
		$out->addJsConfigVars([
			'aspaklaryaLockdown' => $cached,
		]);
	}

	/**
	 * @inheritDoc
	 * @param SkinTemplate $sktemplate
	 * @param array &$links Structured navigation links. This is used to alter the navigation for
	 *   skins which use buildNavigationUrls such as Vector.
	 * @return void This hook must not abort, it must return no value
	 */
	public function onSkinTemplateNavigation__Universal($sktemplate, &$links): void {
		$title = $sktemplate->getTitle();
		if (!$title || $title->isSpecialPage() || !$sktemplate->getUser()->isAllowed('aspaklarya_lockdown')) {
			return;
		}
		$text = '';
		$pos = '';
		$titleId = $title->getArticleID();
		if ($titleId < 1) {
			$pageElimination = ALDBData::isCreateEliminated($title->getNamespace(), $title->getDBkey());
			$text = $pageElimination === true ? 'aspaklarya-lockdown-create-unlock' : 'aspaklarya-lockdown-create-lock';
			$pos = $pageElimination === true ? 'views' : 'actions';
		} else {
			$cached = $this->getCachedvalue($titleId, 'page');
			$text = $cached === 'none' ? 'aspaklarya-lockdown-lock' : 'aspaklarya-lockdown-change';
			$pos = 'actions';
		}

		$links[$pos]['aspaklarya_lockdown'] = [
			'text' => wfMessage($text),
			'href' => $title->getLocalURL('action=aspaklarya_lockdown'),
			'id' => 'ca-aspaklarya_lockdown',
			'class' => 'mw-list-item',
		];
	}

	/**
	 * Use this hook to modify the CSS class of an array of page links.
	 *
	 * @since 1.35
	 *
	 * @param string[] $linkcolour_ids Array of prefixed DB keys of the pages linked to,
	 *   indexed by page_id
	 * @param string[] &$colours (Output) Array of CSS classes, indexed by prefixed DB keys
	 * @param Title $title Title of the page being parsed, on which the links will be shown
	 * @return bool|void True or no return value to continue or false to abort
	 */
	public function onGetLinkColours($linkcolour_ids, &$colours, $title) {
	}

	/**
	 * @inheritDoc
	 */
	public function onHtmlPageLinkRendererEnd(
		$linkRenderer,
		$target,
		$isKnown,
		&$text,
		&$attribs,
		&$ret
	) {
	}

	/**
	 * @inheritDoc
	 */
	public function onHtmlPageLinkRendererBegin(
		$linkRenderer,
		$target,
		&$text,
		&$customAttribs,
		&$query,
		&$ret
	) {
		$title = Title::newFromLinkTarget($target);
		if (!$title || $title->isSpecialPage()) {
			return;
		}
		$titleId = $title->getArticleID();
		if ($titleId < 1) {
			$pageElimination = ALDBData::isCreateEliminated($title->getNamespace(), $title->getDBkey());
			if ($pageElimination === true) {
				return false;
			}
		} else {
			$cached = $this->getCachedvalue($titleId, 'page');
			if ($cached !== 'none') {
				return false;
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

	/**
	 * @param int $id page id or revision id
	 * @param string $type page or revision
	 * @return string|int
	 * @throws Error if not page or revision
	 */
	private function getCachedvalue(int $id, string $type) {
		$key = '';
		if ($type === 'page') {
			$key = $this->cache->makeKey('aspaklarya-lockdown', $id);
		} elseif ($type === 'revision') {
			$key = $this->cache->makeKey("aspaklarya-lockdown", "revision", $id);
		} else {
			throw new Error('Invalid type');
		}

		return $this->cache->getWithSetCallback(
			$key,
			$this->cache::TTL_MONTH,
			function () use ($id, $type) {
				if ($type === 'page') {
					$pageElimination = ALDBData::getPageLimitation($id);
					if (!$pageElimination) {
						return 'none';
					}
					return $pageElimination;
				} else {
					$db = $this->loadBalancer->getConnection(DB_REPLICA);
					$res = $db->newSelectQueryBuilder()
						->select(["alr_rev_id"])
						->from(ALDBData::PAGES_REVISION_NAME)
						->where(["alr_rev_id" => $id])
						->caller(__METHOD__)
						->fetchRow();
					if ($res !== false) {
						return 1;
					}
					return 0;
				}
			}
		);
	}
}
