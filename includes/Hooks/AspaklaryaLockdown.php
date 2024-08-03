<?php

namespace MediaWiki\Extension\AspaklaryaLockDown\Hooks;

use Article;
use ManualLogEntry;
use MediaWiki\Extension\AspaklaryaLockDown\ALDBData;
use MediaWiki\Extension\AspaklaryaLockDown\Main;
use MediaWiki\Hook\BeforePageDisplayHook;
use MediaWiki\Hook\BeforeParserFetchTemplateRevisionRecordHook;
use MediaWiki\Hook\EditPage__showEditForm_initialHook;
use MediaWiki\Hook\EditPage__showReadOnlyForm_initialHook;
use MediaWiki\Hook\GetLinkColoursHook;
use MediaWiki\Hook\InfoActionHook;
use MediaWiki\Hook\RandomPageQueryHook;
use MediaWiki\Hook\SkinTemplateNavigation__UniversalHook;
use MediaWiki\Linker\LinkTarget;
use MediaWiki\MediaWikiServices;
use MediaWiki\Page\Hook\PageDeleteCompleteHook;
use MediaWiki\Page\ProperPageIdentity;
use MediaWiki\Permissions\Authority;
use MediaWiki\Permissions\Hook\GetUserPermissionsErrorsHook;
use MediaWiki\Preferences\Hook\GetPreferencesHook;
use MediaWiki\Revision\RevisionRecord;
use MediaWiki\Title\Title;
use MediaWiki\User\UserGroupMembership;
use RequestContext;
use WANObjectCache;
use Wikimedia\Rdbms\ILoadBalancer;

/**
 * @ingroup Hooks
 */
