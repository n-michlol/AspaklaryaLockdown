<?php

namespace MediaWiki\Extension\PageLockdown\Hooks;

use MediaWiki\Api\ApiComparePages;
use MediaWiki\Api\ApiQueryAllRevisions;
use MediaWiki\Api\ApiQueryInfo;
use MediaWiki\Api\ApiQueryRevisions;
use MediaWiki\Api\ApiResult;
use MediaWiki\Api\Hook\APIAfterExecuteHook;
use MediaWiki\Api\Hook\ApiCheckCanExecuteHook;
use MediaWiki\Api\Hook\APIGetAllowedParamsHook;
use MediaWiki\Api\Hook\APIQueryAfterExecuteHook;
use MediaWiki\Api\Hook\ApiQueryBaseBeforeQueryHook;
use MediaWiki\Extension\PageLockdown\PageLockdownManager;
use MediaWiki\Title\Title;
use Wikimedia\ObjectCache\WANObjectCache;
use Wikimedia\ParamValidator\ParamValidator;
use Wikimedia\Rdbms\ILoadBalancer;

class ApiHooks implements
	ApiCheckCanExecuteHook,
	APIQueryAfterExecuteHook,
	ApiQueryBaseBeforeQueryHook,
	APIGetAllowedParamsHook,
	APIAfterExecuteHook
{
	private ILoadBalancer $loadBalancer;
	private WANObjectCache $cache;

	public function __construct( ILoadBalancer $loadBalancer, WANObjectCache $cache ) {
		$this->loadBalancer = $loadBalancer;
		$this->cache = $cache;
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
			$main = new PageLockdownManager( $this->loadBalancer, $this->cache, $title, $user );
			if ( !$main->isUserAllowed( $action ) ) {
				$module->dieWithError( $main->getErrorMessage( $action, false ) );
			}
		}
	}

	/**
	 * @inheritDoc
	 */
	public function onAPIGetAllowedParams( $module, &$params, $flags ) {
		if ( $module instanceof ApiQueryInfo ) {
			$params['prop'][ParamValidator::PARAM_TYPE][] = 'pagelockdownlevel';
		}
	}

	/**
	 * @inheritDoc
	 */
	public function onApiQueryBaseBeforeQuery( $module, &$tables, &$fields, &$conds, &$query_options, &$join_conds, &$hookData ) {
		if ( $module instanceof ApiQueryAllRevisions || $module instanceof ApiQueryRevisions ) {
			if ( !$module->getAuthority()->isAllowed( 'page-lockdown-read' ) ) {
				$tables['al'] = 'page_lockdown_revisions';
				$join_conds['al'] = [
					'LEFT JOIN',
					[ 'al.plr_rev_id = rev_id' ],
				];
				$conds['al.plr_rev_id'] = null;
			}
		}
	}

	/**
	 * @inheritDoc
	 */
	public function onAPIQueryAfterExecute( $module ) {
		if ( $module instanceof ApiQueryInfo ) {
			$params = $module->extractRequestParams();
			if ( !isset( $params['prop'] ) || $params['prop'] === null || !is_array( $params['prop'] ) ) {
				return;
			}
			if ( !in_array( 'pagelockdownlevel', $params['prop'] ) ) {
				return;
			}
			$result = $module->getResult();
			$data = (array)$result->getResultData( [ 'query', 'pages' ], [ 'Strip' => 'all' ] );
			if ( !$data ) {
				return true;
			}
			$missing = [];
			$existing = [];
			foreach ( $data as $index => $pageInfo ) {
				if ( !is_array( $pageInfo ) || (int)$pageInfo['ns'] < 0 ) {
					continue;
				}
				if ( isset( $pageInfo['missing'] ) ) {
					$title = Title::newFromText( $pageInfo['title'] );
					if ( !$title || $title->isSpecialPage() ) {
						continue;
					}
					$missing[$title->getPrefixedText()] = [ 'title' => $title, 'index' => $index ];
				} else {
					$title = Title::newFromID( $pageInfo['pageid'] );
					if ( !$title || $title->isSpecialPage() ) {
						continue;
					}
					$existing[$title->getId()] = [ 'title' => $title, 'index' => $index ];
				}
			}
			$db = $this->loadBalancer->getConnection( DB_REPLICA );
			if ( !empty( $missing ) ) {
				$where = [];
				foreach ( $missing as  $p ) {
					$where[] = $db->makeList( [ 'plt_page_namespace' => $p['title']->getNamespace(), 'plt_page_title' => $p['title']->getDBkey() ], LIST_AND );
				}
				$res = $db->newSelectQueryBuilder()
					->select( [ 'plt_page_namespace', 'plt_page_title' ] )
					->from( PageLockdownManager::getTitlesTableName() )
					->where( $db->makeList( $where, LIST_OR ) )
					->caller( __METHOD__ )
					->fetchResultSet();

				foreach ( $res as $row ) {
					$t = Title::makeTitle( $row->plt_page_namespace, $row->plt_page_title );
					$index = $missing[$t->getPrefixedText()]['index'];
					$result->addValue( [ 'query', 'pages', $index ], 'pagelockdownlevel', 'create', ApiResult::ADD_ON_TOP );
					unset( $missing[$t->getPrefixedText()] );
				}
				if ( !empty( $missing ) ) {
					foreach ( $missing as $p ) {
						$result->addValue( [ 'query', 'pages', $p['index'] ], 'pagelockdownlevel', 'none', ApiResult::ADD_ON_TOP );
					}
				}
			}
			if ( !empty( $existing ) ) {
				$ids = array_keys( $existing );
				$res = $db->newSelectQueryBuilder()
					->select( [ 'pl_page_id', 'pl_level' ] )
					->from( PageLockdownManager::getPagesTableName() )
					->where( [ 'pl_page_id' => array_map( 'intval', $ids ) ] )
					->caller( __METHOD__ )
					->fetchResultSet();

				foreach ( $res as $row ) {
					$index = $existing[$row->pl_page_id]['index'];
					$result->addValue( [ 'query', 'pages', $index ], 'pagelockdownlevel', PageLockdownManager::getLevelFromBit( (int)$row->pl_level ), ApiResult::ADD_ON_TOP );
					unset( $existing[$row->pl_page_id] );
				}
				if ( !empty( $existing ) ) {
					foreach ( $existing as $p ) {
						$result->addValue( [ 'query', 'pages', $p['index'] ], 'pagelockdownlevel', 'none', ApiResult::ADD_ON_TOP );
					}
				}
			}
		}
		return true;
	}

	/**
	 * @inheritDoc
	 */
	public function onAPIAfterExecute( $module ) {
		if ( $module instanceof ApiComparePages ) {
			$result = $module->getResult();
			$data = (array)$result->getResultData( [ 'compare' ], [ 'Strip' => 'all' ] );
			if ( !$data ) {
				return true;
			}
			$from = (int)$data['fromid'] ?? 0;
			$to = (int)$data['toid'] ?? 0;
			if ( !$from && !$to ) {
				return true;
			}
			$user = $module->getAuthority();
			if ( $from !== 0 ) {
				$fromTitle = Title::newFromID( $from );
				if( $fromTitle ) {
					$main = new PageLockdownManager( $this->loadBalancer, $this->cache, $fromTitle, $user );
					if ( !$main->isUserAllowedToRead() || !$main->isUserIntrestedToRead() ) {
						$module->dieWithError( $main->getErrorMessage( 'read', false ) );
					}
				}
			}
			if ( $to !== 0 && $to !== $from ) {
				$toTitle = Title::newFromID( $to );
				if( $toTitle ) {
					$main = new PageLockdownManager( $this->loadBalancer, $this->cache, $toTitle, $user );
					if ( !$main->isUserAllowedToRead() || !$main->isUserIntrestedToRead() ) {
						$module->dieWithError( $main->getErrorMessage( 'read', false ) );
					}
				}
			}
		}
	}
}
