<?php

namespace MediaWiki\Extension\AspaklaryaLockDown\API;

use ApiBase;
use ApiWatchlistTrait;
use ManualLogEntry;
use MediaWiki\Extension\AspaklaryaLockDown\ALDBData;
use MediaWiki\MediaWikiServices;
use MediaWiki\Permissions\PermissionStatus;
use MediaWiki\Revision\RevisionLookup;
use MediaWiki\Revision\RevisionRecord;
use Status;
use Title;
use Wikimedia\ParamValidator\ParamValidator;
use WikiPage;

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

        $revisionLookup = new RevisionLookup();
        $revision = $revisionLookup->getRevisionById($params['revid']);
        if ($revision == null) {
            $this->dieWithError('apierror-aspaklarya_lockdown-invalidrevid');
        }
        $pageObj = $revision->getPage();
        $status = new PermissionStatus();
        $this->getAuthority()->authorizeWrite('aspaklarya_lockdown', $pageObj, $status);
        if (!$status->isGood()) {
            $this->getUser()->spreadAnyEditBlock();
            $this->dieStatus($status);
        }
        $titleObj = Title::newFromID($pageObj->getId());
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
            'hide' => $params['hide'] == 0 ? false : true
        ];

        $result = $this->getResult();
        $result->addValue(null, $this->getModuleName(), $res);
    }

    /**
     * Update the article's restriction field, and leave a log entry.
     * This works for protection both existing and non-existing pages.
     *
     * @param string $limit edit|read|create|""
     * @param string $reason
     * @return Status Status object; if action is taken, $status->value is the log_id of the
     *   protection log entry.
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
            ->select('al_rev_id')
            ->from($revisionsLockdTable)
            ->where(['al_rev_id' => $revision->getId()])
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

        if ($hide) {
            $dbw->insert(
                $revisionsLockdTable,
                ['al_rev_id' => $revision->getId(), 'al_page_id' => $id],
                __METHOD__
            );
        } else {
            $dbw->delete(
                $revisionsLockdTable,
                ['al_rev_id' => $revision->getId(), 'al_page_id' => $id],
                __METHOD__
            );
        }

        $params = [
            "4::description" => wfMessage("lock-$logAction"),
            "5::revid" => $revision->getId(),
            "detailes" => $logParamsDetails,
        ];


        // Update the aspaklarya log
        $logEntry = new ManualLogEntry('aspaklarya', $logAction);
        $logEntry->setTarget($revision);
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
