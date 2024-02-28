<?php

namespace MediaWiki\Extension\AspaklaryaLockDown;

use InvalidArgumentException;
use MediaWiki\Page\LegacyArticleIdAccess;
use MediaWiki\Page\PageIdentity;
use MediaWiki\Page\PageIdentityValue;
use MediaWiki\Revision\RevisionAccessException;
use MediaWiki\Revision\RevisionStore;
use MediaWiki\Revision\RevisionStoreCacheRecord;
use MediaWiki\Revision\RevisionStoreRecord;
use MediaWiki\Storage\SqlBlobStore;
use RuntimeException;
use stdClass;
use Wikimedia\Rdbms\ILoadBalancer;
use ActorMigration;
use BagOStuff;
use CommentStore;
use CommentStoreComment;
use Content;
use DBAccessObjectUtils;
use FallbackContent;
use IDBAccessObject;
use LogicException;
use MediaWiki\Content\IContentHandlerFactory;
use MediaWiki\DAO\WikiAwareEntity;
use MediaWiki\HookContainer\HookContainer;
use MediaWiki\HookContainer\HookRunner;
use MediaWiki\Linker\LinkTarget;

use MediaWiki\Page\PageStore;
use MediaWiki\Permissions\Authority;
use MediaWiki\Revision\RevisionSlots;
use MediaWiki\Revision\SlotRecord;
use MediaWiki\Revision\SlotRoleRegistry;
use MediaWiki\Storage\BlobAccessException;
use MediaWiki\Storage\BlobStore;
use MediaWiki\Storage\NameTableStore;
use MediaWiki\Storage\RevisionSlotsUpdate;
use MediaWiki\User\ActorStore;
use MediaWiki\User\UserIdentity;
use MWException;
use MWTimestamp;
use MWUnknownContentModelException;
use Psr\Log\LoggerAwareInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use RecentChange;
use StatusValue;
use Title;
use TitleFactory;
use Traversable;
use WANObjectCache;
use Wikimedia\Assert\Assert;
use Wikimedia\IPUtils;
use Wikimedia\Rdbms\Database;
use Wikimedia\Rdbms\DBConnRef;
use Wikimedia\Rdbms\IDatabase;
use Wikimedia\Rdbms\IResultWrapper;

class ALRevisionStore extends RevisionStore {
	use LegacyArticleIdAccess;

	/**
	 * @var SqlBlobStore
	 */
	private $blobStore;

	/**
	 * @var false|string
	 */
	private $wikiId;

	/**
	 * @var ILoadBalancer
	 */
	private $loadBalancer;

	/**
	 * @var WANObjectCache
	 */
	private $cache;

	/**
	 * @var BagOStuff
	 */
	private $localCache;

	/**
	 * @var CommentStore
	 */
	private $commentStore;

	/**
	 * @var ActorMigration
	 */
	private $actorMigration;

	/** @var ActorStore */
	private $actorStore;

	/**
	 * @var LoggerInterface
	 */
	private $logger;

	/**
	 * @var NameTableStore
	 */
	private $contentModelStore;

	/**
	 * @var NameTableStore
	 */
	private $slotRoleStore;

	/** @var SlotRoleRegistry */
	private $slotRoleRegistry;

	/** @var IContentHandlerFactory */
	private $contentHandlerFactory;

	/** @var HookRunner */
	private $hookRunner;

	/** @var PageStore */
	private $pageStore;

	/** @var TitleFactory */
	private $titleFactory;

