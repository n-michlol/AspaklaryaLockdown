<?php

namespace MediaWiki\Extension\AspaklaryaLockDown;

use MediaWiki\MediaWikiServices;

class ALDBData {

    private const PAGES_TABLE_NAME = "aspaklarya_lockdown_pages";
    public const READ = "read";
    public const EDIT = "edit";

    /**
     * get pages table name
     * @return string
     */
    public static function getPagesTableName() {
        return self::PAGES_TABLE_NAME;
    }
    /**
     * check if page is eliminated for read
     * @param string $page_id
     * @return bool
     */
    public static function isReadEliminated($page_id) {
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
    public static function isEditEliminated($page_id) {
        $pageElimination = self::getPage($page_id);
        if ($pageElimination === false) {
            return false;
        }
        return true;
    }
    /**
     * get page limitation
     * @param string $page_id
     * @return READ|EDIT|false
     */
    public static function getPageLimitation($page_id) {
        return self::getPage($page_id);
    }

    /**
     * check if page is eliminated for create
     * @param string $page_id
     * @return bool
     */
    public static function isCreateEliminated($page_id) {
    }

    /**
     * get database connection
     * @param DB_REPLICA|DB_PRIMARY $i 
     */
    private static function getDB($i) {
        $provider = MediaWikiServices::getInstance()->getDBLoadBalancer();
        return $provider->getConnection($i);
    }

    /**
     * get page from database
     * @param string $page_id
     * @return false|READ|EDIT
     */
    private static function getPage($page_id) {
        $db = self::getDB(DB_REPLICA);
        $res = $db->newSelectQueryBuilder()
            ->select(["al_page_id", "al_read_allowed"])
            ->from(self::PAGES_TABLE_NAME)
            ->where(["al_page_id" => $page_id])
            ->caller(__METHOD__)
            ->fetchRow();

        return $res !== false && $res->al_read_allowed == "1" ? self::EDIT : self::READ;
    }

    /**
     * set page in database
     * @param string $page_id
     * @param READ|EDIT $page_read
     * @return bool
     */
    private static function setPage($page_id, $page_read) {
        $res = self::getPage($page_id);
        if ($res === $page_read) {
            return true;
        }
        $db = self::getDB(DB_PRIMARY);
        $page_read = $page_read === self::READ ? "0" : "1";
        if ($res === false) {
            return $db->insert(self::PAGES_TABLE_NAME, [
                "al_page_id" => $page_id,
                "al_read_allowed" => $page_read
            ]);
        }
        return $db->update(self::PAGES_TABLE_NAME, [
            "al_read_allowed" => $page_read
        ], [
            "al_page_id" => $page_id,
        ]);
    }
}
