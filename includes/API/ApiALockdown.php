<?php

namespace MediaWiki\Extension\AspaklaryaLockDown\API;

use ApiBase;
use ApiWatchlistTrait;
use ManualLogEntry;
use MediaWiki\MediaWikiServices;
use MediaWiki\Permissions\PermissionStatus;
use MediaWiki\Title\Title;
use Status;
use Wikimedia\ParamValidator\ParamValidator;

/**
 * API module to lockdown a page
 * @ingroup API
 */
class ApiALockdown extends ApiBase {

	use ApiWatchlistTrait;

	public function execute() {
		// Get parameters
		$params = $this->extractRequestParams();

		$this->requireOnlyOneParameter( $params, 'title', 'pageid' );
		if ( !isset( $params['level'] ) || !in_array( $params['level'], [ 'none', 'create', 'read', 'edit' ] ) ) {
			$this->dieWithError( 'apierror-aspaklarya_lockdown-missinglevel' );
		}

		$pageObj = $this->getTitleOrPageId( $params, 'fromdbmaster' );
		$status = new PermissionStatus();
		$this->getAuthority()->authorizeWrite( 'aspaklarya_lockdown', $pageObj, $status );
		if ( !$status->isGood() ) {
			$this->getUser()->spreadAnyEditBlock();
			$this->dieStatus( $status );
		}
		$titleObj = $pageObj->getTitle();
		if ( $titleObj->isSpecialPage() ) {
			$this->dieWithError( 'apierror-aspaklarya_lockdown-invalidtitle' );
		}
		$user = $this->getUser();
		$watch = $params['watchlist'];
		$watchlistExpiry = $this->getExpiryFromParams( $params );
		$this->setWatch( $watch, $titleObj, $user, 'watchdefault', $watchlistExpiry );

		$status = $this->doUpdateRestrictions( $params['level'], $params['reason'], $titleObj );
		if ( !$status->isOK() ) {
			$this->dieStatus( $status );
		}

		$res = [
			'title' => $titleObj->getPrefixedText(),
			'reason' => $params['reason'],
			'status' => 'Succes',
			'level' => $params['level']
		];

		$result = $this->getResult();
		$result->addValue( null, $this->getModuleName(), $res );
	}

