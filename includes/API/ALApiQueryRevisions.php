<?php

/**
 * Copyright © 2006 Yuri Astrakhan "<Firstname><Lastname>@gmail.com"
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
 */

namespace MediaWiki\Extension\AspaklaryaLockDown\API;

use ActorMigration;
use ApiBase;
use ApiMessage;
use ApiPageSet;
use ApiQuery;
use ApiQueryRevisions;
use ChangeTags;
use MediaWiki\Content\IContentHandlerFactory;
use MediaWiki\Content\Renderer\ContentRenderer;
use MediaWiki\Content\Transform\ContentTransformer;
use MediaWiki\Extension\AspaklaryaLockDown\ALDBData;
use MediaWiki\Revision\RevisionRecord;
use MediaWiki\Revision\RevisionStore;
use MediaWiki\Revision\SlotRoleRegistry;
use MediaWiki\Storage\NameTableAccessException;
use MediaWiki\Storage\NameTableStore;
use ParserFactory;
use Status;
use Title;

/**
 * A query action to enumerate revisions of a given page, or show top revisions
 * of multiple pages. Various pieces of information may be shown - flags,
 * comments, and the actual wiki markup of the rev. In the enumeration mode,
 * ranges of revisions may be requested and filtered.
 *
 * @ingroup API
 */
class ALApiQueryRevisions extends ApiQueryRevisions {

    /** @var RevisionStore */
    private $revisionStore;

    /** @var NameTableStore */
    private $changeTagDefStore;

    /** @var ActorMigration */
    private $actorMigration;

    /**
     * @param ApiQuery $query
     * @param string $moduleName
     * @param RevisionStore $revisionStore
     * @param IContentHandlerFactory $contentHandlerFactory
     * @param ParserFactory $parserFactory
     * @param SlotRoleRegistry $slotRoleRegistry
     * @param NameTableStore $changeTagDefStore
     * @param ActorMigration $actorMigration
     * @param ContentRenderer $contentRenderer
     * @param ContentTransformer $contentTransformer
     */
    public function __construct(
        ApiQuery $query,
        $moduleName,
        RevisionStore $revisionStore,
        IContentHandlerFactory $contentHandlerFactory,
        ParserFactory $parserFactory,
        SlotRoleRegistry $slotRoleRegistry,
        NameTableStore $changeTagDefStore,
        ActorMigration $actorMigration,
        ContentRenderer $contentRenderer,
        ContentTransformer $contentTransformer
    ) {
        parent::__construct(
            $query,
            $moduleName,
            $revisionStore,
            $contentHandlerFactory,
            $parserFactory,
            $slotRoleRegistry,
            $changeTagDefStore,
            $actorMigration,
            $contentRenderer,
            $contentTransformer
        );
        $this->revisionStore = $revisionStore;
        $this->changeTagDefStore = $changeTagDefStore;
        $this->actorMigration = $actorMigration;
    }

