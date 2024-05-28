<?php

namespace MediaWiki\Extension\AspaklaryaLockDown\API;

use ApiBase;
use ApiWatchlistTrait;
use MediaWiki\Extension\AspaklaryaLockDown\ALRevLockRevisionList;
use MediaWiki\MediaWikiServices;
use MediaWiki\Permissions\PermissionStatus;
use MediaWiki\Title\Title;
use RevDelList;
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

		if ( !isset( $params['revids'] ) || !isset( $params['hide'] ) ) {
			$this->dieWithError( 'apierror-aspaklarya_lockdown-missingparams' );
		}

		$this->ids = $params['revids'];
		if ( $this->ids !== null && !is_array( $this->ids ) ) {
			$this->ids = explode( '|', $this->ids );
		} elseif ( $this->ids === null ) {
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
		$this->targetObj = ALRevLockRevisionList::suggestTarget( $this->targetObj, $this->ids );
		if ( $this->targetObj == null ) {
			$this->dieWithError( 'apierror-aspaklarya_lockdown-invalidtitle' );
		}
		$statusA = new PermissionStatus();
		$this->getAuthority()->authorizeWrite( 'aspaklarya-lock-revisions', $this->targetObj, $statusA );
		if ( !$statusA->isGood() ) {
			$this->getUser()->spreadAnyEditBlock();
			$this->dieStatus( $statusA );
		}
		$titleObj = Title::newFromID( $this->targetObj->getId() );
		if ( $titleObj->isSpecialPage() ) {
			$this->dieWithError( 'apierror-aspaklarya_lockdown-invalidtitle' );
		}

		$list = $this->getList();
		$list->reset();

		if ( $list->length() == 0 ) {
			$this->dieWithError( 'apierror-aspaklarya_lockdown-invalidrevid' );
		}
		if ( $list->areAnyDeleted() ) {
			$this->dieWithError( 'apierror-aspaklarya_lockdown-deletedrevid' );
		}

		$user = $this->getUser();
		$watch = $params['watchlist'];
		$watchlistExpiry = $this->getExpiryFromParams( $params );
		$this->setWatch( $watch, $titleObj, $user, 'watchdefault', $watchlistExpiry );

		$status = $list->setVisibility( [
			'value' => $params['hide'] ? 'hide' : 'unhide',
			'comment' => $params['reason'],
		] );
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

	/** @inheritDoc */
	public function getAllowedParams() {
		return [
			'revids' => [
				ParamValidator::PARAM_TYPE => 'integer',
				ParamValidator::PARAM_ISMULTI => true,
				ParamValidator::PARAM_ISMULTI_LIMIT1 => 25,
				ParamValidator::PARAM_ISMULTI_LIMIT2 => 50,
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
			'api.php?revidד=1&action=aspaklaryalockdownrevision&hide=1&token=TOKEN' => 'apihelp-aspaklaryalockdownrevision-example-1'
		];
	}

	public function getHelpUrls() {
		return [ '' ];
	}
}
