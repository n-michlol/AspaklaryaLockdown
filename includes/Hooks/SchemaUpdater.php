<?php

namespace MediaWiki\Extension\PageLockdown\Hooks;

use MediaWiki\Installer\DatabaseUpdater;
use MediaWiki\Installer\Hook\LoadExtensionSchemaUpdatesHook;

class SchemaUpdater implements LoadExtensionSchemaUpdatesHook {
	/**
	 * @param DatabaseUpdater $updater
	 */
	public function onLoadExtensionSchemaUpdates( $updater ) {
		$type = $updater->getDB()->getType();
		$updater->addExtensionTable(
			'page_lockdown_pages',
			__DIR__ . '/../../dbPatches/' . $type . '/tables-generated.sql'
		);
	}
}
