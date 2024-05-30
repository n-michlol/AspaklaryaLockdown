<?php
/**
 * Implements Special:Protectedtitles
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

use HtmlArmor;
use HTMLForm;
use HTMLSelectNamespace;
use MediaWiki\Cache\LinkBatchFactory;
use MediaWiki\Extension\AspaklaryaLockDown\AspaklaryaLockedTitlesPager;
use MediaWiki\Html\Html;
use MediaWiki\Linker\Linker;
use MediaWiki\Title\Title;
use SpecialPage;
use stdClass;
use TitleFormatter;
use Wikimedia\Rdbms\ILoadBalancer;

/**
 * A special page that list protected titles from creation
 *
 * @ingroup SpecialPage
 */
class AspaklaryaLockedTitles extends SpecialPage {

	/** @var LinkBatchFactory */
	private $linkBatchFactory;

	/** @var ILoadBalancer */
	private $loadBalancer;

	/** @var TitleFormatter */
	private $titleFormatter;

	/**
	 * @param LinkBatchFactory $linkBatchFactory
	 * @param ILoadBalancer $loadBalancer
	 * @param TitleFormatter $titleFormatter
	 */
	public function __construct(
		LinkBatchFactory $linkBatchFactory,
		ILoadBalancer $loadBalancer,
		TitleFormatter $titleFormatter
	) {
		parent::__construct( 'Lockedtitles', 'aspaklarya-lockdown-list' );
		$this->linkBatchFactory = $linkBatchFactory;
		$this->loadBalancer = $loadBalancer;
		$this->titleFormatter = $titleFormatter;
	}

	public function execute( $par ) {
		$this->checkPermissions();
		$this->setHeaders();
		$this->outputHeader();

		$request = $this->getRequest();
		$NS = $request->getIntOrNull( 'namespace' );

		$pager = new AspaklaryaLockedTitlesPager(
			$this,
			$this->linkBatchFactory,
			$this->loadBalancer,
			[],
			$NS,
		);

		$this->getOutput()->addHTML( $this->showOptions() );

		if ( $pager->getNumRows() ) {
			$this->getOutput()->addHTML(
				$pager->getNavigationBar() .
					'<ul>' . $pager->getBody() . '</ul>' .
					$pager->getNavigationBar()
			);
		} else {
			$this->getOutput()->addWikiMsg( 'aspaklaryalockedtitlesempty' );
		}
	}

	/**
	 * Callback function to output a restriction
	 *
	 * @param stdClass $row Database row
	 * @return string
	 */
	public function formatRow( $row ) {
		$title = Title::makeTitleSafe( $row->al_page_namespace, $row->al_page_title );
		if ( !$title ) {
			return Html::rawElement(
				'li',
				[],
				Html::element(
					'span',
					[ 'class' => 'mw-invalidtitle' ],
					Linker::getInvalidTitleDescription(
						$this->getContext(),
						$row->al_page_namespace,
						$row->al_page_title
					)
				)
			) . "\n";
		}

		$link = HtmlArmor::getHtml( $this->titleFormatter->getPrefixedText( $title ) );

		$description = $this->getLinkRenderer()
			->makeKnownLink(
				$title,
				$this->msg( 'aspaklarya-lockdown-create-unlock' )->text(),
				[],
				[ 'action' => 'aspaklarya_lockdown' ]
			);
		$lang = $this->getLanguage();
		return '<li>' . $lang->specialList( $link, $description ) . "</li>\n";
	}

	/**
	 * @return string
	 */
	private function showOptions() {
		$formDescriptor = [
			'namespace' => [
				'class' => HTMLSelectNamespace::class,
				'name' => 'namespace',
				'id' => 'namespace',
				'cssclass' => 'namespaceselector',
				'all' => '',
				'label' => $this->msg( 'namespace' )->text()
			],
		];

		$htmlForm = HTMLForm::factory( 'ooui', $formDescriptor, $this->getContext() )
			->setMethod( 'get' )
			->setWrapperLegendMsg( 'lockedtitles' )
			->setSubmitTextMsg( 'lockedtitles-submit' );

		return $htmlForm->prepareForm()->getHTML( false );
	}

	protected function getGroupName() {
		return 'maintenance';
	}
}
