<?php

namespace MediaWiki\Extension\AspaklaryaLockDown;

use LogFormatter;
use LogPage;
use MediaWiki\Extension\AspaklaryaLockDown\Special\ALSpecialRevisionLock;
use SpecialPage;

class ALLogFormatter extends LogFormatter {
	public function getMessageParameters() {
		$params = parent::getMessageParameters();
		$subType = $this->entry->getSubtype();
		if ( $subType == 'hide' || $subType == 'unhide' ) {
			if ( !isset( $params[4] ) ) {
				$params[4] = $this->entry->getParameters()['ids'];
			}
			if ( !isset( $params[4] ) ) {
				$params[4] = $this->entry->getParameters()['revid'];
			}
			if ( !isset( $params[4] ) ) {
				$params[4] = '0';
			}
			if ( !is_array( $params[4] ) ) {
				$params[4] = explode( ',', $params[4] );
			}
			$params[5] = count( $params[4] );
		}
		return $params;
	}

	public function getActionLinks() {
		$linkRenderer = $this->getLinkRenderer();
		if ( !$this->context->getAuthority()->isAllowed( 'aspaklarya_lockdown' )
			|| $this->entry->isDeleted( LogPage::DELETED_ACTION )
		) {
			return '';
		}

		switch ( $this->entry->getSubtype() ) {
			case 'hide':
			case 'unhide':
				$params = $this->extractParameters();
				if ( !isset( $params[3] ) || !isset( $params[4] ) ) {
					return '';
				}

				// This is a array or CSV of the IDs
				$ids = is_array( $params[4] )
					? $params[4]
					: explode( ',', $params[4] );

				$links = [];

				// If there's only one item, we can show a diff link
				if ( count( $ids ) == 1 ) {
					// Live revision diffs...
						$links[] = $linkRenderer->makeKnownLink(
							$this->entry->getTarget(),
							$this->msg( 'diff' )->text(),
							[],
							[
								'diff' => intval( $ids[0] ),
							]
						);
				}

				// View/modify link...
				$links[] = ALSpecialRevisionLock::linkToPage( $this->entry->getTarget(), $ids );

				return $this->msg( 'parentheses' )->rawParams(
					$this->context->getLanguage()->pipeList( $links ) )->escaped();
			default:
				return '';
		}
	}
}
