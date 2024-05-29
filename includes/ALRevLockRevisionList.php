<?php
/**
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
 * @ingroup RevisionDelete
 */

namespace MediaWiki\Extension\AspaklaryaLockDown;

use ChangeTags;
use DeferredUpdates;
use HtmlCacheUpdater;
use IContextSource;
use InvalidArgumentException;
use ManualLogEntry;
use MediaWiki\HookContainer\HookContainer;
use MediaWiki\HookContainer\HookRunner;
use MediaWiki\MediaWikiServices;
use MediaWiki\Page\PageIdentity;
use MediaWiki\Revision\RevisionRecord;
use MediaWiki\Revision\RevisionStore;
use MediaWiki\Title\Title;
use RevDelList;
use Status;
use Wikimedia\Rdbms\FakeResultWrapper;
use Wikimedia\Rdbms\IDatabase;
use Wikimedia\Rdbms\IResultWrapper;
use Wikimedia\Rdbms\LBFactory;

/**
 * List for revision table items
 *
 * This will check both the 'revision' table for live revisions and the
 * 'archive' table for traditionally-deleted revisions that have an
 * ar_rev_id saved.
 *
 * See RevDelRevisionItem and RevDelArchivedRevisionItem for items.
 */
class ALRevLockRevisionList extends RevDelList {

	/** @var LBFactory */
	private $lbFactory;

	/** @var HtmlCacheUpdater */
	private $htmlCacheUpdater;

	/** @var RevisionStore */
	private $revisionStore;

	/** @var int */
	public $currentRevId;

	/** @var array map of ids => bool|int */
	private $currentLockedStatus;

	/** @var IResultWrapper */
	private $lockedRevisionRows;

	/** @var int[] */
	private $succesIds = [];

	/**
	 * @param IContextSource $context
	 * @param PageIdentity $page
	 * @param array $ids
	 * @param LBFactory $lbFactory
	 * @param HookContainer $hookContainer
	 * @param HtmlCacheUpdater $htmlCacheUpdater
	 * @param RevisionStore $revisionStore
	 */
	public function __construct(
		IContextSource $context,
		PageIdentity $page,
		array $ids,
		LBFactory $lbFactory,
		HtmlCacheUpdater $htmlCacheUpdater,
		RevisionStore $revisionStore
	) {
		parent::__construct( $context, $page, array_map( 'intval', $ids ), $lbFactory );
		$this->lbFactory = $lbFactory;
		$this->htmlCacheUpdater = $htmlCacheUpdater;
		$this->revisionStore = $revisionStore;
	}

	public function getType() {
		return 'revision';
	}

	public static function getRelationType() {
		return 'rev_id';
	}

	public static function getRestriction() {
		return 'aspaklarya-lock-revisions';
	}

	public static function getRevdelConstant() {
		return RevisionRecord::DELETED_TEXT;
	}

	public function getSuccessIds() {
		return $this->succesIds;
	}

	private function setSuccessIds( $ids ) {
		$this->succesIds = $ids;
	}

	/**
	 * @return LBFactory
	 */
	public function getLBFactory() {
		return $this->lbFactory;
	}