	/**
	 * @param ILoadBalancer $loadBalancer
	 * @param SqlBlobStore $blobStore
	 * @param WANObjectCache $cache A cache for caching revision rows. This can be the local
	 *        wiki's default instance even if $wikiId refers to a different wiki, since
	 *        makeGlobalKey() is used to constructed a key that allows cached revision rows from
	 *        the same database to be re-used between wikis. For example, enwiki and frwiki will
	 *        use the same cache keys for revision rows from the wikidatawiki database, regardless
	 *        of the cache's default key space.
	 * @param BagOStuff $localCache Another layer of cache, best to use APCu here.
	 * @param CommentStore $commentStore
	 * @param NameTableStore $contentModelStore
	 * @param NameTableStore $slotRoleStore
	 * @param SlotRoleRegistry $slotRoleRegistry
	 * @param ActorMigration $actorMigration
	 * @param ActorStore $actorStore
	 * @param IContentHandlerFactory $contentHandlerFactory
	 * @param PageStore $pageStore
	 * @param TitleFactory $titleFactory
	 * @param HookContainer $hookContainer
	 * @param false|string $wikiId Relevant wiki id or WikiAwareEntity::LOCAL for the current one
	 *
	 * @todo $blobStore should be allowed to be any BlobStore!
	 *
	 */
	public function __construct(
		ILoadBalancer $loadBalancer,
		SqlBlobStore $blobStore,
		WANObjectCache $cache,
		BagOStuff $localCache,
		CommentStore $commentStore,
		NameTableStore $contentModelStore,
		NameTableStore $slotRoleStore,
		SlotRoleRegistry $slotRoleRegistry,
		ActorMigration $actorMigration,
		ActorStore $actorStore,
		IContentHandlerFactory $contentHandlerFactory,
		PageStore $pageStore,
		TitleFactory $titleFactory,
		HookContainer $hookContainer,
		$wikiId = WikiAwareEntity::LOCAL
	) {
		Assert::parameterType(['string', 'false'], $wikiId, '$wikiId');

		$this->loadBalancer = $loadBalancer;
		$this->blobStore = $blobStore;
		$this->cache = $cache;
		$this->localCache = $localCache;
		$this->commentStore = $commentStore;
		$this->contentModelStore = $contentModelStore;
		$this->slotRoleStore = $slotRoleStore;
		$this->slotRoleRegistry = $slotRoleRegistry;
		$this->actorMigration = $actorMigration;
		$this->actorStore = $actorStore;
		$this->wikiId = $wikiId;
		$this->logger = new NullLogger();
		$this->contentHandlerFactory = $contentHandlerFactory;
		$this->pageStore = $pageStore;
		$this->titleFactory = $titleFactory;
		$this->hookRunner = new HookRunner($hookContainer);
	}
	/**
	 * @param PageIdentity $page
	 *
	 * @return PageIdentity
	 */
	private function wrapPage( PageIdentity $page ): PageIdentity {
		if ( $this->wikiId === WikiAwareEntity::LOCAL ) {
			// NOTE: since there is still a lot of code that needs a full Title,
			//       and uses Title::castFromPageIdentity() to get one, it's beneficial
			//       to create a Title right away if we can, so we don't have to convert
			//       over and over later on.
			//       When there is less need to convert to Title, this special case can
			//       be removed.
			// @phan-suppress-next-line PhanTypeMismatchReturnNullable castFrom does not return null here
			return $this->titleFactory->castFromPageIdentity( $page );
		} else {
			return $page;
		}
	}

