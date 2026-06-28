<?php

namespace MediaWiki\Extension\PageLockdown;

use MediaWiki\Context\IContextSource;
use InvalidArgumentException;
use MediaWiki\Logging\ManualLogEntry;
use MediaWiki\MediaWikiServices;
use MediaWiki\Status\Status;
use MediaWiki\Title\Title;
use MediaWiki\User\User;
use MediaWiki\User\UserGroupMembership;
use MediaWiki\User\UserIdentity;
use MediaWiki\Context\RequestContext;
use Wikimedia\ObjectCache\WANObjectCache;
use Wikimedia\Rdbms\LoadBalancer;

class PageLockdownManager {

	private const PAGES_TABLE_NAME = 'page_lockdown_pages';
	private const REVISIONS_TABLE_NAME = 'page_lockdown_revisions';
	private const TITLES_TABLE_NAME = 'page_lockdown_create_titles';
	private const READ = 'read';
	private const READ_SEMI = 'read-semi';
	private const CREATE = 'create';
	private const EDIT = 'edit';
	private const EDIT_SEMI = 'edit-semi';
	private const EDIT_FULL = 'edit-full';
	private const LOCKDOWN_PERM = 'page-lockdown';
	private const READ_LOCKED_PERM = 'page-lockdown-read';
	private const READ_SEMI_LOCKED_PERM = 'page-lockdown-read-confirmed';
	private const EDIT_LOCKED_PERM = 'page-lockdown-edit';
	private const EDIT_SEMI_LOCKED_PERM = 'page-lockdown-edit-confirmed';
	private const CREATE_BIT = 0;
	private const READ_BIT = 1;
	private const READ_SEMI_BIT = self::READ_BIT << 1;
	private const EDIT_BIT = self::READ_SEMI_BIT << 1;
	private const EDIT_SEMI_BIT = self::EDIT_BIT << 1;
	private const EDIT_FULL_BIT = self::EDIT_SEMI_BIT << 1;
	private const FULL_BIT = ( 1 << 8 ) - 1;

	private Title $mTitle;
	private ?int $mId = null;
	private bool $existingPage = false;
	private User $mUser;
	private LoadBalancer $mLoadBalancer;
	private WANObjectCache $mCache;
	private ?string $pageCacheKey = null;
	private ?int $state = null;
	private ?int $restrictionId = null;

	public function __construct( LoadBalancer $loadBalancer, WANObjectCache $cache, ?Title $title = null, ?User $user = null ) {
		$this->mLoadBalancer = $loadBalancer;
		$this->mCache = $cache;
		$this->mTitle = $title;
		if ( $this->mTitle && $this->mTitle->canExist()) {
			$this->mId = $this->mTitle->getId();
			$this->existingPage = $this->mId !== 0;
		} else if ( !$this->mTitle->canExist()) {
			$this->mId = 0;
			$this->existingPage = false;
		}
		$this->createCacheKey();
		$this->loadState();
		if ( !$user || !$user->isSafeToLoad() ) {
			$user = MediaWikiServices::getInstance()->getUserFactory()->newAnonymous();
		}
		$this->mUser = $user;
	}

