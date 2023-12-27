<?php

namespace AspaklaryaLockDown;

use MediaWiki\MediaWikiServices;

class ALDBData
{

    public const PAGES_TABLE_NAME = "aspaklarya_lockdown_pages";

    /**
     * check if page is eliminated for read
     * @param string $page_id
     * @return bool
     */
    public static function isReadEliminated($page_id)
    {
        $pageElimination = self::getPage($page_id);
        if ($pageElimination === false) return false;
        return $pageElimination === "read";
    }

    /**
     * check if page is eliminated for edit
     * @param string $page_id
     * @return bool
     */
    public static function isEditEliminated($page_id)
    {
        $pageElimination = self::getPage($page_id);
        if ($pageElimination === false) return false;
        return true;
    }

    /**
     * check if page is eliminated for create
     * @param string $page_id
     * @return bool
     */
    public static function isCreateEliminated($page_id)
    {
    }

    /**
     * get page from database
     * @param string $page_id
     * @return false|string
     */
    private static function getPage($page_id)
    {
        $provider = MediaWikiServices::getInstance()->getDBLoadBalancer();
        $db = $provider->getConnection(DB_REPLICA);
        $res = $db->newSelectQueryBuilder()
            ->select(["al_page_id", "al_page_read"])
            ->from(self::PAGES_TABLE_NAME)
            ->where(["al_page_id" => $page_id])
            ->caller(__METHOD__)
            ->fetchRow();
        if ($res === false) return false;
        return $res->al_page_read === "1" ? "read" : "edit";
    }
}
