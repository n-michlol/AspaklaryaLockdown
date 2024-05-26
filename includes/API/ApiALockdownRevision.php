<?php

namespace MediaWiki\Extension\AspaklaryaLockDown\API;

use ApiBase;
use ApiWatchlistTrait;
use ManualLogEntry;
use MediaWiki\Extension\AspaklaryaLockDown\ALRevLockRevisionList;
use MediaWiki\MediaWikiServices;
use MediaWiki\Permissions\PermissionStatus;
use MediaWiki\Revision\RevisionRecord;
use MediaWiki\Title\Title;
use RevDelList;
use Status;
use Wikimedia\ParamValidator\ParamValidator;

/**
 * API module to lockdown a page
 * @ingroup API
 */
class ApiALockdownRevision extends ApiBase {

	use ApiWatchlistTrait;

	/** @var RevDelList */
	private $revDelList;

	/** @var int[] */
	private $ids;

	/** @var Title */
	private $targetObj;

	public function execute() {
		// Get parameters
		$params = $this->extractRequestParams();

		if ( !isset( $params['revids'] ) || !isset( $params['hide'] )  ) {
			$this->dieWithError( 'apierror-aspaklarya_lockdown-missingparams' );
		}

		$ids = $params['revids'];
		if ( $ids !== null ) {
			$this->ids = explode( '|', $ids );
		} else {
			$this->ids = [];
		}
		
		$this->ids = array_unique( array_filter( $this->ids ) );

		if ( count( $this->ids ) === 0 ) {
			$this->dieWithError( 'apierror-aspaklarya_lockdown-missingparams' );
		}

		$title = $params['target'];
		if ( $title !== null ) {
			$this->targetObj = Title::newFromText( $title );
		} else {
			$this->targetObj = null;
		}
		$this->targetObj = ALRevLockRevisionList::suggestTarget( $this->targetObj,$this->ids );
		if($this->targetObj == null){
			$this->dieWithError( 'apierror-aspaklarya_lockdown-invalidtitle' );
		}
		$statusA = new PermissionStatus();
		$this->getAuthority()->authorizeWrite( 'aspaklarya_lockdown', $this->targetObj, $statusA );
		if ( !$statusA->isGood() ) {
			$this->getUser()->spreadAnyEditBlock();
			$this->dieStatus( $statusA );
		}
		$titleObj = Title::newFromID( $this->targetObj->getId() );		
		if ( $titleObj->isSpecialPage() ) {
			$this->dieWithError( 'apierror-aspaklarya_lockdown-invalidtitle' );//TODO: add to i18n
		}

		$list = $this->getList();
		$list->reset();

		if ($list->length() == 0) {
			$this->dieWithError( 'apierror-aspaklarya_lockdown-invalidrevid' );//TODO: add to i18n
		}
		if($list->areAnyDeleted()){
			$this->dieWithError( 'apierror-aspaklarya_lockdown-deletedrevid' );//TODO: add to i18n
		}

		$user = $this->getUser();
		$watch = $params['watchlist'];
		$watchlistExpiry = $this->getExpiryFromParams( $params );
		$this->setWatch( $watch, $titleObj, $user, 'watchdefault', $watchlistExpiry );

		$status = $list->setVisibility([
			'value' => $params['hide'] ? 'hide' : 'unhide',
			'comment' => $params['reason'],
		]);
		if ( !$status->isOK() ) {
			$this->dieStatus( $status );
		}
		$idsToReturn = $list->getSuccessIds();

		$res = [
			'title' => $titleObj->getPrefixedText(),
			'reason' => $params['reason'],
			'status' => 'Succes',
			'revisions' => $idsToReturn,
			'hide' => $params['hide'],
		];

		$result = $this->getResult();
		$result->addValue( null, $this->getModuleName(), $res );
	}

	/**
	 * Get the list object for this request
	 * @return ALRevLockRevisionList
	 */
	protected function getList() {
		if ( $this->revDelList === null ) {
            $objectFactory = MediaWikiServices::getInstance()->getObjectFactory();
            $this->revDelList = $objectFactory->createObject(
                [
                    'class' => ALRevLockRevisionList::class,
                    'services' => [
                        'DBLoadBalancerFactory',
                        'HookContainer',
                        'HtmlCacheUpdater',
                        'RevisionStore',
                    ]
                ],
                [
                    'extraArgs' => [ $this->getContext(), $this->targetObj, $this->ids ],
                    'assertClass' => RevDelList::class,
                ]
            );
		}

		return $this->revDelList;
	}

