<?php


namespace MediaWiki\Extension\AspaklaryaLockDown;

use DatabaseUpdater;
use MediaWiki\Installer\Hook\LoadExtensionSchemaUpdatesHook;

class SchemaUpdater implements LoadExtensionSchemaUpdatesHook {
	/**
	 * @param DatabaseUpdater $updater
	 */
	public function onLoadExtensionSchemaUpdates($updater) {
		$type = $updater->getDB()->getType();
		$updater->addExtensionTable(
			'aspaklarya_lockdown_pages',
			__DIR__ . '/../dbPatches/' . $type . '/tables-generated.sql'
		);
		$updater->addExtensionTable(
			'aspaklarya_lockdown_bad_words',
			__DIR__ . '/../dbPatches/' . $type . '/bad_words.sql'
		);
	}
}