	/**
	 * Update the article's restriction field, and leave a log entry.
	 * This works for protection both existing and non-existing pages.
	 *
	 * @param string $limit edit|read|create|""
	 * @param string $reason
	 * @return Status Status object; if action is taken, $status->value is the log_id of the
	 *   protection log entry.
	 */
	public function doUpdateRestrictions(
		string $limit,
		$reason,
		Title $title,
	) {
		$readOnlyMode = MediaWikiServices::getInstance()->getReadOnlyMode();
		if ( $readOnlyMode->isReadOnly() ) {
			return Status::newFatal( wfMessage( 'readonlytext', $readOnlyMode->getReason() ) );
		}
		$pagesLockdTable = 'aspaklarya_lockdown_pages';
		$connection = MediaWikiServices::getInstance()->getDBLoadBalancer()->getConnection( DB_PRIMARY );

		$isRestricted = false;
		$restrict = $limit != 'none';
		$changed = false;

		$id = $title->getId();
		if ( $id > 0 ) {
			$restriction = $connection->newSelectQueryBuilder()
				->select( [ "al_read_allowed", "al_id" ] )
				->from( $pagesLockdTable )
				->where( [ "al_page_id" => $id ] )
				->caller( __METHOD__ )
				->fetchRow();
			if ( $restriction != false ) {
				$isRestricted = true;
			}
			if ( ( !$isRestricted && $restrict ) ||
				( $isRestricted &&
					( $limit == 'read' && 0 != $restriction->al_read_allowed ) ||
					( $limit == 'edit' && 1 != $restriction->al_read_allowed ) ||
					$limit == 'none' )
			) {
				$changed = true;
			}
		} else {
			$restriction = $connection->newSelectQueryBuilder()
				->select( [ "al_page_namespace", "al_page_title", "al_lock_id" ] )
				->from( "aspaklarya_lockdown_create_titles" )
				->where( [ "al_page_namespace" => $title->getNamespace(), "al_page_title" => $title->getDBkey() ] )
				->caller( __METHOD__ )
				->fetchRow();

			if ( $restriction != false ) {
				$isRestricted = true;
			}
			if ( ( !$isRestricted && $restrict ) || ( $isRestricted && $limit == 'none' ) ) {
				$changed = true;
			}
		}

		// If nothing has changed, do nothing
		if ( !$changed ) {
			return Status::newGood();
		}

		if ( !$restrict ) { // No restriction at all means unlock
			$logAction = 'unlock';
		} elseif ( $isRestricted ) {
			$logAction = 'modify';
		} else {
			$logAction = 'lock';
		}

		$dbw = MediaWikiServices::getInstance()->getDBLoadBalancer()->getConnection( DB_PRIMARY );
		$logParamsDetails = [
			'type' => $logAction,
			'level' => $limit,
		];
		$relations = [];
		if ( $id > 0 ) { // lock of existing page
			if ( $limit == 'create' ) {
				return Status::newFatal( wfMessage( 'apierror-aspaklarya_lockdown-invalidlevel' ) );
			}
			if ( $isRestricted ) {
				if ( $restrict ) {
					$dbw->update(
						$pagesLockdTable,
						[ 'al_read_allowed' => $limit == 'read' ? 0 : 1 ],
						[ 'al_page_id' => $id ],
						__METHOD__
					);
					$relations['al_id'] = $restriction->al_id;
				} else {
					$dbw->delete(
						$pagesLockdTable,
						[ 'al_page_id' => $id ],
						__METHOD__
					);
				}
			} else {
				$dbw->insert(
					$pagesLockdTable,
					[ 'al_page_id' => $id, 'al_read_allowed' => $limit == 'read' ? 0 : 1 ],
					__METHOD__

				);
				$relations['al_id'] = $dbw->insertId();
			}
			$cache = MediaWikiServices::getInstance()->getMainWANObjectCache();
			$cacheKey = $cache->makeKey( 'aspaklarya-lockdown', $title->getArticleID() );
			$cache->delete( $cacheKey );
		} else { // lock of non-existing page (also known as "title protection")

			if ( $limit == 'create' ) {
				$dbw->insert(
					'aspaklarya_lockdown_create_titles',
					[
						'al_page_namespace' => $title->getNamespace(),
						'al_page_title' => $title->getDBkey(),
					],
					__METHOD__
				);
				$relations['al_lock_id'] = $dbw->insertId();
			} elseif ( $limit == 'none' ) {
				$dbw->delete(
					'aspaklarya_lockdown_create_titles',
					[ "al_lock_id" => $restriction->al_lock_id ],
					__METHOD__
				);
			} else {
				return Status::newFatal( wfMessage( 'apierror-aspaklarya_lockdown-invalidlevel' ) );
			}
		}
		$params = [];
		if ( $logAction === "modify" ) {
			$params = [
				"4::description" => wfMessage( $restriction->al_read_allowed == 0 ? "lock-read" : "lock-edit" ),
				"5::description" => wfMessage( "$logAction-$limit" ),
				"detailes" => $logParamsDetails,
			];
		} else {
			$params = [
				"4::description" => wfMessage( "$logAction-$limit" ),
				"detailes" => $logParamsDetails,
			];
		}

		// Update the aspaklarya log
		$logEntry = new ManualLogEntry( 'aspaklarya', $logAction );
		$logEntry->setTarget( $title );
		$logEntry->setRelations( $relations );
		$logEntry->setComment( $reason );
		$logEntry->setPerformer( $this->getUser() );
		$logEntry->setParameters( $params );

		$logId = $logEntry->insert();

		return Status::newGood( $logId );
	}

	/** @inheritDoc */
	public function getAllowedParams() {
		return [
			'title' => [
				ParamValidator::PARAM_TYPE => 'string',
				ApiBase::PARAM_HELP_MSG => 'apihelp-aspaklarya_lockdown-param-title',
			],
			'pageid' => [
				ParamValidator::PARAM_TYPE => 'integer',
				ApiBase::PARAM_HELP_MSG => 'apihelp-aspaklarya_lockdown-param-pageid',
			],
			'level' => [
				ParamValidator::PARAM_DEFAULT => 'none',
				ParamValidator::PARAM_TYPE => [ 'none', 'create', 'read', 'edit' ],
				ParamValidator::PARAM_REQUIRED => true,
				ApiBase::PARAM_HELP_MSG => 'apihelp-aspaklarya_lockdown-param-level',
			],
			'reason' => '',
			'token' => null,
		] + $this->getWatchlistParams();
	}

	public function mustBePosted() {
		return true;
	}

	public function needsToken() {
		return 'csrf';
	}

	public function isWriteMode() {
		return true;
	}

	/**
	 * @inheritDoc
	 */
	public function getExamples() {
		return [
			'api.php?title=Main_Page&action=aspaklarya_lockdown&level=read&token=TOKEN' => 'apihelp-aspaklaryalockdown-example-1'
		];
	}

	public function getHelpUrls() {
		return [ '' ];
	}
}
