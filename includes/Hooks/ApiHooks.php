<?php

namespace MediaWiki\Extension\AspaklaryaLockDown\Hooks;

use ApiQueryAllRevisions;
use ApiQueryInfo;
use ApiQueryRevisions;
use ApiResult;
use MediaWiki\Api\Hook\ApiCheckCanExecuteHook;
use MediaWiki\Api\Hook\APIGetAllowedParamsHook;
use MediaWiki\Api\Hook\APIQueryAfterExecuteHook;
use MediaWiki\Api\Hook\ApiQueryBaseBeforeQueryHook;
use MediaWiki\Extension\AspaklaryaLockDown\ALDBData;
use MediaWiki\Extension\AspaklaryaLockDown\AspaklaryaPagesLocker;
use MediaWiki\MediaWikiServices;
use MediaWiki\Title\Title;
use Wikimedia\ParamValidator\ParamValidator;

class ApiHooks implements
	ApiCheckCanExecuteHook,
	APIQueryAfterExecuteHook,
	ApiQueryBaseBeforeQueryHook,
	APIGetAllowedParamsHook
{
	/**
	 * @inheritDoc
	 */
	public function onApiCheckCanExecute( $module, $user, &$message ) {
		$params = $module->extractRequestParams();
		$page = $params['page'] ?? $page['title'] ?? null;

		if ( $page ) {
			$title = Title::newFromText( $page );
			$action = $module->isWriteMode() ? 'edit' : 'read';
			$ald = new AspaklaryaLockdown();
			$allowed = $ald->onGetUserPermissionsErrors( $title, $user, $action, $result );
			if ( $allowed === false ) {
				$module->dieWithError( $result );
			}
		}
	}

	/**
	 * @inheritDoc
	 */
	public function onAPIGetAllowedParams( $module, &$params, $flags ) {
		if ( $module instanceof ApiQueryInfo ) {
			$params['prop'][ParamValidator::PARAM_TYPE][] = 'allevel';
		}
	}

	/**
	 * @inheritDoc
	 */
	public function onApiQueryBaseBeforeQuery( $module, &$tables, &$fields, &$conds, &$query_options, &$join_conds, &$hookData ) {
		if ( $module instanceof ApiQueryAllRevisions || $module instanceof ApiQueryRevisions ) {
			if ( !$module->getAuthority()->isAllowed( 'aspaklarya-read-locked' ) ) {
				$tables['al'] = 'aspaklarya_lockdown_revisions';
				$join_conds['al'] = [
					'LEFT JOIN',
					[ 'al.alr_rev_id = rev_id' ],
				];
				$conds['al.alr_rev_id'] = null;
			}
		}
	}

	/**
	 * @inheritDoc
	 */
	public function onAPIQueryAfterExecute( $module ) {
		if ( $module instanceof ApiQueryInfo ) {
			$params = $module->extractRequestParams();
			if ( !isset( $params[ 'prop' ] ) || $params['prop'] === null || !is_array( $params['prop'] ) ) {
				return;
			}
			if ( !in_array( 'allevel', $params[ 'prop' ] ) ) {
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
				if ( !is_array( $pageInfo ) || (int)$pageInfo[ 'ns' ] < 0 ) {
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
			$db = MediaWikiServices::getInstance()->getDBLoadBalancer()->getConnection( DB_REPLICA );
			if ( !empty( $missing ) ) {
				$where = [];
				foreach ( $missing as  $p ) {
					$where[] = $db->makeList( [ 'al_page_namespace' => $p['title']->getNamespace(), 'al_page_title' => $p['title']->getDBkey() ], LIST_AND );
				}
				$res = $db->newSelectQueryBuilder()
					->select( [ "al_page_namespace", "al_page_title" ] )
					->from( "aspaklarya_lockdown_create_titles" )
					->where( $db->makeList( $where, LIST_OR ) )
					->caller( __METHOD__ )
					->fetchResultSet();

				foreach ( $res as $row ) {
					$t = Title::makeTitle( $row->al_page_namespace, $row->al_page_title );
					$index = $missing[$t->getPrefixedText()]['index'];
					$result->addValue( [ 'query', 'pages', $index ], 'allevel', 'create', ApiResult::ADD_ON_TOP );
					unset( $missing[$t->getPrefixedText()] );
				}
				if ( !empty( $missing ) ) {
					foreach ( $missing as $p ) {
						$result->addValue( [ 'query', 'pages', $p['index'] ], 'allevel', 'none', ApiResult::ADD_ON_TOP );
					}
				}
			}
			if ( !empty( $existing ) ) {
				$ids = array_keys( $existing );
				$res = $db->newSelectQueryBuilder()
					->select( [ "al_page_id", "al_read_allowed" ] )
					->from( ALDBData::PAGES_TABLE_NAME )
					->where( [ "al_page_id" => array_map( 'intval', $ids ) ] )
					->caller( __METHOD__ )
					->fetchResultSet();

				foreach ( $res as $row ) {
					$index = $existing[$row->al_page_id]['index'];
					$result->addValue( [ 'query', 'pages', $index ], 'allevel', AspaklaryaPagesLocker::getLevelFromBits( $row->al_read_allowed ), ApiResult::ADD_ON_TOP );
					unset( $existing[$row->al_page_id] );
				}
				if ( !empty( $existing ) ) {
					foreach ( $existing as $p ) {
						$result->addValue( [ 'query', 'pages', $p['index'] ], 'allevel', 'none', ApiResult::ADD_ON_TOP );
					}
				}
			}
		}
		return true;
	}

}
