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

namespace MediaWiki\Extension\PageLockdown\Special;

use Wikimedia\HtmlArmor\HtmlArmor;
use MediaWiki\HTMLForm\HTMLForm;
use MediaWiki\HTMLForm\Field\HTMLSelectNamespace;
use MediaWiki\Cache\LinkBatchFactory;
use MediaWiki\Extension\PageLockdown\PageLockdownLockedTitlesPager;
use MediaWiki\Html\Html;
use MediaWiki\Linker\Linker;
use MediaWiki\SpecialPage\SpecialPage;
use MediaWiki\Title\Title;
use MediaWiki\Title\TitleFormatter;
use stdClass;
use Wikimedia\Rdbms\ILoadBalancer;

/**
 * A special page that list protected titles from creation
 *
 * @ingroup SpecialPage
 */
class PageLockdownLockedTitles extends SpecialPage {

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
		parent::__construct( 'PageLockdownLockedTitles', 'page-lockdown-list' );
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

		$pager = new PageLockdownLockedTitlesPager(
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
			$this->getOutput()->addWikiMsg( 'pagelockdownlockedtitlesempty' );
		}
	}

	/**
	 * Callback function to output a restriction
	 *
	 * @param stdClass $row Database row
	 * @return string
	 */
	public function formatRow( $row ) {
		$title = Title::makeTitleSafe( $row->plt_page_namespace, $row->plt_page_title );
		if ( !$title ) {
			return Html::rawElement(
				'li',
				[],
				Html::element(
					'span',
					[ 'class' => 'mw-invalidtitle' ],
					Linker::getInvalidTitleDescription(
						$this->getContext(),
						$row->plt_page_namespace,
						$row->plt_page_title
					)
				)
			) . "\n";
		}

		$link = HtmlArmor::getHtml( $this->titleFormatter->getPrefixedText( $title ) );

		$description = $this->getLinkRenderer()
			->makeKnownLink(
				$title,
				$this->msg( 'page-lockdown-create-unlock' )->text(),
				[],
				[ 'action' => 'page-lockdown' ]
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
