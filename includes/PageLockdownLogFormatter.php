<?php

namespace MediaWiki\Extension\PageLockdown;

use MediaWiki\Logging\LogFormatter;
use MediaWiki\Logging\LogPage;
use MediaWiki\Extension\PageLockdown\Special\PageLockdownSpecialRevisionLock;

class PageLockdownLogFormatter extends LogFormatter {
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
		if ( !$this->context->getAuthority()->isAllowed( 'page-lockdown-revisions' )
			|| $this->entry->isDeleted( LogPage::DELETED_ACTION ) ) {
			return '';
		}

		$linkRenderer = $this->getLinkRenderer();

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
				$links[] = PageLockdownSpecialRevisionLock::linkToPage( $this->entry->getTarget(), $ids );

				return $this->msg( 'parentheses' )->rawParams(
					$this->context->getLanguage()->pipeList( $links ) )->escaped();
			default:
				return '';
		}
	}
}
