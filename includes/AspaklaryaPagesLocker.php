<?php

namespace MediaWiki\Extension\AspaklaryaLockDown;

use ManualLogEntry;
use MediaWiki\MediaWikiServices;
use MediaWiki\Title\Title;
use MediaWiki\User\UserIdentity;
use Status;
use Wikimedia\Rdbms\ILoadBalancer;

class AspaklaryaPagesLocker {

    public const READ = 'read';
	public const READ_SEMI = 'read-semi';
	public const EDIT = 'edit';
	public const EDIT_SEMI = 'edit-semi';
	public const EDIT_FULL = 'edit-full';
	private const CREATE = 'create';
	private const READ_BITS = 0;
	private const EDIT_BITS = 1;
	private const EDIT_SEMI_BITS = 2;
	private const EDIT_FULL_BITS = 4;
	private const READ_SEMI_BITS = 8;

    private $mTitle;
    private $mApplicableTypes = [];

	/** @var ILoadBalancer */
	private $lb;

    /**
     * @param Title $title
     */
    public function __construct( $title ) {
        $this->mTitle = $title;
        $this->mApplicableTypes = self::getApplicableTypes( $this->mTitle->getId() > 0 );
		$this->lb = MediaWikiServices::getInstance()->getDBLoadBalancer();
    }