	/**
	 * Determines the page based on the available information.
	 *
	 * @param int|null $pageId
	 * @param int|null $revId
	 * @param int $queryFlags
	 *
	 * @return PageIdentity
	 * @throws RevisionAccessException
	 */
	private function getPage( ?int $pageId, ?int $revId, int $queryFlags = self::READ_NORMAL ) {
		if ( !$pageId && !$revId ) {
			throw new InvalidArgumentException( '$pageId and $revId cannot both be 0 or null' );
		}

		// This method recalls itself with READ_LATEST if READ_NORMAL doesn't get us a Title
		// So ignore READ_LATEST_IMMUTABLE flags and handle the fallback logic in this method
		if ( DBAccessObjectUtils::hasFlags( $queryFlags, self::READ_LATEST_IMMUTABLE ) ) {
			$queryFlags = self::READ_NORMAL;
		}

		// Loading by ID is best
		if ( $pageId !== null && $pageId > 0 ) {
			$page = $this->pageStore->getPageById( $pageId, $queryFlags );
			if ( $page ) {
				return $this->wrapPage( $page );
			}
		}

		// rev_id is defined as NOT NULL, but this revision may not yet have been inserted.
		if ( $revId !== null && $revId > 0 ) {
			$pageQuery = $this->pageStore->newSelectQueryBuilder( $queryFlags )
				->join( 'revision', null, 'page_id=rev_page' )
				->conds( [ 'rev_id' => $revId ] )
				->caller( __METHOD__ );

			$page = $pageQuery->fetchPageRecord();
			if ( $page ) {
				return $this->wrapPage( $page );
			}
		}

		// If we still don't have a title, fallback to primary DB if that wasn't already happening.
		if ( $queryFlags === self::READ_NORMAL ) {
			$title = $this->getPage( $pageId, $revId, self::READ_LATEST );
			if ( $title ) {
				$this->logger->info(
					__METHOD__ . ' fell back to READ_LATEST and got a Title.',
					[ 'exception' => new RuntimeException() ]
				);
				return $title;
			}
		}

		throw new RevisionAccessException(
			'Could not determine title for page ID {page_id} and revision ID {rev_id}',
			[
				'page_id' => $pageId,
				'rev_id' => $revId,
			]
		);
	}

	/**
	 * Check that the given row matches the given Title object.
	 * When a mismatch is detected, this tries to re-load the title from primary DB,
	 * to avoid spurious errors during page moves.
	 *
	 * @param \stdClass $row
	 * @param PageIdentity $page
	 * @param array $context
	 *
	 * @return Pageidentity
	 */
	private function ensureRevisionRowMatchesPage( $row, PageIdentity $page, $context = [] ) {
		$revId = (int)( $row->rev_id ?? 0 );
		$revPageId = (int)( $row->rev_page ?? 0 ); // XXX: also check $row->page_id?
		$expectedPageId = $page->getId( $this->wikiId );
		// Avoid fatal error when the Title's ID changed, T246720
		if ( $revPageId && $expectedPageId && $revPageId !== $expectedPageId ) {
			// NOTE: PageStore::getPageByReference may use the page ID, which we don't want here.
			$pageRec = $this->pageStore->getPageByName(
				$page->getNamespace(),
				$page->getDBkey(),
				PageStore::READ_LATEST
			);
			$masterPageId = $pageRec->getId( $this->wikiId );
			$masterLatest = $pageRec->getLatest( $this->wikiId );
			if ( $revPageId === $masterPageId ) {
				if ( $page instanceof Title ) {
					// If we were using a Title object, keep using it, but update the page ID.
					// This way, we don't unexpectedly mix Titles with immutable value objects.
					$page->resetArticleID( $masterPageId );

				} else {
					$page = $pageRec;
				}

				$this->logger->info(
					"Encountered stale Title object",
					[
						'page_id_stale' => $expectedPageId,
						'page_id_reloaded' => $masterPageId,
						'page_latest' => $masterLatest,
						'rev_id' => $revId,
						'exception' => new RuntimeException(),
					] + $context
				);
			} else {
				$expectedTitle = (string)$page;
				if ( $page instanceof Title ) {
					// If we started with a Title, keep using a Title.
					$page = $this->titleFactory->newFromID( $revPageId );
				} else {
					$page = $pageRec;
				}

				// This could happen if a caller to e.g. getRevisionById supplied a Title that is
				// plain wrong. In this case, we should ideally throw an IllegalArgumentException.
				// However, it is more likely that we encountered a race condition during a page
				// move (T268910, T279832) or database corruption (T263340). That situation
				// should not be ignored, but we can allow the request to continue in a reasonable
				// manner without breaking things for the user.
				$this->logger->error(
					"Encountered mismatching Title object (see T259022, T268910, T279832, T263340)",
					[
						'expected_page_id' => $masterPageId,
						'expected_page_title' => $expectedTitle,
						'rev_page' => $revPageId,
						'rev_page_title' => (string)$page,
						'page_latest' => $masterLatest,
						'rev_id' => $revId,
						'exception' => new RuntimeException(),
					] + $context
				);
			}
		}

		// @phan-suppress-next-line PhanTypeMismatchReturnNullable getPageByName/newFromID should not return null
		return $page;
	}

