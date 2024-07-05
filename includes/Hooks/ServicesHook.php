<?php

namespace MediaWiki\Extension\AspaklaryaLockDown\Hooks;

use MediaWiki\Extension\AspaklaryaLockDown\Services\ALLinkRenderer;
use MediaWiki\Extension\AspaklaryaLockDown\Services\ALLinkRendererFactory;
use MediaWiki\Extension\AspaklaryaLockDown\Services\ALRevisionStore;
use MediaWiki\Extension\AspaklaryaLockDown\Services\ALRevisionStoreFactory;
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
		$services->redefineService( 'RevisionStoreFactory', static function ( MediaWikiServices $services ): ALRevisionStoreFactory {
			return new ALRevisionStoreFactory(
				$services->getDBLoadBalancerFactory(),
				$services->getBlobStoreFactory(),
				$services->getNameTableStoreFactory(),
				$services->getSlotRoleRegistry(),
				$services->getMainWANObjectCache(),
				$services->getLocalServerObjectCache(),
				$services->getCommentStore(),
				$services->getActorMigration(),
				$services->getActorStoreFactory(),
				LoggerFactory::getInstance( 'RevisionStore' ),
				$services->getContentHandlerFactory(),
				$services->getPageStoreFactory(),
				$services->getTitleFactory(),
				$services->getHookContainer()
			);
		} );
		$services->redefineService( 'RevisionStore', static function ( MediaWikiServices $services ): ALRevisionStore {
			return $services->getRevisionStoreFactory()->getRevisionStore();
		} );
		$services->redefineService( 'RevisionFactory', static function ( MediaWikiServices $services ): RevisionFactory {
			return $services->getRevisionStore();
		} );

		$services->redefineService( 'RevisionLookup', static function ( MediaWikiServices $services ): RevisionLookup {
			return $services->getRevisionStore();
		} );

		$services->redefineService( 'LinkRendererFactory', static function ( MediaWikiServices $services ): ALLinkRendererFactory {
			return new ALLinkRendererFactory(
				$services->getTitleFormatter(),
				$services->getLinkCache(),
				$services->getSpecialPageFactory(),
				$services->getHookContainer()
			);
		} );

		$services->redefineService( 'LinkRenderer', static function ( MediaWikiServices $services ): ALLinkRenderer {
			return $services->getLinkRendererFactory()->create();
		} );
	}
}