	private function getRestrictionId() {
		if ( $this->restrictionId === null ) {
			$this->getFromDB( DB_PRIMARY );
		}
		return (int)$this->restrictionId;
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
	) : Status {
		$readOnlyMode = MediaWikiServices::getInstance()->getReadOnlyMode();
		if ( $readOnlyMode->isReadOnly() ) {
			return Status::newFatal( wfMessage( 'readonlytext', $readOnlyMode->getReason() ) );
		}
		if ( $limit !== '' && !in_array( $limit, self::getApplicableTypes( $this->existingPage ) ) ) {
			return Status::newFatal( 'page-lockdown-invalid-level' );
		}

		$current = (int)$this->getFromDB( DB_PRIMARY );
		$bit = self::getBitFromLevel( $limit );
		$restrict = self::getLevelFromBit( $bit ) !== '';

		if ( $current === $bit || ( $current === self::FULL_BIT && $bit === -1 ) ) {
			return Status::newGood();
		}
		$isRestricted = $current !== self::FULL_BIT;

		if ( !$restrict ) { // No restriction at all means unlock
			$logAction = 'unlock';
		} elseif ( $isRestricted ) {
			$logAction = 'modify';
		} else {
			$logAction = 'lock';
		}

		$dbw = $this->mLoadBalancer->getConnection( DB_PRIMARY );
		$logParamsDetails = [
			'type' => $logAction,
			'level' => $limit,
		];
		$relations = [];

		if ( $this->existingPage ) { // lock of existing page

			if ( $isRestricted ) {
				if ( $restrict ) {
					$dbw->delete(
						self::PAGES_TABLE_NAME,
						[ 'pl_page_id' => $this->mId ],
						__METHOD__
					);
					$dbw->insert(
						self::PAGES_TABLE_NAME,
						[ 'pl_page_id' => $this->mId, 'pl_level' => $bit ],
						__METHOD__
					);
					$relations['pl_id'] = $dbw->insertId();
					$this->state = $bit;

				} else {
					$dbw->delete(
						self::PAGES_TABLE_NAME,
						[ 'pl_page_id' => $this->mId ],
						__METHOD__
					);
					$this->state = self::FULL_BIT;
				}
			} else {
				$dbw->insert(
					self::PAGES_TABLE_NAME,
					[ 'pl_page_id' => $this->mId, 'pl_level' => $bit ],
					__METHOD__

				);
				$relations['pl_id'] = $dbw->insertId();
				$this->state = $bit;

			}
		} else { // lock of non-existing page (also known as "title protection")

			if ( $limit == self::CREATE ) {
				$dbw->insert(
					self::TITLES_TABLE_NAME,
					[
						'plt_page_namespace' => $this->mTitle->getNamespace(),
						'plt_page_title' => $this->mTitle->getDBkey(),
					],
					__METHOD__
				);
				$relations['plt_id'] = $dbw->insertId();
				$this->state = $bit;
			} else {
				$dbw->delete(
					self::TITLES_TABLE_NAME,
					[ 'plt_id' => $this->getRestrictionId() ],
					__METHOD__
				);
			}
		}
		$this->invalidateCache();
		$params = [];
		if ( $logAction === "modify" ) {

			$params = [
				"4::description" => wfMessage( 'lock-' . self::getLevelFromBit( $current ) ),
				"5::description" => wfMessage( "$logAction-$limit" ),
				"detailes" => $logParamsDetails,
			];
		} else {
			$params = [
				"4::description" => wfMessage( "$logAction-$limit" ),
				"detailes" => $logParamsDetails,
			];
		}

		// Update the pagelockdown log
		$logEntry = new ManualLogEntry( 'pagelockdown', $logAction );
		$logEntry->setTarget( $this->mTitle );
		$logEntry->setRelations( $relations );
		$logEntry->setComment( $reason );
		$logEntry->setPerformer( $this->mUser );
		$logEntry->setParameters( $params );

		$logId = $logEntry->insert();

		return Status::newGood( $logId );
	}

	private function createCacheKey() {
		if ( !$this->mTitle ) {
			throw new InvalidArgumentException( 'Title is not set' );
		}
		if ( $this->mTitle->isSpecialPage() ) {
			return;
		}
		if( $this->mId === null ) {
			return;
		}
		if ( $this->mId === 0 ) {
			$this->pageCacheKey = $this->mCache->makeKey( 'page-lockdown', 'create', 'v1', $this->mTitle->getNamespace(), $this->mTitle->getDBkey() );
			return;
		}
		$this->pageCacheKey = $this->mCache->makeKey( 'page-lockdown', 'v1', $this->mTitle->getId() );
	}

	public function isUserAllowed( string $action ): bool {
		if ( !$this->mTitle ) {
			throw new InvalidArgumentException( 'Title is not set' );
		}
		if ( $this->mTitle->isSpecialPage() ) {
			return true;
		}
		if ( $this->state === null ) {
			$this->loadState();
		}
		if ( $this->state === self::FULL_BIT || !self::isBitEnabled( $this->state ) ) {
			return true;
		}
		$perm = self::bitPermission( $this->state, $action );
		return $perm !== false && ( $perm === '' || $this->mUser->isAllowed( $perm ) );
	}

