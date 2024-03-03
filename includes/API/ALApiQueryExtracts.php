<?php

namespace MediaWiki\Extension\AspaklaryaLockDown\API;

use MediaWiki\Extension\AspaklaryaLockDown\ALDBData;
use MediaWiki\Extension\AspaklaryaLockDown\AspaklaryaLockdown;
use MediaWiki\MediaWikiServices;
use RequestContext;
use TextExtracts\ApiQueryExtracts;
use UserGroupMembership;

class ALApiQueryExtracts extends ApiQueryExtracts {
    public function execute() {
        if (!$this->getUser()->isAllowed('aspaklarya-read-locked')) {
            $pages = $this->getPageSet()->getGoodPages();
            foreach ($pages as $pageid => $page) {
                $locked = ALDBData::isReadEliminated($pageid);
                if ($locked) {
                    $this->dieWithError(["aspaklarya_lockdown-error", implode(', ', self::getLinks('aspaklarya-edit-locked')), wfMessage('aspaklarya-read')]);
                }
            }
        }
        parent::execute();
    }
    /**
     * get group links for messages
     * @param string $right
     * @return array
     */
    private static function getLinks(string $right) {
        $groups = MediaWikiServices::getInstance()->getGroupPermissionsLookup()->getGroupsWithPermission($right);
        $links = [];
        foreach ($groups as $group) {
            $links[] = UserGroupMembership::getLink($group, RequestContext::getMain(), "wiki");
        }
        return $links;
    }
}