	/**
	 * @param int $queryFlags a bit field composed of READ_XXX flags
	 *
	 * @return DBConnRef
	 */
	private function getDBConnectionRefForQueryFlags( $queryFlags ) {
		list( $mode, ) = DBAccessObjectUtils::getDBOptions( $queryFlags );
		return $this->getDBConnectionRef( $mode );
	}

	/**
	 * @param int $mode DB_PRIMARY or DB_REPLICA
	 *
	 * @param array $groups
	 * @return DBConnRef
	 */
	private function getDBConnectionRef( $mode, $groups = [] ) {
		$lb = $this->getDBLoadBalancer();
		return $lb->getConnectionRef( $mode, $groups, $this->wikiId );
	}

	/**
	 * @return ILoadBalancer
	 */
	private function getDBLoadBalancer() {
		return $this->loadBalancer;
	}

	/**
	 * Factory method for RevisionSlots based on a revision ID.
	 *
	 * @note If other code has a need to construct RevisionSlots objects, this should be made
	 * public, since RevisionSlots instances should not be constructed directly.
	 *
	 * @param int $revId
	 * @param \stdClass $revisionRow
	 * @param \stdClass[]|null $slotRows
	 * @param int $queryFlags
	 * @param PageIdentity $page
	 *
	 * @return RevisionSlots
	 * @throws MWException
	 */
	private function newRevisionSlots(
		$revId,
		$revisionRow,
		$slotRows,
		$queryFlags,
		PageIdentity $page
	) {
		if ( $slotRows ) {
			$slots = new RevisionSlots(
				$this->constructSlotRecords( $revId, $slotRows, $queryFlags, $page )
			);
		} else {
			$slots = new RevisionSlots( function () use( $revId, $queryFlags, $page ) {
				return $this->loadSlotRecords( $revId, $queryFlags, $page );
			} );
		}

		return $slots;
	}

	/**
	 * @param int $revId The revision to load slots for.
	 * @param int $queryFlags
	 * @param PageIdentity $page
	 *
	 * @return SlotRecord[]
	 */
	private function loadSlotRecords( $revId, $queryFlags, PageIdentity $page ) {
		// TODO: Find a way to add NS_MODULE from Scribunto here
		if ( $page->getNamespace() !== NS_TEMPLATE ) {
			$res = $this->loadSlotRecordsFromDb( $revId, $queryFlags, $page );
			return $this->constructSlotRecords( $revId, $res, $queryFlags, $page );
		}

		// TODO: These caches should not be needed. See T297147#7563670
		$res = $this->localCache->getWithSetCallback(
			$this->localCache->makeKey(
				'revision-slots',
				$page->getWikiId(),
				$page->getId( $page->getWikiId() ),
				$revId
			),
			$this->localCache::TTL_HOUR,
			function () use ( $revId, $queryFlags, $page ) {
				return $this->cache->getWithSetCallback(
					$this->cache->makeKey(
						'revision-slots',
						$page->getWikiId(),
						$page->getId( $page->getWikiId() ),
						$revId
					),
					WANObjectCache::TTL_DAY,
					function () use ( $revId, $queryFlags, $page ) {
						$res = $this->loadSlotRecordsFromDb( $revId, $queryFlags, $page );
						if ( !$res ) {
							// Avoid caching
							return false;
						}
						return $res;
					}
				);
			}
		);
		if ( !$res ) {
			$res = [];
		}

		return $this->constructSlotRecords( $revId, $res, $queryFlags, $page );
	}