	public function isUserAllowedToRead(): bool {
		return $this->isUserAllowed( 'read' );
	}

	public function isUserAllowedToEdit(): bool {
		if ( !$this->existingPage ) {
			return $this->isUserAllowed( 'create' );
		}
		return $this->isUserAllowed( 'edit' );
	}

	public function isUserAllowedToCreate(): bool {
		return $this->isUserAllowed( 'create' );
	}

	public function isUserIntrestedToRead(): bool {
		if ( !$this->mTitle || $this->mId === null ) {
			throw new InvalidArgumentException( 'Title or id is not set' );
		}
		if ( $this->mTitle->isSpecialPage() || !$this->existingPage ) {
			return true;
		}
		if ( $this->state === null ) {
			$this->loadState();
		}
		if ( $this->state === self::FULL_BIT || !self::isBitEnabled( $this->state ) ) {
			return true;
		}
		$userOptionLookup = MediaWikiServices::getInstance()->getUserOptionsLookup();
		$option = 'page-lockdown-show' . self::getLevelFromBit( $this->state );
		$intrested = $userOptionLookup->getOption( $this->mUser, $option );
		return $intrested !== null ? (bool)$intrested : (bool)$userOptionLookup->getDefaultOption( $option );
	}

	public function getErrorMessage( string $action, bool $preferenceError, ?IContextSource $context = null ) {
		if ( !$this->existingPage ) {
			return [ 'page-lockdown-create-error' ];
		}
		if ( $preferenceError ) {
			if ( $this->mUser->isAnon() ) { // For anonymous users
				return [ 'page-lockdown-preference-error-anon', wfMessage( 'pagelockdown-' . $action ) ];
			} else {
				return [ 'page-lockdown-preference-error', wfMessage( 'pagelockdown-' . $action ) ];
			}
		}
		return [ 'page-lockdown-error', implode( ', ', $this->getLinks( $action, $context ) ), wfMessage( 'pagelockdown-' . $action ) ];
	}

	public function isExistingPage(): bool {
		if ( $this->mTitle === null ) {
			throw new InvalidArgumentException( 'Title is not set' );
		}
		return $this->existingPage;
	}

	private function loadState( bool $useCache = true ) {
		if ( $useCache ) {
			$this->getCached();
		}
		$this->getFromDB();
	}

	public function getLevel(): string {
		if ( $this->state === null ) {
			$this->loadState();
		}
		return self::isBitEnabled( $this->state ) ? self::getLevelFromBit( $this->state ) : '';
	}

	public static function getLevelFromCache( Title $title, ?WANObjectCache $cache, ?LoadBalancer $loadBalancer ): string {
		if ( !$cache ) {
			$cache = MediaWikiServices::getInstance()->getMainWANObjectCache();
		}
		if ( !$loadBalancer ) {
			$loadBalancer = MediaWikiServices::getInstance()->getDBLoadBalancer();
		}
		$s = new self( $loadBalancer, $cache, $title );
		$s->loadState();
		return self::isBitEnabled( $s->state ) ? self::getLevelFromBit( $s->state ) : '';
	}

	/**
	 * @return int
	 * @throws InvalidArgumentException
	 */
	private function getCached() {
		if ( $this->pageCacheKey === null || $this->mId === null ) {
			throw new InvalidArgumentException( 'Title or id is not set' );
		}
		$this->state = $this->mCache->getWithSetCallback(
			$this->pageCacheKey,
			$this->mCache::TTL_MONTH,
			function () {
				return $this->getFromDB();
			}
		);
		return $this->state;
	}