	// /**
	//  * Update the article's restriction field, and leave a log entry.
	//  * This works for protection both existing and non-existing pages.
	//  *
	//  * @param RevisionRecord $revision
	//  * @param string $reason
	//  * @param bool $hide
	//  * @param Title $title
	//  * @return Status Status object; if action is taken, $status->value is the log_id of the
	//  *   lockdown log entry.
	//  */
	// public function doUpdateRestrictions(
	// 	RevisionRecord $revision,
	// 	$reason,
	// 	bool $hide,
	// 	Title $title,
	// ) {
	// 	$readOnlyMode = MediaWikiServices::getInstance()->getReadOnlyMode();
	// 	if ( $readOnlyMode->isReadOnly() ) {
	// 		return Status::newFatal( wfMessage( 'readonlytext', $readOnlyMode->getReason() ) );
	// 	}
	// 	$revisionsLockdTable = 'aspaklarya_lockdown_revisions';
	// 	$connection = MediaWikiServices::getInstance()->getDBLoadBalancer()->getConnection( DB_PRIMARY );

	// 	$current = $connection->newSelectQueryBuilder()
	// 		->select( 'alr_rev_id' )
	// 		->from( $revisionsLockdTable )
	// 		->where( [ 'alr_rev_id' => $revision->getId() ] )
	// 		->caller( __METHOD__ )
	// 		->fetchRow();

	// 	if ( $current === $hide /* both are false */|| $hide && $current !== false /* already hidden */ ) {
	// 		return Status::newGood();
	// 	}

	// 	$id = $title->getId();
	// 	$logAction = $hide ? 'hide' : 'unhide';

	// 	$dbw = MediaWikiServices::getInstance()->getDBLoadBalancer()->getConnection( DB_PRIMARY );
	// 	$logParamsDetails = [
	// 		'type' => $logAction,
	// 	];
	// 	$relations = [];
	// 	if ( $hide ) {
	// 		$dbw->insert(
	// 			$revisionsLockdTable,
	// 			[ 'alr_rev_id' => $revision->getId(), 'alr_page_id' => $id ],
	// 			__METHOD__
	// 		);
	// 		$relations = [ 'alr_id' => $dbw->insertId(), 'rev_id' => $revision->getId()];
	// 	} else {
	// 		$dbw->delete(
	// 			$revisionsLockdTable,
	// 			[ 'alr_rev_id' => $revision->getId(), 'alr_page_id' => $id ],
	// 			__METHOD__
	// 		);
	// 		$relations = [ 'rev_id' => $revision->getId() ];
	// 	}
	// 	$cache = MediaWikiServices::getInstance()->getMainWANObjectCache();
	// 	$cache->delete( $cache->makeKey( "aspaklarya-lockdown", "revision", $revision->getId() ) );

	// 	$params = [
	// 		"4::description" => wfMessage( "lock-$logAction" ),
	// 		"5::revid" => $revision->getId(),
	// 		"detailes" => $logParamsDetails,
	// 	];

	// 	// Update the aspaklarya log
	// 	$logEntry = new ManualLogEntry( 'aspaklarya', $logAction );
	// 	$logEntry->setTarget( $title );
	// 	$logEntry->setAssociatedRevId( $revision->getId() );
	// 	$logEntry->setRelations( $relations );
	// 	$logEntry->setComment( $reason );
	// 	$logEntry->setPerformer( $this->getUser() );
	// 	$logEntry->setParameters( $params );

	// 	$logId = $logEntry->insert();

	// 	return Status::newGood( $logId );
	// }

	/** @inheritDoc */
	public function getAllowedParams() {
		return [
			'revids' => [
				ParamValidator::PARAM_TYPE => 'integer',
				ParamValidator::PARAM_ISMULTI => true,
				ParamValidator::PARAM_REQUIRED => true,
				ApiBase::PARAM_HELP_MSG => 'apihelp-aspaklarya_lockdown-param-pageid',
			],
			'target' => [
				ParamValidator::PARAM_TYPE => 'title',
				ApiBase::PARAM_HELP_MSG => 'apihelp-aspaklarya_lockdown-param-target',
			],
			'hide' => [
				ParamValidator::PARAM_TYPE => 'boolean',
				ParamValidator::PARAM_REQUIRED => true,
				ApiBase::PARAM_HELP_MSG => 'apihelp-aspaklarya_lockdown-param-hide',
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
			'api.php?revid=1&action=aspaklarya_lockdown&hide=1&token=TOKEN' => 'apihelp-aspaklaryalockdown-example-1'
		];
	}

	public function getHelpUrls() {
		return [ '' ];
	}
}