	private function loadSlotRecordsFromDb( $revId, $queryFlags, PageIdentity $page ): array {
		$revQuery = $this->getSlotsQueryInfo( [ 'content' ] );

		list( $dbMode, $dbOptions ) = DBAccessObjectUtils::getDBOptions( $queryFlags );
		$db = $this->getDBConnectionRef( $dbMode );

		$res = $db->select(
			$revQuery['tables'],
			$revQuery['fields'],
			[
				'slot_revision_id' => $revId,
			],
			__METHOD__,
			$dbOptions,
			$revQuery['joins']
		);

		if ( !$res->numRows() && !( $queryFlags & self::READ_LATEST ) ) {
			// If we found no slots, try looking on the primary database (T212428, T252156)
			$this->logger->info(
				__METHOD__ . ' falling back to READ_LATEST.',
				[
					'revid' => $revId,
					'exception' => new RuntimeException(),
				]
			);
			return $this->loadSlotRecordsFromDb(
				$revId,
				$queryFlags | self::READ_LATEST,
				$page
			);
		}
		return iterator_to_array( $res );
	}

	/**
	 * Factory method for SlotRecords based on known slot rows.
	 *
	 * @param int $revId The revision to load slots for.
	 * @param \stdClass[]|IResultWrapper $slotRows
	 * @param int $queryFlags
	 * @param PageIdentity $page
	 * @param array|null $slotContents a map from blobAddress to slot
	 * 	content blob or Content object.
	 *
	 * @return SlotRecord[]
	 */
	private function constructSlotRecords(
		$revId,
		$slotRows,
		$queryFlags,
		PageIdentity $page,
		$slotContents = null
	) {
		$slots = [];

		foreach ( $slotRows as $row ) {
			// Resolve role names and model names from in-memory cache, if they were not joined in.
			if ( !isset( $row->role_name ) ) {
				$row->role_name = $this->slotRoleStore->getName( (int)$row->slot_role_id );
			}

			if ( !isset( $row->model_name ) ) {
				if ( isset( $row->content_model ) ) {
					$row->model_name = $this->contentModelStore->getName( (int)$row->content_model );
				} else {
					// We may get here if $row->model_name is set but null, perhaps because it
					// came from rev_content_model, which is NULL for the default model.
					$slotRoleHandler = $this->slotRoleRegistry->getRoleHandler( $row->role_name );
					$row->model_name = $slotRoleHandler->getDefaultModel( $page );
				}
			}

			// We may have a fake blob_data field from getSlotRowsForBatch(), use it!
			if ( isset( $row->blob_data ) ) {
				$slotContents[$row->content_address] = $row->blob_data;
			}

			$contentCallback = function ( SlotRecord $slot ) use ( $slotContents, $queryFlags ) {
				$blob = null;
				if ( isset( $slotContents[$slot->getAddress()] ) ) {
					$blob = $slotContents[$slot->getAddress()];
					if ( $blob instanceof Content ) {
						return $blob;
					}
				}
				return $this->loadSlotContent( $slot, $blob, null, null, $queryFlags );
			};

			$slots[$row->role_name] = new SlotRecord( $row, $contentCallback );
		}

		if ( !isset( $slots[SlotRecord::MAIN] ) ) {
			$this->logger->error(
				__METHOD__ . ': Main slot of revision not found in database. See T212428.',
				[
					'revid' => $revId,
					'queryFlags' => $queryFlags,
					'exception' => new RuntimeException(),
				]
			);

			throw new RevisionAccessException(
				'Main slot of revision not found in database. See T212428.'
			);
		}

		return $slots;
	}


