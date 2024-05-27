<?php
/**
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 2 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program; if not, write to the Free Software
 * Foundation, Inc., 51 Franklin Street, Fifth Floor, Boston, MA 02110-1301, USA
 *
 * @file
 * @ingroup Actions
 */

namespace MediaWiki\Extension\AspaklaryaLockDown;

use Article;
use FormlessAction;
use IContextSource;
use MediaWiki\SpecialPage\SpecialPageFactory;

/**
 * An action that just passes the request to the relevant special page
 *
 * @ingroup Actions
 * @since 1.25
 */
class ALRevisionLockAction extends FormlessAction {

	/** @var SpecialPageFactory */
	private $specialPageFactory;

	/**
	 * @param Article $article
	 * @param IContextSource $context
	 * @param SpecialPageFactory $specialPageFactory
	 * @param string $actionName
	 */
	public function __construct(
		Article $article,
		IContextSource $context,
		SpecialPageFactory $specialPageFactory,
	) {
		parent::__construct( $article, $context );
		$this->specialPageFactory = $specialPageFactory;
	}

	/**
	 * @inheritDoc
	 */
	public function getName() {
		return 'revisionlock';
	}

	public function requiresUnblock() {
		return true;
	}

	public function getDescription() {
		return '';
	}

	public function getRestriction() {
		return 'aspaklarya-lock-revisions';
	}

	public function onView() {
		return '';
	}

	public function show() {
		$special = $this->specialPageFactory->getPage(
			$this->getName()
		);
		
		$special->setContext( $this->getContext() );
		// $special->getContext()->setActionName('');
		$special->getContext()->setTitle( $special->getPageTitle() );
		$special->run( '' );
	}

	public function doesWrites() {
		return true;
	}

}
