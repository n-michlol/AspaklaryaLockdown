<?php
/**
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
 * @ingroup Pager
 */
namespace MediaWiki\Extension\AspaklaryaLockDown;

use MediaWiki\Cache\LinkBatchFactory;
use MediaWiki\Extension\AspaklaryaLockDown\Special\AspaklaryaLockedTitles;
use MediaWiki\Pager\AlphabeticPager;
use MediaWiki\Title\Title;
use Wikimedia\Rdbms\ILoadBalancer;

/**
 * @ingroup Pager
 */
class AspaklaryaLockedTitlesPager extends AlphabeticPager {

	/**
	 * @var AspaklaryaLockedTitles
	 */
	public $mForm;

	/**
	 * @var array
	 */
	public $mConds;

	/** @var string|null */
	private $level;

	/** @var int|null */
	private $namespace;

	/** @var LinkBatchFactory */
	private $linkBatchFactory;

	/**
	 * @param AspaklaryaLockedTitles $form
	 * @param LinkBatchFactory $linkBatchFactory
	 * @param ILoadBalancer $loadBalancer
	 * @param array $conds
	 * @param int|null $namespace
	 */
	public function __construct(
		AspaklaryaLockedTitles $form,
		LinkBatchFactory $linkBatchFactory,
		ILoadBalancer $loadBalancer,
		$conds,
		$namespace,
	) {
		// Set database before parent constructor to avoid setting it there with wfGetDB
		$this->mDb = $loadBalancer->getConnection( DB_REPLICA );
		$this->mForm = $form;
		$this->mConds = $conds;
		$this->namespace = $namespace;
		parent::__construct( $form->getContext() );
		$this->linkBatchFactory = $linkBatchFactory;
	}

	protected function getStartBody() {
		# Do a link batch query
		$this->mResult->seek( 0 );
		$lb = $this->linkBatchFactory->newLinkBatch();

		foreach ( $this->mResult as $row ) {
			$lb->add( $row->al_page_namespace, $row->al_page_title );
		}

		$lb->execute();

		return '';
	}

	/**
	 * @return Title
	 */
	public function getTitle() {
		return $this->mForm->getPageTitle();
	}

	public function formatRow( $row ) {
		return $this->mForm->formatRow( $row );
	}

	/**
	 * @return array
	 */
	public function getQueryInfo() {
		$dbr = $this->getDatabase();
		$conds = $this->mConds;

		if ( $this->namespace !== null ) {
			$conds[] = 'al_page_namespace=' . $dbr->addQuotes( $this->namespace );
		}

		return [
			'tables' => 'aspaklarya_lockdown_create_titles',
			'fields' => [ 'al_page_title', 'al_page_namespace' ],
			'conds' => $conds
		];
	}

	public function getIndexField() {
		return [ [ 'al_page_title', 'al_page_namespace' ] ];
	}
}
