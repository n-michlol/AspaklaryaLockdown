<?php

namespace AspaklaryaLockDown;

use MediaWiki\MediaWikiServices;

class ALDBData
{

    private const PAGES_TABLE_NAME = "aspaklarya_lockdown_pages";
    public const READ = "read";
    public const EDIT = "edit";


    /**
     * check if page is eliminated for read
     * @param string $page_id
     * @return bool
     */
    public static function isReadEliminated($page_id)
    {
        $pageElimination = self::getPage($page_id);
        if ($pageElimination === false) {
            return false;
        }
        return $pageElimination === self::READ;
    }

    /**
     * check if page is eliminated for edit
     * @param string $page_id
     * @return bool
     */
    public static function isEditEliminated($page_id)
    {
        $pageElimination = self::getPage($page_id);
        if ($pageElimination === false) {
            return false;
        }
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
     * @param DB_REPLICA|DB_PRIMARY $i 
     */
    private static function getDB($i)
    {
        $provider = MediaWikiServices::getInstance()->getDBLoadBalancer();
        return $provider->getConnection($i);
    }

    /**
     * get page from database
     * @param string $page_id
     * @return false|READ|EDIT
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

        return $res !== false && $res->al_page_read === "1" ? self::READ : self::EDIT;
    }

    /**
     * set page in database
     * @param string $page_id
     * @param READ|EDIT $page_read
     * @return bool
     */
    private static function setPage($page_id, $page_read)
    {
        $res = self::getPage($page_id);
        if ($res === $page_read) {
            return true;
        }
        $db = self::getDB(DB_PRIMARY);
        $page_read = $page_read === self::READ ? "1" : "0";
        if ($res === false) {
            return $db->insert(self::PAGES_TABLE_NAME, [
                "al_page_id" => $page_id,
                "al_page_read" => $page_read
            ]);
        }
        return $db->update(self::PAGES_TABLE_NAME, [
            "al_page_read" => $page_read
        ], [
            "al_page_id" => $page_id,
        ]);
    }
}
