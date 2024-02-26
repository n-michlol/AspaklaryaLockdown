<?php

namespace MediaWiki\Extension\AspaklaryaLockDown;

use MediaWiki\MediaWikiServices;

class ALDBData {

    public const PAGES_TABLE_NAME = "aspaklarya_lockdown_pages";
    public const PAGES_REVISION_NAME = "aspaklarya_lockdown_revisions";
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
     * @param int $namespace
     * @param string $title
     * @return bool
     */
    public static function isCreateEliminated($namespace, $title) {
        $db = self::getDB(DB_REPLICA);
        $res = $db->newSelectQueryBuilder()
            ->select(["al_page_namespace", "al_page_title", "al_lock_id"])
            ->from("aspaklarya_lockdown_create_titles")
            ->where(["al_page_namespace" => $namespace, "al_page_title" => $title])
            ->caller(__METHOD__)
            ->fetchRow();
        return $res !== false;
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
        if ($res === false) {
            return false;
        }
        return $res->al_read_allowed == "1" ? self::EDIT : self::READ;
    }

    /**
     * check if revision is locked
     * @param int $revId
     * @return bool
     */
    public static function isRevisionLocked(int $revId) {
        $db = self::getDB(DB_REPLICA);
        $res = $db->newSelectQueryBuilder()
            ->select(["al_rev_id"])
            ->from(self::PAGES_REVISION_NAME)
            ->where(["al_rev_id" => $revId])
            ->caller(__METHOD__)
            ->fetchRow();
        return $res !== false;
    }

    /**
     * get all locked revisions for this page
     * @param int $pageId
     * @return false|array
     */
    public static function getLockedRevisions(int $pageId) {
        $db = self::getDB(DB_REPLICA);
        $res = $db->newSelectQueryBuilder()
            ->select(["al_rev_id"])
            ->from(self::PAGES_REVISION_NAME)
            ->where(["al_page_id" => $pageId])
            ->caller(__METHOD__)
            ->fetchFieldValues();
        if (empty($res)) {
            return false;
        }
        return $res;
    }
}