    /**
	 * Update the article's restriction field, and leave a log entry.
	 * This works for protection both existing and non-existing pages.
	 *
	 * @param string $limit edit-semi|edit-full|edit|read|create|""
	 * @param string $reason
     * @param UserIdentity $user
	 * @return Status Status object; if action is taken, $status->value is the log_id of the
	 *   protection log entry.
	 */
	public function doUpdateRestrictions(
		string $limit,
		$reason,
        $user
	) {
		$readOnlyMode = MediaWikiServices::getInstance()->getReadOnlyMode();
		if ( $readOnlyMode->isReadOnly() ) {
			return Status::newFatal( wfMessage( 'readonlytext', $readOnlyMode->getReason() ) );
		}
		if( $limit !== '' && !in_array( $limit, $this->mApplicableTypes ) ) {
			return Status::newFatal( 'aspaklarya_lockdown-invalid-level' );
		}
		$id = $this->mTitle->getId();
		$pagesLockedTable = 'aspaklarya_lockdown_pages';
		$connection = $this->lb->getConnection( DB_PRIMARY );

		$isRestricted = false;
		$restrict = !empty( $limit );
		$changed = false;

		if ( $id > 0 ) {
			$restriction = $connection->newSelectQueryBuilder()
				->select( [ "al_read_allowed", "al_id" ] )
				->from( $pagesLockedTable )
				->where( [ "al_page_id" => $id ] )
				->caller( __METHOD__ )
				->fetchRow();
			if ( $restriction != false ) {
				$isRestricted = true;
			}
			if ( ( !$isRestricted && $restrict ) ||
				( $isRestricted && ( $limit == '' || self::getLevelBits( $limit ) != $restriction->al_read_allowed ) )
			) {
				$changed = true;
			}
		} else {
			$restriction = $connection->newSelectQueryBuilder()
				->select( [ "al_page_namespace", "al_page_title", "al_lock_id" ] )
				->from( "aspaklarya_lockdown_create_titles" )
				->where( [ "al_page_namespace" => $this->mTitle->getNamespace(), "al_page_title" => $this->mTitle->getDBkey() ] )
				->caller( __METHOD__ )
				->fetchRow();

			if ( $restriction != false ) {
				$isRestricted = true;
			}
			if ( ( !$isRestricted && $restrict ) || ( $isRestricted && $limit == '' ) ) {
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

		$dbw = $this->lb->getConnection( DB_PRIMARY );
		$logParamsDetails = [
			'type' => $logAction,
			'level' => $limit,
		];
		$relations = [];

		if ( $id > 0 ) { // lock of existing page

			if ( $isRestricted ) {
				if ( $restrict ) {
					$dbw->update(
						$pagesLockedTable,
						[ 'al_read_allowed' => self::getLevelBits( $limit ) ],
						[ 'al_page_id' => $id ],
						__METHOD__
					);
					$relations['al_id'] = $restriction->al_id;
				} else {
					$dbw->delete(
						$pagesLockedTable,
						[ 'al_page_id' => $id ],
						__METHOD__
					);
				}
			} else {
				$dbw->insert(
					$pagesLockedTable,
					[ 'al_page_id' => $id, 'al_read_allowed' => self::getLevelBits( $limit ) ],
					__METHOD__

				);
				$relations['al_id'] = $dbw->insertId();
			}
			$this->invalidateCache();
		} else { // lock of non-existing page (also known as "title protection")

			if ( $limit == self::CREATE ) {
				$dbw->insert(
					'aspaklarya_lockdown_create_titles',
					[
						'al_page_namespace' => $this->mTitle->getNamespace(),
						'al_page_title' => $this->mTitle->getDBkey(),
					],
					__METHOD__
				);
				$relations['al_lock_id'] = $dbw->insertId();
			} else {
				$dbw->delete(
					'aspaklarya_lockdown_create_titles',
					[ "al_lock_id" => $restriction->al_lock_id ],
					__METHOD__
				);
			}
			$cache = MediaWikiServices::getInstance()->getMainWANObjectCache();
			$key = $cache->makeKey( 'aspaklarya-lockdown', 'create', $this->mTitle->getNamespace(), $this->mTitle->getDBkey() );
			$cache->delete( $key );
		}
		$params = [];
		if ( $logAction === "modify" ) {

			$params = [
				"4::description" => wfMessage( 'lock-'. self::getLevelFromBits( $restriction->al_read_allowed ) ),
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
		$logEntry->setTarget( $this->mTitle );
		$logEntry->setRelations( $relations );
		$logEntry->setComment( $reason );
		$logEntry->setPerformer( $user );
		$logEntry->setParameters( $params );

		$logId = $logEntry->insert();

		return Status::newGood( $logId );
	}

    /**
	 * Invalidate the cache for the page
	 */
	private function invalidateCache() {
		$cache = MediaWikiServices::getInstance()->getMainWANObjectCache();
		$cacheKey = $cache->makeKey( 'aspaklarya-lockdown', $this->mTitle->getArticleID() );
		$cache->delete( $cacheKey );
	}

	/**
	 * Get the cached data for the page
	 *
	 * @return int
	 */
	private function getCachedData() {
		$cache = MediaWikiServices::getInstance()->getMainWANObjectCache();
		if ( $this->mTitle->getId() > 0 ) {
			$cacheKey = $cache->makeKey( 'aspaklarya-lockdown-v1', $this->mTitle->getArticleID() );
		} else {
			$cacheKey = $cache->makeKey( 'aspaklarya-lockdown', 'create', $this->mTitle->getNamespace(), $this->mTitle->getDBkey() );
		};
		return $cache->getWithSetCallback(
			$cacheKey,
			$cache::TTL_MONTH,
			function() {
				return $this->getData();
			}
		);
	}

	/**
	 * @param string $db
	 * @return int
	 */
	private function getData( $db = DB_REPLICA ) {
		$connection = $this->lb->getConnection( $db );
		$id = $this->mTitle->getId();
		if ($id < 1) {
			$restriction = $connection->newSelectQueryBuilder()
				->select( [ "al_page_namespace", "al_page_title" ] )
				->from( "aspaklarya_lockdown_create_titles" )
				->where( [ "al_page_namespace" => $this->mTitle->getNamespace(), "al_page_title" => $this->mTitle->getDBkey() ] )
				->caller( __METHOD__ )
				->fetchRow();
			if ( $restriction === false ) {
				return 1;
			}
			return 0;
		}
		$pagesLockedTable = 'aspaklarya_lockdown_pages';
		$restriction = $connection->newSelectQueryBuilder()
			->select( [ "al_read_allowed" ] )
			->from( $pagesLockedTable )
			->where( [ "al_page_id" => $id ] )
			->caller( __METHOD__ )
			->fetchRow();
		if ( $restriction === false ) {
			return 1;
		}
		if ( $restriction->al_read_allowed === self::READ_BITS ) {
			return 2;
		}
		return $restriction->al_read_allowed << 2;
	}

	/**
	 * @return string
	 */
	public function getLevel() {
		return $this->getCachedData();
	}
    
    /**
     * @param string $level
     * @return int
     */
    public static function getLevelBits( $level ) {
		switch ( $level ) {
			case self::READ:
				return self::READ_BITS;
			case self::EDIT:
				return self::EDIT_BITS;
			case self::EDIT_SEMI:
				return self::EDIT_SEMI_BITS;
			case self::EDIT_FULL:
				return self::EDIT_FULL_BITS;
			case self::READ_SEMI:
				return self::READ_SEMI_BITS;
			default:
				return self::READ_BITS;
		}
	}

	/**
     * @param int $bits
	 * @return string
	 */
	public static function getLevelFromBits( $bits ) {
		switch ( $bits ) {
			case self::READ_BITS:
				return self::READ;
			case self::EDIT_BITS:
				return self::EDIT;
			case self::EDIT_SEMI_BITS:
				return self::EDIT_SEMI;
			case self::EDIT_FULL_BITS:
				return self::EDIT_FULL;
			case self::READ_SEMI_BITS:
				return self::READ_SEMI;
			default:
				return self::READ;
		}
	}

	public static function getAllBits() {
		return ( self::READ_SEMI_BITS << 2 ) - 1;
	}

	public static function getApplicableTypesWithReadPermission() {
		return [ 
			'read' => '', 
			'aspaklarya-read-locked' => self::READ, 
			'read' => self::EDIT, 
			'read' => self::EDIT_SEMI, 
			'read' => self::EDIT_FULL, 
			'aspaklarya-read-semi-locked' => self::READ_SEMI 
		];
	}

	public static function getApplicableTypesWithEditPermission() {
		return [ 
			'edit' => '', 
			'aspaklarya-read-locked' => self::READ, 
			'aspaklarya-edit-locked' => self::EDIT, 
			'aspaklarya-edit-semi-locked' => self::EDIT_SEMI, 
			'edit' => self::EDIT_FULL, 
			'aspaklarya-read-semi-locked' => self::READ_SEMI 
		];
	}

	public static function isUserAllowed( UserIdentity $user, Title $title ) {
		$locker = new self( $title );
		$userOptionsLookup = MediaWikiServices::getInstance()->getUserOptionsLookup();
		$grade = $locker->getCachedData();
		

	}
	/**
	 * @param Title $title
	 * @return int
	 */
	public static function getTitleGrade( Title $title ) {
		$locker = new self( $title );
		return $locker->getCachedData();
	}
    /**
     * @param bool $existingPage
     * @return string[]
     */
    public static function getApplicableTypes( $existingPage ) {
        if( $existingPage ) {
            return [ '', self::READ, self::EDIT, self::EDIT_SEMI, self::EDIT_FULL, self::READ_SEMI ];
        }
        return [ '', self::CREATE ];
    }
}