	/**
	 * Indicate whether any item in this list is deleted
	 * @since 1.25
	 * @return bool
	 */
	public function areAnyDeleted() {
		$bit = self::getRevdelConstant();

		/** @var ALRevLockRevisionItem $item */
		foreach ( $this as $item ) {
			if ( $item->getBits() & $bit ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * @param IDatabase $db
	 * @return mixed
	 */
	public function doQuery( $db ) {
		$ids = array_map( 'intval', $this->ids );
		$revQuery = $this->revisionStore->getQueryInfo( [ 'page', 'user' ] );
		$queryInfo = [
			'tables' => $revQuery['tables'],
			'fields' => $revQuery['fields'],
			'conds' => [
				'rev_page' => $this->page->getId(),
				'rev_id' => $ids,
			],
			'options' => [
				'ORDER BY' => 'rev_id DESC',
				'USE INDEX' => [ 'revision' => 'PRIMARY' ] // workaround for MySQL bug (T104313)
			],
			'join_conds' => $revQuery['joins'],
		];
		ChangeTags::modifyDisplayQuery(
			$queryInfo['tables'],
			$queryInfo['fields'],
			$queryInfo['conds'],
			$queryInfo['join_conds'],
			$queryInfo['options'],
			''
		);
		return $db->select(
			$queryInfo['tables'],
			$queryInfo['fields'],
			$queryInfo['conds'],
			__METHOD__,
			$queryInfo['options'],
			$queryInfo['join_conds']
		);
	}

	public function newItem( $row ) {
		if ( isset( $row->rev_id ) ) {
			return new ALRevLockRevisionItem( $this, $row );
		} else {
			// This shouldn't happen. :)
			throw new InvalidArgumentException( 'Invalid row type in ALRevLockRevisionList' );
		}
	}

	/**
	 * @param int $id
	 * @return false|int
	 */
	public function getCurrentlockedStatus( int $id ) {
		if ( $this->currentLockedStatus == null ) {
			$lockedRows = $this->getLockedRevisionRows();
			$this->currentLockedStatus = array_fill_keys( $this->ids, false );
			foreach ( $lockedRows as $row ) {
				$this->currentLockedStatus[(int)$row->alr_rev_id] = $row->alr_id;
			}
		}
		return isset( $this->currentLockedStatus[$id] ) && $this->currentLockedStatus[$id];
	}

	/**
	 * @return IResultWrapper
	 */
	private function getLockedRevisionRows() {
		if ( $this->lockedRevisionRows == null ) {
			$this->lockedRevisionRows = $this->getLockedRevisions();
		}
		return $this->lockedRevisionRows;
	}

	/**
	 * Set the visibility for the revisions in this list. Logging and
	 * transactions are done here.
	 *
	 * @param array $params Associative array of parameters. Members are:
	 *     value:         hide|unhide
	 *     comment:       The log comment
	 *     perItemStatus: Set if you want per-item status reports
	 *     tags:          The array of change tags to apply to the log entry
	 * @return Status
	 */
	public function setVisibility( array $params ) {
		if( !$this->getUser()->isAllowed( self::getRestriction() ) ) {
			return Status::newFatal( 'revlock-no-permission' ); // @TODO: add to i18n
		}
		$status = Status::newGood();

		$action = $params['value'];
		$comment = $params['comment'];
		$perItemStatus = $params['perItemStatus'] ?? false;

		if ( $action !== 'hide' && $action !== 'unhide' ) {
			throw new InvalidArgumentException( 'Invalid action type in ALRevLockRevisionList' );
		}
		// CAS-style checks are done on the _deleted fields so the select
		// does not need to use FOR UPDATE nor be in the atomic section
		$dbw = $this->lbFactory->getPrimaryDatabase();
		$this->res = $this->doQuery( $dbw );

		$status->merge( $this->acquireItemLocks() );
		if ( !$status->isGood() ) {
			return $status;
		}

		$dbw->startAtomic( __METHOD__, $dbw::ATOMIC_CANCELABLE );
		$dbw->onTransactionResolution(
			function () {
				// Release locks on commit or error
				$this->releaseItemLocks();
			},
			__METHOD__
		);

		$missing = array_fill_keys( $this->ids, true );
		$idsForLog = [];
		$authorActors = [];

		if ( $perItemStatus ) {
			$status->value['itemStatuses'] = [];
		}

		// Will be filled with id => [old, new bits] information and
		// passed to doPostCommitUpdates().
		$visibilityChangeMap = [];

		/** @var ALRevLockRevisionItem $item */
		foreach ( $this as $item ) {
			unset( $missing[$item->getId()] );

			if ( $perItemStatus ) {
				$itemStatus = Status::newGood();
				$status->value['itemStatuses'][$item->getId()] = $itemStatus;
			} else {
				$itemStatus = $status;
			}

			if ( $item->isCurrent() ) {
				$status->error(
					'revlock-hide-current', $item->formatDate(), $item->formatTime() );
				$status->failCount++;
				continue;
			}

			$currentState = (int)$this->getCurrentlockedStatus( $item->getId() );

			if ( $action == 'hide' && $currentState > 0 || $action == 'unhide' && $currentState == 0 ) {
				$itemStatus->error(
					'revlock-no-change', $item->formatDate(), $item->formatTime() );
				$status->failCount++;
				continue;
			}

			if ( !$item->canView() ) {
				// Cannot access this revision
				$msg = 'revlock-show-no-access';
				$itemStatus->error( $msg, $item->formatDate(), $item->formatTime() );
				$status->failCount++;
				continue;
			}

			// Update the revision
			$ok = $action == 'hide' ? $item->hide() : $item->unhide();

			if ( $ok ) {
				$idsForLog[] = $item->getId();

				$status->successCount++;
				$authorActors[] = $item->getAuthorActor();

				// Save the old and new bits in $visibilityChangeMap for
				// later use.
				$visibilityChangeMap[$item->getId()] = [
					'oldBits' => $currentState > 0 ? "1" : "0",
					'newBits' => $action === 'hide' ? "1" : "0",
				];
			} else {
				$itemStatus->error(
					'revlock-concurrent-change', $item->formatDate(), $item->formatTime() );
				$status->failCount++;
			}
		}

		$this->setSuccessIds( $idsForLog );
		// Handle missing revisions
		foreach ( $missing as $id => $unused ) {
			if ( $perItemStatus ) {
				$status->value['itemStatuses'][$id] = Status::newFatal( 'revlock-modify-missing', $id ); // @TODO: add to i18n
			} else {
				$status->error( 'revlock-modify-missing', $id );
			}
			$status->failCount++;
		}

		if ( $status->successCount == 0 ) {
			$dbw->endAtomic( __METHOD__ );
			return $status;
		}

		// Save success count
		$successCount = $status->successCount;

		// Move files, if there are any
		$status->merge( $this->doPreCommitUpdates() );
		if ( !$status->isOK() ) {
			// Fatal error, such as no configured archive directory or I/O failures
			$dbw->cancelAtomic( __METHOD__ );
			return $status;
		}

		// Log it
		$authorFields = [];
		$authorFields['authorActors'] = $authorActors;
		$this->updateLog(
			$action,
			[
				'page' => $this->page,
				'count' => $successCount,
				'comment' => $comment,
				'ids' => $idsForLog,
				'tags' => $params['tags'] ?? [],
			] + $authorFields
		);

		// Clear caches after commit
		DeferredUpdates::addCallableUpdate(
			function () use ( $visibilityChangeMap ) {
				$this->invalidateCache( array_keys( $visibilityChangeMap ) );
				$this->doPostCommitUpdates( $visibilityChangeMap );
			},
			DeferredUpdates::PRESEND,
			$dbw
		);

		$dbw->endAtomic( __METHOD__ );

		return $status;
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
	 * Record a log entry on the action
	 * @param string $logType One of (hide,unhide)
	 * @param array $params Associative array of parameters:
	 *     page:            The page object
	 *     count:           The number of revisions affected
	 *     ids:             The ID list
	 *     comment:         The log comment
	 *     authorActors:    The array of the actor IDs of the offenders
	 *     tags:            The array of change tags to apply to the log entry
	 * @throws MWException
	 */
	private function updateLog( $logType, $params ) {
		// Add params for affected page and ids
		$logParams = [
			'4::description' => wfMessage( "lock-$logType" ),
			'5::ids' => $params['ids'],
		];
		// Actually add the deletion log entry
		$logEntry = new ManualLogEntry( 'aspaklarya', $logType );
		$logEntry->setTarget( $this->page );
		$logEntry->setComment( $params['comment'] );
		$logEntry->setParameters( $logParams );
		$logEntry->setPerformer( $this->getUser() );
		if ( count( $params['ids'] ) == 1 ) {
			$logEntry->setAssociatedRevId( $params['ids'][0] );
		}
		// Allow for easy searching of deletion log items for revision/log items
		$relations = [
			'rev_id' => $params['ids'],
		];
		if ( isset( $params['authorActors'] ) ) {
			$relations += [
				'target_author_actor' => $params['authorActors'],
			];
		}
		$logEntry->setRelations( $relations );
		// Apply change tags to the log entry
		$logEntry->addTags( $params['tags'] );
		$logEntry->insert();
	}

	public function getLockedRevisions() {
		if ( $this->ids == null || empty( $this->ids ) ) {
			return new FakeResultWrapper( [] );
		}
		$db = $this->lbFactory->getPrimaryDatabase();
		$res = $db->newSelectQueryBuilder()
			->select( [ "alr_rev_id","alr_id" ] )
			->from( ALDBData::PAGES_REVISION_NAME )
			->where( [
				"alr_page_id" => $this->getPage()->getId(),
				'alr_rev_id' => array_map( 'intval', $this->ids )
 ] )
			->caller( __METHOD__ )
			->fetchResultSet();
		return $res;
	}


	private function invalidateCache(array $ids) {
		$cache = MediaWikiServices::getInstance()->getMainWANObjectCache();
		foreach ( $ids as $id ) {
			$cache->delete( $cache->makeKey( 'aspaklarya-lockdown', 'revision', $id ));
		}
	}

	public function getCurrent() {
		if ( $this->currentRevId === null ) {
			$dbw = $this->lbFactory->getPrimaryDatabase();
			$this->currentRevId = $dbw->selectField(
				'page',
				'page_latest',
				[ 'page_namespace' => $this->page->getNamespace(), 'page_title' => $this->page->getDBkey() ],
				__METHOD__
			);
		}
		return $this->currentRevId;
	}

	public function getSuppressBit() {
		return RevisionRecord::DELETED_RESTRICTED;
	}

	public function doPreCommitUpdates() {
		Title::castFromPageIdentity( $this->page )->invalidateCache();
		return Status::newGood();
	}

	public function reloadFromPrimary() {
		parent::reloadFromPrimary();
		$this->lockedRevisionRows = $this->getLockedRevisions();
		$this->currentLockedStatus = null;
	}

	public function doPostCommitUpdates( array $visibilityChangeMap ) {
		$this->htmlCacheUpdater->purgeTitleUrls(
			$this->page,
			HtmlCacheUpdater::PURGE_INTENT_TXROUND_REFLECTED
		);

		return Status::newGood();
	}
}
