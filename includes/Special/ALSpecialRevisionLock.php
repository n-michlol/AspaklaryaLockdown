<?php
/**
 * Implements Special:Revisiondelete
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 2 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License along
 * with this program; if not, write to the Free Software Foundation, Inc.,
 * 51 Franklin Street, Fifth Floor, Boston, MA 02110-1301, USA.
 * http://www.gnu.org/copyleft/gpl.html
 *
 * @file
 * @ingroup SpecialPage
 */

namespace MediaWiki\Extension\AspaklaryaLockDown\Special;

use ErrorPageError;
use HTMLForm;
use LogEventsList;
use LogPage;
use MediaWiki\CommentStore\CommentStore;
use MediaWiki\Extension\AspaklaryaLockDown\ALRevLockRevisionList;
use MediaWiki\Html\Html;
use MediaWiki\MediaWikiServices;
use MediaWiki\Permissions\PermissionManager;
use MediaWiki\Revision\RevisionRecord;
use MediaWiki\Title\Title;
use PermissionsError;
use RevDelList;
use SpecialPage;
use UnlistedSpecialPage;
use UserBlockedError;
use Xml;

/**
 * Special page allowing users with the appropriate permissions to view
 * and lock revisions.
 *
 * @ingroup SpecialPage
 */
class ALSpecialRevisionLock extends UnlistedSpecialPage {
	/** @var bool Was the DB modified in this request */
	protected $wasSaved = false;

	/** @var bool True if the submit button was clicked, and the form was posted */
	private $submitClicked;

	/** @var array Target ID list */
	private $ids;

	/** @var string Archive name, for reviewing deleted files */
	private $archiveName;

	/** @var string Edit token for securing image views against XSS */
	private $token;

	/** @var Title Title object for target parameter */
	private $targetObj;

	/** @var string Deletion type, may be revision, archive, oldimage, filearchive, logging. */
	private $typeName;

	/** @var array Array of checkbox specs (message, name, deletion bits) */
	private $checks;

	/** @var array UI Labels about the current type */
	private $typeLabels;

	/** @var RevDelList RevDelList object, storing the list of items to be deleted/undeleted */
	private $revDelList;

	/** @var bool Whether user is allowed to perform the action */
	private $mIsAllowed;

	/** @var string */
	private $otherReason;

	/** @var PermissionManager */
	private $permissionManager;


	/**
	 * @inheritDoc
	 *
	 * @param PermissionManager $permissionManager
	 */
	public function __construct( PermissionManager $permissionManager ) {
		parent::__construct( 'Revisionlock' ,'aspaklarya_lockdown');

		$this->permissionManager = $permissionManager;
	}

	public function doesWrites() {
		return true;
	}

