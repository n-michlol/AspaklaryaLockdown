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
namespace MediaWiki\Extension\Special\AspaklaryaLockDown;

use Html;
use HtmlArmor;
use ILanguageConverter;
use Linker;
use MediaWiki\Cache\LinkBatchFactory;
use MediaWiki\Languages\LanguageConverterFactory;
use QueryPage;
use Title;
use Wikimedia\Rdbms\IDatabase;
use Wikimedia\Rdbms\ILoadBalancer;
use Wikimedia\Rdbms\IResultWrapper;

/**
 * Special page for listing the articles with the fewest revisions.
 *
 * @ingroup SpecialPage
 * @author Martin Drashkov
 */
class AspaklaryaLockedPages extends QueryPage {

	/** @var ILanguageConverter */
	private $languageConverter;

	/**
	 * @param ILoadBalancer $loadBalancer
	 * @param LinkBatchFactory $linkBatchFactory
	 * @param LanguageConverterFactory $languageConverterFactory
	 */
	public function __construct(
		ILoadBalancer $loadBalancer,
		LinkBatchFactory $linkBatchFactory,
		LanguageConverterFactory $languageConverterFactory
	) {
		parent::__construct( 'Aspaklaryalockedpages','aspaklarya_lockdown' );
		$this->setDBLoadBalancer( $loadBalancer );
		$this->setLinkBatchFactory( $linkBatchFactory );
		$this->languageConverter = $languageConverterFactory->getLanguageConverter( $this->getContentLanguage() );
	}

	public function isSyndicated() {
		return false;
	}

	public function getQueryInfo() {
		return [
			'tables' => [ 'aspaklarya_lockdown_pages', 'page' ],
			'fields' => [
				'namespace' => 'page_namespace',
				'title' => 'page_title',
				'value' => 'COUNT(*)',
			],
			'conds' => [
				'page_id = al_page_id',
			],
			'options' => [
				'GROUP BY' => [ 'page_namespace', 'page_title' ]
			]
		];
	}

	protected function sortDescending() {
		return false;
	}

	/**
	 * @param Skin $skin
	 * @param stdClass $result Database row
	 * @return string
	 */
	public function formatResult( $skin, $result ) {
		$nt = Title::makeTitleSafe( $result->namespace, $result->title );
		if ( !$nt ) {
			return Html::element(
				'span',
				[ 'class' => 'mw-invalidtitle' ],
				Linker::getInvalidTitleDescription(
					$this->getContext(),
					$result->namespace,
					$result->title
				)
			);
		}
		$linkRenderer = $this->getLinkRenderer();

		$text = $this->languageConverter->convertHtml( $nt->getPrefixedText() );
		$plink = $linkRenderer->makeLink( $nt, new HtmlArmor( $text ) );
		
		$nlink = $linkRenderer->makeKnownLink(
			$nt,
			null,
			[],
			[ 'action' => 'history' ]
		);

		return $this->getLanguage()->specialList( $plink, $nlink );
	}

	/**
	 * Cache page existence for performance
	 *
	 * @param IDatabase $db
	 * @param IResultWrapper $res
	 */
	protected function preprocessResults( $db, $res ) {
		$this->executeLBFromResultWrapper( $res );
	}

	protected function getGroupName() {
		return 'maintenance';
	}
}
