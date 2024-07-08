<?php

namespace MediaWiki\Extension\AspaklaryaLockDown;

use InvalidArgumentException;
use MediaWiki\MediaWikiServices;
use MediaWiki\Title\Title;
use MediaWiki\User\User;
use WANObjectCache;
use Wikimedia\Rdbms\LoadBalancer;

class Main {

    private const PAGES_TABLE_NAME = "aspaklarya_lockdown_pages";
    private const REVISIONS_TABLE_NAME = "aspaklarya_lockdown_revisions";
    private const TITLES_TABLE_NAME = "aspaklarya_lockdown_create_titles";
    private const READ = 'read';
    private const READ_SEMI = 'read-semi';
    private const CREATE = 'create';
    private const EDIT = 'edit';
    private const EDIT_SEMI = 'edit-semi';
    private const EDIT_FULL = 'edit-full';
    private const LOCKDOWN_PERM = 'aspaklarya_lockdown';
    private const READ_LOCKED_PERM = 'aspaklarya-read-locked';
    private const READ_SEMI_LOCKED_PERM = 'aspaklarya-read-semi-locked';
    private const EDIT_LOCKED_PERM = 'aspaklarya-edit-locked';
    private const EDIT_SEMI_LOCKED_PERM = 'aspaklarya-edit-semi-locked';
    private const CREATE_BIT = 0;
    private const READ_BIT = 1;
    private const READ_SEMI_BIT = self::READ_BIT << 1;
    private const EDIT_BIT = self::READ_SEMI_BIT << 1;
    private const EDIT_SEMI_BIT = self::EDIT_BIT << 1;
    private const EDIT_FULL_BIT = self::EDIT_SEMI_BIT << 1;

    private Title $mTitle;
    private ?int $mId = null;
    private User $mUser;
    private LoadBalancer $mLoadBalancer;
    private WANObjectCache $mCache;
    private ?string $pageCacheKey = null;
    private ?int $state = null;


    public function __construct(LoadBalancer $loadBalancer, WANObjectCache $cache, Title $title = null, User $user = null) {
        $this->mLoadBalancer = $loadBalancer;
        $this->mCache = $cache;
        $this->mTitle = $title;
        if ($this->mTitle) {
            $this->mId = $this->mTitle->getId();
        }
        $this->createCacheKey();
        $this->loadState();
        if (!$user || !$user->isSafeToLoad()) {
            $user = MediaWikiServices::getInstance()->getUserFactory()->newAnonymous();
        }
        $this->mUser = $user;
    }

    private function createCacheKey(){
        if (!$this->mTitle || $this->mId === null) {
            throw new InvalidArgumentException('Title or id is not set');
        }
        if ($this->mTitle->isSpecialPage()) {
            return;
        }
        if ($this->mId === 0) {
            $this->pageCacheKey = $this->mCache->makeKey('aspaklarya-lockdown', 'create', $this->mTitle->getNamespace(), $this->mTitle->getDBkey());
            return;
        }
        $this->pageCacheKey = $this->mCache->makeKey('aspaklarya-lockdown', $this->mTitle->getId());
    }

    public function isUserAllowed(string $action): bool {
        if (!$this->mTitle) {
            throw new InvalidArgumentException('Title is not set');
        }
        if ($this->mTitle->isSpecialPage()) {
            return true;
        }

        if ($this->mId === 0) {
        }
        return false;
    }

    private function loadState(bool $useCache = true) {
        if ($useCache) {
            $this->getCached();
        }
        $this->getFromDB();
    }

    private function getCached(){
    }

    private function getFromDB($db = DB_REPLICA){
        if ($this->mId === null) {
            throw new InvalidArgumentException('Title is not set');
        }
        $exist = $this->mId > 0;
        $var = !$exist ? 'al_lock_id' : 'al_read_allowed';
        $where = !$exist ? ['al_page_namespace' => $this->mTitle->getNamespace(), 'al_page_title' => $this->mTitle->getDBkey()] : ['al_page_id' => $this->mId];

        $dbr = $this->mLoadBalancer->getConnection($db);
        $res = $dbr->newSelectQueryBuilder()
            ->select($var)
            ->from($exist ? self::PAGES_TABLE_NAME : self::TITLES_TABLE_NAME)
            ->where($where)
            ->caller(__METHOD__)
            ->fetchRow();
        if ($res === false) {
            $this->state = (1<<8)-1;
            return $this->state;
        }
        $this->state = $exist ? $res->al_read_allowed : 0;
        return $this->state;
    }
    private function isUserAllowedLevel(int $level): bool
    {
        $perm = $this->levelPermission($level, 'edit');
        if ($perm === false) {
            return false;
        }
        return $this->mUser->isAllowed($perm);
    }

