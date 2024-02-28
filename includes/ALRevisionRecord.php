<?php

namespace MediaWiki\Extension\AspaklaryaLockDown;

use Content;
use MediaWiki\Permissions\Authority;
use MediaWiki\Revision\RevisionStoreRecord;

class ALRevisionRecord extends RevisionStoreRecord {
    /**
	 * Returns the Content of the given slot of this revision.
	 * Call getSlotNames() to get a list of available slots.
	 *
	 * Note that for mutable Content objects, each call to this method will return a
	 * fresh clone.
	 *
	 * MCR migration note: this replaced Revision::getContent
	 *
	 * @param string $role The role name of the desired slot
	 * @param int $audience
	 * @param Authority|null $performer user on whose behalf to check
	 *
	 * @return Content|null The content of the given slot, or null if access is forbidden.
	 */
	public function getContent( $role, $audience = self::FOR_PUBLIC, Authority $performer = null ): ?Content {
		// XXX: throwing an exception would be nicer, but would a further
		// departure from the old signature of Revision::getContent() when it existed,
		// and thus result in more complex and error prone refactoring.
		if ( !$this->audienceCan( self::DELETED_TEXT, $audience, $performer ) ) {
			return null;
		}

		$content = $this->getSlot( $role, $audience, $performer )->getContent();
		return $content->copy();
	}
}