<?php

namespace MediaWiki\Extension\AspaklaryaLockDown\Services;

use MediaWiki\Extension\AspaklaryaLockDown\ALDBData;
use MediaWiki\Permissions\Authority;
use MediaWiki\Revision\RevisionStoreRecord;

class ALRevisionStoreRecord extends RevisionStoreRecord {
	public function userCan( $field, Authority $performer ) {
		if ( $this->isCurrent() && $field === self::DELETED_TEXT ) {
			// Current revisions of pages cannot have the content hidden. Skipping this
			// check is very useful for Parser as it fetches templates using newKnownCurrent().
			// Calling getVisibility() in that case triggers a verification database query.
			return true; // no need to check
		}
		if ( $field === self::DELETED_TEXT && $this->mId && !$performer->isAllowed( 'aspaklarya-read-locked' ) ) {
			$locked = ALDBData::isRevisionLocked( $this->mId );
			if ( $locked === true ) {
				return false;
			}
		}

		return parent::userCan( $field, $performer );
	}
}
