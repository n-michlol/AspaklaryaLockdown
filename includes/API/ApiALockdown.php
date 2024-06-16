<?php

namespace MediaWiki\Extension\AspaklaryaLockDown\API;

use ApiBase;
use ApiWatchlistTrait;
use MediaWiki\Extension\AspaklaryaLockDown\AspaklaryaPagesLocker;
use MediaWiki\Permissions\PermissionStatus;
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
		
		if ( !isset( $params['level'] )  ) {
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
		$applicableTypes = AspaklaryaPagesLocker::getApplicableTypes( $titleObj->getId() > 0 );
		$applicableTypes[0] = 'none';
		if ( !in_array( $params['level'], $applicableTypes ) ) {
			$this->dieWithError( 'apierror-aspaklarya_lockdown-invalidlevel' );
		}
		$user = $this->getUser();
		$watch = $params['watchlist'];
		$watchlistExpiry = $this->getExpiryFromParams( $params );
		$this->setWatch( $watch, $titleObj, $user, 'watchdefault', $watchlistExpiry );

		$locker = new AspaklaryaPagesLocker( $titleObj );
		$status = $locker->doUpdateRestrictions( $params['level'], $params['reason'], $this->getAuthority()->getUser() );
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
				ParamValidator::PARAM_TYPE => [ 'none', 'create', 'read', 'edit', 'edit-semi', 'edit-full'],
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