	public function execute( $par ) {
		$this->useTransactionalTimeLimit();

		$this->checkPermissions();
		$this->checkReadOnly();

		$output = $this->getOutput();
		$user = $this->getUser();

		$this->setHeaders();
		$this->outputHeader();
		$request = $this->getRequest();
		$this->submitClicked = $request->wasPosted() && $request->getBool( 'wpSubmit' );
		# Handle our many different possible input types.
		$ids = $request->getVal( 'ids' );
		if ( $ids !== null ) {
			# Allow CSV, for backwards compatibility, or a single ID for show/hide links
			$this->ids = explode( ',', $ids );
		} else {
			# Array input
			$this->ids = array_keys( $request->getArray( 'ids', [] ) );
		}
		// $this->ids = array_map( 'intval', $this->ids );
		$this->ids = array_unique( array_filter( $this->ids ) );

		$this->typeName = 'revision';
		$this->targetObj = Title::newFromText( $request->getText( 'target' ) );

		# No targets?
		if ( count( $this->ids ) == 0 ) {
			throw new ErrorPageError( 'aspaklarya-revlock-nooldid-title', 'aspaklarya-revlock-nooldid-text' );
		}

		$restriction = 'aspaklarya_lockdown';

		if ( !$this->getAuthority()->isAllowed( $restriction ) ) {
			throw new PermissionsError( $restriction );
		}

		# Allow the list type to adjust the passed target
		$this->targetObj = self::suggestTarget(
			$this->targetObj,
			$this->ids,
		);

		# We need a target page!
		if ( $this->targetObj === null ) {
			$output->addWikiMsg( 'aspaklarya-unlock-header' );

			return;
		}

		// Check blocks
		$checkReplica = !$this->submitClicked;
		if (
			$this->permissionManager->isBlockedFrom(
				$user,
				$this->targetObj,
				$checkReplica
			)
		) {
			throw new UserBlockedError(
				// @phan-suppress-next-line PhanTypeMismatchArgumentNullable Block is checked and not null
				$user->getBlock(),
				$user,
				$this->getLanguage(),
				$request->getIP()
			);
		}

		$this->typeLabels = [
			'check-label' => 'revlock-hide-text',
			'success' => 'revlock-success',
			'failure' => 'revlock-failure',
			'text' => 'revlock-text-text',
			'selected' => 'revlock-selected-text',
		];
		$list = $this->getList();
		$list->reset();

		if( $list->length() == 0 ) {
			throw new ErrorPageError( 'aspaklarya-revlock-nooldid-title', 'aspaklarya-revlock-nooldid-text' );
		}

		if( $list->areAnyDeleted() ) {
			throw new ErrorPageError( 'aspaklarya-revlock-deleted-title', 'aspaklarya-revlock-deleted-text' );
		}

		$this->mIsAllowed = $this->permissionManager->userHasRight( $user, $restriction );
		$this->otherReason = $request->getVal( 'wpReason', '' );

		# Either submit or create our form
		if ( $this->mIsAllowed && $this->submitClicked ) {
			$this->submit();
		} else {
			$this->showForm();
		}

		if ( $this->permissionManager->userHasRight( $user, 'aspaklarya-lockdown-logs' ) ) {
			# Show relevant lines from the aspaklarya log
			$aLockdownLogPage = new LogPage( 'aspaklarya' );
			$output->addHTML( "<h2>" . $aLockdownLogPage->getName()->escaped() . "</h2>\n" );
			LogEventsList::showLogExtract(
				$output,
				'aspaklarya',
				$this->targetObj,
				'', /* user */
				[ 'lim' => 25, 'conds' => $this->getLogQueryCond(), 'useMaster' => $this->wasSaved ]
			);
		}
		
	}

    public static function suggestTarget( $target, array $ids ) {
		$revisionRecord = MediaWikiServices::getInstance()
			->getRevisionLookup()
			->getRevisionById( $ids[0] );

		if ( $revisionRecord ) {
			return Title::newFromLinkTarget( $revisionRecord->getPageAsLinkTarget() );
		}
		return $target;
	}

