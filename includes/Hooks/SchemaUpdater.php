<?php

namespace MediaWiki\Extension\AspaklaryaLockDown\Hooks;

use DatabaseUpdater;
use MediaWiki\Installer\Hook\LoadExtensionSchemaUpdatesHook;

class SchemaUpdater implements LoadExtensionSchemaUpdatesHook {
	/**
	 * @param DatabaseUpdater $updater
	 */
	public function onLoadExtensionSchemaUpdates( $updater ) {
		$type = $updater->getDB()->getType();
		$updater->addExtensionTable(
			'aspaklarya_lockdown_pages',
			__DIR__ . '/../../dbPatches/' . $type . '/tables-generated.sql'
		);
		$updater->addExtensionField(
			'aspaklarya_lockdown_pages',
			'al_level',
			__DIR__ . '/../../dbPatches/' . $type . '/add_level_field.sql'
		);

		$updater->modifyExtensionField(
			'aspaklarya_lockdown_pages',
			'al_level',
			__DIR__ . '/../../dbPatches/' . $type . '/set_level_field.sql'
		);
		$updater->dropExtensionField(
			'aspaklarya_lockdown_pages',
			'al_read_allowed',
			__DIR__ . '/../../dbPatches/' . $type . '/remove_read_allowed_field.sql'
		);
	}
}
