<?php

namespace MediaWiki\Extension\AspaklaryaLockDown;

use MediaWiki;
use MediaWiki\Linker\LinkRenderer;
use MediaWiki\Linker\LinkTarget;
use MediaWiki\MediaWikiServices;
use MediaWiki\Page\PageReference;
use Wikimedia\Assert\Assert;

class ALLinkRenderer extends LinkRenderer {

    /**
     * @inheritDoc
     */
    public function makeBrokenLink(
		$target, $text = null, array $extraAttribs = [], array $query = []
	){
        Assert::parameterType( [ LinkTarget::class, PageReference::class ], $target, '$target' );
        $ns = $target->getNamespace();
        $title = $target->getDBkey();
        if ( $ns == NS_SPECIAL ) {
            return parent::makeBrokenLink($target, $text, $extraAttribs, $query);
        }
        $cache = MediaWikiServices::getInstance()->getMainWANObjectCache();
        $key = $cache->makeKey( 'aspaklarya-lockdown', 'create', $ns, $title );
        $state = $cache->getWithSetCallback( $key, $cache::TTL_MONTH, function() use ( $ns, $title ) {
            $inDb = ALDBData::isCreateEliminated( $ns, $title );
            if ( $inDb ) {
                return 'locked';
            }
            return 'unlocked';
        });
        if ($state === 'locked'){
            $formatter = MediaWikiServices::getInstance()->getTitleFormatter();
            return $text ?? $formatter->getPrefixedText( $target );
        }  
        return parent::makeBrokenLink($target, $text, $extraAttribs, $query);
    }
}