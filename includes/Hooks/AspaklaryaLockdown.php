<?php

namespace MediaWiki\Extension\AspaklaryaLockDown\Hooks;

use ApiBase;
use Article;
use DifferenceEngine;
use Error;
use InvalidArgumentException;
use ManualLogEntry;
use MediaWiki\Api\Hook\ApiCheckCanExecuteHook;
use MediaWiki\Diff\Hook\ArticleContentOnDiffHook;
use MediaWiki\Diff\Hook\DifferenceEngineNewHeaderHook;
use MediaWiki\Diff\Hook\DifferenceEngineOldHeaderHook;
use MediaWiki\Extension\AspaklaryaLockDown\ALDBData;
use MediaWiki\Extension\AspaklaryaLockDown\AspaklaryaPagesLocker;
use MediaWiki\Extension\AspaklaryaLockDown\Services\ALLinkRenderer;
use MediaWiki\Extension\AspaklaryaLockDown\Services\ALLinkRendererFactory;
use MediaWiki\Extension\AspaklaryaLockDown\Services\ALRevisionStore;
use MediaWiki\Extension\AspaklaryaLockDown\Services\ALRevisionStoreFactory;
use MediaWiki\Extension\AspaklaryaLockDown\Special\ALSpecialRevisionLock;
use MediaWiki\Hook\ArticleRevisionVisibilitySetHook;
use MediaWiki\Hook\BeforePageDisplayHook;
use MediaWiki\Hook\BeforeParserFetchTemplateRevisionRecordHook;
use MediaWiki\Hook\EditPage__showEditForm_initialHook;
use MediaWiki\Hook\EditPage__showReadOnlyForm_initialHook;
use MediaWiki\Hook\GetLinkColoursHook;
use MediaWiki\Hook\InfoActionHook;
use MediaWiki\Hook\MediaWikiServicesHook;
use MediaWiki\Hook\RandomPageQueryHook;
use MediaWiki\Hook\SkinTemplateNavigation__UniversalHook;
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
use Xml;

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
	DifferenceEngineOldHeaderHook,
	DifferenceEngineNewHeaderHook,
	EditPage__showReadOnlyForm_initialHook,
	EditPage__showEditForm_initialHook,
	RandomPageQueryHook,
	ArticleRevisionVisibilitySetHook,
	ArticleContentOnDiffHook
{

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
	public function onMediaWikiServices( $services ) {
		$services->redefineService( 'RevisionStoreFactory', static function ( MediaWikiServices $services ): ALRevisionStoreFactory {
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
		} );
		$services->redefineService( 'RevisionStore', static function ( MediaWikiServices $services ): ALRevisionStore {
			return $services->getRevisionStoreFactory()->getRevisionStore();
		} );
		$services->redefineService( 'RevisionFactory', static function ( MediaWikiServices $services ): RevisionFactory {
			return $services->getRevisionStore();
		} );

		$services->redefineService( 'RevisionLookup', static function ( MediaWikiServices $services ): RevisionLookup {
			return $services->getRevisionStore();
		} );

		$services->redefineService( 'LinkRendererFactory', static function ( MediaWikiServices $services ): ALLinkRendererFactory {
			return new ALLinkRendererFactory(
				$services->getTitleFormatter(),
				$services->getLinkCache(),
				$services->getSpecialPageFactory(),
				$services->getHookContainer()
			);
		} );

		$services->redefineService( 'LinkRenderer', static function ( MediaWikiServices $services ): ALLinkRenderer {
			return $services->getLinkRendererFactory()->create();
		} );
	}

	/**
	 * @inheritDoc
	 */
	public function onRandomPageQuery( &$tables, &$conds, &$joinConds ) {
		$ptn = ALDBData::PAGES_TABLE_NAME;
		$tables['al'] = $ptn;
		$joinConds['al'] = [
			'LEFT JOIN',
			[ 'al.al_page_id = page_id' ],
		];
		$conds['al.al_page_id'] = null;
	}

	/**
	 * @inheritDoc
	 */
	public function onEditPage__showReadOnlyForm_initial( $editor, $out ) {
		$user = $editor->getContext()->getUser();
		if ( $user->isSafeToLoad() && $user->isAllowed( 'aspaklarya-edit-locked' ) ) {
			return;
		}
		$title = $editor->getTitle();
		$titleId = $title->getArticleID();
		if ( $titleId < 1 ) {
			return;
		}
		$pageElimination = $this->getCachedvalue( $titleId, 'page' );
		if ( $pageElimination === 'none' || $pageElimination === AspaklaryaPagesLocker::EDIT_FULL ) {
			return;
		}
		if ( $pageElimination === AspaklaryaPagesLocker::EDIT ) {
			$out->redirect( $title->getLocalURL() );
		} elseif ( $pageElimination === AspaklaryaPagesLocker::EDIT_SEMI && ( !$user->isSafeToLoad() || !$user->isAllowed( 'aspaklarya-edit-semi-locked' )) ) {
			$out->redirect( $title->getLocalURL() );
		}
	}

	/**
	 * @inheritDoc
	 */
	public function onEditPage__showEditForm_initial( $editor, $out ) {
		$this->onEditPage__showReadOnlyForm_initial( $editor, $out );
	}

	/**
	 * @inheritDoc
	 */
	public function onGetUserPermissionsErrors( $title, $user, $action, &$result ) {
		if ( $title->isSpecialPage() ) {
			return;
		}
		$titleId = $title->getArticleID();

		if ( $action === 'upload' ) {
			return;
		}
		if ( $action === 'create' || $action === 'createpage' || $action === 'createtalk' || $titleId < 1 ) {
			if ( ( $action == 'aspaklarya_lockdown' || $action == 'read' ) && $user->isAllowed( 'aspaklarya_lockdown' ) ) {
				return;
			}
			// check if page is eliminated for create
			$pageElimination = ALDBData::isCreateEliminated( $title->getNamespace(), $title->getDBkey() );
			if ( $pageElimination === true ) {
				$result = [ "aspaklarya_lockdown-create-error" ];
				return false;
			}
			return;
		}

		$article = new Article( $title );
		$oldId = $article->getOldID();
		$request = RequestContext::getMain()->getRequest();
		$diff = $request->getInt( 'diff' );
		

		if ( $action === "edit" ) {
			if ( $user->isSafeToLoad() && $user->isAllowed( 'aspaklarya-edit-locked' ) ) {
				return;
			}
			// check if page is eliminated for edit
			$pageElimination = $this->getCachedvalue( $titleId, 'page' );
			if ( $pageElimination === AspaklaryaPagesLocker::READ || $pageElimination === AspaklaryaPagesLocker::EDIT ) {
				$result = [ "aspaklarya_lockdown-error", implode( ', ', self::getLinks( 'aspaklarya-edit-locked' ) ), wfMessage( 'aspaklarya-' . $action ) ];
				return false;
			} elseif ( $pageElimination === AspaklaryaPagesLocker::EDIT_SEMI && (!$user->isSafeToLoad() || !$user->isAllowed( 'aspaklarya-edit-semi-locked' )) ) {
				$result = [ "aspaklarya_lockdown-error", implode( ', ', self::getLinks( 'aspaklarya-edit-semi-locked' ) ), wfMessage( 'aspaklarya-' . $action ) ];
				return false;
			}
			if ( $oldId == 0 && $diff == 0) {
				return;
			}
		}

		if ( $user->isSafeToLoad() && $user->isAllowed( 'aspaklarya-read-locked' ) ) {
			return;
		}

		$cached = $this->getCachedvalue( $titleId, 'page' );

		if ( $cached === ALDBData::READ ) {
			$result = [ "aspaklarya_lockdown-error", implode( ', ', self::getLinks( 'aspaklarya-read-locked' ) ), wfMessage( 'aspaklarya-' . $action ) ];
			return false;
		}
		if ( $oldId > 0 ) {
			$locked = $this->getCachedvalue( $oldId, 'revision' );
			if ( $locked ) {
				$result = [ "aspaklarya_lockdown-rev-error", implode( ', ', self::getLinks( 'aspaklarya-lock-revisions' ) ), wfMessage( 'aspaklarya-' . $action ) ];
				return false;
			}
		}
		if ( $diff > 0 ) {
			$locked = $this->getCachedvalue( $diff, 'revision' );
			if ( $locked ) {
				$result = [ "aspaklarya_lockdown-rev-error", implode( ', ', self::getLinks( 'aspaklarya-lock-revisions' ) ), wfMessage( 'aspaklarya-' . $action ) ];
				return false;
			}
		}
	}

	/**
	* @inheritDoc
	 */
	public function onArticleContentOnDiff( $differenceEngine, $out ) {
		if( $differenceEngine->getAuthority()->isAllowed( 'aspaklarya-lock-revisions' ) ) {
			return true;
		}
		$newId = $differenceEngine->getNewid();
		$oldId = $differenceEngine->getOldid();
		if($newId > 0) {

			$locked = $this->getCachedvalue( $newId, 'revision' );
			if ($locked){
				$out->showPermissionsErrorPage([['aspaklarya_lockdown-rev-error',implode( ', ', self::getLinks( 'aspaklarya-lock-revisions' ) )]]);
				return false;
			}
		}
		if($oldId > 0) {
			$locked = $this->getCachedvalue( $oldId, 'revision' );
			if ($locked){
				$out->showPermissionsErrorPage([['aspaklarya_lockdown-rev-error',implode( ', ', self::getLinks( 'aspaklarya-lock-revisions' ) )]]);
				return false;
			}
		}
		
		return true;
	}

	/**
	 * @inheritDoc
	 */
	public function onBeforeParserFetchTemplateRevisionRecord( ?LinkTarget $contextTitle, LinkTarget $title, bool &$skip, ?RevisionRecord &$revRecord ) {
		$user = RequestContext::getMain()->getUser();
		if ( $user->isSafeToLoad() && $user->isAllowed( 'aspaklarya-read-locked' ) ) {
			$skip = false;
			return;
		}
		// get the title id
		$titleId = Title::newFromLinkTarget( $title )->getArticleID();
		if ( $titleId < 1 ) {
			$skip = false;
			return;
		}
		// check if page is eliminated for read
		$pageElimination = $this->getCachedvalue( $titleId, 'page' );
		if ( $pageElimination === 'read' ) {
			$skip = true;
			return;
		}
		$skip = false;
		return;
	}

	/**
	 * @inheritDoc
	 */
	public function onPageDeleteComplete( ProperPageIdentity $page, Authority $deleter, string $reason, int $pageID, RevisionRecord $deletedRev, ManualLogEntry $logEntry, int $archivedRevisionCount ) {
		$dbw = $this->loadBalancer->getConnection( DB_PRIMARY );
		$dbw->delete( ALDBData::getPagesTableName(), [ 'al_page_id' => $pageID ], __METHOD__ );
		$revisions = ALDBData::getLockedRevisions( $pageID );
		if ( $revisions !== false ) {
			$dbw->delete( ALDBData::getRevisionsTableName(), [ 'alr_page_id' => $pageID ], __METHOD__ );
			foreach ( $revisions as $revision ) {
				$this->cache->delete( $this->cache->makeKey( 'aspaklarya-lockdown', 'revision', $revision->alr_rev_id ) );
			}
		}

		$cacheKey = $this->cache->makeKey( 'aspaklarya-lockdown', $pageID );
		$this->cache->delete( $cacheKey );
	}

	/**
	 * @inheritDoc
	 */
	public function onArticleRevisionVisibilitySet( $title, $ids, $visibilityChangeMap ) {
		$dbw = $this->loadBalancer->getConnection( DB_PRIMARY );
		foreach( $visibilityChangeMap as $id => $visibility ) {
			if ( $visibility['newBits'] & RevisionRecord::DELETED_TEXT ) {
				$dbw->delete( ALDBData::getRevisionsTableName(), [ 'alr_rev_id' => $id ], __METHOD__ );
				$cacheKey = $this->cache->makeKey( 'aspaklarya-lockdown', 'revision', $id );
				$this->cache->delete( $cacheKey );
			}
		}
	}

	/**
	 * @inheritDoc
	 */
	public function onApiCheckCanExecute( $module, $user, &$message ) {
		$params = $module->extractRequestParams();
		$page = $params['page'] ?? $page['title'] ?? null;
		if ( $page ) {
			$title = Title::newFromText( $page );
			$action = $module->isWriteMode() ? 'edit' : 'read';
			$allowed = self::onGetUserPermissionsErrors( $title, $user, $action, $result );
			if ( $allowed === false ) {
				$module->dieWithError( $result );
			}
		}
	}

	/**
	 * @inheritDoc
	 */
	public function onInfoAction( $context, &$pageInfo ) {
		$titleId = $context->getTitle()->getArticleID();
		if ( $titleId > 0 ) {
			$pageElimination = $this->getCachedvalue( $titleId, 'page' );

			$info = 'aspaklarya-info-' . $pageElimination;
			
			$pageInfo['header-basic'][] = [
				$context->msg( 'aspaklarya-info-label' ),
				$context->msg( $info ),
			];
		}
	}

	/**
	 * @inheritDoc
	 */
	public function onBeforePageDisplay( $out, $skin ): void {
		$title = $out->getTitle();
		if ( !$title ) {
			return;
		}
		$titleId = $title->getArticleID();
		$cached = $this->getCachedvalue( $titleId, 'page' );
		$out->addJsConfigVars( [
			'aspaklaryaLockdown' => $cached,
		] );
	}

	/**
	 * @inheritDoc
	 */
	public function onSkinTemplateNavigation__Universal( $sktemplate, &$links ): void {
		$title = $sktemplate->getTitle();
		if ( !$title || $title->isSpecialPage() || !$sktemplate->getUser()->isAllowed( 'aspaklarya_lockdown' ) ) {
			return;
		}
		$text = '';
		$pos = '';
		$titleId = $title->getArticleID();
		if ( $titleId < 1 ) {
			$pageElimination = ALDBData::isCreateEliminated( $title->getNamespace(), $title->getDBkey() );
			$text = $pageElimination === true ? 'aspaklarya-lockdown-create-unlock' : 'aspaklarya-lockdown-create-lock';
			$pos = $pageElimination === true ? 'views' : 'actions';
		} else {
			$cached = $this->getCachedvalue( $titleId, 'page' );
			$text = $cached === 'none' ? 'aspaklarya-lockdown-lock' : 'aspaklarya-lockdown-change';
			$pos = 'actions';
		}

		$links[$pos]['aspaklarya_lockdown'] = [
			'text' => wfMessage( $text ),
			'href' => $title->getLocalURL( 'action=aspaklarya_lockdown' ),
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
	public function onGetLinkColours( $linkcolour_ids, &$colours, $title ) {
		if ( $title->isSpecialPage() ) {
			return true;
		}

		// dont check special pages
		$linkcolour_ids = array_filter( $linkcolour_ids, static function ( $id ) {
			return $id > 0;
		}, ARRAY_FILTER_USE_KEY );

		$redirects = array_filter( $linkcolour_ids, static function ( $pdbk ) use( $colours ) {
			return strpos( $colours[$pdbk], 'redirect' ) !== false;
		} );
		$regulars = array_diff_key( $linkcolour_ids, $redirects );

		$db = $this->loadBalancer->getConnection( DB_REPLICA );

		if ( !empty( $redirects ) ) {
			$res = $db->newSelectQueryBuilder()
						->select( [ 'page_id', 'rd_from' ] )
						->from( 'page' )
						->join( 'redirect', null, [
							'rd_namespace=page_namespace',
							'rd_title=page_title',
							'rd_interwiki' => '',
						] )
						->where( [ 'rd_from' => array_keys( $redirects ) ] )
						->caller( __METHOD__ )
						->fetchResultSet();

			foreach ( $res as $row ) {
				if ( !isset( $regulars[$row->page_id] ) ) {
					$regulars[$row->page_id] = $linkcolour_ids[$row->rd_from];
					unset( $redirects[$row->rd_from] );
				}
			}
			unset( $res );
		}
		if( !empty( $regulars )){
			$res = $db->newSelectQueryBuilder()
				->select( [ "al_page_id", "al_read_allowed" ] )
				->from( ALDBData::PAGES_TABLE_NAME )
				->where( [ "al_page_id" => array_map( 'intval', array_keys( $regulars ) ) ] )
				->caller( __METHOD__ )
				->fetchResultSet();

			foreach ( $res as $row ) {
				if ( $row->al_read_allowed > 0 ) {
					$colours[$regulars[$row->al_page_id]] .= ' aspaklarya-edit-locked';
				} else {
					$colours[$regulars[$row->al_page_id]] .= ' aspaklarya-read-locked';
				}
				if ( !empty( $redirects ) && isset( $redirects[$row->al_page_id] ) ) {
					$colours[$redirects[$row->al_page_id]] .= $colours[$regulars[$row->al_page_id]];
					unset( $redirects[$row->al_page_id] );
				}
			}
		}
		return true;
	}

	/**
	 * @inheritDoc
	 */
	public function onDifferenceEngineOldHeader( $differenceEngine, &$oldHeader,
		$prevlink, $oldminor, $diffOnly, $ldel, $unhide
	) {
		$user = $differenceEngine->getAuthority();
		if ( !$user->isAllowed( 'aspaklarya-lock-revisions' ) ) {
			return true;
		}
		$title = $differenceEngine->getTitle();
		$oldId = $differenceEngine->getOldId();
		if ( $oldId < 1 || !$title ) {
			return true;
		}
		$link = ALSpecialRevisionLock::linkToPage( $title, [ $oldId ] );
		$tag = Xml::tags( 'span', [ 'class' => 'mw-revdelundel-link' ], wfMessage( 'parentheses' )->rawParams( $link )->escaped() );
		$oldHeader .= '<div id="mw-diff-otitle5">' . $tag . '</div>';
	}

	/**
	 * @inheritDoc
	 */
	public function onDifferenceEngineNewHeader( $differenceEngine, &$newHeader,
		$formattedRevisionTools, $nextlink, $rollback, $newminor, $diffOnly, $rdel,
		$unhide
	) {
		$user = $differenceEngine->getAuthority();
		if ( !$user->isAllowed( 'aspaklarya-lock-revisions' ) ) {
			return true;
		}
		$title = $differenceEngine->getTitle();
		if ( !$title ) {
			return true;
		}
		$newrev = $differenceEngine->getNewRevision();
		if ( !$newrev ) {
			return true;
		}
		$newId = $newrev->getId();
		if ( $newrev->isDeleted( RevisionRecord::DELETED_TEXT ) || $newId < 1 || $title->getLatestRevID() == $newId ) {
			return true;
		}
		if ( $newId < 1 ) {
			return true;
		}
		$link = ALSpecialRevisionLock::linkToPage( $title, [ $newId ] );
		$tag = Xml::tags( 'span', [ 'class' => 'mw-revdelundel-link' ], wfMessage( 'parentheses' )->rawParams( $link )->escaped() );
		$newHeader = str_replace( '<div id="mw-diff-ntitle4">', '<div id="mw-diff-ntitle6">' . $tag . '</div>' . '<div id="mw-diff-ntitle4">', $newHeader );
	}

	/**
	 * get group links for messages
	 * @param string $right
	 * @return array
	 */
	private static function getLinks( string $right ) {
		$groups = MediaWikiServices::getInstance()->getGroupPermissionsLookup()->getGroupsWithPermission( $right );
		$links = [];
		foreach ( $groups as $group ) {
			$links[] = UserGroupMembership::getLink( $group, RequestContext::getMain(), "wiki" );
		}
		return $links;
	}

	/**
	 * @param int $id page id or revision id
	 * @param string $type page or revision
	 * @return string|int
	 * @throws Error if not page or revision
	 */
	private function getCachedvalue( int $id, string $type ) {
		$id = (int)$id;
		$key = '';
		if ( $type === 'page' ) {
			$key = $this->cache->makeKey( 'aspaklarya-lockdown', $id );
		} elseif ( $type === 'revision' ) {
			$key = $this->cache->makeKey( 'aspaklarya-lockdown', 'revision', $id );
		} else {
			throw new InvalidArgumentException( 'Invalid type: ' . $type );
		}

		return $this->cache->getWithSetCallback(
			$key,
			$this->cache::TTL_MONTH,
			function () use ( $id, $type ) {
				if ( $type === 'page' ) {
					$pageElimination = ALDBData::getPageLimitation( $id );
					if ( !$pageElimination ) {
						return 'none';
					}
					return $pageElimination;
				} else {
					$db = $this->loadBalancer->getConnection( DB_REPLICA );
					$res = $db->newSelectQueryBuilder()
						->select( [ "alr_rev_id" ] )
						->from( ALDBData::PAGES_REVISION_NAME )
						->where( [ "alr_rev_id" => $id ] )
						->caller( __METHOD__ )
						->fetchRow();
					if ( $res !== false ) {
						return 1;
					}
					return 0;
				}
			}
		);
	}
}
