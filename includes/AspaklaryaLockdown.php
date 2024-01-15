<?php

namespace MediaWiki\Extension\AspaklaryaLockDown;

require_once __DIR__ . '/dbData.php';

use Title;
use User;
use ApiBase;
use MediaWiki\Linker\LinkTarget;
use MediaWiki\Revision\RevisionRecord;
use RequestContext;

class AspaklaryaLockdown {

	/**
	 * Main hook
	 *
	 * @param Title $title
	 * @param User $user
	 * @param string $action
	 * @param string &$result
	 * @return false|void
	 */
	public static function onGetUserPermissionsErrors($title, $user, $action, &$result) {

		if (($action === "edit" && $user->isAllowed('aspaklarya-edit-locked')) || ($action === "read" && $user->isAllowed('aspaklarya-read-locked'))) {
			return;
		}

		// get the title id
		$titleId = $title->getArticleID();

		if ($action === "read") {
			// check if page is eliminated for read
			$pageElimination = ALDBData::isReadEliminated($titleId);
			if ($pageElimination === true) {
				$result = "This page is eliminated for read";
				return false;
			}
		} else if ($action === "edit") {
			// check if page is eliminated for edit
			$pageElimination = ALDBData::isEditEliminated($titleId);
			if ($pageElimination === true) {
				$result = "This page is eliminated for edit";
				return false;
			}
		} else if ($action === "create") {
			// check if page is eliminated for create
			$pageElimination = ALDBData::isCreateEliminated($titleId);
			if ($pageElimination === true) {
				$result = "This page is eliminated for create";
				return false;
			}
		}
	}
	/**
	 * @inheritDoc
	 */
	public static function onBeforeParserFetchTemplateRevisionRecord(?LinkTarget $contextTitle, LinkTarget $title, bool &$skip, ?RevisionRecord &$revRecord) {
		$user = RequestContext::getMain()->getUser();
		if ($user->isAllowed('aspaklarya-read-locked')) {
			$skip = false;
			return;
		}
		// get the title id
		$titleId = Title::newFromLinkTarget($title)->getArticleID();
		if ($titleId < 1) {
			$skip = false;
			return;
		}
		// check if page is eliminated for read
		$pageElimination = ALDBData::isReadEliminated($titleId);
		if ($pageElimination === true) {
			$skip = true;
			return;
		}
		$skip = false;
		return;
	}
	/**
	 * API hook
	 *
	 * @todo This hook is rather hacky but should work well enough
	 *
	 * @param ApiBase $module
	 * @param User $user
	 * @param string &$message
	 * @return false|void
	 */
	public static function onApiCheckCanExecute($module, $user, &$message) {
		$params = $module->extractRequestParams();
		$page = $params['page'] ?? null;
		if ($page) {
			$title = Title::newFromText($page);
			$action = $module->isWriteMode() ? 'edit' : 'read';
			$allowed = self::onGetUserPermissionsErrors($title, $user, $action, $result);
			if ($allowed === false) {
				$module->dieWithError($result);
			}
		}
	}
}