class AspaklaryaLockdown implements
	GetUserPermissionsErrorsHook,
	BeforeParserFetchTemplateRevisionRecordHook,
	PageDeleteCompleteHook,
	InfoActionHook,
	BeforePageDisplayHook,
	SkinTemplateNavigation__UniversalHook,
	GetLinkColoursHook,
	EditPage__showReadOnlyForm_initialHook,
	EditPage__showEditForm_initialHook,
	RandomPageQueryHook,
	GetPreferencesHook
{

	private ILoadBalancer $loadBalancer;
	private WANObjectCache $cache;

	public function __construct() {
		$service = MediaWikiServices::getInstance();
		$this->loadBalancer = $service->getDBLoadBalancer();
		$this->cache = $service->getMainWANObjectCache();
	}

	/**
	 * @inheritDoc
	 */
	public function onRandomPageQuery( &$tables, &$conds, &$joinConds ) {
		$ptn = Main::getPagesTableName();
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
		$title = $editor->getTitle();
		$mannager = new Main( $this->loadBalancer, $this->cache, $title, $user );
		if ( !$mannager->isUserAllowedToEdit() ) {
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
		if ( $action === 'upload' ) {
			return;
		}
		$main = new Main( $this->loadBalancer, $this->cache, $title, $user );

		if ( !$main->isExistingPage() || $action === 'create' || $action === 'createpage' || $action === 'createtalk' ) {
			if ( ( $action == 'aspaklarya_lockdown' || $action == 'read' ) && $main->isUserAllowedToRead() ) {
				return;
			}
			if ( !$main->isUserAllowedToCreate() ) {
				$result = $main->getErrorMessage( 'create', false );
				return false;
			}
			return;
		}

		$article = new Article( $title );
		$oldId = $article->getOldID();
		$context = RequestContext::getMain();
		$request = $context->getRequest();
		$diff = $request->getInt( 'diff' );

		if ( $action === 'edit' ) {
			if ( !$main->isUserAllowedToEdit() ) {
				$result = $main->getErrorMessage( 'edit', false, $context );
				return false;
			}
			if ( $oldId == 0 && $diff == 0 ) {
				return;
			}
		}

		if ( !$main->isUserAllowedToRead() ) {
			$result = $main->getErrorMessage( 'read', false, $context );
			return false;
		}
		if ( !$main->isUserIntrestedToRead() ) {
			$result = $main->getErrorMessage( 'read', true, $context );
			return false;
		}
		if ( $user->isAllowed( 'aspaklarya-lock-revisions' ) ) {
			return;
		}
		if ( $oldId > 0 ) {
			$locked = ALDBData::isRevisionLocked( $oldId );
			if ( $locked ) {
				$result = [ "aspaklarya_lockdown-rev-error", implode( ', ', self::getLinks( 'aspaklarya-lock-revisions' ) ), wfMessage( 'aspaklarya-' . $action ) ];
				return false;
			}
		}
		if ( $diff > 0 ) {
			$locked = ALDBData::isRevisionLocked( $diff );
			if ( $locked ) {
				$result = [ "aspaklarya_lockdown-rev-error", implode( ', ', self::getLinks( 'aspaklarya-lock-revisions' ) ), wfMessage( 'aspaklarya-' . $action ) ];
				return false;
			}
		}
	}

	/**
	 * @inheritDoc
	 */
	public function onBeforeParserFetchTemplateRevisionRecord( ?LinkTarget $contextTitle, LinkTarget $title, bool &$skip, ?RevisionRecord &$revRecord ) {
		$user = RequestContext::getMain()->getUser();
		// get the title id
		$main = new Main( $this->loadBalancer, $this->cache, Title::newFromLinkTarget( $title ), $user );
		if ( !$main->isExistingPage() ) {
			$skip = false;
			return;
		}

		if ( !$main->isUserAllowedToRead() ) {
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
		$dbw->delete( Main::getPagesTableName(), [ 'al_page_id' => $pageID ], __METHOD__ );
		$revisions = ALDBData::getLockedRevisions( $pageID );
		if ( $revisions !== false ) {
			$dbw->delete( Main::getRevisionsTableName(), [ 'alr_page_id' => $pageID ], __METHOD__ );
			foreach ( $revisions as $revision ) {
				$this->cache->delete( $this->cache->makeKey( 'aspaklarya-lockdown', 'revision', $revision->alr_rev_id ) );
			}
		}

		$cacheKey = $this->cache->makeKey( 'aspaklarya-lockdown', 'v1', $pageID );
		$this->cache->delete( $cacheKey );
	}

	/**
	 * @inheritDoc
	 */
	public function onInfoAction( $context, &$pageInfo ) {
		$main = new Main( $this->loadBalancer, $this->cache, $context->getTitle(), $context->getUser() );
		if ( $main->isExistingPage() ) {
			$pageElimination = $main->getLevel();

			$info = 'aspaklarya-info-' . $pageElimination === '' ? 'none' : $pageElimination;

			$pageInfo['header-basic'][] = [
				$context->msg( 'aspaklarya-info-label' ),
				$context->msg( $info ),
			];
		}
	}

	/**
	 * @inheritDoc
	 */
	public function onGetPreferences( $user, &$preferences ) {
		Main::getPerferences( $user, $preferences );
	}

	/**
	 * @inheritDoc
	 */
	public function onBeforePageDisplay( $out, $skin ): void {
		$title = $out->getTitle();
		if ( !$title || !$title->canExist() ) {
			return;
		}
		$level = Main::getLevelFromCache( $title, null, null );
		$userOptions = Main::getBodyClasses( $out->getUser() );
		$out->addBodyClasses( $userOptions );
		$out->addJsConfigVars( [
			'aspaklaryaLockdown' => $level === '' ? 'none' : $level,
			'ALLinksUserPerferences' => $userOptions,
		] );
		$out->addModuleStyles( [ 'ext.aspaklaryaLockDown.styles' ] );
		$out->addModules( [ 'ext.aspaklaryalockdown.messages' ] );
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
		$main = new Main( $this->loadBalancer, $this->cache, $title, $sktemplate->getUser() );
		if ( !$main->isExistingPage() ) {
			$pageElimination = $main->getLevel() === 'create';
			$text = $pageElimination === true ? 'aspaklarya-lockdown-create-unlock' : 'aspaklarya-lockdown-create-lock';
			$pos = $pageElimination === true ? 'views' : 'actions';
		} else {
			$cached = $main->getLevel();
			$text = $cached === '' ? 'aspaklarya-lockdown-lock' : 'aspaklarya-lockdown-change';
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
	 * @inheritDoc
	 */
	public function onGetLinkColours( $linkcolour_ids, &$colours, $title ) {
		// dont check special pages
		$linkcolour_ids = array_filter( $linkcolour_ids, static function ( $id ) {
			return $id > 0;
		}, ARRAY_FILTER_USE_KEY );

		$redirects = array_filter( $linkcolour_ids, static function ( $pdbk ) use ( $colours ) {
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
		if ( !empty( $regulars ) ) {
			$res = $db->newSelectQueryBuilder()
				->select( [ 'al_page_id', 'al_level' ] )
				->from( Main::getPagesTableName() )
				->where( [ 'al_page_id' => array_map( 'intval', array_keys( $regulars ) ) ] )
				->caller( __METHOD__ )
				->fetchResultSet();

			foreach ( $res as $row ) {
				$level = Main::getLevelFromBit( $row->al_level );
				$class = ' aspaklarya-' . $level . '-locked';
				$colours[$regulars[$row->al_page_id]] .= $class;
				if ( !empty( $redirects ) && isset( $redirects[$row->al_page_id] ) ) {
					$colours[$redirects[$row->al_page_id]] .= $colours[$regulars[$row->al_page_id]];
					unset( $redirects[$row->al_page_id] );
				}
			}
		}
		return true;
	}

	/**
	 * get group links for messages
	 * @param string $right
	 * @return array
	 */
	public static function getLinks( string $right ) {
		$groups = MediaWikiServices::getInstance()->getGroupPermissionsLookup()->getGroupsWithPermission( $right );
		$links = [];
		foreach ( $groups as $group ) {
			$links[] = UserGroupMembership::getLinkWiki( $group, RequestContext::getMain() );
		}
		return $links;
	}

}