    public static function getPagesTableName()
    {
        return self::PAGES_TABLE_NAME;
    }

    public static function getRevisionsTableName()
    {
        return self::REVISIONS_TABLE_NAME;
    }

    public static function getTitlesTableName()
    {
        return self::TITLES_TABLE_NAME;
    }

    public static function getLevelFromBit(int $bit): string
    {
        switch ($bit) {
            case self::CREATE_BIT:
                return self::CREATE;
            case self::READ_BIT:
                return self::READ;
            case self::READ_SEMI_BIT:
                return self::READ_SEMI;
            case self::EDIT_BIT:
                return self::EDIT;
            case self::EDIT_SEMI_BIT:
                return self::EDIT_SEMI;
            case self::EDIT_FULL_BIT:
                return self::EDIT_FULL;
            default:
                return "";
        }
    }

    public static function getBitFromLevel(string $level): int
    {
        switch ($level) {
            case self::CREATE:
                return self::CREATE_BIT;
            case self::READ:
                return self::READ_BIT;
            case self::READ_SEMI:
                return self::READ_SEMI_BIT;
            case self::EDIT:
                return self::EDIT_BIT;
            case self::EDIT_SEMI:
                return self::EDIT_SEMI_BIT;
            case self::EDIT_FULL:
                return self::EDIT_FULL_BIT;
            default:
                return -1;
        }
    }

    public static function getApplicableTypes(bool $existingPage): array
    {
        if ($existingPage) {
            return [
                -1 => '',
                self::READ_BIT => self::READ,
                self::READ_SEMI_BIT => self::READ_SEMI,
                self::EDIT_BIT => self::EDIT,
                self::EDIT_SEMI_BIT => self::EDIT_SEMI,
                self::EDIT_FULL_BIT => self::EDIT_FULL,
            ];
        }
        return [
            -1 => '',
            self::CREATE_BIT => self::CREATE,
        ];
    }

    /**
     * @param int $level
     * @param string $action 
     * @return string|bool false means no one is allowed, empty string means everyone is allowed, otherwise permission name is returned
     */
    private static function levelPermission(int $level, string $action): string|bool {
        switch ($level) {
            case self::READ_BIT:
                return self::READ_LOCKED_PERM;
            case self::READ_SEMI_BIT:
                return self::READ_SEMI_LOCKED_PERM;
            case self::CREATE_BIT:
                if ($action === 'read') {
                    return self::LOCKDOWN_PERM;
                }
                return false;   
        }
        if ( $action === 'edit' || $action === 'readsource' ) {
            switch ($level) {
                case self::EDIT_BIT:
                    return self::EDIT_LOCKED_PERM;
                case self::EDIT_SEMI_BIT:
                    return self::EDIT_SEMI_LOCKED_PERM;
            }
        }
        
        return '';
    }

    public static function getPerferences( User $user, &$perferences){
        $options = [];
        $p = 1;
        while ($option = self::getLevelFromBit($p)) {
            if ($user->isAllowed(self::levelPermission($p, 'read'))) {
                $options['al-show-' . $option . '-locked'] = $option;
            }
            $p <<= 1;
        }
        $perferences['aspaklarya-links'] = [
                'type' => 'multiselect',
                'label-message' => 'aspaklarya-links',
                'options-messages' => $options,
                'help-message' => 'aspaklarya-links-help',
                'section' => 'aspaklarya/links',
            ];
        $perferences['aspaklarya-read'] = [
                'type' => 'multiselect',
                'label-message' => 'aspaklarya-read',
                'options-messages' => $options,
                'help-message' => 'aspaklarya-read-help',
                'section' => 'aspaklarya/read',
            ];
        
    }
    
    
}
