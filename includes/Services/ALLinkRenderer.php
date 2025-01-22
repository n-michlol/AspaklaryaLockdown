<?php

namespace MediaWiki\Extension\AspaklaryaLockDown\Services;

use HtmlArmor;
use MediaWiki\Extension\AspaklaryaLockDown\Main;
use MediaWiki\Linker\LinkRenderer;
use MediaWiki\Linker\LinkTarget;
use MediaWiki\MediaWikiServices;
use MediaWiki\Page\PageReference;
use MediaWiki\Title\Title;
use Wikimedia\Assert\Assert;

class ALLinkRenderer extends LinkRenderer {

	/**
	 * @inheritDoc
	 */
	public function makeBrokenLink(
		$target, $text = null, array $extraAttribs = [], array $query = []
	) {
		Assert::parameterType( [ LinkTarget::class, PageReference::class ], $target, '$target' );
		$ns = $target->getNamespace();
		if ( $ns == NS_SPECIAL ) {
			return parent::makeBrokenLink( $target, $text, $extraAttribs, $query );
		}
		$title = Title::newFromText( $target->getText() );
		if ( !$title || !$title->canExist() ) {
			return parent::makeBrokenLink( $target, $text, $extraAttribs, $query );
		}
		$state = Main::getLevelFromCache( $title, null, null );
		if ( $state === 'create' ) {
			$formatter = MediaWikiServices::getInstance()->getTitleFormatter();
			return HtmlArmor::getHtml( $text ?? $formatter->getPrefixedText( $target ) );
		}
		return parent::makeBrokenLink( $target, $text, $extraAttribs, $query );
	}
}
