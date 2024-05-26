<?php

namespace MediaWiki\Extension\AspaklaryaLockDown;

use LogFormatter;
use LogPage;
use Message;
use SpecialPage;

class ALLogFormatter extends LogFormatter {
	// public function getMessageParameters() {
	// 	$params = parent::getMessageParameters();
	// 	$type = $this->entry->getFullType();

	// 	if ( $type === 'aspaklarya/hide' || $type === 'aspaklarya/unhide' ) {

	// 		$link = $this->getLinkRenderer()->makeKnownLink(
	// 			$this->entry->getTarget(),
	// 			$this->msg( 'revision' )->text(),
	// 			[],
	// 			[ 'oldid' => isset( $params[4] ) ? $params[4] : $this->entry->getParameters()['revid'] ?? 0 ],
	// 		);
	// 		$params[4] = Message::rawParam( $link );
	// 	}
	// 	return $params;
	// }

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
				$links[] = $linkRenderer->makeKnownLink(
					SpecialPage::getTitleFor( 'Revisionlock' ),
					$this->msg( 'revlock-restore' )->text(),
					[],
					[
						'target' => $this->entry->getTarget()->getPrefixedText(),
						'ids' => implode( ',', $ids ),
					]
				);

				return $this->msg( 'parentheses' )->rawParams(
					$this->context->getLanguage()->pipeList( $links ) )->escaped();
			default:
				return '';
		}
	}
}
