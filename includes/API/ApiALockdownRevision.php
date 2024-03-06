<?php

namespace MediaWiki\Extension\AspaklaryaLockDown\API;

use ApiBase;
use ApiWatchlistTrait;
use Html;
use ManualLogEntry;
use MediaWiki\MediaWikiServices;
use MediaWiki\Permissions\PermissionStatus;
use MediaWiki\Revision\RevisionRecord;
use SpecialPage;
use Status;
use Title;
use Wikimedia\ParamValidator\ParamValidator;

/**
 * API module to lockdown a page
 * @ingroup API
 */
class ApiALockdownRevision extends ApiBase {

    use ApiWatchlistTrait;

    public function execute() {

        // Get parameters
        $params = $this->extractRequestParams();


        if (!isset($params['revid']) || !isset($params['hide']) || !is_numeric($params['revid'])) {
            $this->dieWithError('apierror-aspaklarya_lockdown-missingparams');
        }

        $revisionLookup = MediaWikiServices::getInstance()->getRevisionLookup();
        $revision = $revisionLookup->getRevisionById($params['revid']);
        if ($revision == null) {
            $this->dieWithError('apierror-aspaklarya_lockdown-invalidrevid');
        }
        $pageObj = $revision->getPage();
        $statusA = new PermissionStatus();
        $this->getAuthority()->authorizeWrite('aspaklarya_lockdown', $pageObj, $statusA);
        if (!$statusA->isGood()) {
            $this->getUser()->spreadAnyEditBlock();
            $this->dieStatus($statusA);
        }
        $titleObj = Title::newFromID($pageObj->getId());
        $currentRevision = $revisionLookup->getKnownCurrentRevision($titleObj);
        if (!$currentRevision || $currentRevision->getId() === $revision->getId()) {
            $this->dieWithError('apierror-aspaklarya_lockdown-currentrevision');
        }
        if ($titleObj->isSpecialPage()) {
            $this->dieWithError('apierror-aspaklarya_lockdown-invalidtitle');
        }
        $user = $this->getUser();
        $watch = $params['watchlist'];
        $watchlistExpiry = $this->getExpiryFromParams($params);
        $this->setWatch($watch, $titleObj, $user, 'watchdefault', $watchlistExpiry);

        $status = $this->doUpdateRestrictions($revision, $params['reason'], $params['hide'] == 0 ? false : true, $titleObj);
        if (!$status->isOK()) {
            $this->dieStatus($status);
        }

        $res = [
            'title' => $titleObj->getPrefixedText(),
            'reason' => $params['reason'],
            'status' => 'Succes',
            'revision' => $revision->getId(),
            'hide' => $params['hide'],
        ];

        $result = $this->getResult();
        $result->addValue(null, $this->getModuleName(), $res);
    }

    /**
     * Update the article's restriction field, and leave a log entry.
     * This works for protection both existing and non-existing pages.
     *
     * @param RevisionRecord $revision
     * @param string $reason
     * @param bool $hide
     * @param Title $title
     * @return Status Status object; if action is taken, $status->value is the log_id of the
     *   lockdown log entry.
     */
    public function doUpdateRestrictions(
        RevisionRecord $revision,
        $reason,
        bool $hide,
        Title $title,
    ) {
        $readOnlyMode = MediaWikiServices::getInstance()->getReadOnlyMode();
        if ($readOnlyMode->isReadOnly()) {
            return Status::newFatal(wfMessage('readonlytext', $readOnlyMode->getReason()));
        }
        $revisionsLockdTable = 'aspaklarya_lockdown_revisions';
        $connection = MediaWikiServices::getInstance()->getDBLoadBalancer()->getConnection(DB_PRIMARY);

        $current = $connection->newSelectQueryBuilder()
            ->select('alr_rev_id')
            ->from($revisionsLockdTable)
            ->where(['alr_rev_id' => $revision->getId()])
            ->caller(__METHOD__)
            ->fetchRow();

        if ($current === $hide || $hide && $current !== false) {
            return Status::newGood();
        }

        $id = $title->getId();
        $logAction = $hide ? 'hide' : 'unhide';

        $dbw = MediaWikiServices::getInstance()->getDBLoadBalancer()->getConnection(DB_PRIMARY);
        $logParamsDetails = [
            'type' => $logAction,
        ];
        $relations = [];
        if ($hide) {
            $dbw->insert(
                $revisionsLockdTable,
                ['alr_rev_id' => $revision->getId(), 'alr_page_id' => $id],
                __METHOD__
            );
            $relations[] = ['alr_id' => $dbw->insertId()];
        } else {
            $dbw->delete(
                $revisionsLockdTable,
                ['alr_rev_id' => $revision->getId(), 'alr_page_id' => $id],
                __METHOD__
            );
        }
        $cache = MediaWikiServices::getInstance()->getMainWANObjectCache();
        $cache->delete($cache->makeKey("aspaklarya-lockdown", "revision", $revision->getId()));

        $params = [
            "4::description" => wfMessage("lock-$logAction"),
            "5::revid" => $revision->getId(),
            "detailes" => $logParamsDetails,
        ];


        // Update the aspaklarya log
        $logEntry = new ManualLogEntry('aspaklarya', $logAction);
        $logEntry->setTarget($title);
        $logEntry->setAssociatedRevId($revision->getId());
        $logEntry->setRelations($relations);
        $logEntry->setComment($reason);
        $logEntry->setPerformer($this->getUser());
        $logEntry->setParameters($params);

        $logId = $logEntry->insert();

        return Status::newGood($logId);
    }

    /** @inheritDoc */
    public function getAllowedParams() {
        return [
            'revid' => [
                ParamValidator::PARAM_TYPE => 'integer',
                ParamValidator::PARAM_REQUIRED => true,
                ApiBase::PARAM_HELP_MSG => 'apihelp-aspaklarya_lockdown-param-pageid',
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
        return [''];
    }
}
