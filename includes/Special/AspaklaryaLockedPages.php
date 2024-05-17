<?php

/**
 * Implements Special:Aspaklaryalockedpages
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 2 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License along
 * with this program; if not, write to the Free Software Foundation, Inc.,
 * 51 Franklin Street, Fifth Floor, Boston, MA 02110-1301, USA.
 * http://www.gnu.org/copyleft/gpl.html
 *
 * @file
 * @ingroup SpecialPage
 */

namespace MediaWiki\Extension\AspaklaryaLockDown\Special;

use HTMLForm;
use HTMLMultiSelectField;
use HTMLSelectNamespace;
use HTMLSizeFilterField;
use MediaWiki\Cache\LinkBatchFactory;
use MediaWiki\CommentFormatter\RowCommentFormatter;
use MediaWiki\CommentStore\CommentStore;
use MediaWiki\Extension\AspaklaryaLockDown\AspaklaryaLockedPagesPager;
use MediaWiki\MediaWikiServices;
use SpecialPage;
use UserCache;
use Wikimedia\Rdbms\ILoadBalancer;

/**
 * Special page for listing the articles with the fewest revisions.
 *
 * @ingroup SpecialPage
 * @author Martin Drashkov
 */
class AspaklaryaLockedPages extends SpecialPage {
	protected $IdLevel = 'level';

	/** @var LinkBatchFactory */
	private $linkBatchFactory;

	/** @var ILoadBalancer */
	private $loadBalancer;

	/** @var CommentStore */
	private $commentStore;

	/** @var UserCache */
	private $userCache;

	/** @var RowCommentFormatter */
	private $rowCommentFormatter;

	public function __construct() {
		parent::__construct( 'Aspaklaryalockedpage', 'aspaklarya-lockdown-list' );
		$instance = MediaWikiServices::getInstance();
		$this->linkBatchFactory = $instance->getLinkBatchFactory();
		$this->loadBalancer = $instance->getDBLoadBalancer();
		$this->commentStore = $instance->getCommentStore();
		$this->userCache = $instance->getUserCache();
		$this->rowCommentFormatter = $instance->getRowCommentFormatter();
	}

	public function execute( $par ) {
		$this->checkPermissions();
		$this->setHeaders();
		$this->outputHeader();
		$this->getOutput()->addModuleStyles( 'mediawiki.special' );
		$this->addHelpLink( 'Help:Locked_pages' );

		$request = $this->getRequest();
		$level = $request->getVal( $this->IdLevel );
		$sizetype = $request->getVal( 'size-mode' );
		$size = $request->getIntOrNull( 'size' );
		$ns = $request->getIntOrNull( 'namespace' );

		$filters = $request->getArray( 'wpfilters', [] );
		$noRedirect = in_array( 'noredirect', $filters );

		$pager = new AspaklaryaLockedPagesPager(
			$this->getContext(),
			$this->commentStore,
			$this->linkBatchFactory,
			$this->getLinkRenderer(),
			$this->loadBalancer,
			$this->rowCommentFormatter,
			$this->userCache,
			[],
			$level,
			$ns,
			$sizetype,
			$size,
			$noRedirect
		);

		$this->getOutput()->addHTML( $this->showOptions( $filters ) );

		if ( $pager->getNumRows() ) {
			$this->getOutput()->addParserOutputContent( $pager->getFullOutput() );
		} else {
			$this->getOutput()->addWikiMsg( 'lockdownpagesempty' );
		}
	}

	/**
	 * @param array $filters Filters set for the pager: noRedirect
	 * @return string Input form
	 */
	protected function showOptions( $filters ) {
		$formDescriptor = [
			'namespace' => [
				'class' => HTMLSelectNamespace::class,
				'name' => 'namespace',
				'id' => 'namespace',
				'cssclass' => 'namespaceselector',
				'all' => '',
				'label' => $this->msg( 'namespace' )->text(),
			],
			'levelmenu' => $this->getLevelMenu(),
			'filters' => [
				'class' => HTMLMultiSelectField::class,
				'label' => $this->msg( 'aLockdownpages-filters' )->text(),
				'flatlist' => true,
				'options-messages' => [
					'lockdownpages-noredirect' => 'noredirect',
				],
				'default' => $filters,
			],
			'sizelimit' => [
				'class' => HTMLSizeFilterField::class,
				'name' => 'size',
			]
		];
		$htmlForm = HTMLForm::factory( 'ooui', $formDescriptor, $this->getContext() )
			->setMethod( 'get' )
			->setWrapperLegendMsg( 'aLockdownpages' )
			->setSubmitTextMsg( 'aLockdownpages-submit' );

		return $htmlForm->prepareForm()->getHTML( false );
	}

	/**
	 * Creates the input label of the restriction level
	 * @param string $pr_level Protection level
	 * @return array
	 */
	protected function getLevelMenu() {
		// Temporary array
		$m = [ $this->msg( 'aLockdown-level-all' )->text() => 0 ];
		$options = [];

		// First pass to load the log names
		foreach ( [ 'edit', 'read' ] as $type ) {
			$text = $this->msg( "aLockdown-level-$type" )->text();
			$m[$text] = $type;
		}

		// Third pass generates sorted XHTML content
		foreach ( $m as $text => $type ) {
			$options[$text] = $type;
		}

		return [
			'type' => 'select',
			'options' => $options,
			'label' => $this->msg( 'aLockdown-level' )->text(),
			'name' => $this->IdLevel,
			'id' => $this->IdLevel
		];
	}

	protected function getGroupName() {
		return 'maintenance';
	}
}
