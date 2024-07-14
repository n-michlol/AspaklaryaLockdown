<?php

namespace MediaWiki\Extension\AspaklaryaLockDown;

use MediaWiki\MediaWikiServices;

class ALDBData {

	public const PAGES_REVISION_NAME = "aspaklarya_lockdown_revisions";

	/**
	 * get pages revision name
	 * @return string
	 */
	public static function getRevisionsTableName() {
		return self::PAGES_REVISION_NAME;
	}

	/**
	 * get database connection
	 * @param DB_REPLICA|DB_PRIMARY $i
	 */
	private static function getDB( $i ) {
		$provider = MediaWikiServices::getInstance()->getDBLoadBalancer();
		return $provider->getConnection( $i );
	}

	/**
	 * check if revision is locked
	 * @param int $revId
	 * @return bool
	 */
	public static function isRevisionLocked( int $revId ) {
		$cache = MediaWikiServices::getInstance()->getMainWANObjectCache();
		$locked = $cache->getWithSetCallback(
			$cache->makeKey( "aspaklarya-lockdown", "revision", $revId ),
			$cache::TTL_MONTH,
			function () use ( $revId ) {
				return self::getRevisionState( $revId ) === true ? 1 : 0;
			}
		);
		return $locked === 1;
	}

	private static function getRevisionState( int $revId ) {
		$db = self::getDB( DB_REPLICA );
		$res = $db->newSelectQueryBuilder()
			->select( [ "alr_rev_id" ] )
			->from( self::PAGES_REVISION_NAME )
			->where( [ "alr_rev_id" => $revId ] )
			->caller( __METHOD__ )
			->fetchRow();
		return $res !== false;
	}

	/**
	 * get all locked revisions for this page
	 * @param int $pageId
	 * @return false|array
	 */
	public static function getLockedRevisions( int $pageId ) {
		$db = self::getDB( DB_REPLICA );
		$res = $db->newSelectQueryBuilder()
			->select( [ "alr_rev_id" ] )
			->from( self::PAGES_REVISION_NAME )
			->where( [ "alr_page_id" => $pageId ] )
			->caller( __METHOD__ )
			->fetchFieldValues();
		if ( empty( $res ) ) {
			return false;
		}
		return $res;
	}
}