	private function getFromDB( $db = DB_REPLICA ) {
		if ( $this->mId === null ) {
			throw new InvalidArgumentException( 'Title is not set' );
		}
		$exist = $this->mId > 0;
		$var = !$exist ? 'plt_id' : [ 'pl_level', 'pl_id' ];
		$where = !$exist ? [ 'plt_page_namespace' => $this->mTitle->getNamespace(), 'plt_page_title' => $this->mTitle->getDBkey() ] : [ 'pl_page_id' => $this->mId ];

		$dbr = $this->mLoadBalancer->getConnection( $db );
		$res = $dbr->newSelectQueryBuilder()
			->select( $var )
			->from( $exist ? self::PAGES_TABLE_NAME : self::TITLES_TABLE_NAME )
			->where( $where )
			->caller( __METHOD__ )
			->fetchRow();
		if ( $res === false ) {
			$this->state = self::FULL_BIT;
			return $this->state;
		}

		$this->restrictionId = $exist ? (int)$res->pl_id : (int)$res->plt_id;
		$this->state = $exist ? (int)$res->pl_level : self::CREATE_BIT;
		if ( $exist && !self::isBitEnabled( $this->state ) ) {
			$this->state = self::FULL_BIT;
		}
		return $this->state;
	}

	public static function getPagesTableName() {
		return self::PAGES_TABLE_NAME;
	}

	public static function getRevisionsTableName() {
		return self::REVISIONS_TABLE_NAME;
	}

	public static function getTitlesTableName() {
		return self::TITLES_TABLE_NAME;
	}

	public static function getLevelFromBit( int $bit ): string {
		switch ( $bit ) {
			case self::FULL_BIT:
				return '';
			case self::CREATE_BIT:
				return self::CREATE;
			case self::READ_BIT:
				return self::READ;
			case self::READ_SEMI_BIT:
				return self::READ_SEMI;
			case self::EDIT_BIT:
				return self::EDIT;
			case self::EDIT_SEMI_BIT:
				return self::EDIT_SEMI;
			case self::EDIT_FULL_BIT:
				return self::EDIT_FULL;
			default:
				return '';
		}
	}

	/**
	 * @todo change default to full bit and make sure it works were it is used
	 */
	public static function getBitFromLevel( string $level ): int {
		switch ( $level ) {
			case self::CREATE:
				return self::CREATE_BIT;
			case self::READ:
				return self::READ_BIT;
			case self::READ_SEMI:
				return self::READ_SEMI_BIT;
			case self::EDIT:
				return self::EDIT_BIT;
			case self::EDIT_SEMI:
				return self::EDIT_SEMI_BIT;
			case self::EDIT_FULL:
				return self::EDIT_FULL_BIT;
			case 'none':
				return self::FULL_BIT;
			default:
				return -1;
		}
	}

	public static function getApplicableTypes( bool $existingPage ) {
		if ( $existingPage ) {
			$types = [
				self::FULL_BIT => '',
				self::READ_BIT => self::READ,
				self::READ_SEMI_BIT => self::READ_SEMI,
				self::EDIT_BIT => self::EDIT,
				self::EDIT_SEMI_BIT => self::EDIT_SEMI,
				self::EDIT_FULL_BIT => self::EDIT_FULL,
			];
			return array_filter(
				$types,
				static function ( $level ) {
					return $level === '' || self::isLevelEnabled( $level );
				}
			);
		}
		return [
			-1 => '',
			self::CREATE_BIT => self::CREATE,
		];
	}

	private static function isBitEnabled( int $bit ): bool {
		return self::isLevelEnabled( self::getLevelFromBit( $bit ) );
	}

	private static function isLevelEnabled( string $level ): bool {
		if ( $level === '' || $level === self::CREATE || $level === self::EDIT_FULL ) {
			return true;
		}
		if ( !isset( self::LEVEL_CONFIGS[$level] ) ) {
			return false;
		}
		return (bool)MediaWikiServices::getInstance()->getMainConfig()->get( self::LEVEL_CONFIGS[$level] );
	}

	public static function getLevelPermission( $level, string $action ): string {
		$bit = is_int( $level ) ? $level : self::getBitFromLevel( $level );
		if ( $bit === -1 ) {
			return '';
		}
		$permission = self::bitPermission( $bit, $action );
		return $permission === false ? '' : $permission;
	}