	/**
	 * Loads a Content object based on a slot row.
	 *
	 * This method does not call $slot->getContent(), and may be used as a callback
	 * called by $slot->getContent().
	 *
	 * MCR migration note: this roughly corresponded to Revision::getContentInternal
	 *
	 * @param SlotRecord $slot The SlotRecord to load content for
	 * @param string|null $blobData The content blob, in the form indicated by $blobFlags
	 * @param string|null $blobFlags Flags indicating how $blobData needs to be processed.
	 *        Use null if no processing should happen. That is in contrast to the empty string,
	 *        which causes the blob to be decoded according to the configured legacy encoding.
	 * @param string|null $blobFormat MIME type indicating how $dataBlob is encoded
	 * @param int $queryFlags
	 *
	 * @throws RevisionAccessException
	 * @return Content
	 */
	private function loadSlotContent(
		SlotRecord $slot,
		?string $blobData = null,
		?string $blobFlags = null,
		?string $blobFormat = null,
		int $queryFlags = 0
	) {
		if ( $blobData !== null ) {
			$cacheKey = $slot->hasAddress() ? $slot->getAddress() : null;

			if ( $blobFlags === null ) {
				// No blob flags, so use the blob verbatim.
				$data = $blobData;
			} else {
				$data = $this->blobStore->expandBlob( $blobData, $blobFlags, $cacheKey );
				if ( $data === false ) {
					throw new RevisionAccessException(
						'Failed to expand blob data using flags {flags} (key: {cache_key})',
						[
							'flags' => $blobFlags,
							'cache_key' => $cacheKey,
						]
					);
				}
			}

		} else {
			$address = $slot->getAddress();
			try {
				$data = $this->blobStore->getBlob( $address, $queryFlags );
			} catch ( BlobAccessException $e ) {
				throw new RevisionAccessException(
					'Failed to load data blob from {address}'
						. 'If this problem persist, use the findBadBlobs maintenance script '
						. 'to investigate the issue and mark bad blobs.',
					[ 'address' => $e->getMessage() ],
					0,
					$e
				);
			}
		}

		$model = $slot->getModel();

		// If the content model is not known, don't fail here (T220594, T220793, T228921)
		if ( !$this->contentHandlerFactory->isDefinedModel( $model ) ) {
			$this->logger->warning(
				"Undefined content model '$model', falling back to FallbackContent",
				[
					'content_address' => $slot->getAddress(),
					'rev_id' => $slot->getRevision(),
					'role_name' => $slot->getRole(),
					'model_name' => $model,
					'exception' => new RuntimeException()
				]
			);

			return new FallbackContent( $data, $model );
		}

		return $this->contentHandlerFactory
			->getContentHandler( $model )
			->unserializeContent( $data, $blobFormat );
	}

	/**
	 * Given a set of conditions, return a row with the
	 * fields necessary to build RevisionRecord objects.
	 *
	 * MCR migration note: this corresponded to Revision::fetchFromConds
	 *
	 * @param IDatabase $db
	 * @param array $conditions
	 * @param int $flags (optional)
	 * @param array $options (optional) additional query options
	 *
	 * @return \stdClass|false data row as a raw object
	 */
	private function fetchRevisionRowFromConds(
		IDatabase $db,
		array $conditions,
		int $flags = IDBAccessObject::READ_NORMAL,
		array $options = []
	) {
		$this->checkDatabaseDomain( $db );

		$revQuery = $this->getQueryInfo( [ 'page', 'user' ] );
		if ( ( $flags & self::READ_LOCKING ) == self::READ_LOCKING ) {
			$options[] = 'FOR UPDATE';
		}
		return $db->selectRow(
			$revQuery['tables'],
			$revQuery['fields'],
			$conditions,
			__METHOD__,
			$options,
			$revQuery['joins']
		);
	}

	/**
	 * Throws an exception if the given database connection does not belong to the wiki this
	 * RevisionStore is bound to.
	 *
	 * @param IDatabase $db
	 * @throws MWException
	 */
	private function checkDatabaseDomain( IDatabase $db ) {
		$dbDomain = $db->getDomainID();
		$storeDomain = $this->loadBalancer->resolveDomainID( $this->wikiId );
		if ( $dbDomain === $storeDomain ) {
			return;
		}

		throw new MWException( "DB connection domain '$dbDomain' does not match '$storeDomain'" );
	}