	/**
	 * Get the condition used for fetching log snippets
	 * @return array
	 */
	protected function getLogQueryCond() {
		$conds = [];
		// Revision aspaklarya logs for these item
		$conds['log_type'] = [ 'hide', 'unhide' ];
		$conds['ls_field'] = 'rev_id';
		// Convert IDs to strings, since ls_value is a text field. This avoids
		// a fatal error in PostgreSQL: "operator does not exist: text = integer".
		$conds['ls_value'] = array_map( 'strval', $this->ids );

		return $conds;
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

	/**
	 * Show a list of items that we will operate on, and show a form with checkboxes
	 * which will allow the user to choose new visibility settings.
	 */
	protected function showForm() {
		$userAllowed = true;

		$out = $this->getOutput();
		$out->wrapWikiMsg( "<strong>$1</strong>", [ 'revlock-selected-text',
			$this->getLanguage()->formatNum( count( $this->ids ) ), $this->targetObj->getPrefixedText() ] );

		$this->addHelpLink( 'Help:RevisionLock' );
		$out->addHTML( "<ul>" );

		$numRevisions = 0;
		// Live revisions...
		$list = $this->getList();
		for ( $list->reset(); $list->current(); $list->next() ) {
			$item = $list->current();

			if ( !$item->canView() ) {
				if ( !$this->submitClicked ) {
					throw new PermissionsError( 'suppressrevision' );
				}
				$userAllowed = false;
			}

			$numRevisions++;
			$out->addHTML( $item->getHTML() );
		}

		if ( !$numRevisions ) {
			throw new ErrorPageError( 'aspaklarya-revlock-nooldid-title', 'aspaklarya-revlock-nooldid-text' );
		}

		$out->addHTML( "</ul>" );
		// Explanation text
		$this->addUsageText();

		// Normal sysops can always see what they did, but can't always change it
		if ( !$userAllowed ) {
			return;
		}

		// Show form if the user can submit
		if ( $this->mIsAllowed ) {
			$out->addModules( [ 'mediawiki.special.revisionDelete' ] );
			$out->addModuleStyles( [ 'mediawiki.special',
				'mediawiki.interface.helpers.styles' ] );

			$dropDownReason = $this->msg( 'aspaklarya-revlock-reason-dropdown' )->inContentLanguage()->text();
			

			$fields = $this->buildCheckBoxes();

			$fields[] = [
				'type' => 'select',
				'label' => $this->msg( 'revdelete-log' )->text(),
				'cssclass' => 'wpReasonDropDown',
				'id' => 'wpRevDeleteReasonList',
				'name' => 'wpRevDeleteReasonList',
				'options' => Xml::listDropDownOptions(
					$dropDownReason,
					[ 'other' => $this->msg( 'revdelete-reasonotherlist' )->text() ]
				),
				'default' => $this->getRequest()->getText( 'wpRevDeleteReasonList', 'other' )
			];

			$fields[] = [
				'type' => 'text',
				'label' => $this->msg( 'revdelete-otherreason' )->text(),
				'name' => 'wpReason',
				'id' => 'wpReason',
				// HTML maxlength uses "UTF-16 code units", which means that characters outside BMP
				// (e.g. emojis) count for two each. This limit is overridden in JS to instead count
				// Unicode codepoints.
				// "- 155" is to leave room for the 'wpRevDeleteReasonList' value.
				'maxlength' => CommentStore::COMMENT_CHARACTER_LIMIT - 155,
			];

			$fields[] = [
				'type' => 'hidden',
				'name' => 'wpEditToken',
				'default' => $this->getUser()->getEditToken()
			];

			$fields[] = [
				'type' => 'hidden',
				'name' => 'target',
				'default' => $this->targetObj->getPrefixedText()
			];

			$fields[] = [
				'type' => 'hidden',
				'name' => 'type',
				'default' => $this->typeName
			];

			$fields[] = [
				'type' => 'hidden',
				'name' => 'ids',
				'default' => implode( ',', $this->ids )
			];

			$htmlForm = HTMLForm::factory( 'ooui', $fields, $this->getContext() );
			$htmlForm
				->setSubmitText( $this->msg( 'revdelete-submit', $numRevisions )->text() )
				->setSubmitName( 'wpSubmit' )
				->setWrapperLegend( $this->msg( 'revdelete-legend' )->text() )
				->setAction( $this->getPageTitle()->getLocalURL( [ 'action' => 'submit' ] ) )
				->loadData();
			// Show link to edit the dropdown reasons
			if ( $this->permissionManager->userHasRight( $this->getUser(), 'editinterface' ) ) {
				$link = '';
				$linkRenderer = $this->getLinkRenderer();
				
				$link .= $linkRenderer->makeKnownLink(
					$this->msg( 'revdelete-reason-dropdown' )->inContentLanguage()->getTitle(),
					$this->msg( 'revdelete-edit-reasonlist' )->text(),
					[],
					[ 'action' => 'edit' ]
				);
				$htmlForm->setPostHtml( Xml::tags( 'p', [ 'class' => 'mw-revdel-editreasons' ], $link ) );
			}
			$out->addHTML( $htmlForm->getHTML( false ) );
		}
	}

	/**
	 * Show some introductory text
	 * @todo FIXME: Wikimedia-specific policy text
	 */
	protected function addUsageText() {
		// Messages: revdelete-text-text, revdelete-text-file, logdelete-text
		$this->getOutput()->wrapWikiMsg(
			"<strong>$1</strong>\n$2", $this->typeLabels['text'],
			'revdelete-text-others'
		);

		if ( $this->permissionManager->userHasRight( $this->getUser(), 'suppressrevision' ) ) {
			$this->getOutput()->addWikiMsg( 'revdelete-suppress-text' );
		}

		if ( $this->mIsAllowed ) {
			$this->getOutput()->addWikiMsg( 'revdelete-confirm' );
		}
	}

	/**
	 * @return array $fields
	 */
	protected function buildCheckBoxes() {
		$fields = [];

		$type = 'radio';

		$list = $this->getList();

		// If there is just one item, use checkboxes
		if ( $list->length() == 1 ) {
			$list->reset();

			$type = 'check';
		}
		$name = 'wpLock';
		$field = [
			'type' => $type,
			'label-raw' => $this->msg( 'revlock-hide-text' )->escaped(),
			'id' => $name,
			'flatlist' => true,
			'name' => $name,
			'default' =>  null
		];
		$fields[] = $field;

		return $fields;
	}

	/**
	 * UI entry point for form submission.
	 * @throws PermissionsError
	 * @return bool
	 */
	protected function submit() {
		# Check edit token on submission
		$token = $this->getRequest()->getVal( 'wpEditToken' );
		if ( $this->submitClicked && !$this->getUser()->matchEditToken( $token ) ) {
			$this->getOutput()->addWikiMsg( 'sessionfailure' );

			return false;
		}
		$bitParams = $this->extractBitParams();
		// from dropdown
		$listReason = $this->getRequest()->getText( 'wpRevDeleteReasonList', 'other' );
		$comment = $listReason;
		if ( $comment === 'other' ) {
			$comment = $this->otherReason;
		} elseif ( $this->otherReason !== '' ) {
			// Entry from drop down menu + additional comment
			$comment .= $this->msg( 'colon-separator' )->inContentLanguage()->text()
				. $this->otherReason;
		}
		# Can the user set this field?
		if ( $bitParams[RevisionRecord::DELETED_RESTRICTED] == 1
			&& !$this->permissionManager->userHasRight( $this->getUser(), 'suppressrevision' )
		) {
			throw new PermissionsError( 'suppressrevision' );
		}
		# If the save went through, go to success message...
		$status = $this->save( $bitParams, $comment );
		if ( $status->isGood() ) {
			$this->success();

			return true;
		} else {
			# ...otherwise, bounce back to form...
			$this->failure( $status );
		}

		return false;
	}

	/**
	 * Report that the submit operation succeeded
	 */
	protected function success() {
		// Messages: revdelete-success, logdelete-success
		$out = $this->getOutput();
		$out->setPageTitle( $this->msg( 'actioncomplete' ) );
		$out->addHTML(
			Html::successBox(
				$out->msg( $this->typeLabels['success'] )->parse()
			)
		);
		$this->wasSaved = true;
		$this->revDelList->reloadFromPrimary();
		$this->showForm();
	}

	/**
	 * Report that the submit operation failed
	 * @param Status $status
	 */
	protected function failure( $status ) {
		// Messages: revdelete-failure, logdelete-failure
		$out = $this->getOutput();
		$out->setPageTitle( $this->msg( 'actionfailed' ) );
		$out->addHTML(
			Html::errorBox(
				$out->parseAsContent(
					$status->getWikiText( $this->typeLabels['failure'], false, $this->getLanguage() )
				)
			)
		);
		$this->showForm();
	}

	/**
	 * Put together an array that contains -1, 0, or the *_deleted const for each bit
	 *
	 * @return array
	 */
	protected function extractBitParams() {
		$bitfield = [];
		foreach ( $this->checks as $item ) {
			[ /* message */, $name, $field ] = $item;
			$val = $this->getRequest()->getInt( $name, 0 /* unchecked */ );
			if ( $val < -1 || $val > 1 ) {
				$val = -1; // -1 for existing value
			}
			$bitfield[$field] = $val;
		}
		if ( !isset( $bitfield[RevisionRecord::DELETED_RESTRICTED] ) ) {
			$bitfield[RevisionRecord::DELETED_RESTRICTED] = 0;
		}

		return $bitfield;
	}

	/**
	 * Do the write operations. Simple wrapper for RevDel*List::setVisibility().
	 * @param array $bitPars ExtractBitParams() bitfield array
	 * @param string $reason
	 * @return Status
	 */
	protected function save( array $bitPars, $reason ) {
		return $this->getList()->setVisibility(
			[ 'value' => $bitPars, 'comment' => $reason ]
		);
	}

	protected function getGroupName() {
		return 'pagetools';
	}
}
