<?php

namespace MediaWiki\Extension\PageLockdown\Hooks;

use MediaWiki\Extension\PageLockdown\Services\PageLockdownLinkRenderer;
use MediaWiki\Extension\PageLockdown\Services\PageLockdownLinkRendererFactory;
use MediaWiki\Extension\PageLockdown\Services\PageLockdownRevisionStore;
use MediaWiki\Extension\PageLockdown\Services\PageLockdownRevisionStoreFactory;
use MediaWiki\Hook\MediaWikiServicesHook;
use MediaWiki\Logger\LoggerFactory;
use MediaWiki\MediaWikiServices;
use MediaWiki\Revision\RevisionFactory;
use MediaWiki\Revision\RevisionLookup;

class ServicesHook implements MediaWikiServicesHook {

	/**
	 * @param MediaWikiServices $services
	 * @return bool|void True or no return value to continue or false to abort
	 */
	public function onMediaWikiServices( $services ) {
		$services->redefineService( 'RevisionStoreFactory', static function ( MediaWikiServices $services ): PageLockdownRevisionStoreFactory {
			return new PageLockdownRevisionStoreFactory(
				$services->getDBLoadBalancerFactory(),
				$services->getBlobStoreFactory(),
				$services->getNameTableStoreFactory(),
				$services->getSlotRoleRegistry(),
				$services->getMainWANObjectCache(),
				$services->getLocalServerObjectCache(),
				$services->getCommentStore(),
				$services->getActorStoreFactory(),
				LoggerFactory::getInstance( 'RevisionStore' ),
				$services->getContentHandlerFactory(),
				$services->getPageStoreFactory(),
				$services->getTitleFactory(),
				$services->getHookContainer(),
				$services->getRecentChangeLookup()
			);
		} );
		$services->redefineService( 'RevisionStore', static function ( MediaWikiServices $services ): PageLockdownRevisionStore {
			return $services->getRevisionStoreFactory()->getRevisionStore();
		} );
		$services->redefineService( 'RevisionFactory', static function ( MediaWikiServices $services ): RevisionFactory {
			return $services->getRevisionStore();
		} );

		$services->redefineService( 'RevisionLookup', static function ( MediaWikiServices $services ): RevisionLookup {
			return $services->getRevisionStore();
		} );

		$services->redefineService( 'LinkRendererFactory', static function ( MediaWikiServices $services ): PageLockdownLinkRendererFactory {
			return new PageLockdownLinkRendererFactory(
				$services->getTitleFormatter(),
				$services->getLinkCache(),
				$services->getSpecialPageFactory(),
				$services->getHookContainer()
			);
		} );

		$services->redefineService( 'LinkRenderer', static function ( MediaWikiServices $services ): PageLockdownLinkRenderer {
			return $services->getLinkRendererFactory()->create();
		} );
	}
}