	/**
	 * @see newFromRevisionRow()
	 *
	 * @param stdClass $row A database row generated from a query based on getQueryInfo()
	 * @param null|stdClass[]|RevisionSlots $slots
	 *  - Database rows generated from a query based on getSlotsQueryInfo
	 *    with the 'content' flag set. Or
	 *  - RevisionSlots instance
	 * @param int $queryFlags
	 * @param PageIdentity|null $page
	 * @param bool $fromCache if true, the returned RevisionRecord will ensure that no stale
	 *   data is returned from getters, by querying the database as needed
	 *
	 * @return RevisionRecord
	 * @throws MWException
	 * @throws RevisionAccessException
	 * @see RevisionFactory::newRevisionFromRow
	 */
	public function newRevisionFromRowAndSlots(
		stdClass $row,
		$slots,
		int $queryFlags = 0,
		?PageIdentity $page = null,
		bool $fromCache = false
	) {
		if (!$page) {
			if (
				isset($row->page_id)
				&& isset($row->page_namespace)
				&& isset($row->page_title)
			) {
				$page = new PageIdentityValue(
					(int)$row->page_id,
					(int)$row->page_namespace,
					$row->page_title,
					$this->wikiId
				);

				$page = $this->wrapPage($page);
			} else {
				$pageId = (int)($row->rev_page ?? 0);
				$revId = (int)($row->rev_id ?? 0);

				$page = $this->getPage($pageId, $revId, $queryFlags);
			}
		} else {
			$page = $this->ensureRevisionRowMatchesPage($row, $page);
		}

		if (!$page) {
			// This should already have been caught about, but apparently
			// it not always is, see T286877.
			throw new RevisionAccessException(
				"Failed to determine page associated with revision {$row->rev_id}"
			);
		}

		try {
			$user = $this->actorStore->newActorFromRowFields(
				$row->rev_user ?? null,
				$row->rev_user_text ?? null,
				$row->rev_actor ?? null
			);
		} catch (InvalidArgumentException $ex) {
			$this->logger->warning('Could not load user for revision {rev_id}', [
				'rev_id' => $row->rev_id,
				'rev_actor' => $row->rev_actor ?? 'null',
				'rev_user_text' => $row->rev_user_text ?? 'null',
				'rev_user' => $row->rev_user ?? 'null',
				'exception' => $ex
			]);
			$user = $this->actorStore->getUnknownActor();
		}

		$db = $this->getDBConnectionRefForQueryFlags($queryFlags);
		// Legacy because $row may have come from self::selectFields()
		$comment = $this->commentStore->getCommentLegacy($db, 'rev_comment', $row, true);

		if (!($slots instanceof RevisionSlots)) {
			$slots = $this->newRevisionSlots((int)$row->rev_id, $row, $slots, $queryFlags, $page);
		}

		// If this is a cached row, instantiate a cache-aware RevisionRecord to avoid stale data.
		if ($fromCache) {
			$rev = new RevisionStoreCacheRecord(
				function ($revId) use ($queryFlags) {
					$db = $this->getDBConnectionRefForQueryFlags($queryFlags);
					$row = $this->fetchRevisionRowFromConds(
						$db,
						['rev_id' => intval($revId)]
					);
					if (!$row && !($queryFlags & self::READ_LATEST)) {
						// If we found no slots, try looking on the primary database (T259738)
						$this->logger->info(
							'RevisionStoreCacheRecord refresh callback falling back to READ_LATEST.',
							[
								'revid' => $revId,
								'exception' => new RuntimeException(),
							]
						);
						$dbw = $this->getDBConnectionRefForQueryFlags(self::READ_LATEST);
						$row = $this->fetchRevisionRowFromConds(
							$dbw,
							['rev_id' => intval($revId)]
						);
					}
					if (!$row) {
						return [null, null];
					}
					return [
						$row->rev_deleted,
						$this->actorStore->newActorFromRowFields(
							$row->rev_user ?? null,
							$row->rev_user_text ?? null,
							$row->rev_actor ?? null
						)
					];
				},
				$page,
				$user,
				$comment,
				$row,
				$slots,
				$this->wikiId
			);
		} else {
			$rev = new RevisionStoreRecord(
				$page,
				$user,
				$comment,
				$row,
				$slots,
				$this->wikiId
			);
		}
		return $rev;
	}
}
