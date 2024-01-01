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
     * get database connection
     * @param $i DB_REPLICA or DB_PRIMARY
     */
    private static function getDB($i)
    {
        $provider = MediaWikiServices::getInstance()->getDBLoadBalancer();
        return $provider->getConnection($i);
    }

    /**
     * get page from database
     * @param string $page_id
     * @return false|string
     */
    private static function getPage($page_id)
    {
        $db = self::getDB(DB_REPLICA);
        $res = $db->newSelectQueryBuilder()
            ->select(["al_page_id", "al_page_read"])
            ->from(self::PAGES_TABLE_NAME)
            ->where(["al_page_id" => $page_id])
            ->caller(__METHOD__)
            ->fetchRow();
        if ($res === false) return false;
        return $res->al_page_read === "1" ? "read" : "edit";
    }

    /**
     * set page in database
     * @param string $page_id
     * @param string $page_read
     * @return bool
     */
    private static function setPage($page_id, $page_read)
    {
        $db = self::getDB(DB_PRIMARY);
        $res = $db->newSelectQueryBuilder()
            ->select(["al_page_id", "al_page_read"])
            ->from(self::PAGES_TABLE_NAME)
            ->where(["al_page_id" => $page_id])
            ->caller(__METHOD__)
            ->fetchRow();

        if ($res === false) {
            return $db->insert(self::PAGES_TABLE_NAME, [
                "al_page_id" => $page_id,
                "al_page_read" => $page_read
            ]);
        }
        return $db->update(self::PAGES_TABLE_NAME, [
            "al_page_id" => $page_id,
            "al_page_read" => $page_read
        ], [
            "al_page_read" => $page_read
        ]);
    }
}
