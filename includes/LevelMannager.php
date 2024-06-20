<?php

namespace MediaWiki\Extension\AspaklaryaLockDown;

class LevelMannager {

    public const READ_FULL = 'read-full';
    public const READ_SEMI = 'read-semi';
    public const READ = 'read';
    public const EDIT_FULL = 'edit-full';
    public const EDIT_SEMI = 'edit-semi';
    public const EDIT = 'edit';
    public const CREATE = 'create';

    private const READ_FULL_BIT = 0;
    private const READ_SEMI_BIT = 1;
    private const READ_BIT = 2;
    private const EDIT_FULL_BIT = 3;
    private const EDIT_SEMI_BIT = 4;
    private const EDIT_BIT = 5;

    private const READ_FULL_BITS = 1 << self::READ_FULL_BIT;
    private const READ_SEMI_BITS = 1 << self::READ_SEMI_BIT;
    private const READ_BITS = 1 << self::READ_BIT;
    private const EDIT_FULL_BITS = 1 << self::EDIT_FULL_BIT;
    private const EDIT_SEMI_BITS = 1 << self::EDIT_SEMI_BIT;
    private const EDIT_BITS = 1 << self::EDIT_BIT;

    

    /**
     * @return int
     */
    public static function getFullBits () :int {
        return (self::EDIT_BITS << 1) - 1;
    }

    /**
     * @param bool $exist
     * @return array
     */
    public static function getApplicableLevels( bool $exist ): array {
        return $exist ? [
            self::READ_FULL,
            self::READ_SEMI,
            self::READ,
            self::EDIT_FULL,
            self::EDIT_SEMI,
            self::EDIT,
        ] : [
            self::READ_FULL,
            self::CREATE,
        ];
    }

    /**
     * @param int $bit
     * @return string  
     */
    public static function getLevelFromBits(int $bit): string {
        switch ($bit) {
            case self::READ_FULL_BITS:
                return self::READ_FULL;
            case self::READ_SEMI_BITS:
                return self::READ_SEMI;
            case self::READ_BITS:
                return self::READ;
            case self::EDIT_FULL_BITS:
                return self::EDIT_FULL;
            case self::EDIT_SEMI_BITS:
                return self::EDIT_SEMI;
            case self::EDIT_BITS:
                return self::EDIT;
            default:
                return self::READ_FULL;
        }
    }

    /**
     * @param string $level
     * @return int
     */
    public static function getBitsFromLevel(string $level): int {
        switch ($level) {
            case self::READ_FULL:
                return self::READ_FULL_BITS;
            case self::READ_SEMI:
                return self::READ_SEMI_BITS;
            case self::READ:
                return self::READ_BITS;
            case self::EDIT_FULL:
                return self::EDIT_FULL_BITS;
            case self::EDIT_SEMI:
                return self::EDIT_SEMI_BITS;
            case self::EDIT:
                return self::EDIT_BITS;
            default:
                return self::READ_FULL_BITS;
        }
    }
}