	/**
	 * @param int $level
	 * @param string $action
	 * @return string|false false means no one is allowed, empty string means everyone is allowed, otherwise permission name is returned
	 */
	private static function bitPermission( int $level, string $action ): string|bool {
		if ( !self::isBitEnabled( $level ) ) {
			return '';
		}
		switch ( $level ) {
			case self::FULL_BIT:
				return '';
			case self::READ_BIT:
				return self::READ_LOCKED_PERM;
			case self::READ_SEMI_BIT:
				return self::READ_SEMI_LOCKED_PERM;
			case self::CREATE_BIT:
				if ( $action === 'read' ) {
					return self::LOCKDOWN_PERM;
				}
				return false;
		}
		if ( $action === 'edit' ) {
			switch ( $level ) {
				case self::EDIT_BIT:
					return self::EDIT_LOCKED_PERM;
				case self::EDIT_SEMI_BIT:
					return self::EDIT_SEMI_LOCKED_PERM;
			}
		}

		return '';
	}

	public static function getPerferences( User $user, &$perferences ) {
		$linksOptions = [];
		$showOptions = [];
		$p = 1;
		while ( $option = self::getLevelFromBit( $p ) ) {
			$permission = self::bitPermission( $p, 'read' );
			if ( self::isBitEnabled( $p ) && ( $permission === '' || ( $permission !== false && $user->isAllowed( $permission ) ) ) ) {
				$linksOptions['pl-link-' . $option . '-locked'] = $option;
				$showOptions['pl-show-' . $option . '-locked'] = $option;
			}
			$p <<= 1;
		}
		$perferences['page-lockdown-links'] = [
				'type' => 'multiselect',
				'label-message' => 'page-lockdown-links',
				'options-messages' => $linksOptions,
				'help-message' => 'page-lockdown-links-help',
				'section' => 'page-lockdown/links',
			];
		$perferences['page-lockdown-show'] = [
				'type' => 'multiselect',
				'label-message' => 'page-lockdown-show',
				'options-messages' => $showOptions,
				'help-message' => 'page-lockdown-show-help',
				'section' => 'page-lockdown/show',
			];
	}

	public static function getBodyClasses( User $user ) {
		$userOptionsLookup = MediaWikiServices::getInstance()->getUserOptionsLookup();
		$safe = $user->isSafeToLoad();
		$classes = [];
		$p = 1;
		while ( $option = self::getLevelFromBit( $p ) ) {
			if ( !self::isBitEnabled( $p ) ) {
				$p <<= 1;
				continue;
			}
			$class = 'pl-preference-hide-' . $option;
			$opt = 'page-lockdown-links' . $option;
			if ( $safe && !$user->isAllowed( self::getLevelPermission( $p, 'read' ) ) ) {
				$classes[] = $class;
			} elseif ( !$safe || $userOptionsLookup->getOption( $user, $opt ) === null ) {
				if ( !(bool)$userOptionsLookup->getDefaultOption( $opt ) ) {
					$classes[] = $class;
				}
			} elseif ( !$userOptionsLookup->getBoolOption( $user, $opt ) ) {
				$classes[] = $class;
			}
			$p <<= 1;
		}
		return $classes;
	}

	/**
	 * get group links for messages
	 * @param string $right
	 * @return array
	 */
	private function getLinks( string $action, ?IContextSource $context = null ) {
		$right = self::bitPermission( $this->state, $action );
		$groups = MediaWikiServices::getInstance()->getGroupPermissionsLookup()->getGroupsWithPermission( $right );
		$links = [];
		foreach ( $groups as $group ) {
			$links[] = UserGroupMembership::getLinkWiki( $group, $context !== null ? $context : RequestContext::getMain() );
		}
		return $links;
	}

	private function invalidateCache() {
		if ( $this->pageCacheKey === null ) {
			throw new InvalidArgumentException( 'cachekey is not set' );
		}
		$this->mCache->delete( $this->pageCacheKey );
	}
}
