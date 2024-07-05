<?php

namespace MediaWiki\Extension\AspaklaryaLockDown\Hooks;

use MediaWiki\Diff\Hook\ArticleContentOnDiffHook;
use MediaWiki\Diff\Hook\DifferenceEngineNewHeaderHook;
use MediaWiki\Diff\Hook\DifferenceEngineOldHeaderHook;
use MediaWiki\Extension\AspaklaryaLockDown\ALDBData;
use MediaWiki\Extension\AspaklaryaLockDown\Special\ALSpecialRevisionLock;
use MediaWiki\Hook\ArticleRevisionVisibilitySetHook;
use MediaWiki\MediaWikiServices;
use MediaWiki\Revision\RevisionRecord;
use Xml;

class RevisionHooks implements
	DifferenceEngineOldHeaderHook,
	DifferenceEngineNewHeaderHook,
	ArticleRevisionVisibilitySetHook,
	ArticleContentOnDiffHook
	{
   /**
	* @inheritDoc
	*/
	public function onArticleContentOnDiff( $differenceEngine, $out ) {
		if ( $differenceEngine->getAuthority()->isAllowed( 'aspaklarya-lock-revisions' ) ) {
			return true;
		}
		$newId = $differenceEngine->getNewid();
		$oldId = $differenceEngine->getOldid();
		if ( $newId > 0 ) {

			$locked = AspaklaryaLockdown::cachedVal( $newId, 'revision' );
			if ( $locked ) {
				$out->showPermissionsErrorPage( [ [ 'aspaklarya_lockdown-rev-error',implode( ', ', AspaklaryaLockdown::getLinks( 'aspaklarya-lock-revisions' ) ) ] ] );
				return false;
			}
		}
		if ( $oldId > 0 ) {
			$locked = AspaklaryaLockdown::cachedVal( $oldId, 'revision' );
			if ( $locked ) {
				$out->showPermissionsErrorPage( [ [ 'aspaklarya_lockdown-rev-error',implode( ', ', AspaklaryaLockdown::getLinks( 'aspaklarya-lock-revisions' ) ) ] ] );
				return false;
			}
		}

		return true;
	}

	/**
	 * @inheritDoc
	 */
	public function onDifferenceEngineOldHeader( $differenceEngine, &$oldHeader,
	$prevlink, $oldminor, $diffOnly, $ldel, $unhide
) {
	$user = $differenceEngine->getAuthority();
	if ( !$user->isAllowed( 'aspaklarya-lock-revisions' ) ) {
		return true;
	}
	$title = $differenceEngine->getTitle();
	$oldId = $differenceEngine->getOldId();
	if ( $oldId < 1 || !$title ) {
		return true;
	}
	$link = ALSpecialRevisionLock::linkToPage( $title, [ $oldId ] );
	$tag = Xml::tags( 'span', [ 'class' => 'mw-revdelundel-link' ], wfMessage( 'parentheses' )->rawParams( $link )->escaped() );
	$oldHeader .= '<div id="mw-diff-otitle5">' . $tag . '</div>';
	}

/**
 * @inheritDoc
 */
public function onDifferenceEngineNewHeader( $differenceEngine, &$newHeader,
	$formattedRevisionTools, $nextlink, $rollback, $newminor, $diffOnly, $rdel,
	$unhide
) {
	$user = $differenceEngine->getAuthority();
	if ( !$user->isAllowed( 'aspaklarya-lock-revisions' ) ) {
		return true;
	}
	$title = $differenceEngine->getTitle();
	if ( !$title ) {
		return true;
	}
	$newrev = $differenceEngine->getNewRevision();
	if ( !$newrev ) {
		return true;
	}
	$newId = $newrev->getId();
	if ( $newrev->isDeleted( RevisionRecord::DELETED_TEXT ) || $newId < 1 || $title->getLatestRevID() == $newId ) {
		return true;
	}
	if ( $newId < 1 ) {
		return true;
	}
	$link = ALSpecialRevisionLock::linkToPage( $title, [ $newId ] );
	$tag = Xml::tags( 'span', [ 'class' => 'mw-revdelundel-link' ], wfMessage( 'parentheses' )->rawParams( $link )->escaped() );
	$newHeader = str_replace( '<div id="mw-diff-ntitle4">', '<div id="mw-diff-ntitle6">' . $tag . '</div>' . '<div id="mw-diff-ntitle4">', $newHeader );
}

/**
 * @inheritDoc
 */
	public function onArticleRevisionVisibilitySet( $title, $ids, $visibilityChangeMap ) {
		$dbw = MediaWikiServices::getInstance()->getDBLoadBalancer()->getConnection( DB_PRIMARY );
		$cache = MediaWikiServices::getInstance()->getMainWANObjectCache();
		foreach ( $visibilityChangeMap as $id => $visibility ) {
			if ( $visibility['newBits'] & RevisionRecord::DELETED_TEXT ) {
				$dbw->delete( ALDBData::getRevisionsTableName(), [ 'alr_rev_id' => $id ], __METHOD__ );
				$cacheKey = $cache->makeKey( 'aspaklarya-lockdown', 'revision', $id );
				$cache->delete( $cacheKey );
			}
		}
	}
}