    protected function run(ApiPageSet $resultPageSet = null) {
        $params = $this->extractRequestParams(false);

        // If any of those parameters are used, work in 'enumeration' mode.
        // Enum mode can only be used when exactly one page is provided.
        // Enumerating revisions on multiple pages make it extremely
        // difficult to manage continuations and require additional SQL indexes
        $enumRevMode = ($params['user'] !== null || $params['excludeuser'] !== null ||
            $params['limit'] !== null || $params['startid'] !== null ||
            $params['endid'] !== null || $params['dir'] === 'newer' ||
            $params['start'] !== null || $params['end'] !== null);

        $pageSet = $this->getPageSet();
        $pageCount = $pageSet->getGoodTitleCount();
        $revCount = $pageSet->getRevisionCount();

        // Optimization -- nothing to do
        if ($revCount === 0 && $pageCount === 0) {
            // Nothing to do
            return;
        }
        if ($revCount > 0 && count($pageSet->getLiveRevisionIDs()) === 0) {
            // We're in revisions mode but all given revisions are deleted
            return;
        }

        if ($revCount > 0 && $enumRevMode) {
            $this->dieWithError(
                ['apierror-revisions-norevids', $this->getModulePrefix()],
                'invalidparammix'
            );
        }

        if ($pageCount > 1 && $enumRevMode) {
            $this->dieWithError(
                ['apierror-revisions-singlepage', $this->getModulePrefix()],
                'invalidparammix'
            );
        }

        // In non-enum mode, rvlimit can't be directly used. Use the maximum
        // allowed value.
        if (!$enumRevMode) {
            $this->setParsedLimit = false;
            $params['limit'] = 'max';
        }

        $db = $this->getDB();

        $idField = 'rev_id';
        $tsField = 'rev_timestamp';
        $pageField = 'rev_page';

        $ignoreIndex = [
            // T224017: `rev_timestamp` is never the correct index to use for this module, but
            // MariaDB sometimes insists on trying to use it anyway. Tell it not to.
            // Last checked with MariaDB 10.4.13
            'revision' => 'rev_timestamp',
        ];
        $useIndex = [];
        if ($resultPageSet === null) {
            $this->parseParameters($params);
            $opts = ['page'];
            if ($this->fld_user) {
                $opts[] = 'user';
            }
            $revQuery = $this->revisionStore->getQueryInfo($opts);
            $this->addTables($revQuery['tables']);
            $this->addFields($revQuery['fields']);
            $this->addJoinConds($revQuery['joins']);
        } else {
            $this->limit = $this->getParameter('limit') ?: 10;
            // Always join 'page' so orphaned revisions are filtered out
            $this->addTables(['revision', 'page']);
            $this->addJoinConds(
                ['page' => ['JOIN', ['page_id = rev_page']]]
            );
            $this->addFields([
                'rev_id' => $idField, 'rev_timestamp' => $tsField, 'rev_page' => $pageField
            ]);
        }

        if ($this->fld_tags) {
            $this->addFields(['ts_tags' => ChangeTags::makeTagSummarySubquery('revision')]);
        }

        if ($params['tag'] !== null) {
            $this->addTables('change_tag');
            $this->addJoinConds(
                ['change_tag' => ['JOIN', ['rev_id=ct_rev_id']]]
            );
            try {
                $this->addWhereFld('ct_tag_id', $this->changeTagDefStore->getId($params['tag']));
            } catch (NameTableAccessException $exception) {
                // Return nothing.
                $this->addWhere('1=0');
            }
        }

        if ($resultPageSet === null && $this->fetchContent) {
            // For each page we will request, the user must have read rights for that page
            $status = Status::newGood();

            /** @var Title $title */
            foreach ($pageSet->getGoodTitles() as $title) {
                if (!$this->getAuthority()->authorizeRead('read', $title)) {
                    $status->fatal(ApiMessage::create(
                        ['apierror-cannotviewtitle', wfEscapeWikiText($title->getPrefixedText())],
                        'accessdenied'
                    ));
                }
            }
            if (!$status->isGood()) {
                $this->dieStatus($status);
            }
        }

        if ($enumRevMode) {
            // Indexes targeted:
            //  page_timestamp if we don't have rvuser
            //  page_actor_timestamp (on revision_actor_temp) if we have rvuser in READ_NEW mode
            //  page_user_timestamp if we have a logged-in rvuser
            //  page_timestamp or usertext_timestamp if we have an IP rvuser

            // This is mostly to prevent parameter errors (and optimize SQL?)
            $this->requireMaxOneParameter($params, 'startid', 'start');
            $this->requireMaxOneParameter($params, 'endid', 'end');
            $this->requireMaxOneParameter($params, 'user', 'excludeuser');

            if ($params['continue'] !== null) {
                $cont = explode('|', $params['continue']);
                $this->dieContinueUsageIf(count($cont) != 2);
                $op = ($params['dir'] === 'newer' ? '>' : '<');
                $continueTimestamp = $db->addQuotes($db->timestamp($cont[0]));
                $continueId = (int)$cont[1];
                $this->dieContinueUsageIf($continueId != $cont[1]);
                $this->addWhere(
                    "$tsField $op $continueTimestamp OR " .
                        "($tsField = $continueTimestamp AND " .
                        "$idField $op= $continueId)"
                );
            }

            // Convert startid/endid to timestamps (T163532)
            $revids = [];
            if ($params['startid'] !== null) {
                $revids[] = (int)$params['startid'];
            }
            if ($params['endid'] !== null) {
                $revids[] = (int)$params['endid'];
            }
            if ($revids) {
                $db = $this->getDB();
                $sql = $db->unionQueries([
                    $db->selectSQLText(
                        'revision',
                        ['id' => 'rev_id', 'ts' => 'rev_timestamp'],
                        ['rev_id' => $revids],
                        __METHOD__
                    ),
                    $db->selectSQLText(
                        'archive',
                        ['id' => 'ar_rev_id', 'ts' => 'ar_timestamp'],
                        ['ar_rev_id' => $revids],
                        __METHOD__
                    ),
                ], $db::UNION_DISTINCT);
                $res = $db->query($sql, __METHOD__);
                foreach ($res as $row) {
                    if ((int)$row->id === (int)$params['startid']) {
                        $params['start'] = $row->ts;
                    }
                    if ((int)$row->id === (int)$params['endid']) {
                        $params['end'] = $row->ts;
                    }
                }
                // @phan-suppress-next-line PhanTypePossiblyInvalidDimOffset False positive
                if ($params['startid'] !== null && $params['start'] === null) {
                    $p = $this->encodeParamName('startid');
                    $this->dieWithError(['apierror-revisions-badid', $p], "badid_$p");
                }
                // @phan-suppress-next-line PhanTypePossiblyInvalidDimOffset False positive
                if ($params['endid'] !== null && $params['end'] === null) {
                    $p = $this->encodeParamName('endid');
                    $this->dieWithError(['apierror-revisions-badid', $p], "badid_$p");
                }

                // @phan-suppress-next-line PhanTypePossiblyInvalidDimOffset False positive
                if ($params['start'] !== null) {
                    $op = ($params['dir'] === 'newer' ? '>' : '<');
                    // @phan-suppress-next-line PhanTypePossiblyInvalidDimOffset False positive
                    $ts = $db->addQuotes($db->timestampOrNull($params['start']));
                    if ($params['startid'] !== null) {
                        $this->addWhere("$tsField $op $ts OR "
                            . "$tsField = $ts AND $idField $op= " . (int)$params['startid']);
                    } else {
                        $this->addWhere("$tsField $op= $ts");
                    }
                }
                // @phan-suppress-next-line PhanTypePossiblyInvalidDimOffset False positive
                if ($params['end'] !== null) {
                    $op = ($params['dir'] === 'newer' ? '<' : '>'); // Yes, opposite of the above
                    // @phan-suppress-next-line PhanTypePossiblyInvalidDimOffset False positive
                    $ts = $db->addQuotes($db->timestampOrNull($params['end']));
                    if ($params['endid'] !== null) {
                        $this->addWhere("$tsField $op $ts OR "
                            . "$tsField = $ts AND $idField $op= " . (int)$params['endid']);
                    } else {
                        $this->addWhere("$tsField $op= $ts");
                    }
                }
            } else {
                $this->addTimestampWhereRange(
                    $tsField,
                    $params['dir'],
                    $params['start'],
                    $params['end']
                );
            }

            $sort = ($params['dir'] === 'newer' ? '' : 'DESC');
            $this->addOption('ORDER BY', ["rev_timestamp $sort", "rev_id $sort"]);

            // There is only one ID, use it
            $ids = array_keys($pageSet->getGoodPages());
            $this->addWhereFld($pageField, reset($ids));

            if ($params['user'] !== null) {
                $actorQuery = $this->actorMigration->getWhere($db, 'rev_user', $params['user']);
                $this->addTables($actorQuery['tables']);
                $this->addJoinConds($actorQuery['joins']);
                $this->addWhere($actorQuery['conds']);
            } elseif ($params['excludeuser'] !== null) {
                $actorQuery = $this->actorMigration->getWhere($db, 'rev_user', $params['excludeuser']);
                $this->addTables($actorQuery['tables']);
                $this->addJoinConds($actorQuery['joins']);
                $this->addWhere('NOT(' . $actorQuery['conds'] . ')');
            } else {
                // T258480: MariaDB ends up using rev_page_actor_timestamp in some cases here.
                // Last checked with MariaDB 10.4.13
                // Unless we are filtering by user (see above), we always want to use the
                // "history" index on the revision table, namely page_timestamp.
                $useIndex['revision'] = 'rev_page_timestamp';
            }

            if ($params['user'] !== null || $params['excludeuser'] !== null) {
                // Paranoia: avoid brute force searches (T19342)
                if (!$this->getAuthority()->isAllowed('deletedhistory')) {
                    $bitmask = RevisionRecord::DELETED_USER;
                } elseif (!$this->getAuthority()->isAllowedAny('suppressrevision', 'viewsuppressed')) {
                    $bitmask = RevisionRecord::DELETED_USER | RevisionRecord::DELETED_RESTRICTED;
                } else {
                    $bitmask = 0;
                }
                if ($bitmask) {
                    $this->addWhere($db->bitAnd('rev_deleted', $bitmask) . " != $bitmask");
                }
            }
        } elseif ($revCount > 0) {
            // Always targets the PRIMARY index

            $revs = $pageSet->getLiveRevisionIDs();
           
            // Get all revision IDs
            $this->addWhereFld('rev_id', array_keys($revs));

            if ($params['continue'] !== null) {
                $this->addWhere('rev_id >= ' . (int)$params['continue']);
            }
            $this->addOption('ORDER BY', 'rev_id');
        } elseif ($pageCount > 0) {
            // Always targets the rev_page_id index

            $pageids = array_keys($pageSet->getGoodPages());

            // When working in multi-page non-enumeration mode,
            // limit to the latest revision only
            $this->addWhere('page_latest=rev_id');

            // Get all page IDs
            $this->addWhereFld('page_id', $pageids);
            // Every time someone relies on equality propagation, god kills a kitten :)
            $this->addWhereFld('rev_page', $pageids);

            if ($params['continue'] !== null) {
                $cont = explode('|', $params['continue']);
                $this->dieContinueUsageIf(count($cont) != 2);
                $pageid = (int)$cont[0];
                $revid = (int)$cont[1];
                $this->addWhere(
                    "rev_page > $pageid OR " .
                        "(rev_page = $pageid AND " .
                        "rev_id >= $revid)"
                );
            }
            $this->addOption('ORDER BY', [
                'rev_page',
                'rev_id'
            ]);
        } else {
            ApiBase::dieDebug(__METHOD__, 'param validation?');
        }

        $this->addOption('LIMIT', $this->limit + 1);

        $this->addOption('IGNORE INDEX', $ignoreIndex);

        if ($useIndex) {
            $this->addOption('USE INDEX', $useIndex);
        }

        if (!$this->getAuthority()->isAllowed('aspaklarya-read-locked')) {
            $lockedRevisionSubquery = $db->selectSQLText(
                'aspaklarya_lockdown_revisions',
                'al_rev_id',
                [],
                __METHOD__
            );
            // Exclude locked revision IDs from the query
            $this->addWhere("rev_id NOT IN ($lockedRevisionSubquery)");
        }

        $count = 0;
        $generated = [];
        $hookData = [];
        $res = $this->select(__METHOD__, [], $hookData);

        foreach ($res as $row) {
            if (++$count > $this->limit) {
                // We've reached the one extra which shows that there are
                // additional pages to be had. Stop here...
                if ($enumRevMode) {
                    $this->setContinueEnumParameter(
                        'continue',
                        $row->rev_timestamp . '|' . (int)$row->rev_id
                    );
                } elseif ($revCount > 0) {
                    $this->setContinueEnumParameter('continue', (int)$row->rev_id);
                } else {
                    $this->setContinueEnumParameter('continue', (int)$row->rev_page .
                        '|' . (int)$row->rev_id);
                }
                break;
            }

            if ($resultPageSet !== null) {
               
                $generated[] = $row->rev_id;
            } else {
                
                $revision = $this->revisionStore->newRevisionFromRow($row, 0, Title::newFromRow($row));
                $rev = $this->extractRevisionInfo($revision, $row);
                $fit = $this->processRow($row, $rev, $hookData) &&
                    $this->addPageSubItem($row->rev_page, $rev, 'rev');
                if (!$fit) {
                    if ($enumRevMode) {
                        $this->setContinueEnumParameter(
                            'continue',
                            $row->rev_timestamp . '|' . (int)$row->rev_id
                        );
                    } elseif ($revCount > 0) {
                        $this->setContinueEnumParameter('continue', (int)$row->rev_id);
                    } else {
                        $this->setContinueEnumParameter('continue', (int)$row->rev_page .
                            '|' . (int)$row->rev_id);
                    }
                    break;
                }
            }
        }

        if ($resultPageSet !== null) {
            $resultPageSet->populateFromRevisionIDs($generated);
        }
    }
}
